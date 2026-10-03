<?php

namespace Tests\Feature;

use App\Mail\Notice;
use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\CustomerContact;
use App\Models\Plan;
use App\Models\Source;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Users\UserFilters;
use App\Services\Users\UserQuery;
use App\Services\Users\UserStage;
use App\Support\ContactTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/** Le suivi des personnes inscrites par le super administrateur : segments, fiche, contacts, relances, export. */
class PeopleTest extends TestCase
{
    use CreatesTenants;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow(Carbon::create(2026, 10, 5, 12, 0, 0));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Une personne inscrite avec son espace, sur l'offre gratuite par défaut sauf indication. */
    private function person(string $name, array $user = [], array $workspace = []): User
    {
        $free = Plan::default();
        $space = Workspace::create($workspace + ['name' => 'Entreprise '.$name, 'plan' => $free->slug, 'subscription_status' => 'trialing', 'plan_ends_at' => now()->addDays(14)]);
        $person = User::factory()->create(['name' => $name, 'email' => strtolower($name).'@exemple.bf', 'workspace_id' => $space->id]);
        $person->forceFill($user + ['created_at' => $user['created_at'] ?? now()->subDays(2)])->save();

        return $person;
    }

    private function bot(User $person, string $name = 'Assistant'): Bot
    {
        return Bot::withoutGlobalScopes()->create(['workspace_id' => $person->workspace_id, 'name' => $name.' '.$person->name]);
    }

    private function source(Bot $bot, string $status = Source::READY): void
    {
        Source::withoutGlobalScopes()->create(['workspace_id' => $bot->workspace_id, 'bot_id' => $bot->id, 'type' => Source::TYPE_TEXT, 'name' => 'Prix', 'payload' => ['content' => 'x'], 'status' => $status]);
    }

    private function conversation(Bot $bot, string $channel = 'web'): void
    {
        Conversation::withoutGlobalScopes()->create(['workspace_id' => $bot->workspace_id, 'bot_id' => $bot->id, 'channel' => $channel, 'external_id' => uniqid(), 'last_message_at' => now()]);
    }

    private function ids(string $segment, array $extra = []): array
    {
        return collect((new UserQuery(new UserFilters(...array_merge(["segment" => $segment], $extra))))->list(100)->items())->pluck('id')->sort()->values()->all();
    }

    public function test_only_the_super_admin_can_open_the_users_pages(): void
    {
        $person = $this->person('Awa');

        $this->get(route('admin.people.index'))->assertRedirect(route('login'));
        foreach ([[User::CLIENT, $person], [User::ADMIN, null]] as [$role, $who]) {
            $user = $who ?? $this->staff($role);
            $this->actingAs($user)->get(route('admin.people.index'))->assertForbidden();
            $this->actingAs($user)->get(route('admin.people.show', $person->id))->assertForbidden();
            $this->actingAs($user)->get(route('admin.people.export'))->assertForbidden();
            $this->actingAs($user)->post(route('admin.people.contact', $person->id), ['channel' => 'note', 'body' => 'x'])->assertForbidden();
            $this->actingAs($user)->put(route('admin.people.crm', $person->id), ['status' => 'perdu'])->assertForbidden();
        }
        $this->actingAs($this->admin())->get(route('admin.people.index'))->assertOk()->assertSee('Utilisateurs')->assertSee('Awa');
    }

    public function test_the_stage_follows_the_journey(): void
    {
        $facts = fn (array $o = []) => (object) ($o + ['needs_profile' => false, 'bots_count' => 0, 'sources_count' => 0, 'tests_count' => 0, 'conversations_count' => 0, 'channels_count' => 0,
            'paid' => false, 'plan_ends_at' => null, 'is_suspended' => false, 'is_active' => true, 'last_activity' => now()->toDateTimeString(), 'created_at' => now()->subDays(3)->toDateTimeString()]);

        $this->assertSame(['profil', 'profil'], [UserStage::for($facts(['needs_profile' => true, 'bots_count' => 2]))['key'], UserStage::for($facts(['needs_profile' => true]))['template']]);
        $this->assertSame('sans_assistant', UserStage::for($facts())['key']);
        $this->assertSame('sans_connaissances', UserStage::for($facts(['bots_count' => 1]))['key']);
        $this->assertSame('a_tester', UserStage::for($facts(['bots_count' => 1, 'sources_count' => 1]))['key']);
        $this->assertSame('pas_en_ligne', UserStage::for($facts(['bots_count' => 1, 'sources_count' => 1, 'tests_count' => 3]))['key']);
        $this->assertSame('en_ligne', UserStage::for($facts(['bots_count' => 1, 'sources_count' => 1, 'conversations_count' => 2]))['key']);
        $this->assertSame('payant', UserStage::for($facts(['paid' => true, 'bots_count' => 1]))['key']);
        $this->assertSame('en_ligne', UserStage::for($facts(['bots_count' => 1, 'sources_count' => 1, 'channels_count' => 1]))['key'], 'un WhatsApp actif compte comme en ligne');

        // Les signaux, et le message conseillé le plus urgent.
        $ending = UserStage::for($facts(['bots_count' => 1, 'plan_ends_at' => now()->addDays(3)->toDateTimeString()]));
        $this->assertSame(['essai_fin'], array_column($ending['signals'], 'key'));
        $this->assertSame('essai_fin', $ending['template']);
        $expired = UserStage::for($facts(['bots_count' => 1, 'plan_ends_at' => now()->subDay()->toDateTimeString()]));
        $this->assertSame('essai_expire', $expired['template']);
        $dormant = UserStage::for($facts(['bots_count' => 1, 'sources_count' => 1, 'created_at' => now()->subDays(40)->toDateTimeString(), 'last_activity' => now()->subDays(20)->toDateTimeString()]));
        $this->assertContains('dormant', array_column($dormant['signals'], 'key'));
        $this->assertSame('dormant', $dormant['template']);
        $this->assertNotContains('dormant', array_column(UserStage::for($facts(['created_at' => now()->subDays(40)->toDateTimeString(), 'last_activity' => now()->subDay()->toDateTimeString()]))['signals'], 'key'));
        $paidEnding = UserStage::for($facts(['paid' => true, 'plan_ends_at' => now()->addDays(2)->toDateTimeString()]));
        $this->assertSame([], array_column($paidEnding['signals'], 'key'), 'une offre payante n\'a pas d\'essai qui finit');
    }

    public function test_each_segment_lists_the_right_people(): void
    {
        $profil = $this->person('Profil', ['needs_profile' => true, 'has_password' => false, 'signup_source' => 'google']);
        $sans = $this->person('Sans');
        $vide = $this->person('Vide');
        $this->bot($vide);
        $test = $this->person('Test');
        $this->source($this->bot($test));
        $enTest = $this->person('Entest');
        $b = $this->bot($enTest);
        $this->source($b);
        $this->conversation($b, 'playground');
        $live = $this->person('Live');
        $b = $this->bot($live);
        $this->source($b);
        $this->conversation($b);
        $paid = $this->person('Paye', [], ['plan' => 'pro', 'subscription_status' => 'active', 'plan_ends_at' => now()->addMonth()]);
        $ending = $this->person('Fin', [], ['plan_ends_at' => now()->addDays(3)]);
        $expired = $this->person('Expire', [], ['plan_ends_at' => now()->subDays(2)]);
        $dormant = $this->person('Dormant', ['created_at' => now()->subDays(40), 'last_login_at' => now()->subDays(25)]);
        $old = $this->person('Ancien', ['created_at' => now()->subDays(40)]);
        $old->forceFill(['last_login_at' => now()->subDays(2)])->save();

        $this->assertSame([$profil->id], $this->ids('profil'));
        $this->assertContains($sans->id, $this->ids('sans_assistant'));
        $this->assertNotContains($profil->id, $this->ids('sans_assistant'), 'un profil à compléter n\'est pas « sans assistant »');
        $this->assertSame([$vide->id], $this->ids('sans_connaissances'));
        $this->assertSame([$test->id], $this->ids('a_tester'));
        $this->assertSame([$enTest->id], $this->ids('pas_en_ligne'));
        $this->assertSame([$live->id], $this->ids('en_ligne'));
        $this->assertSame([$paid->id], $this->ids('payants'));
        $this->assertSame([$ending->id], $this->ids('essai_fin'));
        $this->assertSame([$expired->id], $this->ids('essai_expire'));
        $this->assertEqualsCanonicalizing([$dormant->id], $this->ids('dormants'));
        $this->assertContains($sans->id, $this->ids('nouveaux'));
        $this->assertNotContains($dormant->id, $this->ids('nouveaux'));

        $counts = (new UserQuery(new UserFilters))->segmentCounts();
        $this->assertSame(1, $counts['profil']);
        $this->assertSame(1, $counts['payants']);
        $this->assertSame(count($this->ids('tous')), $counts['tous']);
    }

    public function test_the_list_excludes_the_team_and_finds_people_by_any_detail(): void
    {
        $awa = $this->person('Awa', ['phone' => '+226 70 11 22 33', 'signup_source' => 'google'], ['country' => 'Burkina Faso']);
        $moussa = $this->person('Moussa');
        $this->admin();
        $this->staff(User::ADMIN);

        $this->assertEqualsCanonicalizing([$awa->id, $moussa->id], $this->ids('tous'), 'l\'équipe n\'est pas dans la liste');
        $this->assertSame([$awa->id], $this->ids('tous', ['q' => 'Awa']));
        $this->assertSame([$awa->id], $this->ids('tous', ['q' => '70 11 22']));
        $this->assertSame([$moussa->id], $this->ids('tous', ['q' => 'entreprise moussa']));
        $this->assertSame([$awa->id], $this->ids('tous', ['source' => 'google']));
        $this->assertSame([$moussa->id], $this->ids('tous', ['source' => 'email']), 'les comptes d\'avant comptent comme « e-mail »');
        $this->assertSame([], $this->ids('tous', ['q' => '%']));

        $this->assertSame(['google' => 1, 'email' => 1], (new UserQuery(new UserFilters))->bySource());
    }

    public function test_the_follow_up_fields_filter_and_sort(): void
    {
        $owner = $this->admin();
        $a = $this->person('Aaa', ['crm_status' => 'interesse', 'crm_owner_id' => $owner->id, 'crm_next_follow_up_at' => now()->subDay()]);
        $b = $this->person('Bbb', ['crm_status' => 'stop', 'crm_next_follow_up_at' => now()->subDays(2)]);
        $c = $this->person('Ccc', ['crm_status' => 'contacte', 'crm_next_follow_up_at' => now()->addDays(3)]);
        $d = $this->person('Ddd');

        $this->assertSame([$a->id], $this->ids('a_relancer'), 'une relance dépassée, sauf pour « ne plus contacter »');
        $this->assertSame([$a->id], $this->ids('interesses'));
        $this->assertSame([$b->id], $this->ids('stop'));
        $this->assertSame([$a->id], $this->ids('tous', ['crm' => 'interesse']));
        $this->assertSame([$d->id], $this->ids('tous', ['crm' => 'nouveau']));
        $this->assertSame([$a->id], $this->ids('tous', ['owner' => $owner->id]));

        $order = collect((new UserQuery(new UserFilters(sort: 'relance')))->list(10)->items())->pluck('id')->all();
        $this->assertSame([$b->id, $a->id, $c->id], array_slice($order, 0, 3), 'les relances d\'abord, les plus anciennes en tête');
    }

    public function test_the_page_shows_segments_filters_and_people(): void
    {
        $this->person('Awa', ['needs_profile' => true, 'signup_source' => 'google']);
        $this->person('Moussa');

        $this->actingAs($this->admin())->get(route('admin.people.index'))
            ->assertOk()->assertSee('Profil à compléter')->assertSee('Sans assistant')->assertSee('Dormants')->assertSee('Inscription par')
            ->assertSee('awa@exemple.bf')->assertSee('moussa@exemple.bf')->assertSee('Google');

        $this->actingAs($this->admin())->get(route('admin.people.index', ['segment' => 'profil']))
            ->assertSee('awa@exemple.bf')->assertDontSee('moussa@exemple.bf');
        // Une valeur inconnue dans l'adresse est ignorée.
        $this->actingAs($this->admin())->get(route('admin.people.index', ['segment' => 'nimporte', 'tri' => 'x', 'etat' => 'y']))->assertOk()->assertSee('moussa@exemple.bf');
    }

    public function test_the_sheet_shows_everything_and_is_logged_once_an_hour(): void
    {
        $person = $this->person('Awa', ['phone' => '+226 70 11 22 33', 'signup_source' => 'google', 'has_password' => false], ['country' => 'Burkina Faso']);
        $bot = $this->bot($person);
        $this->source($bot);
        $this->conversation($bot);
        \App\Models\SocialAccount::create(['user_id' => $person->id, 'provider' => 'google', 'provider_user_id' => 'g1']);
        $admin = $this->admin();

        $page = $this->actingAs($admin)->get(route('admin.people.show', $person->id))->assertOk();
        $page->assertSee('awa@exemple.bf')->assertSee('+226 70 11 22 33')->assertSee('Burkina Faso')->assertSee('Entreprise Awa')
            ->assertSee('En ligne')->assertSee('Premier vrai client qui écrit')->assertSee('Inscription (Google)')->assertSee('aucun (connexion externe)')
            ->assertSee('Écrire : Passer à une offre')->assertSee('Assistant Awa');

        $this->actingAs($admin)->get(route('admin.people.show', $person->id))->assertOk();
        $this->assertSame(1, AuditLog::where('action', 'user.viewed')->count());
        $this->assertSame($admin->id, AuditLog::where('action', 'user.viewed')->value('user_id'));

        Carbon::setTestNow(now()->addHours(2));
        $this->actingAs($admin)->get(route('admin.people.show', $person->id));
        $this->assertSame(2, AuditLog::where('action', 'user.viewed')->count());

        // L'équipe n'a pas de fiche ici, ni une personne qui n'existe pas.
        $this->actingAs($admin)->get(route('admin.people.show', $admin->id))->assertNotFound();
        $this->actingAs($admin)->get(route('admin.people.show', 99999))->assertNotFound();
    }

    public function test_the_sheet_shows_where_the_person_came_from_when_the_measure_saw_them(): void
    {
        $person = $this->person('Awa');
        $now = now()->toDateTimeString();
        foreach ([['first', null, 'facebook', 'Boutique', 'mobile'], ['second', $person->id, 'direct', null, 'mobile']] as [$key, $user, $source, $campaign, $device]) {
            \Illuminate\Support\Facades\DB::table('analytics_sessions')->insert([
                'session_key' => $key.'-session', 'visitor_key' => 'visiteur-awa', 'user_id' => $user, 'audience' => $user ? 'client' : 'visitor',
                'started_at' => $key === 'first' ? now()->subDays(3)->toDateTimeString() : $now, 'last_seen_at' => $now, 'date' => now()->toDateString(), 'hour' => 12, 'dow' => 1,
                'source' => $source, 'utm_campaign' => $campaign, 'device' => $device, 'country' => 'BF', 'entry_path' => '/', 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $this->actingAs($this->admin())->get(route('admin.people.show', $person->id))->assertOk()
            ->assertSee("D'où elle vient", false)->assertSee('Facebook')->assertSee('Boutique')->assertSee('BF');
    }

    public function test_an_email_is_sent_in_the_brand_template_and_recorded(): void
    {
        Mail::fake();
        $person = $this->person('Awa');
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.people.contact', $person->id), [
            'channel' => 'email', 'subject' => 'Votre premier assistant', 'body' => "Premier paragraphe.\n\nSecond paragraphe.", 'template' => 'demarrage',
            'outcome' => 'replied', 'follow_up' => '2026-10-12',
        ])->assertRedirect()->assertSessionHas('status', 'E-mail envoyé à awa@exemple.bf.');

        // Les réponses vont à l'adresse publique de la marque, ou à la personne de l'équipe qui a écrit si elle n'est pas réglée.
        $replyTo = app(\App\Services\PlatformSettings::class)->brand()['email'] ?: $admin->email;
        Mail::assertSent(Notice::class, fn (Notice $mail) => $mail->hasTo($person->email) && $mail->subjectLine === 'Votre premier assistant'
            && $mail->paragraphs === ['Premier paragraphe.', 'Second paragraphe.'] && $mail->greeting() === 'Bonjour Awa,'
            && $mail->actionLabel === 'Créer mon assistant' && $mail->actionUrl === route('bots.create') && $mail->hasReplyTo($replyTo));

        $contact = CustomerContact::firstOrFail();
        $this->assertSame([$person->id, $admin->id, 'email', 'replied', 'demarrage'], [$contact->user_id, $contact->staff_id, $contact->channel, $contact->outcome, $contact->template]);

        $person->refresh();
        $this->assertSame('en_discussion', $person->crm_status);
        $this->assertNotNull($person->crm_last_contacted_at);
        $this->assertSame('2026-10-12 09:00:00', $person->crm_next_follow_up_at->toDateTimeString());
        $audit = AuditLog::where('action', 'user.contacted')->firstOrFail();
        $this->assertSame('email', $audit->meta['canal']);
        $this->assertStringNotContainsString('Premier paragraphe', json_encode($audit->meta), 'le texte du message n\'est pas copié dans le journal');
    }

    public function test_an_email_needs_a_subject_and_a_message_and_a_failure_is_not_recorded(): void
    {
        $person = $this->person('Awa');
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.people.contact', $person->id), ['channel' => 'email', 'subject' => '', 'body' => 'Bonjour'])->assertSessionHas('error');
        $this->actingAs($admin)->post(route('admin.people.contact', $person->id), ['channel' => 'email', 'subject' => 'Objet', 'body' => ''])->assertSessionHas('error');
        $this->actingAs($admin)->post(route('admin.people.contact', $person->id), ['channel' => 'pigeon', 'body' => 'x'])->assertSessionHasErrors('channel');
        $this->assertSame(0, CustomerContact::count());

        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('SMTP injoignable'));
        $this->actingAs($admin)->post(route('admin.people.contact', $person->id), ['channel' => 'email', 'subject' => 'Objet', 'body' => 'Bonjour'])->assertSessionHas('error');
        $this->assertSame(0, CustomerContact::count(), 'un envoi qui échoue n\'est pas consigné comme fait');
        $this->assertNull($person->fresh()->crm_last_contacted_at);
    }

    public function test_whatsapp_opens_the_chat_with_the_text_and_records_the_contact(): void
    {
        $person = $this->person('Awa', ['phone' => '+226 70 11 22 33']);
        $admin = $this->admin();

        $response = $this->actingAs($admin)->post(route('admin.people.contact', $person->id), ['channel' => 'whatsapp', 'body' => 'Bonjour Awa, c\'est Emma.']);
        $response->assertRedirect('https://wa.me/22670112233?text='.rawurlencode('Bonjour Awa, c\'est Emma.'));
        $this->assertSame('whatsapp', CustomerContact::firstOrFail()->channel);
        $this->assertSame('contacte', $person->fresh()->crm_status);

        // Le numéro peut venir de l'entreprise, avec « 00 » devant l'indicatif.
        $other = $this->person('Moussa', [], ['phone' => '00226 76 00 00 00']);
        $this->actingAs($admin)->post(route('admin.people.contact', $other->id), ['channel' => 'whatsapp', 'body' => 'Bonjour'])->assertRedirect('https://wa.me/22676000000?text=Bonjour');

        $nobody = $this->person('Sansnumero');
        $this->actingAs($admin)->post(route('admin.people.contact', $nobody->id), ['channel' => 'whatsapp', 'body' => 'Bonjour'])->assertSessionHas('error');
        $this->assertSame(2, CustomerContact::count());
    }

    public function test_a_notification_goes_to_the_persons_bell(): void
    {
        $person = $this->person('Awa');

        $this->actingAs($this->admin())->post(route('admin.people.contact', $person->id), ['channel' => 'push', 'subject' => 'Votre essai se termine', 'body' => 'Choisissez une offre pour continuer.'])->assertSessionHas('status');

        $notification = AppNotification::where('user_id', $person->id)->firstOrFail();
        $this->assertSame(['Votre essai se termine', 'system'], [$notification->title, $notification->category]);
        $this->assertSame('push', CustomerContact::firstOrFail()->channel);
    }

    public function test_calls_and_notes_are_only_recorded(): void
    {
        Mail::fake();
        $person = $this->person('Awa');
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.people.contact', $person->id), ['channel' => 'call', 'body' => 'Appel passé.', 'outcome' => 'interested'])->assertSessionHas('status');
        $this->assertSame('interesse', $person->fresh()->crm_status);
        $this->assertNotNull($person->fresh()->crm_last_contacted_at);

        $second = $this->person('Moussa');
        $this->actingAs($admin)->post(route('admin.people.contact', $second->id), ['channel' => 'note', 'body' => 'Veut un devis pour 3 boutiques.'])->assertSessionHas('status');
        $this->assertNull($second->fresh()->crm_last_contacted_at, 'une note n\'est pas un contact');
        $this->assertNull($second->fresh()->crm_status);
        Mail::assertNothingSent();

        $this->actingAs($admin)->get(route('admin.people.show', $second->id))->assertSee('Veut un devis pour 3 boutiques.');
    }

    public function test_the_outcome_moves_the_relationship(): void
    {
        $admin = $this->admin();
        foreach (['converted' => 'client', 'not_interested' => 'perdu', 'interested' => 'interesse'] as $outcome => $status) {
            $person = $this->person('P'.$outcome);
            $this->actingAs($admin)->post(route('admin.people.contact', $person->id), ['channel' => 'call', 'body' => 'Appel', 'outcome' => $outcome]);
            $this->assertSame($status, $person->fresh()->crm_status, $outcome);
        }

        // Un statut choisi à la main l'emporte sur le résultat.
        $person = $this->person('Choisi');
        $this->actingAs($admin)->post(route('admin.people.contact', $person->id), ['channel' => 'call', 'body' => 'Appel', 'outcome' => 'interested', 'status' => 'en_discussion']);
        $this->assertSame('en_discussion', $person->fresh()->crm_status);
    }

    public function test_a_person_who_asked_to_stop_is_not_contacted(): void
    {
        Mail::fake();
        $person = $this->person('Awa', ['phone' => '+226 70 11 22 33', 'crm_status' => 'stop']);
        $admin = $this->admin();

        foreach (['email' => ['subject' => 'Objet', 'body' => 'Bonjour'], 'whatsapp' => ['body' => 'Bonjour'], 'push' => ['subject' => 'T', 'body' => 'B'], 'call' => ['body' => 'Appel']] as $channel => $fields) {
            $this->actingAs($admin)->post(route('admin.people.contact', $person->id), ['channel' => $channel] + $fields)->assertSessionHas('error');
        }
        Mail::assertNothingSent();
        $this->assertSame(0, AppNotification::count());

        $this->actingAs($admin)->post(route('admin.people.contact', $person->id), ['channel' => 'note', 'body' => 'Elle ne veut plus être appelée.'])->assertSessionHas('status');
        $this->assertSame(['note'], CustomerContact::pluck('channel')->all());
        $this->actingAs($admin)->get(route('admin.people.show', $person->id))->assertSee('a demandé à ne plus être contactée');
    }

    public function test_a_contact_clears_a_due_follow_up_unless_a_new_one_is_set(): void
    {
        $person = $this->person('Awa', ['phone' => '+226 70 11 22 33', 'crm_status' => 'contacte', 'crm_next_follow_up_at' => now()->subDay()]);
        $admin = $this->admin();
        $this->assertSame([$person->id], $this->ids('a_relancer'));

        $this->actingAs($admin)->post(route('admin.people.contact', $person->id), ['channel' => 'call', 'body' => 'Appel passé.']);
        $this->assertNull($person->fresh()->crm_next_follow_up_at);
        $this->assertSame([], $this->ids('a_relancer'));

        $this->actingAs($admin)->post(route('admin.people.contact', $person->id), ['channel' => 'call', 'body' => 'Appel passé.', 'follow_up' => '2026-10-20']);
        $this->assertSame('2026-10-20', $person->fresh()->crm_next_follow_up_at->toDateString());
    }

    public function test_the_follow_up_form_updates_status_date_and_owner(): void
    {
        $person = $this->person('Awa');
        $admin = $this->admin();
        $member = $this->staff(User::ADMIN);

        $this->actingAs($admin)->put(route('admin.people.crm', $person->id), ['status' => 'interesse', 'follow_up' => '2026-10-09', 'owner' => $member->id])->assertSessionHas('status');
        $person->refresh();
        $this->assertSame(['interesse', '2026-10-09', $member->id], [$person->crm_status, $person->crm_next_follow_up_at->toDateString(), $person->crm_owner_id]);

        // Seule l'équipe peut être responsable ; un statut inconnu est refusé ; « nouveau » efface le statut.
        $other = $this->person('Moussa');
        $this->actingAs($admin)->put(route('admin.people.crm', $person->id), ['owner' => $other->id])->assertSessionHasErrors('owner');
        $this->actingAs($admin)->put(route('admin.people.crm', $person->id), ['status' => 'gagnant'])->assertSessionHasErrors('status');
        $this->actingAs($admin)->put(route('admin.people.crm', $person->id), ['status' => 'nouveau']);
        $this->assertNull($person->fresh()->crm_status);
        $this->assertNull($person->fresh()->crm_owner_id);
        $this->assertTrue(AuditLog::where('action', 'user.crm_updated')->exists());
    }

    public function test_the_export_is_complete_safe_logged_and_follows_the_filters(): void
    {
        $awa = $this->person('Awa', ['phone' => '+226 70 11 22 33', 'signup_source' => 'google']);
        $this->person('=CMD()');
        $this->person('Moussa');
        $admin = $this->admin();

        $csv = $this->actingAs($admin)->get(route('admin.people.export'))->assertOk()->streamedContent();
        $this->assertStringContainsString('awa@exemple.bf', $csv);
        $this->assertStringContainsString('+226 70 11 22 33', $csv);
        $this->assertStringContainsString('Google', $csv);
        $this->assertStringContainsString("'=CMD()", $csv, 'les formules sont neutralisées');
        $this->assertStringNotContainsString('admin', strtolower(explode("\n", $csv)[1] ?? ''), 'l\'équipe n\'est pas exportée');

        $only = $this->actingAs($admin)->get(route('admin.people.export', ['source' => 'google']))->streamedContent();
        $this->assertStringContainsString('awa@exemple.bf', $only);
        $this->assertStringNotContainsString('moussa@exemple.bf', $only);
        $this->assertSame(2, AuditLog::where('action', 'user.exported')->count());
    }

    public function test_templates_fill_in_the_person_and_never_write_a_price(): void
    {
        $person = $this->person('Awa Ouédraogo');
        $staff = $this->staff(User::SUPER_ADMIN);
        $staff->forceFill(['name' => 'Emma Kaboré'])->save();

        foreach (array_keys(ContactTemplates::labels()) as $key) {
            $t = ContactTemplates::render($key, $person, $staff, ['assistant' => 'Assistante Awa', 'ends_at' => now()->addDays(3)]);
            foreach (['subject', 'email', 'whatsapp'] as $part) {
                $this->assertStringNotContainsString('{', $t[$part], "{$key}: une variable n'est pas remplie dans {$part}");
                $this->assertDoesNotMatchRegularExpression('/\d\s?(FCFA|EUR|€|\$|XOF)/', $t[$part], "{$key}: pas de prix écrit en dur");
                $this->assertStringNotContainsString('—', $t[$part]);
            }
        }

        $t = ContactTemplates::render('essai_fin', $person, $staff, ['ends_at' => now()->addDays(3)]);
        $this->assertStringContainsString('8 octobre', $t['email']);
        $this->assertStringContainsString('dans 3 jours', $t['email']);
        $this->assertStringContainsString('Emma', $t['whatsapp']);
        $this->assertStringStartsWith('Bonjour Awa,', $t['whatsapp']);
        $this->assertSame(route('billing.show'), $t['action_url']);
    }

    public function test_the_team_account_pages_and_the_clients_never_see_follow_up_data(): void
    {
        $person = $this->person('Awa');
        CustomerContact::create(['user_id' => $person->id, 'channel' => 'note', 'body' => 'Note interne sur Awa']);
        $person->forceFill(['crm_status' => 'interesse'])->save();

        $this->actingAs($person)->get(route('profile.edit'))->assertOk()->assertDontSee('Note interne sur Awa')->assertDontSee('interesse');
        $this->actingAs($person)->get(route('dashboard'))->assertDontSee('Note interne sur Awa');
    }

    public function test_the_audit_page_knows_the_new_actions(): void
    {
        AuditLog::create(['user_id' => $this->admin()->id, 'action' => 'user.contacted', 'subject' => 'awa@exemple.bf', 'meta' => ['canal' => 'email'], 'created_at' => now()]);

        $this->actingAs($this->admin())->get(route('admin.audit.index'))->assertSee('Utilisateur contacté par l\'équipe');
    }
}
