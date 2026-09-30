<?php

namespace App\Channels\WhatsApp;

use App\Models\Channel;
use InvalidArgumentException;

class GatewayFactory
{
    public function for(Channel $channel): WhatsAppGateway
    {
        return match ($channel->type) {
            Channel::WHATSAPP_META => new MetaCloudGateway($channel),
            Channel::WHATSAPP_TWILIO => new TwilioGateway($channel),
            default => throw new InvalidArgumentException("Type de canal inconnu : {$channel->type}"),
        };
    }
}
