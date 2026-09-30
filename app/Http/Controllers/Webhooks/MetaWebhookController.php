<?php

namespace App\Http\Controllers\Webhooks;

use App\Channels\WhatsApp\MetaCloudGateway;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessInboundWhatsApp;
use App\Models\Channel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Un seul webhook Meta pour tous les clients : le phone_number_id du message designe le canal.
 */
class MetaWebhookController extends Controller
{
    /** Verification d'abonnement (GET) : Meta renvoie un defi que l'on doit echo si le jeton correspond. */
    public function verify(Request $request): Response
    {
        // PHP transforme les points des noms de parametres en underscores (hub.mode devient hub_mode).
        $mode = $request->query('hub_mode', $request->query('hub.mode'));
        $token = (string) $request->query('hub_verify_token', $request->query('hub.verify_token'));
        $expected = (string) config('platform.whatsapp.meta.verify_token');

        if ($mode === 'subscribe' && $expected !== '' && hash_equals($expected, $token)) {
            return response((string) $request->query('hub_challenge', $request->query('hub.challenge')), 200)
                ->header('Content-Type', 'text/plain');
        }

        abort(403);
    }

    public function receive(Request $request): JsonResponse
    {
        if (! MetaCloudGateway::verifySignature($request)) {
            abort(401, 'Signature invalide');
        }

        foreach (MetaCloudGateway::parse($request->json()->all()) as $inbound) {
            $channel = Channel::withoutGlobalScopes()
                ->where('type', Channel::WHATSAPP_META)
                ->where('external_ref', $inbound->channelRef)
                ->where('status', Channel::ACTIVE)
                ->first();

            if (! $channel) {
                Log::warning('Webhook Meta : aucun canal actif pour ce numero', ['phone_number_id' => $inbound->channelRef]);

                continue;
            }

            ProcessInboundWhatsApp::dispatch($channel->id, $inbound->toArray());
        }

        // Toujours 200 rapidement, sinon Meta re-livre en boucle.
        return response()->json(['ok' => true]);
    }
}
