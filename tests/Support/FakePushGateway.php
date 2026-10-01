<?php

namespace Tests\Support;

use App\Models\PushSubscription;
use App\Push\PushGateway;
use App\Push\PushResult;

/** Un faux service de notification : il note ce qui est envoyé et répond ce qu'on lui demande, sans aucun réseau. */
class FakePushGateway implements PushGateway
{
    /** @var list<array{endpoint:string, payload:array<string,mixed>, urgency:string}> */
    public array $sent = [];

    /** @var array<string,string> endpoint => statut forcé (PushResult::GONE...) */
    public array $results = [];

    public string $default = PushResult::OK;

    public function send(PushSubscription $subscription, array $payload, string $urgency = 'normal'): PushResult
    {
        $this->sent[] = ['endpoint' => $subscription->endpoint, 'payload' => $payload, 'urgency' => $urgency];

        return new PushResult($this->results[$subscription->endpoint] ?? $this->default, 200);
    }

    public function count(): int
    {
        return count($this->sent);
    }

    /** @return list<array<string,mixed>> */
    public function payloads(): array
    {
        return array_column($this->sent, 'payload');
    }
}
