<?php

namespace App\Push;

use App\Models\PushSubscription;
use Illuminate\Support\Facades\Http;

/** L'envoi réel : message chiffré (RFC 8291), signé VAPID (RFC 8292), posté au service de notification de l'appareil. */
class WebPushGateway implements PushGateway
{
    public function __construct(private readonly Vapid $vapid) {}

    public function send(PushSubscription $subscription, array $payload, string $urgency = 'normal'): PushResult
    {
        if (! PushEndpoint::isAllowed($subscription->endpoint)) {
            return new PushResult(PushResult::GONE, 0, 'adresse de service non autorisée');
        }

        try {
            $body = WebPushCrypto::encrypt(
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                WebPushCrypto::b64urlDecode($subscription->p256dh),
                WebPushCrypto::b64urlDecode($subscription->auth),
            );

            $response = Http::timeout((int) config('notifications.push.timeout_seconds', 8))
                ->withHeaders([
                    'Authorization' => $this->vapid->authorization($subscription->endpoint),
                    'Content-Encoding' => 'aes128gcm',
                    'TTL' => (string) config('notifications.push.ttl', 86400),
                    'Urgency' => in_array($urgency, ['very-low', 'low', 'normal', 'high'], true) ? $urgency : 'normal',
                ])
                ->withBody($body, 'application/octet-stream')
                ->post($subscription->endpoint);
        } catch (\Throwable $e) {
            // Réseau coupé, délai dépassé, clé illisible : on ne perd pas la notification pour autant (elle est dans le centre).
            return new PushResult(PushResult::RETRY, 0, mb_substr($e->getMessage(), 0, 160));
        }

        $code = $response->status();

        return match (true) {
            $code >= 200 && $code < 300 => new PushResult(PushResult::OK, $code),
            in_array($code, [404, 410], true) => new PushResult(PushResult::GONE, $code),
            $code === 429 || $code >= 500 => new PushResult(PushResult::RETRY, $code, mb_substr($response->body(), 0, 160)),
            default => new PushResult(PushResult::ERROR, $code, mb_substr($response->body(), 0, 160)),
        };
    }
}
