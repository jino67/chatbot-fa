<?php

namespace App\Http\Controllers\Webhooks;

use App\Channels\WhatsApp\TwilioGateway;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessInboundWhatsApp;
use App\Models\Channel;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class TwilioWebhookController extends Controller
{
    public function receive(Request $request, int $channel): Response
    {
        $channel = Channel::withoutGlobalScopes()
            ->where('type', Channel::WHATSAPP_TWILIO)
            ->findOrFail($channel);

        if (! TwilioGateway::verifySignature($request, $channel)) {
            abort(401, 'Signature invalide');
        }

        if ($channel->isActive() && ($inbound = TwilioGateway::parse($request))) {
            ProcessInboundWhatsApp::dispatch($channel->id, $inbound->toArray());
        }

        // La reponse part plus tard par l'API REST : on renvoie un TwiML vide.
        return response('<?xml version="1.0" encoding="UTF-8"?><Response></Response>', 200)
            ->header('Content-Type', 'text/xml');
    }
}
