<?php

namespace Tests\Feature;

use App\Mail\Notice;
use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\Bot;
use App\Models\CustomerContact;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Users\BulkAudience;
use App\Services\Users\UserFilters;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/** Écrire à un groupe d'inscrits par lots, relances du matin, et plafond d'e-mails par jour. */
class PeopleBulkTest extends TestCase
{
    use CreatesTenants;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow(Carbon::create(2026, 10, 5, 12, 0, 0));
        config(['people.batch' => 3, 'people.daily_email_cap' => 5, 'people.cooldown_days' => 7]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Une personne sans assistant (segment « Sans assistant »). */
    private function person(string $name, array $user = [], ?string $email = null): User
    {
        $free = Plan::default();
        $space = Workspace::create(['name' => 'Entreprise '.$name, 'plan' => $free->slug, 'subscription_status' => 'trialing', 'plan_ends_at' => now()->addDays(14)]);
        $person = User::factory()->create(['name' => $name, 'email' => $email ?? strtolower($name).'@exemple.bf', 'workspace_id' => $space->id]);
        $person->forceFill($user + ['created_at' => now()->subDays(2)])->save();

        return $person;
    }

    private function send(array $extra = [], string $channel = 'email', string $segment = 'sans_assistant')
    {
        return $this->actingAs($this->admin())->post(route('admin.people.bulk.send'), $extra + [
            'segment' => $segment, 'canal' => $channel, 'modele' => 'demarrage', 'subject' => 'Bonjour {entreprise}', 'body' => "Message pour {prenom}.\n\nÀ bientôt, {expediteur}.", 'reviewed' => 1,
        ]);
    }

    public function test_only_the_super_admin_can_write_to_a_group(): void
    {
        $person = $this->person('Awa');

        $this->get(route('admin.people.bulk'))->assertRedirect(route('login'));
        $this->actingAs($person)->get(route('admin.people.bulk'))->assertForbidden();
        $this->actingAs($this->staff(User::ADMIN))->post(route('admin.people.bulk.send'), ['body' => 'x'])->assertForbidden();
        $this->actingAs($this->admin())->get(route('admin.people.bulk', ['segment' => 'sans_assistant']))->assertOk()->assertSee('Écrire à un groupe')->assertSee('Sans assistant');
    }

    public function test_the_audience_leaves_out_who_must_not_be_written_to(): void
    {
        $ok = $this->person('Awa');
        $this->person('Stop', ['crm_status' => 'stop']);
        $this->person('Inactif', ['is_active' => false]);
        $this->person('Recent', ['crm_last_contacted_at' => now()->subDays(2)]);
        $old = $this->person('Ancien', ['crm_last_contacted_at' => now()->subDays(10)]);
        $this->person('Relais', [], 'xyz@privaterelay.appleid.com');

        $email = (new BulkAudience(new UserFilters(segment: 'sans_assistant'), 'email'))->resolve();
        $this->assertEqualsCanonicalizing([$ok->id, $old->id], $email['eligible']->pluck('id')->all());
        $this->assertSame(['stop' => 1, 'inactif' => 1, 'recent' => 1, 'relais' => 1], $email['excluded']);
        $this->assertSame(6, $email['selected']);

        // La notification n'a pas besoin d'une adresse : le relais Apple reste joignable par la cloche.
        $push = (new BulkAudience(new UserFilters(segment: 'sans_assistant'), 'push'))->resolve();
        $this->assertSame(3, $push['eligible']->count());
        $this->assertArrayNotHasKey('relais', $push['excluded']);
    }

    public function test_the_page_shows_counts_exclusions_and_a_personalised_preview(): void
    {
        $this->person('Awa Ouédraogo');
        $this->person('Stop', ['crm_status' => 'stop']);

        $this->actingAs($this->admin())->get(route('admin.people.bulk', ['segment' => 'sans_assistant']))->assertOk()
            ->assertSee('Sans assistant : 2 personne(s)')->assertSee('1 ont demandé à ne plus être contactés')
            ->assertSee('Aperçu pour Awa Ouédraogo')->assertSee('Entreprise Awa Ouédraogo')->assertSee('Envoyer à 1 personne(s)')
            ->assertSee('Plafond du jour : 0 e-mail(s) envoyé(s) sur 5');
    }

    public function test_the_default_template_follows_the_segment(): void
    {
        $this->assertSame('essai_fin', BulkAudience::templateFor('essai_fin'));
        $this->assertSame('dormant', BulkAudience::templateFor('dormants'));
        $this->assertSame('libre', BulkAudience::templateFor('tous'));
        $this->assertSame('libre', BulkAudience::templateFor('stop'));
    }

    public function test_an_email_goes_out_in_batches_personalised_and_each_one_is_recorded(): void
    {
        Mail::fake();
        $people = collect(['Aa', 'Bb', 'Cc', 'Dd', 'Ee'])->map(fn ($n) => $this->person($n));

        $this->send()->assertRedirect()->assertSessionHas('status');

        // Un lot de 3 seulement, personnalisé pour chacun.
        Mail::assertSent(Notice::class, 3);
        Mail::assertSent(Notice::class, fn (Notice $m) => $m->hasTo('aa@exemple.bf') && $m->subjectLine === 'Bonjour Entreprise Aa'
            && $m->paragraphs[0] === 'Message pour Aa.' && str_contains($m->paragraphs[1], 'À bientôt, ') && $m->greeting() === 'Bonjour Aa,');
        $this->assertSame(3, CustomerContact::where('channel', 'email')->count());
        $this->assertSame(['contacte'], User::whereIn('id', $people->take(3)->pluck('id'))->pluck('crm_status')->unique()->all());
        $this->assertNull($people[3]->fresh()->crm_status);
        $this->assertSame(1, AuditLog::where('action', 'user.bulk_contacted')->count());
        $this->assertSame(3, AuditLog::where('action', 'user.bulk_contacted')->first()->meta['envoyes']);

        // Un deuxième clic prend les suivants : ceux déjà joints sortent de la sélection.
        $this->send()->assertSessionHas('status');
        Mail::assertSent(Notice::class, 5);
        Mail::assertSent(Notice::class, fn (Notice $m) => $m->hasTo('ee@exemple.bf'));
        $this->send()->assertSessionHas('error');
        Mail::assertSent(Notice::class, 5);
    }

    public function test_the_daily_email_cap_counts_every_email_of_the_team(): void
    {
        Mail::fake();
        config(['people.daily_email_cap' => 4, 'people.batch' => 25]);
        $first = $this->person('Aa');
        foreach (['Bb', 'Cc', 'Dd', 'Ee', 'Ff'] as $name) {
            $this->person($name);
        }
        // Un e-mail individuel envoyé aujourd'hui compte déjà dans le plafond.
        CustomerContact::create(['user_id' => $first->id, 'channel' => 'email', 'subject' => 'x', 'body' => 'x']);
        $first->forceFill(['crm_last_contacted_at' => now()])->save();

        $this->send()->assertSessionHas('status');
        Mail::assertSent(Notice::class, 3);

        $this->send()->assertSessionHas('error', fn ($m) => str_contains($m, 'plafond de 4 e-mails'));
        Mail::assertSent(Notice::class, 3);

        // Le lendemain, le compteur repart de zéro.
        Carbon::setTestNow(now()->addDay());
        $this->send()->assertSessionHas('status');
        Mail::assertSent(Notice::class, 5);
    }

    public function test_a_message_needs_a_body_a_subject_and_the_review_box(): void
    {
        Mail::fake();
        $this->person('Awa');

        $this->send(['reviewed' => null])->assertSessionHasErrors('reviewed');
        $this->send(['body' => ''])->assertSessionHasErrors('body');
        $this->send(['subject' => ''])->assertSessionHasErrors('subject');
        Mail::assertNothingSent();
        $this->assertSame(0, CustomerContact::count());
    }

    public function test_notifications_reach_the_bell_without_a_subject_rule(): void
    {
        $a = $this->person('Aa');
        $this->person('Relais', [], 'zz@privaterelay.appleid.com');

        $this->send(['subject' => 'Votre essai', 'body' => 'Bonjour {prenom}, pensez à votre assistant.'], 'push')->assertSessionHas('status');

        $this->assertSame(2, AppNotification::where('category', 'system')->count());
        $this->assertSame('Bonjour Aa, pensez à votre assistant.', AppNotification::where('user_id', $a->id)->value('body'));
        $this->assertSame(2, CustomerContact::where('channel', 'push')->count());
    }

    public function test_a_failing_mail_server_stops_the_batch_and_nothing_is_recorded(): void
    {
        $this->person('Aa');
        $this->person('Bb');
        $this->person('Cc');
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP injoignable'));

        $this->send()->assertSessionHas('error');
        $this->assertSame(0, CustomerContact::count());
        $this->assertNull(User::where('email', 'aa@exemple.bf')->first()->crm_last_contacted_at);
    }

    public function test_the_list_page_offers_the_group_button_only_for_a_selection(): void
    {
        $this->person('Awa');
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.people.index'))->assertDontSee('Écrire à cette sélection');
        $this->actingAs($admin)->get(route('admin.people.index', ['segment' => 'sans_assistant']))->assertSee('Écrire à cette sélection')->assertSee(route('admin.people.bulk', ['segment' => 'sans_assistant']), false);
    }

    /* ------------------------------------------------------------------------------------------------
       Relances du matin
       ------------------------------------------------------------------------------------------------ */

    public function test_the_morning_command_warns_each_owner_of_their_follow_ups(): void
    {
        $emma = $this->admin();
        $moussa = $this->staff(User::ADMIN);
        $this->person('Aa', ['crm_next_follow_up_at' => now()->subDay(), 'crm_owner_id' => $moussa->id]);
        $this->person('Bb', ['crm_next_follow_up_at' => now()->subHours(2)]);
        $this->person('Cc', ['crm_next_follow_up_at' => now()->subDay(), 'crm_owner_id' => $moussa->id]);
        $this->person('Dd', ['crm_next_follow_up_at' => now()->addDays(2)]);
        $this->person('Ee', ['crm_next_follow_up_at' => now()->subDay(), 'crm_status' => 'stop']);

        $this->artisan('people:remind')->expectsOutputToContain('3 relance(s) à faire')->assertSuccessful();

        $mine = AppNotification::where('user_id', $moussa->id)->firstOrFail();
        $this->assertSame('2 relances à faire', $mine->title);
        $this->assertSame('Aa, Cc', $mine->body);
        $this->assertSame('/admin/utilisateurs?segment=a_relancer', $mine->url);
        $this->assertSame('1 relance à faire', AppNotification::where('user_id', $emma->id)->value('title'), 'sans responsable : les super administrateurs');

        // Une seule notification par personne et par jour.
        $this->artisan('people:remind')->assertSuccessful();
        $this->assertSame(1, AppNotification::where('user_id', $moussa->id)->count());
    }

    public function test_the_morning_command_says_when_there_is_nothing_to_do(): void
    {
        $this->person('Aa', ['crm_next_follow_up_at' => now()->addDay()]);

        $this->artisan('people:remind')->expectsOutputToContain('Aucune relance à faire')->assertSuccessful();
        $this->assertSame(0, AppNotification::count());
    }

    public function test_the_menu_shows_how_many_follow_ups_are_due(): void
    {
        Cache::forget('people.followups.due');
        $this->person('Aa', ['crm_next_follow_up_at' => now()->subDay()]);
        $this->person('Bb', ['crm_next_follow_up_at' => now()->subDay()]);

        $this->actingAs($this->admin())->get(route('admin.overview'))->assertSee('title="Relances à faire"', false);

        $this->assertSame(2, Cache::get('people.followups.due'));
    }
}
