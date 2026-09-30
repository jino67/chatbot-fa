<?php

namespace App\Jobs;

use App\Channels\WhatsApp\InboundHandler;
use App\Channels\WhatsApp\InboundMessage;
use App\Models\Channel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Le webhook repond 200 immediatement (Meta et Twilio re-livrent au-dela de quelques secondes) ;
 * la generation de la reponse et son envoi se font ici, en file d'attente.
 */
class ProcessInboundWhatsApp implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 120;

    /** @param array<string,mixed> $inbound */
    public function __construct(public int $channelId, public array $inbound) {}

    public function handle(InboundHandler $handler): void
    {
        $channel = Channel::withoutGlobalScopes()->find($this->channelId);

        if ($channel) {
            $handler->handle($channel, InboundMessage::fromArray($this->inbound));
        }
    }
}
