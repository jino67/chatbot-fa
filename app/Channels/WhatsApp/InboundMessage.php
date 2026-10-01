<?php

namespace App\Channels\WhatsApp;

/** Message entrant normalise : identique quel que soit le fournisseur (Meta ou Twilio). */
final class InboundMessage
{
    public function __construct(
        public readonly string $providerMessageId,
        public readonly string $from,          // numero de l'expediteur, chiffres uniquement (ex. 22670123456)
        public readonly ?string $name,
        public readonly string $type,          // text | image | audio | document | location | other
        public readonly ?string $text,
        public readonly ?string $channelRef = null, // Meta : phone_number_id du destinataire
        public readonly ?string $mediaRef = null,   // audio : identifiant du media chez Meta, ou URL du media chez Twilio
        public readonly ?string $mediaMime = null,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['providerMessageId'],
            $data['from'],
            $data['name'] ?? null,
            $data['type'],
            $data['text'] ?? null,
            $data['channelRef'] ?? null,
            $data['mediaRef'] ?? null,
            $data['mediaMime'] ?? null,
        );
    }
}
