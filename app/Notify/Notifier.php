<?php

namespace App\Notify;

use App\Mail\Notice;
use App\Models\AppNotification;
use App\Models\PushSubscription;
use App\Models\User;
use App\Models\Workspace;
use App\Push\PushGateway;
use App\Push\PushResult;
use App\Services\Analytics\Tracker;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Le point d'entrée unique des notifications : une commande, un paiement, une promotion... Pour chaque personne concernée,
 * on respecte ses choix (catégories, heures calmes, plafond de promotions), on écrit le message dans son centre de
 * notifications (cloche, compteur sur l'icône) et on l'envoie sur ses appareils (Web Push), avec un e-mail quand l'événement
 * l'exige. Un échec d'envoi ne perd jamais le message : il reste dans le centre de notifications.
 *
 * Options : urgent (part même la nuit), tag (remplace la notification précédente du même tag sur le téléphone), data,
 * campaign_id, email (true ou un Notice prêt), push (false : centre de notifications seulement), dedupe_minutes,
 * workspace_id, silent (pas de push), mark_read (déjà lue : pour les essais), sync (envoi immédiat même dans une requête web), ignore_cap.
 */
final class Notifier
{
    /** Ce qui s'est passé lors du dernier envoi à une personne (pour les compteurs d'une campagne). @var array{pushed:int, failed:int, deferred:bool, emailed:bool} */
    public array $last = ['pushed' => 0, 'failed' => 0, 'deferred' => false, 'emailed' => false];

    public function __construct(private readonly PushGateway $push) {}

    /* ------------------------------------------------------------------------------------------------
       Destinataires
       ------------------------------------------------------------------------------------------------ */

    /** @param array<string,mixed> $opts */
    public function toUser(User $user, string $category, string $title, string $body = '', ?string $url = null, array $opts = []): ?AppNotification
    {
        $this->last = ['pushed' => 0, 'failed' => 0, 'deferred' => false, 'emailed' => false];

        if ($this->skipReason($user, $category, $opts) !== null) {
            return null;
        }

        $def = (array) config("notifications.categories.{$category}");
        $urgent = (bool) ($opts['urgent'] ?? ($def['urgent'] ?? false));
        $prefs = $user->notificationPrefs();
        $now = CarbonImmutable::now();

        $notification = AppNotification::create([
            'user_id' => $user->id,
            'workspace_id' => $opts['workspace_id'] ?? ($user->isStaff() ? null : $user->workspace_id),
            'category' => $category,
            'title' => Str::limit(trim($title), 120, '…'),
            'body' => $body !== '' ? Str::limit(trim($body), 300, '…') : null,
            'url' => $this->normalizeUrl($url),
            'campaign_id' => $opts['campaign_id'] ?? null,
            'tag' => isset($opts['tag']) ? Str::limit((string) $opts['tag'], 60, '') : null,
            'data' => $opts['data'] ?? null,
            'read_at' => ! empty($opts['mark_read']) ? $now : null,
            'created_at' => $now,
        ]);

        if (($opts['push'] ?? true) && ! ($opts['silent'] ?? false) && $prefs['push'] && $user->pushSubscriptions()->exists()) {
            if (($def['quiet'] ?? false) && ! $urgent && $this->inQuietHours($user, $now)) {
                $notification->forceFill(['push_after' => $this->quietEnd($user, $now)])->save();
                $this->last['deferred'] = true;
            } elseif (! empty($opts['sync'])) {
                $sent = $this->deliver($notification, $urgent);
                $this->last['pushed'] = $sent['sent'];
                $this->last['failed'] = $sent['failed'];
            } else {
                $this->deliverSoon($notification, $urgent);
            }
        }

        if (! empty($opts['email'])) {
            $this->last['emailed'] = $this->email($user, $notification, $opts['email']);
        }

        return $notification;
    }

    /**
     * Tous les membres actifs d'un espace (ou le propriétaire seul avec `owner_only`). L'e-mail, quand il est demandé,
     * ne part qu'au propriétaire (`email_scope` => 'all' pour tous).
     *
     * @param  array<string,mixed>  $opts
     */
    public function toWorkspace(Workspace|int $workspace, string $category, string $title, string $body = '', ?string $url = null, array $opts = []): int
    {
        $workspace = $workspace instanceof Workspace ? $workspace : Workspace::withoutGlobalScopes()->find($workspace);
        if (! $workspace) {
            return 0;
        }

        $users = User::where('workspace_id', $workspace->id)->where('is_active', true)->where('role', User::CLIENT)->orderBy('id')->get();
        if (! empty($opts['owner_only']) && ($owner = $workspace->owner())) {
            $users = $users->where('id', $owner->id);
        }
        if (! empty($opts['user_ids'])) {
            $users = $users->whereIn('id', (array) $opts['user_ids']);
        }

        $ownerId = $workspace->owner()?->id;
        $count = 0;

        foreach ($users as $user) {
            $mine = $opts + ['workspace_id' => $workspace->id];
            if (! empty($opts['email']) && ($opts['email_scope'] ?? 'owner') === 'owner' && $user->id !== $ownerId) {
                unset($mine['email']);
            }

            $count += $this->toUser($user, $category, $title, $body, $url, $mine) ? 1 : 0;
        }

        return $count;
    }

    /** L'équipe de la plateforme (administrateurs et super administrateurs, ou seulement les seconds). */
    public function toStaff(string $category, string $title, string $body = '', ?string $url = null, array $opts = []): int
    {
        $roles = ! empty($opts['super_only']) ? [User::SUPER_ADMIN] : [User::SUPER_ADMIN, User::ADMIN];
        $count = 0;

        foreach (User::whereIn('role', $roles)->where('is_active', true)->orderBy('id')->get() as $user) {
            $count += $this->toUser($user, $category, $title, $body, $url, $opts) ? 1 : 0;
        }

        return $count;
    }

    /* ------------------------------------------------------------------------------------------------
       Règles : qui reçoit quoi, et quand
       ------------------------------------------------------------------------------------------------ */

    /**
     * Pourquoi cette personne ne recevra pas ce message (null : elle le recevra) : compte désactivé, catégorie refusée,
     * promotion déjà reçue aujourd'hui, doublon.
     *
     * @param  array<string,mixed>  $opts
     */
    public function skipReason(User $user, string $category, array $opts = []): ?string
    {
        $def = config("notifications.categories.{$category}");

        if (! $def || ! $user->is_active) {
            return 'inactive';
        }

        if (! $user->notificationPrefs()['cats'][$category]) {
            return 'opt_out';
        }

        if (($def['quiet'] ?? false) && empty($opts['ignore_cap'])
            && AppNotification::where('user_id', $user->id)->where('category', $category)
                ->where('created_at', '>=', now()->subHours((int) config('notifications.promo_cap_hours')))->exists()) {
            return 'cap';
        }

        if (isset($opts['tag'], $opts['dedupe_minutes'])
            && AppNotification::where('user_id', $user->id)->where('tag', $opts['tag'])
                ->where('created_at', '>=', now()->subMinutes((int) $opts['dedupe_minutes']))->exists()) {
            return 'duplicate';
        }

        return null;
    }

    public function inQuietHours(User $user, ?CarbonInterface $at = null): bool
    {
        $quiet = $user->notificationPrefs()['quiet'];
        if (! $quiet['on'] || $quiet['from'] === $quiet['to']) {
            return false;
        }

        $hour = ($at ? CarbonImmutable::instance($at) : CarbonImmutable::now())->setTimezone(Tracker::timezone())->hour;

        return $quiet['from'] > $quiet['to']
            ? ($hour >= $quiet['from'] || $hour < $quiet['to'])
            : ($hour >= $quiet['from'] && $hour < $quiet['to']);
    }

    /** La prochaine fin des heures calmes de cette personne. */
    public function quietEnd(User $user, ?CarbonInterface $at = null): CarbonImmutable
    {
        $to = $user->notificationPrefs()['quiet']['to'];
        $local = ($at ? CarbonImmutable::instance($at) : CarbonImmutable::now())->setTimezone(Tracker::timezone());
        $end = $local->setTime($to, 0);

        return ($end->lte($local) ? $end->addDay() : $end)->setTimezone(config('app.timezone'));
    }

    /* ------------------------------------------------------------------------------------------------
       Envoi sur les appareils
       ------------------------------------------------------------------------------------------------ */

    /** Dans une vraie requête web, l'envoi se fait après la réponse : l'attente du service de notification ne retarde personne. */
    private function deliverSoon(AppNotification $notification, bool $urgent): void
    {
        if (app()->runningInConsole()) {
            $this->deliver($notification, $urgent);

            return;
        }

        app()->terminating(function () use ($notification, $urgent) {
            try {
                $this->deliver($notification, $urgent);
            } catch (\Throwable $e) {
                report($e);
            }
        });
    }

    /**
     * Envoie une notification à tous les appareils de la personne. Un appareil disparu est oublié, un appareil qui échoue
     * trop souvent aussi ; la notification reste dans le centre quoi qu'il arrive.
     *
     * @return array{sent:int, failed:int}
     */
    public function deliver(AppNotification $notification, ?bool $urgent = null): array
    {
        $user = $notification->user ?? User::find($notification->user_id);
        $sent = 0;
        $failed = 0;

        if (! $user) {
            return ['sent' => 0, 'failed' => 0];
        }

        $def = (array) config("notifications.categories.{$notification->category}");
        $urgent ??= (bool) ($def['urgent'] ?? false);
        $payload = $this->payload($notification, $user);
        $urgency = $urgent ? 'high' : (($def['quiet'] ?? false) ? 'low' : 'normal');

        foreach ($user->pushSubscriptions()->get() as $subscription) {
            $result = $this->push->send($subscription, $payload, $urgency);

            if ($result->ok()) {
                $subscription->forceFill(['failures' => 0, 'last_success_at' => now()])->save();
                $sent++;

                continue;
            }

            $failed++;
            $this->recordFailure($subscription, $result);
        }

        $notification->forceFill(['pushed_at' => $sent > 0 ? now() : $notification->pushed_at, 'push_after' => null])->save();

        return ['sent' => $sent, 'failed' => $failed];
    }

    private function recordFailure(PushSubscription $subscription, PushResult $result): void
    {
        if ($result->gone()) {
            $subscription->delete();

            return;
        }

        $failures = $subscription->failures + 1;

        if ($failures >= (int) config('notifications.push.max_failures', 5)) {
            $subscription->delete();

            return;
        }

        $subscription->forceFill(['failures' => $failures, 'last_failure_at' => now()])->save();
    }

    /**
     * Les notifications dont l'envoi attendait la fin des heures calmes. Appelé chaque minute par le planificateur.
     *
     * @return int le nombre de notifications poussées
     */
    public function flushDeferred(int $limit = 200): int
    {
        $count = 0;

        AppNotification::whereNotNull('push_after')->where('push_after', '<=', now())->whereNull('pushed_at')->whereNull('read_at')
            ->orderBy('id')->limit($limit)->get()->each(function (AppNotification $notification) use (&$count) {
                $this->deliver($notification);
                $count++;
            });

        // Une notification déjà lue pendant l'attente n'a plus besoin d'être poussée.
        AppNotification::whereNotNull('push_after')->whereNotNull('read_at')->update(['push_after' => null]);

        return $count;
    }

    /** Le message tel qu'il part vers l'appareil : court (quelques centaines d'octets), avec le compteur de l'icône. */
    public function payload(AppNotification $notification, User $user): array
    {
        return [
            'id' => $notification->id,
            'title' => $notification->title,
            'body' => (string) $notification->body,
            'url' => $notification->url ?: '/notifications',
            'tag' => $notification->tag ?: 'n'.$notification->id,
            'category' => $notification->category,
            'badge' => $user->unreadNotificationCount(),
        ];
    }

    /* ------------------------------------------------------------------------------------------------
       Lecture
       ------------------------------------------------------------------------------------------------ */

    /** @param  list<int>|null  $ids  null : tout marquer comme lu */
    public function markRead(User $user, ?array $ids = null): int
    {
        $query = $user->appNotifications()->whereNull('read_at');
        if ($ids !== null) {
            $query->whereIn('id', $ids);
        }

        return $query->update(['read_at' => now(), 'push_after' => null]);
    }

    /** Un essai : arrive sur les appareils de la personne, sans augmenter son compteur. */
    public function test(User $user): ?AppNotification
    {
        return $this->toUser($user, 'system', 'Notifications activées', 'Tout fonctionne : vous serez prévenu ici dès qu\'un client vous écrit ou passe commande.', '/notifications', [
            'tag' => 'test', 'mark_read' => true, 'urgent' => true, 'data' => ['test' => true],
        ]);
    }

    /* ------------------------------------------------------------------------------------------------
       Détails
       ------------------------------------------------------------------------------------------------ */

    /** Un lien du site devient un chemin (« /demandes ») : une notification ne mène jamais ailleurs que sur le site, sauf lien https explicite d'une campagne. */
    public function normalizeUrl(?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }

        $app = rtrim((string) config('app.url'), '/');
        if ($app !== '' && str_starts_with($url, $app)) {
            $url = substr($url, strlen($app)) ?: '/';
        }

        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return Str::limit($url, 255, '');
        }

        return preg_match('#^https://[^\s]+$#i', $url) ? Str::limit($url, 255, '') : null;
    }

    /** @param  Notice|bool  $email */
    private function email(User $user, AppNotification $notification, $email): bool
    {
        try {
            $mail = $email instanceof Notice ? $email : new Notice(
                subjectLine: $notification->title,
                heading: $notification->title,
                paragraphs: array_filter([(string) $notification->body]),
                actionLabel: $notification->url ? 'Ouvrir' : null,
                actionUrl: $notification->url ? $this->absoluteUrl($notification->url) : null,
                greetingName: $user->name,
                reason: 'Vous recevez ce message parce que vous avez un compte '.(app(\App\Services\PlatformSettings::class)->brand()['name'] ?? 'Kouma').'.',
            );

            Mail::to($user->email)->send($mail);

            return true;
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }

    public function absoluteUrl(string $url): string
    {
        return str_starts_with($url, 'http') ? $url : url($url);
    }
}
