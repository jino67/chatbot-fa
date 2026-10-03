<?php

namespace App\Social\Auth;

use App\Models\SocialAccount;
use App\Models\User;
use App\Services\AccountRegistrar;
use Illuminate\Support\Str;

/**
 * Que faire d'une identité confirmée par un fournisseur : connecter, relier à un compte existant, créer un compte, ou
 * demander un complément. Règles de sécurité, dans l'ordre :
 *  1. une identité déjà reliée connecte son compte (sauf compte désactivé) ;
 *  2. une adresse déjà connue ne relie JAMAIS un compte de l'équipe : l'équipe relie depuis son profil, une fois connectée ;
 *  3. pour un client, la liaison automatique par adresse exige que le fournisseur certifie l'adresse ET que le compte
 *     n'ait aucun mot de passe en jeu (sinon, un inconnu inscrit avec l'adresse d'autrui pourrait en hériter) : dans le cas
 *     contraire, la personne prouve qu'elle possède le compte en saisissant son mot de passe ;
 *  4. une adresse inconnue crée un compte (profil à compléter), sauf si le fournisseur n'en donne pas : on la demande d'abord.
 */
class SocialSignIn
{
    public const LOGGED_IN = 'logged_in';

    public const CREATED = 'created';

    public const LINKED = 'linked';

    public const CONFIRM_PASSWORD = 'confirm_password';

    public const NEEDS_EMAIL = 'needs_email';

    public const ERROR = 'error';

    public function __construct(private readonly AccountRegistrar $registrar) {}

    /**
     * @param  'login'|'link'  $intent
     * @return array{status:string, user?:User, message?:string, identity?:SocialIdentity}
     */
    public function handle(SocialIdentity $identity, string $intent, ?User $current = null): array
    {
        $label = ucfirst($identity->provider);
        $account = SocialAccount::where('provider', $identity->provider)->where('provider_user_id', $identity->id)->first();

        if ($intent === 'link') {
            if (! $current) {
                return $this->error('Connectez-vous d\'abord, puis reliez votre compte '.$label.'.');
            }
            if ($account && $account->user_id !== $current->id) {
                return $this->error('Ce compte '.$label.' est déjà relié à un autre compte. Utilisez un autre compte '.$label.'.');
            }
            $this->attach($current, $identity);

            return ['status' => self::LINKED, 'user' => $current];
        }

        if ($account) {
            return $this->signIn($account->user, $identity);
        }

        $existing = $identity->email ? User::whereRaw('lower(email) = ?', [mb_strtolower($identity->email)])->first() : null;

        if ($existing) {
            if ($existing->isStaff()) {
                return $this->error('Cette adresse est celle d\'un compte de l\'équipe. Connectez-vous avec votre mot de passe, puis reliez '.$label.' depuis votre profil.');
            }

            // Un compte sans mot de passe n'a que les fournisseurs pour s'ouvrir : si celui-ci certifie l'adresse, c'est la même personne.
            if ($identity->emailVerified && ! $existing->has_password) {
                $this->attach($existing, $identity);

                return $this->signIn($existing, $identity);
            }

            return ['status' => self::CONFIRM_PASSWORD, 'user' => $existing, 'identity' => $identity];
        }

        if (! $identity->email) {
            return ['status' => self::NEEDS_EMAIL, 'identity' => $identity];
        }

        return $this->create($identity, $identity->email);
    }

    /** La personne a saisi son adresse (le fournisseur n'en donnait pas) : on reprend le parcours avec elle, non certifiée. @return array{status:string, user?:User, message?:string, identity?:SocialIdentity} */
    public function withEmail(SocialIdentity $identity, string $email): array
    {
        $typed = new SocialIdentity($identity->provider, $identity->id, mb_strtolower($email), false, $identity->name, $identity->avatar, $identity->relayEmail);

        return $this->handle($typed, 'login');
    }

    /** Le mot de passe du compte existant a été prouvé : on relie et on connecte. @return array{status:string, user:User} */
    public function confirmed(User $user, SocialIdentity $identity): array
    {
        $this->attach($user, $identity);

        return $this->signIn($user, $identity);
    }

    /** @return array{status:string, user?:User, message?:string} */
    private function signIn(User $user, SocialIdentity $identity): array
    {
        if (! $user->is_active) {
            return $this->error('Ce compte a été désactivé. Contactez notre équipe.');
        }

        SocialAccount::where('provider', $identity->provider)->where('provider_user_id', $identity->id)->update(['last_login_at' => now()]);

        return ['status' => self::LOGGED_IN, 'user' => $user];
    }

    /** @return array{status:string, user:User} */
    private function create(SocialIdentity $identity, string $email): array
    {
        $name = trim((string) $identity->name) !== '' ? trim((string) $identity->name) : Str::headline(Str::before($email, '@'));

        // L'entreprise porte d'abord le nom de la personne : l'étape « terminer mon profil » demande le vrai nom.
        [$user] = $this->registrar->register([
            'name' => $name,
            'company' => $name,
            'email' => $email,
            'password' => null,
            'verified' => $identity->emailVerified,
            'needs_profile' => true,
        ], $identity->provider);

        $this->attach($user, $identity);

        return ['status' => self::CREATED, 'user' => $user];
    }

    private function attach(User $user, SocialIdentity $identity): void
    {
        SocialAccount::updateOrCreate(
            ['provider' => $identity->provider, 'provider_user_id' => $identity->id],
            ['user_id' => $user->id, 'email' => $identity->email, 'name' => $identity->name, 'avatar_url' => $identity->avatar, 'last_login_at' => now()],
        );

        // Un fournisseur qui certifie l'adresse du compte la rend vérifiée.
        if ($identity->emailVerified && ! $user->email_verified_at && $identity->email && strcasecmp($identity->email, $user->email) === 0) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }
    }

    /** @return array{status:string, message:string} */
    private function error(string $message): array
    {
        return ['status' => self::ERROR, 'message' => $message];
    }
}
