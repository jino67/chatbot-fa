<?php

namespace Tests\Feature;

use App\Leads\LeadService;
use App\Models\Bot;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\User;
use App\Models\WhatsAppTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/** Bouton « Confirmer au client » de la page Demandes : le bon modèle WhatsApp est déjà choisi, le prénom rempli. */
class LeadConfirmationTest extends TestCase
{
    use CreatesTenants;
    use RefreshDatabase;

    private Bot $bot;

    private User $owner;

    private Channel $channel;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Mail::fake();
        [, $this->owner, $this->bot] = $this->tenant('Boutique Awa', 'pro');
        $this->channel = Channel::withoutGlobalScopes()->create([
            'workspace_id' => $this->bot->workspace_id, 'bot_id' => $this->bot->id, 'type' => Channel::WHATSAPP_META,
            'status' => Channel::ACTIVE, 'display_phone' => '+226 70 00 00 00', 'external_ref' => '109876543210',
            'credentials' => ['access_token' => 'EAAtoken', 'waba_id' => '555'],
        ]);
    }

    private function orderTemplate(array $overrides = []): WhatsAppTemplate
    {
        return WhatsAppTemplate::withoutGlobalScopes()->create($overrides + [
            'workspace_id' => $this->bot->workspace_id, 'channel_id' => $this->channel->id, 'external_id' => 'tpl-cc', 'name' => 'commande_confirmee',
            'language' => 'fr', 'category' => 'UTILITY', 'status' => WhatsAppTemplate::APPROVED, 'variables_count' => 4,
            'body' => "Bonjour {{1}}, votre commande n° {{2}} est confirmée ✅\n\nMontant à régler : {{3}}\nRetrait ou livraison : {{4}}",
        ]);
    }

    private function conversation(string $channel = 'whatsapp', string $name = 'Fatou Diallo', int $hoursAgo = 2): Conversation
    {
        return Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $this->bot->workspace_id, 'bot_id' => $this->bot->id, 'channel' => $channel, 'external_id' => '226701'.str_pad((string) ++$this->sequence, 5, '0', STR_PAD_LEFT),
            'contact_name' => $name, 'last_inbound_at' => now()->subHours($hoursAgo),
        ]);
    }

    private function lead(Conversation $conversation, string $kind = Lead::ORDER): Lead
    {
        return app(LeadService::class)->capture($conversation, $kind, '2 boubous brodés, 70 000 FCFA');
    }

    public function test_a_whatsapp_order_gets_a_confirm_button_once_the_template_is_approved(): void
    {
        $conversation = $this->conversation();
        $this->lead($conversation);

        $this->actingAs($this->owner)->get(route('leads.index'))->assertOk()
            ->assertSee('Répondre')->assertDontSee('Confirmer la commande');

        $this->orderTemplate();

        $this->actingAs($this->owner)->get(route('leads.index'))->assertOk()
            ->assertSee('Confirmer la commande')
            ->assertSee(route('conversations.show', [$this->bot, $conversation, 'modele' => 'commande_confirmee']), false);
    }

    public function test_no_button_for_a_pending_template_a_web_chat_a_closed_lead_or_another_kind(): void
    {
        $this->orderTemplate(['status' => WhatsAppTemplate::PENDING]);
        $whatsapp = $this->conversation();
        $order = $this->lead($whatsapp);

        $this->actingAs($this->owner)->get(route('leads.index'))->assertDontSee('Confirmer la commande');

        WhatsAppTemplate::withoutGlobalScopes()->update(['status' => WhatsAppTemplate::APPROVED]);

        // Une demande de personne n'a pas de modèle de confirmation, une discussion du site ne passe pas par WhatsApp.
        $this->lead($this->conversation('web', 'Visiteur'), Lead::ORDER);
        $this->lead($this->conversation(), Lead::HUMAN);
        $this->actingAs($this->owner)->get(route('leads.index'))->assertOk()
            ->assertSee('Confirmer la commande');
        $this->assertSame(1, substr_count($this->actingAs($this->owner)->get(route('leads.index'))->getContent(), 'Confirmer la commande</a>'));

        $order->forceFill(['status' => Lead::DONE])->save();
        $this->actingAs($this->owner)->get(route('leads.index'))->assertDontSee('Confirmer la commande');
    }

    public function test_each_kind_of_request_points_to_its_own_template(): void
    {
        $this->assertSame('commande_confirmee', Lead::CONFIRMATIONS[Lead::ORDER][0]);
        $this->assertSame('rdv_confirme', Lead::CONFIRMATIONS[Lead::APPOINTMENT][0]);
        $this->assertSame('devis_pret', Lead::CONFIRMATIONS[Lead::QUOTE][0]);

        // Chaque modèle visé existe bien dans la bibliothèque : un renommage là-bas ne casse pas le bouton en silence.
        foreach (Lead::CONFIRMATIONS as [$name]) {
            $this->assertNotNull(config('whatsapp_templates.templates.'.$name.'.fr'), $name);
        }

        $this->orderTemplate(['name' => 'rdv_confirme', 'variables_count' => 4, 'external_id' => 'tpl-rdv']);
        $this->lead($this->conversation(), Lead::APPOINTMENT);

        $this->actingAs($this->owner)->get(route('leads.index'))->assertSee('Confirmer le rendez-vous');
    }

    public function test_the_link_preselects_the_template_and_fills_the_first_name_even_inside_the_24h_window(): void
    {
        $template = $this->orderTemplate();
        $conversation = $this->conversation();

        $page = $this->actingAs($this->owner)->get(route('conversations.show', [$this->bot, $conversation, 'modele' => 'commande_confirmee']));

        $page->assertOk()
            ->assertSee('Commande confirmée', false)
            ->assertSee("id: '{$template->id}'", false)
            ->assertSee('Répondre en tant que conseiller')
            // Le prénom seul, pas le nom, et le libellé de chaque variable vient de la bibliothèque.
            ->assertSee('\u0022Montant\u0022,\u0022Retrait ou livraison\u0022],\u0022prefill\u0022:[\u0022Fatou\u0022]', false)
            ->assertDontSee('+ i +', false);

        // Sans le paramètre, dans la fenêtre de 24 h : seulement la réponse libre, pas de formulaire de modèle.
        $this->actingAs($this->owner)->get(route('conversations.show', [$this->bot, $conversation]))->assertOk()
            ->assertSee('Répondre en tant que conseiller')->assertDontSee('Envoyer le modèle');
    }

    public function test_an_unknown_or_unapproved_template_name_is_ignored(): void
    {
        $this->orderTemplate(['status' => WhatsAppTemplate::PENDING]);
        $conversation = $this->conversation();

        $this->actingAs($this->owner)->get(route('conversations.show', [$this->bot, $conversation, 'modele' => 'commande_confirmee']))
            ->assertOk()->assertDontSee('Envoyer le modèle');
        $this->actingAs($this->owner)->get(route('conversations.show', [$this->bot, $conversation, 'modele' => ['x']]))
            ->assertOk()->assertDontSee('Envoyer le modèle');
    }

    public function test_the_first_name_is_only_taken_from_a_real_name(): void
    {
        $this->assertSame('Fatou', $this->conversation()->firstName());
        $this->assertSame("N'Dri", $this->conversation('whatsapp', "N'Dri Koffi")->firstName());
        $this->assertSame('Jean-Marc', $this->conversation('whatsapp', ' Jean-Marc Ouédraogo')->firstName());
        $this->assertNull($this->conversation('whatsapp', '+22670123456')->firstName());
        $this->assertNull($this->conversation('whatsapp', '')->firstName());
    }

    public function test_variable_labels_come_from_the_library_only_when_they_still_match_the_template(): void
    {
        $this->assertSame(['Prénom du client', 'Numéro de commande', 'Montant', 'Retrait ou livraison'], $this->orderTemplate()->variableLabels());
        $this->assertSame([], $this->orderTemplate(['name' => 'ecrit_a_la_main'])->variableLabels());
        $this->assertSame([], $this->orderTemplate(['name' => 'commande_confirmee', 'variables_count' => 2, 'language' => 'en', 'external_id' => 'x2'])->variableLabels());
    }

    public function test_sending_the_template_takes_over_the_lead_and_returns_to_the_clean_conversation_page(): void
    {
        $template = $this->orderTemplate();
        $conversation = $this->conversation();
        $lead = $this->lead($conversation);
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.1']]])]);

        $this->actingAs($this->owner)
            ->from(route('conversations.show', [$this->bot, $conversation, 'modele' => 'commande_confirmee']))
            ->post(route('conversations.template', [$this->bot, $conversation]), [
                'template_id' => $template->id, 'variables' => ['Fatou', '1042', '70 000 FCFA', 'demain avant 17 h'],
            ])
            ->assertRedirect(route('conversations.show', [$this->bot, $conversation]))
            ->assertSessionHas('status');

        Http::assertSent(fn (HttpRequest $r) => ($r['template']['name'] ?? null) === 'commande_confirmee' && $r['to'] === $conversation->external_id);
        $this->assertSame(Lead::TAKEN, $lead->fresh()->status);
        $this->assertSame($this->owner->id, $lead->fresh()->assigned_to);
    }

    public function test_a_failed_send_keeps_the_lead_untouched(): void
    {
        $template = $this->orderTemplate(['status' => WhatsAppTemplate::PENDING]);
        $conversation = $this->conversation();
        $lead = $this->lead($conversation);
        Http::fake();

        $this->actingAs($this->owner)->post(route('conversations.template', [$this->bot, $conversation]), ['template_id' => $template->id, 'variables' => ['A', 'B', 'C', 'D']])
            ->assertSessionHas('error');

        Http::assertNothingSent();
        $this->assertSame(Lead::NEW, $lead->fresh()->status);
    }
}
