<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\UserAgent;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/** Page de profil (informations, mot de passe oublié, application, appareils) et pages de mot de passe. */
class ProfileSpaceTest extends TestCase
{
    use CreatesTenants;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function addSession(User $user, string $id, string $agent, int $ago = 0): void
    {
        DB::table('sessions')->insert([
            'id' => $id, 'user_id' => $user->id, 'ip_address' => '102.64.1.'.strlen($id), 'user_agent' => $agent,
            'payload' => '', 'last_activity' => now()->subMinutes($ago)->timestamp,
        ]);
    }

    public function test_the_profile_page_is_in_french_and_lists_its_sections(): void
    {
        [, $owner] = $this->tenant();
        $owner->forceFill(['phone' => '+226 70 00 00 00'])->save();

        $this->actingAs($owner)->get(route('profile.edit'))->assertOk()
            ->assertSee('Mes informations')
            ->assertSee('Mot de passe')
            ->assertSee('Vous ne vous souvenez plus de votre mot de passe actuel ?')
            ->assertSee('M\'envoyer le lien par e-mail', false)
            ->assertSee('Mes alertes')
            ->assertSee('+226 70 00 00 00')
            ->assertSee('comme une application')
            ->assertSee('Sur l\'écran d\'accueil', false)
            ->assertSee('Supprimer mon compte')
            ->assertSee('Se déconnecter')
            ->assertDontSee('Forgot')
            ->assertDontSee('Update Password');
    }

    public function test_a_user_who_installed_the_app_sees_it_and_is_no_longer_invited(): void
    {
        [, $owner] = $this->tenant();

        $this->actingAs($owner)->get(route('profile.edit'))->assertOk()
            ->assertSee('Me guider sur cet appareil')
            ->assertDontSee('Application installée');

        $owner->forceFill(['pwa_installed_at' => now()])->save();

        $this->actingAs($owner->fresh())->get(route('profile.edit'))->assertOk()
            ->assertSee('Application installée')
            ->assertSee('C\'est fait', false)
            ->assertDontSee('Me guider sur cet appareil')
            ->assertDontSee('installHint(', false);
    }

    public function test_the_forgotten_password_link_is_sent_from_the_profile_to_the_account_address(): void
    {
        Notification::fake();
        [, $owner] = $this->tenant();

        $this->actingAs($owner)->post(route('profile.password-link'))->assertRedirect()->assertSessionHas('status', 'password-link-sent');

        Notification::assertSentTo($owner, ResetPassword::class);

        // Une seconde demande tout de suite est freinée, avec un message clair.
        $this->actingAs($owner)->post(route('profile.password-link'))->assertSessionHas('status', 'password-link-throttled');
    }

    public function test_a_logged_in_person_can_open_the_reset_link_and_ends_on_the_profile(): void
    {
        Notification::fake();
        [, $owner] = $this->tenant();
        $this->actingAs($owner)->post(route('profile.password-link'));

        Notification::assertSentTo($owner, ResetPassword::class, function ($notification) use ($owner) {
            $this->actingAs($owner)->get(route('password.reset', ['token' => $notification->token, 'email' => $owner->email]))
                ->assertOk()->assertSee('Choisissez un nouveau mot de passe')->assertSee('aria-pressed', false);

            $this->actingAs($owner)->post(route('password.store'), [
                'token' => $notification->token, 'email' => $owner->email, 'password' => 'nouveau-mot-de-passe-1', 'password_confirmation' => 'nouveau-mot-de-passe-1',
            ])->assertRedirect(route('profile.edit'));

            return true;
        });
    }

    public function test_the_reset_email_is_in_french_with_the_brand_and_the_link(): void
    {
        [, $owner] = $this->tenant();

        $mail = (new ResetPassword('jeton-123'))->toMail($owner);
        $html = (string) $mail->render();

        $this->assertStringContainsString('Réinitialisation de votre mot de passe', $mail->subject);
        $this->assertStringContainsString('Kouma', $mail->subject);
        $this->assertStringContainsString('Choisir un nouveau mot de passe', $html);
        $this->assertStringContainsString('jeton-123', $html);
        $this->assertStringContainsString(urlencode($owner->email), $html);
        $this->assertStringContainsString('valable 60 minutes', $html);
        $this->assertStringNotContainsString('Reset Password', $html);
        $this->assertStringNotContainsString("\u{2014}", $html);
    }

    public function test_asking_for_a_reset_link_never_reveals_whether_the_account_exists(): void
    {
        Notification::fake();

        $this->post('/forgot-password', ['email' => 'personne@exemple.test'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', fn ($status) => str_contains($status, 'Si un compte existe'));

        Notification::assertNothingSent();

        [, $owner] = $this->tenant();
        $this->post('/forgot-password', ['email' => $owner->email])
            ->assertSessionHas('status', fn ($status) => str_contains($status, 'Si un compte existe'));
        Notification::assertSentTo($owner, ResetPassword::class);
    }

    public function test_the_password_pages_are_branded_and_in_french(): void
    {
        $this->get('/forgot-password')->assertOk()->assertSee('Mot de passe oublié ?')->assertSee('Recevoir le lien')->assertSee('noindex', false);
        $this->get('/reset-password/un-jeton?email=a@b.test')->assertOk()->assertSee('Enregistrer le mot de passe')->assertSee('Très bon', false);

        [, $owner] = $this->tenant();
        $this->actingAs($owner)->get('/confirm-password')->assertOk()->assertSee('Une confirmation, par sécurité');
    }

    public function test_connected_devices_are_listed_and_the_others_can_be_signed_out(): void
    {
        config(['session.driver' => 'database']);
        [, $owner] = $this->tenant();
        $this->addSession($owner, 'autre-ordinateur', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36', 30);
        $this->addSession($owner, 'autre-telephone', 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1', 600);

        $this->actingAs($owner)->get(route('profile.edit'))->assertOk()
            ->assertSee('Appareils connectés')
            ->assertSee('Chrome sur Windows')
            ->assertSee('Safari sur iPhone')
            ->assertSee('Déconnecter les autres appareils');

        // Mauvais mot de passe : rien n'est fermé.
        $this->actingAs($owner)->post(route('profile.logout-others'), ['password' => 'faux'])->assertSessionHasErrors('password', errorBag: 'logoutOthers');
        $ours = ['autre-ordinateur', 'autre-telephone'];
        $this->assertSame(2, DB::table('sessions')->whereIn('id', $ours)->count());

        $oldToken = $owner->fresh()->remember_token;
        $this->actingAs($owner)->post(route('profile.logout-others'), ['password' => 'password'])->assertSessionHas('status', 'sessions-closed');
        $this->assertSame(0, DB::table('sessions')->whereIn('id', $ours)->count());
        $this->assertNotSame($oldToken, $owner->fresh()->remember_token, 'les cookies « rester connecté » des autres appareils ne valent plus');
    }

    public function test_another_persons_sessions_are_never_listed_or_closed(): void
    {
        config(['session.driver' => 'database']);
        [, $owner] = $this->tenant();
        $other = User::factory()->create();
        $this->addSession($other, 'session-de-l-autre', 'Mozilla/5.0 (Linux; Android 14) Chrome/126.0 Mobile Safari/537.36');

        $this->actingAs($owner)->get(route('profile.edit'))->assertOk()->assertDontSee('Chrome sur Android');
        $this->actingAs($owner)->post(route('profile.logout-others'), ['password' => 'password']);

        $this->assertSame(1, DB::table('sessions')->where('user_id', $other->id)->count());
    }

    public function test_the_user_agent_is_described_in_a_few_words(): void
    {
        $cases = [
            ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36', 'Chrome sur Windows', false],
            ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36 Edg/126.0.0.0', 'Edge sur Windows', false],
            ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1', 'Safari sur iPhone', true],
            ['Mozilla/5.0 (Linux; Android 14; SM-A546B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Mobile Safari/537.36', 'Chrome sur Android', true],
            ['Mozilla/5.0 (X11; Linux x86_64; rv:127.0) Gecko/20100101 Firefox/127.0', 'Firefox sur Linux', false],
            ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15', 'Safari sur Mac', false],
            ['', 'Navigateur sur appareil inconnu', false],
        ];

        foreach ($cases as [$agent, $label, $mobile]) {
            $device = UserAgent::describe($agent);
            $this->assertSame($label, $device['label'], $agent);
            $this->assertSame($mobile, $device['mobile'], $agent);
        }
    }
}
