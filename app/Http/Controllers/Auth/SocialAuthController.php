<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Social\Auth\SocialAuthException;
use App\Social\Auth\SocialIdentity;
use App\Social\Auth\SocialLogin;
use App\Social\Auth\SocialSignIn;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Connexion avec Google, Apple, Microsoft ou Facebook : départ, retour, liaison depuis le profil, et les deux étapes de
 * confirmation (mot de passe d'un compte existant, adresse manquante). Voir SocialSignIn pour les règles de sécurité.
 */
class SocialAuthController extends Controller
{
    private const PENDING = 'social.pending';

    public function __construct(private readonly SocialLogin $login, private readonly SocialSignIn $signIn) {}

    /** La personne clique sur « Continuer avec ... » : on l'envoie chez le fournisseur. */
    public function redirect(Request $request, string $provider): RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }

        $oauth = $this->login->enabled()[$provider] ?? null;

        return $oauth
            ? $this->login->begin($request, $oauth, 'login')
            : redirect()->route('login')->withErrors(['social' => 'Cette méthode de connexion n\'est pas disponible pour le moment.']);
    }

    /** « Relier mon compte ... » depuis le profil : même trajet, mais pour la personne déjà connectée. */
    public function link(Request $request, string $provider): RedirectResponse
    {
        $oauth = $this->login->enabled()[$provider] ?? null;

        return $oauth
            ? $this->login->begin($request, $oauth, 'link', $request->user()->id)
            : redirect()->route('profile.edit')->with('error', 'Cette méthode de connexion n\'est pas disponible pour le moment.');
    }

    /** Le retour du fournisseur : une adresse (GET) ou, pour Apple, un formulaire (POST). */
    public function callback(Request $request, string $provider): RedirectResponse
    {
        $oauth = $this->login->enabled()[$provider] ?? null;
        abort_unless($oauth, 404);

        try {
            $state = $this->login->consume($request, $provider);

            if ($request->filled('error') || ! $request->filled('code')) {
                throw new SocialAuthException($request->input('error') === 'access_denied' || $request->input('error') === 'user_cancelled_authorize'
                    ? 'Connexion annulée : vous n\'avez rien autorisé.'
                    : 'La connexion avec '.$oauth->label().' n\'a pas abouti. Réessayez.');
            }

            $identity = $oauth->identity(
                (string) $request->input('code'), $this->login->redirectUri($provider), $state['verifier'], $state['nonce'], $request->only('user'),
            );

            $current = null;
            if ($state['intent'] === 'link') {
                // Seule la personne qui a lancé la liaison, toujours connectée, peut la terminer.
                $current = Auth::id() && Auth::id() === $state['user_id'] ? $request->user() : null;
            }
        } catch (SocialAuthException $e) {
            return $this->failure(isset($state) && $state['intent'] === 'link', $e->getMessage());
        }

        return $this->finish($request, $this->signIn->handle($identity, $state['intent'], $current), $state['intent']);
    }

    /* ------------------------------------------------------------------------------------------------
       Étape : le compte existe déjà avec cette adresse, la personne prouve qu'il est à elle
       ------------------------------------------------------------------------------------------------ */

    public function confirmForm(Request $request): View|RedirectResponse
    {
        $pending = $request->session()->get(self::PENDING);
        if (! is_array($pending) || ($pending['kind'] ?? null) !== 'password') {
            return redirect()->route('login');
        }

        return view('auth.social-link', [
            'identity' => SocialIdentity::fromArray($pending['identity']),
            'email' => User::find($pending['user_id'])?->email,
        ]);
    }

    public function confirmStore(Request $request): RedirectResponse
    {
        $pending = $request->session()->get(self::PENDING);
        if (! is_array($pending) || ($pending['kind'] ?? null) !== 'password' || ! ($user = User::find($pending['user_id']))) {
            return redirect()->route('login');
        }

        $request->validate(['password' => ['required', 'string']]);

        $key = 'social-confirm:'.$user->id.'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['password' => 'Trop d\'essais. Réessayez dans '.ceil(RateLimiter::availableIn($key) / 60).' minute(s).']);
        }

        if (! $user->has_password || ! Hash::check((string) $request->input('password'), $user->password)) {
            RateLimiter::hit($key);

            throw ValidationException::withMessages(['password' => 'Ce mot de passe n\'est pas le bon.']);
        }

        RateLimiter::clear($key);
        $identity = SocialIdentity::fromArray($pending['identity']);
        $request->session()->forget(self::PENDING);
        AuditLog::record('user.social_linked', $user->email, ['fournisseur' => $identity->provider, 'mode' => 'mot de passe'], $user->workspace_id);

        return $this->finish($request, $this->signIn->confirmed($user, $identity), 'login');
    }

    /* ------------------------------------------------------------------------------------------------
       Étape : le fournisseur ne donne pas d'adresse e-mail
       ------------------------------------------------------------------------------------------------ */

    public function emailForm(Request $request): View|RedirectResponse
    {
        $pending = $request->session()->get(self::PENDING);
        if (! is_array($pending) || ($pending['kind'] ?? null) !== 'email') {
            return redirect()->route('login');
        }

        return view('auth.social-email', ['identity' => SocialIdentity::fromArray($pending['identity'])]);
    }

    public function emailStore(Request $request): RedirectResponse
    {
        $pending = $request->session()->get(self::PENDING);
        if (! is_array($pending) || ($pending['kind'] ?? null) !== 'email') {
            return redirect()->route('login');
        }

        $data = $request->validate(['email' => ['required', 'string', 'lowercase', 'email', 'max:255']]);
        $request->session()->forget(self::PENDING);

        return $this->finish($request, $this->signIn->withEmail(SocialIdentity::fromArray($pending['identity']), $data['email']), 'login');
    }

    /* ------------------------------------------------------------------------------------------------
       Suite commune
       ------------------------------------------------------------------------------------------------ */

    /** @param array{status:string, user?:User, message?:string, identity?:SocialIdentity} $result */
    private function finish(Request $request, array $result, string $intent): RedirectResponse
    {
        switch ($result['status']) {
            case SocialSignIn::ERROR:
                return $this->failure($intent === 'link', $result['message'] ?? 'La connexion a échoué.');

            case SocialSignIn::LINKED:
                AuditLog::record('user.social_linked', $result['user']->email, ['mode' => 'profil'], $result['user']->workspace_id);

                return redirect()->to(route('profile.edit').'#connexions')->with('status', 'social-linked');

            case SocialSignIn::CONFIRM_PASSWORD:
                $request->session()->put(self::PENDING, ['kind' => 'password', 'user_id' => $result['user']->id, 'identity' => $result['identity']->toArray()]);

                return redirect()->route('social.confirm');

            case SocialSignIn::NEEDS_EMAIL:
                $request->session()->put(self::PENDING, ['kind' => 'email', 'identity' => $result['identity']->toArray()]);

                return redirect()->route('social.email');
        }

        /** @var User $user */
        $user = $result['user'];

        Auth::login($user, true);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();

        if ($result['status'] === SocialSignIn::CREATED) {
            // Message de bienvenue : il n'empêche jamais l'inscription d'aboutir.
            app(\App\Notify\Events::class)->welcome($user, $user->workspace);
        }

        return $user->needs_profile
            ? redirect()->route('account.complete')
            : redirect()->intended(route('dashboard', absolute: false));
    }

    private function failure(bool $linking, string $message): RedirectResponse
    {
        return $linking && Auth::check()
            ? redirect()->to(route('profile.edit').'#connexions')->with('error', $message)
            : redirect()->route('login')->withErrors(['social' => $message]);
    }
}
