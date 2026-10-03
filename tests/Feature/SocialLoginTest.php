<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\SocialAccount;
use App\Models\User;
use App\Push\WebPushCrypto;
use App\Services\PlatformSettings;
use App\Social\Auth\SocialLogin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/** Connexion avec Google, Apple, Microsoft ou Facebook : départ, retour, liaison, création de compte, sécurité. */
class SocialLoginTest extends TestCase
{
    use CreatesTenants;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function configure(array $only = ['google', 'apple', 'microsoft', 'facebook']): void
    {
        $s = app(PlatformSettings::class);

        if (in_array('google', $only, true)) {
            $s->set('social.google.client_id', 'google-client');
            $s->set('social.google.client_secret', 'google-secret', true);
        }
        if (in_array('apple', $only, true)) {
            $s->set('social.apple.client_id', 'site.kouma.web');
            $s->set('social.apple.team_id', 'TEAM123456');
            $s->set('social.apple.key_id', 'KEY1234567');
            $s->set('social.apple.private_key', WebPushCrypto::generateKeyPair()['pem'], true);
        }
        if (in_array('microsoft', $only, true)) {
            $s->set('social.microsoft.client_id', 'ms-client');
            $s->set('social.microsoft.client_secret', 'ms-secret', true);
        }
        if (in_array('facebook', $only, true)) {
            $s->set('facebook.app_id', 'fb-app');
            $s->set('facebook.app_secret', 'fb-secret', true);
            $s->set('social.facebook.login', true);
        }
    }

    /** Une page vue sans être connecté (le test précédent a pu connecter quelqu'un). */
    private function asGuest(string $url)
    {
        auth()->logout();

        return $this->get($url);
    }

    /** Http::fake garde le premier motif qui correspond : on repart d'un client neuf à chaque réponse simulée. */
    private function fakeHttp(array $stubs): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake($stubs);
    }

    /** Un jeton d'identité non signé : le serveur le reçoit directement du fournisseur, sa signature n'est pas relue (voir Jwt). */
    private function jwt(array $claims): string
    {
        $b64 = fn (array $data) => rtrim(strtr(base64_encode(json_encode($data)), '+/', '-_'), '=');

        return $b64(['alg' => 'RS256', 'typ' => 'JWT']).'.'.$b64($claims).'.signature';
    }

    /** @return array{0:array<string,string>, 1:string, 2:string} paramètres de l'adresse du fournisseur, cookie, adresse complète */
    private function begin(string $provider): array
    {
        $response = $this->get(route('social.redirect', $provider))->assertRedirect();
        $url = $response->headers->get('Location');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return [$query, $response->getCookie(SocialLogin::COOKIE, false)->getValue(), $url];
    }

    /** Le retour du fournisseur, avec le cookie du navigateur qui a commencé. */
    private function back(string $provider, array $query, string $cookie, array $params = [], bool $post = false)
    {
        $params += ['code' => 'code-ok', 'state' => $query['state']];
        $client = $this->withUnencryptedCookie(SocialLogin::COOKIE, $cookie);

        return $post ? $client->post(route('social.callback', $provider), $params) : $client->get(route('social.callback', $provider).'?'.http_build_query($params));
    }

    private function google(array $query, array $claims = []): void
    {
        $this->fakeHttp(['oauth2.googleapis.com/*' => Http::response(['id_token' => $this->jwt($claims + [
            'iss' => 'https://accounts.google.com', 'aud' => 'google-client', 'sub' => 'g-100', 'email' => 'awa@exemple.bf', 'email_verified' => true,
            'name' => 'Awa Ouédraogo', 'picture' => 'https://exemple.test/awa.jpg', 'exp' => time() + 3600, 'nonce' => $query['nonce'],
        ])])]);
    }

    /* ------------------------------------------------------------------------------------------------
       Boutons
       ------------------------------------------------------------------------------------------------ */

    public function test_no_button_shows_until_a_provider_is_set_up(): void
    {
        $this->get(route('login'))->assertOk()->assertDontSee('Continuer avec');
        $this->get(route('register'))->assertOk()->assertDontSee('Continuer avec');
        $this->get(route('social.redirect', 'google'))->assertRedirect(route('login'));
    }

    public function test_only_the_providers_that_are_set_up_show_up_on_login_and_registration(): void
    {
        $this->configure(['google', 'apple']);

        foreach ([route('login'), route('register')] as $page) {
            $this->get($page)->assertOk()->assertSee('Continuer avec Google')->assertSee('Continuer avec Apple')
                ->assertDontSee('Continuer avec Microsoft')->assertDontSee('Continuer avec Facebook');
        }

        // Facebook réclame en plus le feu vert explicite de l'administration.
        $this->configure(['facebook']);
        app(PlatformSettings::class)->set('social.facebook.login', false);
        $this->get(route('login'))->assertDontSee('Continuer avec Facebook');
        app(PlatformSettings::class)->set('social.facebook.login', true);
        $this->get(route('login'))->assertSee('Continuer avec Facebook');
    }

    /* ------------------------------------------------------------------------------------------------
       Départ et retour
       ------------------------------------------------------------------------------------------------ */

    public function test_the_departure_sends_the_right_parameters_and_binds_the_browser(): void
    {
        $this->configure();

        [$query, $cookie, $url] = $this->begin('google');

        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $url);
        $this->assertSame('google-client', $query['client_id']);
        $this->assertSame(route('social.callback', 'google'), $query['redirect_uri']);
        $this->assertSame('openid email profile', $query['scope']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame(40, strlen($query['state']));
        $this->assertNotEmpty($query['nonce']);
        $this->assertNotEmpty($cookie);

        [$apple] = $this->begin('apple');
        $this->assertSame('form_post', $apple['response_mode']);
        $this->assertSame('name email', $apple['scope']);
        $this->assertArrayNotHasKey('code_challenge', $apple);

        [$facebook, , $facebookUrl] = $this->begin('facebook');
        $this->assertStringContainsString('facebook.com', $facebookUrl);
        $this->assertSame('email,public_profile', $facebook['scope']);
    }

    public function test_a_new_google_user_gets_an_account_a_trial_and_the_profile_step(): void
    {
        $this->configure();
        [$query, $cookie] = $this->begin('google');
        $this->google($query);

        $this->back('google', $query, $cookie)->assertRedirect(route('account.complete'));

        $user = User::where('email', 'awa@exemple.bf')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('google', $user->signup_source);
        $this->assertFalse($user->has_password);
        $this->assertTrue($user->needs_profile);
        $this->assertNotNull($user->email_verified_at, 'Google certifie l\'adresse');
        $this->assertSame('Awa Ouédraogo', $user->name);
        $this->assertSame('trialing', $user->workspace->subscription_status);

        $account = SocialAccount::firstOrFail();
        $this->assertSame([$user->id, 'google', 'g-100', 'awa@exemple.bf'], [$account->user_id, $account->provider, $account->provider_user_id, $account->email]);

        // Le code et le secret partent bien vers Google, le secret n'est jamais dans l'adresse du navigateur.
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'oauth2.googleapis.com/token') && $r['code'] === 'code-ok' && $r['client_secret'] === 'google-secret' && ! empty($r['code_verifier']));
    }

    public function test_the_workspace_stays_closed_until_the_profile_is_completed(): void
    {
        $this->configure();
        [$query, $cookie] = $this->begin('google');
        $this->google($query);
        $this->back('google', $query, $cookie);

        $this->get(route('dashboard'))->assertRedirect(route('account.complete'));
        $this->get(route('bots.index'))->assertRedirect(route('account.complete'));
        $this->get(route('account.complete'))->assertOk()->assertSee('Nom de votre entreprise')->assertSee('numéro WhatsApp');
        $this->get(route('profile.edit'))->assertOk();

        $this->post(route('account.complete.store'), ['name' => 'Awa', 'company' => '', 'phone' => 'abc'])->assertSessionHasErrors(['company', 'phone', 'terms']);

        $this->post(route('account.complete.store'), [
            'name' => 'Awa Ouédraogo', 'company' => 'Boutique Awa', 'phone' => '+226 70 00 00 00', 'country' => 'Burkina Faso', 'terms' => '1',
        ])->assertRedirect(route('dashboard'));

        $user = User::where('email', 'awa@exemple.bf')->firstOrFail();
        $this->assertFalse($user->needs_profile);
        $this->assertSame('+226 70 00 00 00', $user->phone);
        $this->assertSame(['Boutique Awa', 'Burkina Faso', '+226 70 00 00 00'], [$user->workspace->name, $user->workspace->country, $user->workspace->phone]);
        $this->get(route('dashboard'))->assertOk();
        $this->post(route('account.complete.store'), [])->assertNotFound();
    }

    public function test_a_returning_user_is_logged_in_without_a_second_account(): void
    {
        $this->configure();
        [$query, $cookie] = $this->begin('google');
        $this->google($query);
        $this->back('google', $query, $cookie);
        $user = User::where('email', 'awa@exemple.bf')->firstOrFail();
        $user->forceFill(['needs_profile' => false])->save();
        auth()->logout();

        [$query, $cookie] = $this->begin('google');
        $this->google($query, ['email' => 'nouvelle-adresse@exemple.bf']);
        $this->back('google', $query, $cookie)->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertSame(1, User::count());
        $this->assertSame(1, SocialAccount::count());
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    /* ------------------------------------------------------------------------------------------------
       Refus
       ------------------------------------------------------------------------------------------------ */

    public function test_a_return_without_the_browsers_cookie_or_with_a_reused_state_is_refused(): void
    {
        $this->configure();
        [$query, $cookie] = $this->begin('google');
        $this->google($query);

        // Un autre navigateur (pas de cookie) ne peut pas terminer la connexion.
        $this->get(route('social.callback', 'google').'?'.http_build_query(['code' => 'x', 'state' => $query['state']]))
            ->assertRedirect(route('login'))->assertSessionHasErrors('social');
        $this->assertGuest();

        // L'état ne sert qu'une fois : la première tentative sans cookie l'a déjà consommé.
        $this->back('google', $query, $cookie)->assertRedirect(route('login'))->assertSessionHasErrors('social');
        $this->assertSame(0, User::count());

        // Un état inventé n'ouvre rien.
        $this->withUnencryptedCookie(SocialLogin::COOKIE, $cookie)->get(route('social.callback', 'google').'?code=x&state=inventé')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_a_state_started_for_one_provider_cannot_finish_another(): void
    {
        $this->configure();
        [$query, $cookie] = $this->begin('google');

        $this->back('microsoft', $query, $cookie)->assertRedirect(route('login'))->assertSessionHasErrors('social');
        $this->assertGuest();
    }

    public function test_a_user_who_declines_gets_a_clear_message(): void
    {
        $this->configure();
        [$query, $cookie] = $this->begin('google');

        $this->back('google', $query, $cookie, ['error' => 'access_denied', 'code' => ''])
            ->assertRedirect(route('login'))->assertSessionHasErrors(['social' => 'Connexion annulée : vous n\'avez rien autorisé.']);
    }

    public function test_the_identity_token_claims_are_checked(): void
    {
        $this->configure();

        foreach ([
            'mauvais destinataire' => ['aud' => 'un-autre-client'],
            'mauvais émetteur' => ['iss' => 'https://evil.example'],
            'expiré' => ['exp' => time() - 3600],
            'mauvais nonce' => ['nonce' => 'rejoué'],
        ] as $why => $bad) {
            [$query, $cookie] = $this->begin('google');
            $this->google($query, $bad);

            $this->back('google', $query, $cookie)->assertRedirect(route('login'))->assertSessionHasErrors('social');
            $this->assertGuest();
        }

        $this->assertSame(0, User::count());
    }

    public function test_a_refused_code_creates_nothing(): void
    {
        $this->configure();
        [$query, $cookie] = $this->begin('google');
        $this->fakeHttp(['oauth2.googleapis.com/*' => Http::response(['error' => 'invalid_grant'], 400)]);

        $this->back('google', $query, $cookie)->assertRedirect(route('login'))->assertSessionHasErrors('social');
        $this->assertSame(0, User::count());
    }

    /* ------------------------------------------------------------------------------------------------
       Comptes existants
       ------------------------------------------------------------------------------------------------ */

    public function test_an_existing_password_account_must_prove_it_before_google_is_linked(): void
    {
        $this->configure();
        [, $owner] = $this->tenant('Boutique Awa');
        $owner->forceFill(['email' => 'awa@exemple.bf', 'password' => 'mot-de-passe-secret'])->save();

        [$query, $cookie] = $this->begin('google');
        $this->google($query);
        $this->back('google', $query, $cookie)->assertRedirect(route('social.confirm'));

        // Même avec une adresse certifiée par Google : sans le mot de passe, rien n'est relié ni connecté.
        $this->assertGuest();
        $this->assertSame(0, SocialAccount::count());
        $this->get(route('social.confirm'))->assertOk()->assertSee('awa@exemple.bf')->assertSee('Relier et me connecter');

        $this->post(route('social.confirm.store'), ['password' => 'faux'])->assertSessionHasErrors('password');
        $this->assertGuest();

        $this->post(route('social.confirm.store'), ['password' => 'mot-de-passe-secret'])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($owner);
        $this->assertSame('google', SocialAccount::firstOrFail()->provider);
        $this->assertTrue(AuditLog::where('action', 'user.social_linked')->exists());
        $this->assertSame(1, User::count());
    }

    public function test_the_password_confirmation_is_rate_limited(): void
    {
        $this->configure();
        [, $owner] = $this->tenant('Boutique Awa');
        $owner->forceFill(['email' => 'awa@exemple.bf', 'password' => 'mot-de-passe-secret'])->save();

        [$query, $cookie] = $this->begin('google');
        $this->google($query);
        $this->back('google', $query, $cookie);

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('social.confirm.store'), ['password' => 'faux-'.$i]);
        }
        $this->post(route('social.confirm.store'), ['password' => 'mot-de-passe-secret'])->assertSessionHasErrors('password');
        $this->assertGuest();
    }

    public function test_an_account_without_password_is_linked_to_a_provider_that_certifies_the_address(): void
    {
        $this->configure();
        [$query, $cookie] = $this->begin('google');
        $this->google($query);
        $this->back('google', $query, $cookie);
        $user = User::where('email', 'awa@exemple.bf')->firstOrFail();
        auth()->logout();

        // Plus tard, la même personne choisit Apple : l'adresse est certifiée et le compte n'a aucun mot de passe en jeu.
        [$query, $cookie] = $this->begin('apple');
        $this->fakeHttp(['appleid.apple.com/*' => Http::response(['id_token' => $this->jwt([
            'iss' => 'https://appleid.apple.com', 'aud' => 'site.kouma.web', 'sub' => 'apple-001', 'email' => 'awa@exemple.bf', 'email_verified' => 'true',
            'exp' => time() + 3600, 'nonce' => $query['nonce'],
        ])])]);
        $this->back('apple', $query, $cookie, post: true)->assertRedirect();

        $this->assertAuthenticatedAs($user);
        $this->assertSame(['apple', 'google'], $user->socialAccounts()->orderBy('provider')->pluck('provider')->all());
        $this->assertSame(1, User::count());
    }

    public function test_a_team_account_is_never_linked_by_its_address(): void
    {
        $this->configure();
        $admin = $this->admin();
        $admin->forceFill(['email' => 'awa@exemple.bf'])->save();

        [$query, $cookie] = $this->begin('google');
        $this->google($query);

        $this->back('google', $query, $cookie)->assertRedirect(route('login'))->assertSessionHasErrors('social');
        $this->assertGuest();
        $this->assertSame(0, SocialAccount::count());
    }

    public function test_a_disabled_account_cannot_come_back_through_a_provider(): void
    {
        $this->configure();
        [$query, $cookie] = $this->begin('google');
        $this->google($query);
        $this->back('google', $query, $cookie);
        User::where('email', 'awa@exemple.bf')->update(['is_active' => false]);
        auth()->logout();

        [$query, $cookie] = $this->begin('google');
        $this->google($query);
        $this->back('google', $query, $cookie)->assertRedirect(route('login'))->assertSessionHasErrors('social');
        $this->assertGuest();
    }

    /* ------------------------------------------------------------------------------------------------
       Apple, Microsoft, Facebook
       ------------------------------------------------------------------------------------------------ */

    public function test_apple_returns_by_post_without_a_csrf_token_and_signs_its_client_secret(): void
    {
        $this->configure();
        [$query, $cookie] = $this->begin('apple');
        $this->fakeHttp(['appleid.apple.com/*' => Http::response(['id_token' => $this->jwt([
            'iss' => 'https://appleid.apple.com', 'aud' => 'site.kouma.web', 'sub' => 'apple-777', 'email' => 'xk2d9@privaterelay.appleid.com',
            'email_verified' => 'true', 'is_private_email' => 'true', 'exp' => time() + 3600, 'nonce' => $query['nonce'],
        ])])]);

        $this->back('apple', $query, $cookie, ['user' => json_encode(['name' => ['firstName' => 'Moussa', 'lastName' => 'Traoré']])], post: true)
            ->assertRedirect(route('account.complete'));

        $user = User::where('email', 'xk2d9@privaterelay.appleid.com')->firstOrFail();
        $this->assertSame('Moussa Traoré', $user->name, 'le nom vient du formulaire de retour, il n\'est donné qu\'une fois');
        $this->assertSame('apple', $user->signup_source);

        // Un relais Apple : l'étape de profil propose l'adresse habituelle.
        $this->get(route('account.complete'))->assertSee('Apple a masqué votre adresse');

        Http::assertSent(function (HttpRequest $r) {
            if (! str_contains($r->url(), 'appleid.apple.com/auth/token')) {
                return false;
            }
            [$head, $body, $signature] = explode('.', $r['client_secret']);
            $header = json_decode(WebPushCrypto::b64urlDecode($head), true);
            $claims = json_decode(WebPushCrypto::b64urlDecode($body), true);

            return $header['alg'] === 'ES256' && $header['kid'] === 'KEY1234567'
                && $claims['iss'] === 'TEAM123456' && $claims['sub'] === 'site.kouma.web' && $claims['aud'] === 'https://appleid.apple.com'
                && $claims['exp'] - $claims['iat'] <= 300 && strlen(WebPushCrypto::b64urlDecode($signature)) === 64;
        });
    }

    public function test_microsoft_never_links_by_address_and_checks_the_tenant_in_the_issuer(): void
    {
        $this->configure();
        [, $owner] = $this->tenant('Boutique Awa');
        $owner->forceFill(['email' => 'awa@exemple.bf'])->save();

        [$query, $cookie] = $this->begin('microsoft');
        $claims = ['aud' => 'ms-client', 'sub' => 'ms-1', 'tid' => 'tenant-1', 'iss' => 'https://login.microsoftonline.com/tenant-1/v2.0', 'preferred_username' => 'awa@exemple.bf', 'name' => 'Awa', 'exp' => time() + 3600, 'nonce' => $query['nonce']];

        $this->fakeHttp(['login.microsoftonline.com/*' => Http::response(['id_token' => $this->jwt($claims)])]);
        $this->back('microsoft', $query, $cookie)->assertRedirect(route('social.confirm'));
        $this->assertGuest();

        [$query, $cookie] = $this->begin('microsoft');
        $this->fakeHttp(['login.microsoftonline.com/*' => Http::response(['id_token' => $this->jwt(['iss' => 'https://login.microsoftonline.com/autre/v2.0', 'nonce' => $query['nonce']] + $claims)])]);
        $this->back('microsoft', $query, $cookie)->assertRedirect(route('login'))->assertSessionHasErrors('social');
    }

    public function test_facebook_without_an_email_asks_for_it_first(): void
    {
        $this->configure();
        [$query, $cookie] = $this->begin('facebook');
        $this->fakeHttp([
            'graph.facebook.com/*/oauth/access_token*' => Http::response(['access_token' => 'fb-token']),
            'graph.facebook.com/*/me*' => Http::response(['id' => 'fb-42', 'name' => 'Ibrahim Sawadogo', 'picture' => ['data' => ['url' => 'https://exemple.test/i.jpg']]]),
        ]);

        $this->back('facebook', $query, $cookie)->assertRedirect(route('social.email'));
        $this->assertGuest();
        $this->assertSame(0, User::count());
        $this->get(route('social.email'))->assertOk()->assertSee('ne nous a pas transmis votre adresse');

        $this->post(route('social.email.store'), ['email' => 'pas-une-adresse'])->assertSessionHasErrors('email');
        $this->post(route('social.email.store'), ['email' => 'ibrahim@exemple.bf'])->assertRedirect(route('account.complete'));

        $user = User::where('email', 'ibrahim@exemple.bf')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertNull($user->email_verified_at, 'une adresse saisie à la main n\'est pas certifiée');
        $this->assertSame('facebook', $user->signup_source);

        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/me') && ! empty($r['appsecret_proof']) && $r['appsecret_proof'] === hash_hmac('sha256', 'fb-token', 'fb-secret'));
    }

    public function test_facebook_never_links_an_existing_account_by_its_address(): void
    {
        $this->configure();
        [, $owner] = $this->tenant('Boutique Awa');
        $owner->forceFill(['email' => 'awa@exemple.bf'])->save();

        [$query, $cookie] = $this->begin('facebook');
        $this->fakeHttp([
            'graph.facebook.com/*/oauth/access_token*' => Http::response(['access_token' => 'fb-token']),
            'graph.facebook.com/*/me*' => Http::response(['id' => 'fb-43', 'name' => 'Pirate', 'email' => 'awa@exemple.bf']),
        ]);

        $this->back('facebook', $query, $cookie)->assertRedirect(route('social.confirm'));
        $this->assertGuest();
        $this->assertSame(0, SocialAccount::count());
    }

    /* ------------------------------------------------------------------------------------------------
       Profil
       ------------------------------------------------------------------------------------------------ */

    public function test_a_signed_in_user_can_link_and_unlink_a_provider_from_the_profile(): void
    {
        $this->configure();
        [, $user] = $this->tenant('Boutique Awa');
        $this->actingAs($user);

        $this->get(route('profile.edit'))->assertOk()->assertSee('Connexions')->assertSee('Relier');

        [$query, $cookie] = (function () {
            $response = $this->get(route('social.link', 'google'))->assertRedirect();
            parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);

            return [$query, $response->getCookie(SocialLogin::COOKIE, false)->getValue()];
        })();
        $this->google($query, ['email' => 'autre@gmail.test', 'sub' => 'g-555']);

        $this->back('google', $query, $cookie)->assertRedirect(route('profile.edit').'#connexions');
        $this->assertSame('g-555', $user->socialAccounts()->firstOrFail()->provider_user_id);
        $this->assertSame($user->email, $user->fresh()->email, 'l\'adresse du compte ne change pas');

        $this->get(route('profile.edit'))->assertSee('autre@gmail.test');

        $this->delete(route('social.unlink', 'google'))->assertRedirect(route('profile.edit').'#connexions');
        $this->assertSame(0, $user->socialAccounts()->count());
        $this->assertTrue(AuditLog::where('action', 'user.social_unlinked')->exists());
        $this->delete(route('social.unlink', 'google'))->assertNotFound();
    }

    public function test_a_provider_account_already_used_elsewhere_cannot_be_linked_twice(): void
    {
        $this->configure();
        [, $first] = $this->tenant('Boutique Awa');
        [, $second] = $this->tenant('Atelier B');
        SocialAccount::create(['user_id' => $first->id, 'provider' => 'google', 'provider_user_id' => 'g-999']);

        $this->actingAs($second);
        $response = $this->get(route('social.link', 'google'))->assertRedirect();
        parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->google($query, ['sub' => 'g-999']);

        $this->back('google', $query, $response->getCookie(SocialLogin::COOKIE, false)->getValue())->assertRedirect(route('profile.edit').'#connexions')->assertSessionHas('error');
        $this->assertSame(0, $second->socialAccounts()->count());
    }

    public function test_the_last_way_to_sign_in_cannot_be_removed(): void
    {
        $this->configure();
        [$query, $cookie] = $this->begin('google');
        $this->google($query);
        $this->back('google', $query, $cookie);
        $user = User::where('email', 'awa@exemple.bf')->firstOrFail();
        $user->forceFill(['needs_profile' => false])->save();

        $this->delete(route('social.unlink', 'google'))->assertSessionHas('error');
        $this->assertSame(1, $user->socialAccounts()->count());

        // Avec un mot de passe, la liaison peut partir.
        $this->put(route('password.update'), ['password' => 'Un-mot-de-passe-solide-42', 'password_confirmation' => 'Un-mot-de-passe-solide-42'])->assertSessionHasNoErrors();
        $this->assertTrue($user->fresh()->has_password);
        $this->delete(route('social.unlink', 'google'))->assertSessionHas('status', 'social-unlinked');
    }

    public function test_a_password_less_account_can_set_a_first_password_and_confirm_by_email(): void
    {
        $this->configure();
        [$query, $cookie] = $this->begin('google');
        $this->google($query);
        $this->back('google', $query, $cookie);
        $user = User::where('email', 'awa@exemple.bf')->firstOrFail();
        $user->forceFill(['needs_profile' => false])->save();

        // Sans mot de passe actuel à confirmer, la page ne le demande pas.
        $this->get(route('profile.edit'))->assertOk()->assertDontSee('Mot de passe actuel')->assertSee('Choisir un mot de passe');

        // Suppression : on confirme avec l'adresse e-mail du compte.
        $this->delete(route('profile.destroy'), ['password' => 'mauvaise@adresse.test'])->assertSessionHasErrorsIn('userDeletion', 'password');
        $this->assertNotNull(User::find($user->id));
        $this->delete(route('profile.destroy'), ['password' => 'AWA@exemple.bf'])->assertRedirect('/');
        $this->assertNull(User::find($user->id));
        $this->assertSame(0, SocialAccount::count(), 'les liaisons partent avec le compte');
    }

    public function test_the_login_form_explains_that_an_account_uses_a_provider(): void
    {
        $this->configure();
        [$query, $cookie] = $this->begin('google');
        $this->google($query);
        $this->back('google', $query, $cookie);
        auth()->logout();

        $this->post(route('login'), ['email' => 'awa@exemple.bf', 'password' => 'nimporte-quoi'])
            ->assertSessionHasErrors(['email' => 'Ce compte se connecte avec Google. Utilisez le bouton correspondant, ou choisissez un mot de passe avec « Mot de passe oublié ».']);
        $this->assertGuest();
    }

    public function test_a_password_reset_gives_the_account_a_password(): void
    {
        $this->configure();
        [$query, $cookie] = $this->begin('google');
        $this->google($query);
        $this->back('google', $query, $cookie);
        $user = User::where('email', 'awa@exemple.bf')->firstOrFail();
        auth()->logout();

        $token = \Illuminate\Support\Facades\Password::createToken($user);
        $this->post(route('password.store'), ['token' => $token, 'email' => $user->email, 'password' => 'Nouveau-mot-de-passe-42', 'password_confirmation' => 'Nouveau-mot-de-passe-42'])->assertRedirect(route('login'));

        $this->assertTrue($user->fresh()->has_password);
        $this->post(route('login'), ['email' => $user->email, 'password' => 'Nouveau-mot-de-passe-42'])->assertRedirect();
        $this->assertAuthenticatedAs($user);
    }

    public function test_email_registration_keeps_working_and_records_its_source(): void
    {
        $this->post(route('register'), [
            'name' => 'Awa', 'company' => 'Boutique Awa', 'email' => 'awa@exemple.bf', 'password' => 'Un-mot-de-passe-solide-42', 'password_confirmation' => 'Un-mot-de-passe-solide-42',
        ])->assertRedirect(route('dashboard', absolute: false));

        $user = User::where('email', 'awa@exemple.bf')->firstOrFail();
        $this->assertSame('email', $user->signupSource());
        $this->assertTrue($user->has_password);
        $this->assertFalse($user->needs_profile);
        $this->assertSame('Boutique Awa', $user->workspace->name);
        $this->get(route('dashboard'))->assertOk();
    }

    /* ------------------------------------------------------------------------------------------------
       Administration
       ------------------------------------------------------------------------------------------------ */

    public function test_the_admin_sets_up_a_provider_and_secrets_are_encrypted_and_never_shown(): void
    {
        $admin = $this->admin();
        $page = $this->actingAs($admin)->get(route('admin.settings.edit'))->assertOk();
        $page->assertSee('Connexion avec Google, Apple, Microsoft, Facebook')->assertSee(route('social.callback', 'google'), false)->assertSee(route('social.callback', 'apple'), false);

        $this->actingAs($admin)->put(route('admin.settings.update'), [
            'brand_name' => 'Kouma', 'social_form' => 1,
            'social_google_client_id' => 'abc.apps.googleusercontent.com', 'social_google_client_secret' => 'GOCSPX-tres-secret',
            'social_apple_client_id' => 'site.kouma.web', 'social_apple_team_id' => 'T1', 'social_apple_key_id' => 'K1',
        ])->assertSessionHasNoErrors();

        $settings = app(PlatformSettings::class);
        $this->assertSame('abc.apps.googleusercontent.com', $settings->get('social.google.client_id'));
        $this->assertSame('GOCSPX-tres-secret', $settings->get('social.google.client_secret'));
        $this->assertStringNotContainsString('GOCSPX-tres-secret', (string) \App\Models\PlatformSetting::where('key', 'social.google.client_secret')->value('value'), 'chiffré en base');

        $this->asGuest(route('login'))->assertSee('Continuer avec Google')->assertDontSee('Continuer avec Apple');
        $this->actingAs($admin)->get(route('admin.settings.edit'))->assertDontSee('GOCSPX-tres-secret')->assertSee('Enregistré : laisser vide pour conserver');

        // Laisser le secret vide le conserve ; « Retirer » efface le fournisseur.
        $this->actingAs($admin)->put(route('admin.settings.update'), ['brand_name' => 'Kouma', 'social_form' => 1, 'social_google_client_id' => 'abc.apps.googleusercontent.com']);
        $this->assertSame('GOCSPX-tres-secret', app(PlatformSettings::class)->get('social.google.client_secret'));

        $this->actingAs($admin)->put(route('admin.settings.update'), ['brand_name' => 'Kouma', 'social_form' => 1, 'social_google_clear' => 1]);
        $this->assertFalse(app(SocialLogin::class)->provider('google')->isConfigured());
        $this->asGuest(route('login'))->assertDontSee('Continuer avec Google');

        // Un enregistrement qui ne porte pas la section ne touche pas aux fournisseurs.
        $this->configure(['microsoft']);
        $this->actingAs($admin)->put(route('admin.settings.update'), ['brand_name' => 'Kouma'])->assertSessionHasNoErrors();
        $this->assertTrue(app(SocialLogin::class)->provider('microsoft')->isConfigured());
    }

    public function test_the_return_state_expires(): void
    {
        $this->configure();
        [$query, $cookie] = $this->begin('google');
        $this->google($query);

        Cache::flush();

        $this->back('google', $query, $cookie)->assertRedirect(route('login'))->assertSessionHasErrors('social');
        $this->assertGuest();
    }
}
