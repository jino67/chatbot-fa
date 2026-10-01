<?php

namespace App\Push;

use App\Models\PushSubscription;

/**
 * Envoi d'un message à un appareil abonné. Une interface (comme LlmClient ou WhatsAppGateway) : l'application ne parle
 * jamais directement à Google, Apple ou Mozilla, et les tests remplacent l'envoi par un faux sans réseau.
 */
interface PushGateway
{
    /**
     * @param  array<string,mixed>  $payload  title, body, url, tag, badge (compteur sur l'icône), icon
     * @param  string  $urgency  very-low, low, normal ou high (high réveille un téléphone en veille : commandes et demandes de clients)
     */
    public function send(PushSubscription $subscription, array $payload, string $urgency = 'normal'): PushResult;
}
