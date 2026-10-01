<?php

namespace Tests\Concerns;

use App\Models\PushSubscription;
use App\Models\User;
use App\Push\PushGateway;
use Tests\Support\FakePushGateway;

trait FakesPush
{
    protected FakePushGateway $push;

    /** Remplace l'envoi réel par un faux : aucun appel au réseau, et on peut lire ce qui est parti. */
    protected function fakePush(): FakePushGateway
    {
        $this->push = new FakePushGateway;
        $this->app->instance(PushGateway::class, $this->push);

        return $this->push;
    }

    /** Un appareil abonné, avec de vraies clés (le faux n'en a pas besoin, mais elles passent la validation). */
    protected function device(User $user, string $host = 'fcm.googleapis.com', string $id = 'abc', string $platform = 'android'): PushSubscription
    {
        $endpoint = "https://{$host}/fcm/send/{$id}-".$user->id;

        return PushSubscription::create([
            'user_id' => $user->id,
            'workspace_id' => $user->isStaff() ? null : $user->workspace_id,
            'endpoint_hash' => PushSubscription::hashOf($endpoint),
            'endpoint' => $endpoint,
            'p256dh' => rtrim(strtr(base64_encode("\x04".random_bytes(64)), '+/', '-_'), '='),
            'auth' => rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '='),
            'platform' => $platform,
        ]);
    }
}
