<?php

namespace Tests\Feature;

use App\Chat\ChatService;
use App\Chat\PromptBuilder;
use App\Leads\LeadService;
use App\Mail\HandoffRequested;
use App\Mail\LeadAlert;
use App\Models\Bot;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Models\User;
use App\Models\WhatsAppTemplate;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/** Demandes à traiter (commande, rendez-vous, devis, personne demandée) et alertes choisies par le propriétaire. */
class LeadsTest extends TestCase
{
    use CreatesTenants;
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private Bot $bot;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        [$this->workspace, $this->owner, $this->bot] = $this->tenant('Boutique Awa', 'pro');
        $this->bot->update(['handoff_email' => 'equipe@boutique.test']);
        $this->conversation = $this->conversation();
    }

    private function conversation(string $channel = 'web', ?Bot $bot = null, string $external = 'visiteur-1'): Conversation
    {
        $bot ??= $this->bot;

        return Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $bot->workspace_id, 'bot_id' => $bot->id, 'channel' => $channel, 'external_id' => $external,
            'contact_name' => 'Fatou', 'contact_phone' => '+22670123456',
        ]);
    }

    private function capture(string $kind = Lead::ORDER, string $summary = '2 boubous brodés, 70 000 FCFA', ?Conversation $conversation = null): Lead
    {
        return app(LeadService::class)->capture($conversation ?? $this->conversation, $kind, $summary);
    }

    private function saveAlerts(array $data = []): void
    {
        $this->actingAs($this->owner)->put(route('alerts.update'), $data + [
            'email' => '1', 'reminder_minutes' => 30, 'kinds' => array_keys(Lead::KINDS),
        ])->assertSessionHasNoErrors();
        $this->app['auth']->forgetGuards();
    }

    /* ---------- Création ---------- */

    public function test_a_confirmed_order_becomes_a_lead_with_the_customers_contact(): void
    {
        Mail::fake();

        $lead = $this->capture();

        $this->assertSame(Lead::ORDER, $lead->kind);
        $this->assertSame(Lead::NEW, $lead->status);
        $this->assertSame('2 boubous brodés, 70 000 FCFA', $lead->title);
        $this->assertSame('Fatou', $lead->contact_name);
        $this->assertSame('+22670123456', $lead->contact_phone);
        $this->assertSame($this->workspace->id, $lead->workspace_id);
        $this->assertSame('Commande à confirmer', $lead->label());
    }

    public function test_repeating_the_same_request_updates_the_open_lead_instead_of_alerting_twice(): void
    {
        Mail::fake();

        $first = $this->capture(Lead::ORDER, '2 boubous');
        $second = $this->capture(Lead::ORDER, '2 boubous et 1 foulard');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Lead::withoutGlobalScopes()->count());
        $this->assertSame('2 boubous et 1 foulard', $second->fresh()->title);
        Mail::assertQueuedCount(1);
    }

    public function test_a_closed_lead_lets_the_same_customer_open_a_new_one(): void
    {
        Mail::fake();

        $first = $this->capture();
        $first->update(['status' => Lead::DONE]);
        $second = $this->capture();

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, Lead::withoutGlobalScopes()->count());
    }

    public function test_the_assistant_marker_creates_the_lead_and_is_hidden_from_the_customer(): void
    {
        Mail::fake();
        $prompts = app(PromptBuilder::class);

        $parsed = $prompts->parse("Merci Fatou ! L'équipe confirme votre commande.\n[[LEAD: commande | 2 boubous brodés, 70 000 FCFA, livraison à Bobo]]\n[[REPLIES: Merci | Autre chose]]");

        $this->assertSame(['kind' => 'order', 'summary' => '2 boubous brodés, 70 000 FCFA, livraison à Bobo'], $parsed['lead']);
        $this->assertStringNotContainsString('LEAD', $parsed['text']);
        $this->assertStringNotContainsString('[[', $parsed['text']);
        $this->assertSame("Merci Fatou ! L'équipe confirme votre commande.", $parsed['text']);
    }

    public function test_every_supported_word_maps_to_a_kind_and_unknown_words_are_ignored(): void
    {
        $prompts = app(PromptBuilder::class);

        foreach (['commande' => 'order', 'Rendez-vous' => 'appointment', 'RDV' => 'appointment', 'réservation' => 'appointment', 'devis' => 'quote'] as $word => $kind) {
            $this->assertSame($kind, $prompts->parse("Ok.\n[[LEAD: {$word} | test]]")['lead']['kind'], $word);
        }

        $unknown = $prompts->parse("Ok.\n[[LEAD: blague | test]]");
        $this->assertNull($unknown['lead']);
        $this->assertStringNotContainsString('LEAD', $unknown['text'], 'un marqueur inventé est retiré quand même');
    }

    public function test_the_system_prompt_explains_the_lead_marker_to_the_assistant(): void
    {
        $prompt = app(PromptBuilder::class)->system($this->bot);

        $this->assertStringContainsString('[[LEAD: commande | résumé]]', $prompt);
        $this->assertStringContainsString('sans promettre toi-même un paiement ni une livraison', $prompt);
    }

    public function test_a_human_handoff_creates_a_human_lead_with_the_last_message(): void
    {
        Mail::fake();
        $this->conversation->messages()->create(['workspace_id' => $this->workspace->id, 'role' => Message::USER, 'content' => 'Je veux parler à Awa']);

        app(ChatService::class)->requestHuman($this->conversation, 'demande du client');

        $lead = Lead::withoutGlobalScopes()->firstOrFail();
        $this->assertSame(Lead::HUMAN, $lead->kind);
        $this->assertSame('« Je veux parler à Awa »', $lead->summary);
        $this->assertSame(Conversation::NEEDS_HUMAN, $this->conversation->fresh()->status);
        Mail::assertQueued(HandoffRequested::class, fn ($mail) => $mail->hasTo('equipe@boutique.test'));
    }

    /* ---------- Alertes ---------- */

    public function test_by_default_the_owner_gets_an_email_for_an_order_and_the_dashboard_is_always_fed(): void
    {
        Mail::fake();

        $lead = $this->capture();

        Mail::assertQueued(LeadAlert::class, fn ($mail) => $mail->hasTo('equipe@boutique.test') && $mail->lead->is($lead));
        $this->assertSame('sent', $lead->fresh()->alert_log[0]['email']);
        $this->assertNotNull($lead->fresh()->alerted_at);
    }

    public function test_the_alert_email_goes_to_the_address_chosen_by_the_owner(): void
    {
        Mail::fake();
        $this->saveAlerts(['email_to' => 'proprietaire@boutique.test']);

        $this->capture();

        Mail::assertQueued(LeadAlert::class, fn ($mail) => $mail->hasTo('proprietaire@boutique.test'));
    }

    public function test_the_owner_can_switch_email_off_and_the_lead_still_reaches_the_dashboard(): void
    {
        Mail::fake();
        $this->saveAlerts(['email' => '0']);

        $lead = $this->capture();

        Mail::assertNothingQueued();
        $this->assertSame(Lead::NEW, $lead->fresh()->status);
        $this->actingAs($this->owner)->get(route('leads.index'))->assertOk()->assertSee('2 boubous brodés, 70 000 FCFA');
    }

    public function test_the_owner_can_choose_to_be_alerted_only_for_some_kinds(): void
    {
        Mail::fake();
        $this->saveAlerts(['kinds' => [Lead::ORDER]]);

        $this->capture(Lead::QUOTE, 'Devis pour 20 robes');
        Mail::assertNothingQueued();

        $this->capture(Lead::ORDER, '1 robe');
        Mail::assertQueuedCount(1);
    }

    public function test_a_whatsapp_alert_uses_free_text_inside_the_service_window(): void
    {
        Mail::fake();
        $channel = $this->whatsappChannel();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.A']]])]);
        $this->saveAlerts(['whatsapp' => '1', 'whatsapp_number' => '+226 70 99 88 77']);

        // Le propriétaire a écrit au numéro de l'assistant il y a une heure : la fenêtre de 24 h est ouverte.
        $this->conversation('whatsapp', $this->bot, '22670998877')->forceFill(['last_inbound_at' => now()->subHour()])->save();

        $lead = $this->capture();

        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/'.$channel->external_ref.'/messages')
            && $r['type'] === 'text' && $r['to'] === '22670998877'
            && str_contains($r['text']['body'], 'Commande à confirmer') && str_contains($r['text']['body'], '2 boubous brodés'));
        $this->assertSame('sent', $lead->fresh()->alert_log[0]['whatsapp']);
    }

    public function test_outside_the_window_the_alert_needs_the_approved_template_and_says_so_when_missing(): void
    {
        Mail::fake();
        $channel = $this->whatsappChannel();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.B']]])]);
        $this->saveAlerts(['whatsapp' => '1', 'whatsapp_number' => '+22670998877']);

        $missing = $this->capture(Lead::ORDER, 'Commande 1');
        $this->assertSame('template_missing', $missing->fresh()->alert_log[0]['whatsapp']);
        Http::assertNothingSent();

        WhatsAppTemplate::withoutGlobalScopes()->create([
            'workspace_id' => $this->workspace->id, 'channel_id' => $channel->id, 'name' => 'alerte_demande', 'language' => 'fr', 'category' => 'UTILITY',
            'status' => WhatsAppTemplate::APPROVED, 'body' => 'Nouvelle demande : {{1}}. Détail : {{2}}. Répondez.', 'variables_count' => 2,
        ]);
        $second = $this->capture(Lead::QUOTE, 'Devis 30 robes', $this->conversation('web', $this->bot, 'visiteur-2'));

        Http::assertSent(fn (HttpRequest $r) => ($r['type'] ?? null) === 'template' && $r['template']['name'] === 'alerte_demande'
            && $r['template']['components'][0]['parameters'][0]['text'] === 'Devis demandé');
        $this->assertSame('sent', $second->fresh()->alert_log[0]['whatsapp']);
    }

    public function test_whatsapp_alerts_are_refused_on_an_offer_without_whatsapp(): void
    {
        $this->workspace->update(['plan' => 'essentiel']);

        $this->actingAs($this->owner)->put(route('alerts.update'), ['whatsapp' => '1', 'whatsapp_number' => '+22670998877', 'reminder_minutes' => 30])
            ->assertSessionHasErrors('whatsapp');
    }

    public function test_a_number_is_required_when_whatsapp_alerts_are_on(): void
    {
        $this->actingAs($this->owner)->put(route('alerts.update'), ['whatsapp' => '1', 'reminder_minutes' => 30])
            ->assertSessionHasErrors('whatsapp_number');
    }

    public function test_the_alert_template_can_be_created_for_approval_from_the_alerts_page(): void
    {
        $this->whatsappChannel();
        Http::fake(['graph.facebook.com/*' => Http::response(['id' => 'tpl-1', 'status' => 'PENDING'])]);

        $this->actingAs($this->owner)->post(route('alerts.template'))->assertSessionHas('status');

        $template = WhatsAppTemplate::withoutGlobalScopes()->where('name', 'alerte_demande')->firstOrFail();
        $this->assertSame('UTILITY', $template->category);
        $this->assertSame(2, $template->variables_count);
        $this->assertSame(WhatsAppTemplate::PENDING, $template->status);
    }

    /* ---------- Destinataires : membres de l'équipe, autres numéros ---------- */

    public function test_team_members_and_extra_addresses_all_receive_the_email(): void
    {
        Mail::fake();
        $colleague = User::factory()->create(['workspace_id' => $this->workspace->id, 'email' => 'collegue@boutique.test']);
        $this->saveAlerts(['email_members' => [$this->owner->id, $colleague->id], 'email_extra' => "associe@exemple.test\nlivreur@exemple.test"]);

        $this->capture();

        Mail::assertQueued(LeadAlert::class, fn ($mail) => $mail->hasTo($this->owner->email) && $mail->hasTo('collegue@boutique.test')
            && $mail->hasTo('associe@exemple.test') && $mail->hasTo('livreur@exemple.test') && ! $mail->hasTo('equipe@boutique.test'));
    }

    public function test_the_alert_can_go_to_the_agents_own_whatsapp_number_taken_from_their_profile(): void
    {
        Mail::fake();
        $this->whatsappChannel();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.M']]])]);
        $this->owner->update(['phone' => '+226 70 55 44 33']);
        $this->saveAlerts(['whatsapp' => '1', 'whatsapp_members' => [$this->owner->id]]);
        $this->conversation('whatsapp', $this->bot, '22670554433')->forceFill(['last_inbound_at' => now()->subHour()])->save();

        $lead = $this->capture();

        Http::assertSent(fn (HttpRequest $r) => ($r['type'] ?? null) === 'text' && $r['to'] === '22670554433');
        $this->assertSame('sent', $lead->fresh()->alert_log[0]['whatsapp']);
    }

    public function test_several_numbers_are_alerted_and_each_has_its_own_service_window(): void
    {
        Mail::fake();
        $channel = $this->whatsappChannel();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.N']]])]);
        WhatsAppTemplate::withoutGlobalScopes()->create([
            'workspace_id' => $this->workspace->id, 'channel_id' => $channel->id, 'name' => 'alerte_demande', 'language' => 'fr', 'category' => 'UTILITY',
            'status' => WhatsAppTemplate::APPROVED, 'body' => 'Nouvelle demande : {{1}}. Détail : {{2}}. Répondez.', 'variables_count' => 2,
        ]);
        $this->saveAlerts(['whatsapp' => '1', 'whatsapp_number' => '+226 70 99 88 77', 'whatsapp_extra' => "+226 70 11 22 33\n+226 70 44 55 66"]);
        // Seul le numéro principal a écrit récemment : les deux autres passent par le modèle approuvé.
        $this->conversation('whatsapp', $this->bot, '22670998877')->forceFill(['last_inbound_at' => now()->subHour()])->save();

        $this->capture();

        $sent = Http::recorded(fn (HttpRequest $r) => str_ends_with($r->url(), '/'.$channel->external_ref.'/messages'))->map(fn ($p) => $p[0]);
        $this->assertCount(3, $sent);
        $this->assertEqualsCanonicalizing(['text', 'template', 'template'], $sent->map(fn ($r) => $r['type'])->all());
        $this->assertEqualsCanonicalizing(['22670998877', '22670112233', '22670445566'], $sent->map(fn ($r) => $r['to'])->all());
    }

    public function test_the_extra_addresses_and_numbers_are_validated_and_limited_to_three(): void
    {
        $this->actingAs($this->owner)->put(route('alerts.update'), ['reminder_minutes' => 30, 'email_extra' => 'pas-une-adresse'])->assertSessionHasErrors('email_extra');
        $this->actingAs($this->owner)->put(route('alerts.update'), ['reminder_minutes' => 30, 'email_extra' => "a@x.test\nb@x.test\nc@x.test\nd@x.test"])->assertSessionHasErrors('email_extra');
        $this->actingAs($this->owner)->put(route('alerts.update'), ['reminder_minutes' => 30, 'whatsapp_extra' => 'abc'])->assertSessionHasErrors('whatsapp_extra');
    }

    public function test_whatsapp_alerts_need_at_least_one_recipient_but_any_kind_counts(): void
    {
        // Ni numéro principal, ni membre avec un numéro, ni autre numéro : refusé.
        $this->actingAs($this->owner)->put(route('alerts.update'), ['whatsapp' => '1', 'whatsapp_members' => [$this->owner->id], 'reminder_minutes' => 30])->assertSessionHasErrors('whatsapp_number');

        // Un seul « autre numéro » suffit.
        $this->actingAs($this->owner)->put(route('alerts.update'), ['whatsapp' => '1', 'whatsapp_extra' => '+226 70 11 22 33', 'reminder_minutes' => 30])->assertSessionHasNoErrors();
        $this->assertSame(['+22670112233'], $this->workspace->fresh()->alertSettings()['whatsapp_extra']);
    }

    public function test_members_of_another_workspace_can_never_be_added_as_recipients(): void
    {
        Mail::fake();
        [, $stranger] = $this->tenant('Autre entreprise', 'pro');
        $this->saveAlerts(['email_members' => [$stranger->id]]);

        $this->assertSame([], $this->workspace->fresh()->alertSettings()['email_members']);
        $this->capture();
        Mail::assertNotQueued(LeadAlert::class, fn ($mail) => $mail->hasTo($stranger->email));
    }

    public function test_the_profile_keeps_the_personal_whatsapp_number(): void
    {
        $this->actingAs($this->owner)->patch(route('profile.update'), ['name' => $this->owner->name, 'email' => $this->owner->email, 'phone' => '+226 70 55 44 33'])->assertSessionHasNoErrors();
        $this->assertSame('+226 70 55 44 33', $this->owner->fresh()->phone);

        $this->actingAs($this->owner)->patch(route('profile.update'), ['name' => $this->owner->name, 'email' => $this->owner->email, 'phone' => 'pas un numéro'])->assertSessionHasErrors('phone');
        $this->actingAs($this->owner)->get(route('alerts.edit'))->assertOk()->assertSee('Numéros des membres de l')->assertSee('+226 70 55 44 33');
    }

    /* ---------- Rappels ---------- */

    public function test_unanswered_leads_get_up_to_three_reminders_spaced_by_the_chosen_delay(): void
    {
        Mail::fake();
        $this->saveAlerts(['reminder_minutes' => 15]);
        $lead = $this->capture();
        Mail::assertQueuedCount(1);

        $this->artisan('leads:remind')->assertSuccessful();
        Mail::assertQueuedCount(1); // trop tôt

        foreach ([1, 2, 3] as $n) {
            $this->travel(16 * $n + 40)->minutes();
            $this->artisan('leads:remind')->assertSuccessful();
        }
        $this->travel(500)->minutes();
        $this->artisan('leads:remind')->assertSuccessful();

        $this->assertSame(3, $lead->fresh()->reminders, 'trois rappels au plus');
        Mail::assertQueuedCount(4);
        Mail::assertQueued(LeadAlert::class, fn ($mail) => $mail->reminder === true);
    }

    public function test_taking_a_lead_or_replying_stops_the_reminders(): void
    {
        Mail::fake();
        $lead = $this->capture();

        $this->actingAs($this->owner)->patch(route('leads.update', $lead), ['action' => 'take'])->assertSessionHas('status');
        $this->assertSame(Lead::TAKEN, $lead->fresh()->status);
        $this->assertSame($this->owner->id, $lead->fresh()->assigned_to);

        $this->travel(3)->hours();
        $this->artisan('leads:remind')->assertSuccessful();
        Mail::assertQueuedCount(1);
    }

    public function test_replying_to_the_customer_takes_over_the_open_leads(): void
    {
        Mail::fake();
        $lead = $this->capture();

        $this->actingAs($this->owner)->post(route('conversations.reply', [$this->bot, $this->conversation]), ['content' => 'Bonsoir Fatou, c\'est Awa.'])->assertRedirect();

        $this->assertSame(Lead::TAKEN, $lead->fresh()->status);
    }

    public function test_reminders_can_be_switched_off(): void
    {
        Mail::fake();
        $this->saveAlerts(['reminder_minutes' => 0]);
        $lead = $this->capture();

        $this->travel(2)->days();
        $this->artisan('leads:remind')->assertSuccessful();

        $this->assertSame(0, $lead->fresh()->reminders);
        Mail::assertQueuedCount(1);
    }

    /* ---------- Pages ---------- */

    public function test_the_leads_page_lists_open_leads_and_lets_the_owner_close_them(): void
    {
        Mail::fake();
        $lead = $this->capture(Lead::APPOINTMENT, 'Samedi 10 h, coupe et brushing');

        $this->actingAs($this->owner)->get(route('leads.index'))->assertOk()
            ->assertSee('Rendez-vous à confirmer')->assertSee('Samedi 10 h, coupe et brushing')->assertSee('Fatou')
            ->assertSee('Choisissez comment être prévenu');

        $this->actingAs($this->owner)->patch(route('leads.update', $lead), ['action' => 'done'])->assertSessionHas('status');
        $this->assertSame(Lead::DONE, $lead->fresh()->status);
        $this->assertNotNull($lead->fresh()->closed_at);

        $this->actingAs($this->owner)->get(route('leads.index'))->assertOk()->assertDontSee('Samedi 10 h, coupe et brushing');
        $this->actingAs($this->owner)->get(route('leads.index', ['etat' => 'done']))->assertOk()->assertSee('Samedi 10 h, coupe et brushing');

        $this->actingAs($this->owner)->patch(route('leads.update', $lead), ['action' => 'reopen']);
        $this->assertSame(Lead::NEW, $lead->fresh()->status);
    }

    public function test_an_invalid_action_is_refused(): void
    {
        Mail::fake();
        $lead = $this->capture();

        $this->actingAs($this->owner)->patch(route('leads.update', $lead), ['action' => 'supprimer-tout'])->assertSessionHasErrors('action');
    }

    public function test_whatsapp_leads_suggest_the_manual_label_to_apply(): void
    {
        Mail::fake();
        $this->capture(Lead::ORDER, '1 robe', $this->conversation('whatsapp', $this->bot, '22670123456'));

        $this->actingAs($this->owner)->get(route('leads.index'))->assertOk()
            ->assertSee('Étiquette conseillée dans WhatsApp Business')->assertSee('Commande à confirmer');
    }

    public function test_the_dashboard_and_the_menu_show_the_number_of_new_leads_and_invite_to_choose_alerts(): void
    {
        Mail::fake();
        $this->capture();
        $this->capture(Lead::HUMAN, 'Veut parler à Awa', $this->conversation('web', $this->bot, 'visiteur-2'));

        $this->actingAs($this->owner)->get(route('dashboard'))->assertOk()
            ->assertSee('2 demandes à traiter')->assertSee('Comment voulez-vous être prévenu ?')->assertSee(route('leads.index'), false);

        $this->saveAlerts();
        $this->actingAs($this->owner)->get(route('dashboard'))->assertOk()->assertDontSee('Comment voulez-vous être prévenu ?');
    }

    public function test_the_alerts_page_explains_the_whatsapp_limits_and_saves_the_choices(): void
    {
        $this->actingAs($this->owner)->get(route('alerts.edit'))->assertOk()
            ->assertSee('Et les étiquettes WhatsApp ?')->assertSee('24 heures');

        $this->saveAlerts(['reminder_minutes' => 60, 'kinds' => [Lead::ORDER, Lead::HUMAN], 'email_to' => 'moi@boutique.test']);

        $alerts = $this->workspace->fresh()->alertSettings();
        $this->assertSame(60, $alerts['reminder_minutes']);
        $this->assertSame([Lead::ORDER, Lead::HUMAN], $alerts['kinds']);
        $this->assertSame('moi@boutique.test', $alerts['email_to']);
        $this->assertTrue($alerts['chosen']);
    }

    public function test_the_inbox_shows_the_open_lead_as_a_label_on_the_conversation(): void
    {
        Mail::fake();
        $this->capture();

        $this->actingAs($this->owner)->get(route('conversations.index', $this->bot))->assertOk()->assertSee('Commande à confirmer');
        $this->actingAs($this->owner)->get(route('conversations.show', [$this->bot, $this->conversation]))->assertOk()->assertSee('2 boubous brodés, 70 000 FCFA');
    }

    /* ---------- Isolation entre entreprises ---------- */

    public function test_a_client_never_sees_or_changes_another_clients_leads(): void
    {
        Mail::fake();
        $lead = $this->capture();
        [, $stranger, $otherBot] = $this->tenant('Autre entreprise', 'pro');
        $this->capture(Lead::ORDER, 'Commande secrète de l\'autre', $this->conversation('web', $otherBot, 'autre-visiteur'));

        $this->actingAs($stranger)->get(route('leads.index'))->assertOk()
            ->assertDontSee('2 boubous brodés, 70 000 FCFA')->assertSee('Commande secrète de l\'autre');
        $this->app['auth']->forgetGuards();

        $this->actingAs($stranger)->patch(route('leads.update', $lead), ['action' => 'dismiss'])->assertNotFound();
        $this->assertSame(Lead::NEW, $lead->fresh()->status);
    }

    public function test_alert_settings_are_per_workspace(): void
    {
        [$other, $stranger] = $this->tenant('Autre entreprise', 'pro');
        $this->saveAlerts(['email' => '0', 'kinds' => [Lead::HUMAN]]);

        $this->assertFalse($this->workspace->fresh()->alertSettings()['email']);
        $this->assertTrue($other->fresh()->alertSettings()['email']);
        $this->assertSame(array_keys(Lead::KINDS), $other->fresh()->alertSettings()['kinds']);
    }

    private function whatsappChannel(): Channel
    {
        return Channel::withoutGlobalScopes()->create([
            'workspace_id' => $this->workspace->id, 'bot_id' => $this->bot->id, 'type' => Channel::WHATSAPP_META, 'status' => Channel::ACTIVE,
            'display_phone' => '+226 70 00 00 00', 'external_ref' => '109876543210', 'credentials' => ['access_token' => 'EAAtoken', 'waba_id' => '555'],
        ]);
    }
}
