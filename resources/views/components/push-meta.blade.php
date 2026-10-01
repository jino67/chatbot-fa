{{--
    Configuration des notifications pour resources/js/push.js : la clé publique de la plateforme, la personne connectée, le compteur
    de notifications non lues (affiché sur l'icône de l'application installée) et les adresses des appels. Seulement pour une
    personne connectée. Si les clés ne peuvent pas être créées (OpenSSL absent), la page reste utilisable sans notifications.
--}}
@auth
    @php
        try {
            $user = auth()->user();
            $pushConfig = [
                'key' => app(\App\Push\Vapid::class)->publicKey(),
                'user' => $user->id,
                'unread' => $user->unreadNotificationCount(),
                'subscribe' => route('push.subscribe', [], false),
                'unsubscribe' => route('push.unsubscribe', [], false),
                'test' => route('push.test', [], false),
                'snoozeDays' => (int) config('notifications.reminder.snooze_days'),
                'deniedSnoozeDays' => (int) config('notifications.reminder.denied_snooze_days'),
                'reminderMax' => (int) config('notifications.reminder.max_dismissals'),
            ];
        } catch (\Throwable $e) {
            report($e);
            $pushConfig = null;
        }
    @endphp
    @if ($pushConfig)
        <meta name="kouma-push" content="{{ json_encode($pushConfig) }}">
    @endif
@endauth
