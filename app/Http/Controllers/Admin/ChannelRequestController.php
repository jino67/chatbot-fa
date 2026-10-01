<?php

namespace App\Http\Controllers\Admin;

use App\Channels\WhatsApp\GatewayFactory;
use App\Channels\WhatsApp\TemplateProvisioner;
use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\ChannelRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Le WhatsApp d'un client est active par l'equipe technique : ici, on choisit Meta (par defaut)
 * ou Twilio, on saisit les identifiants, on teste la connexion, puis on active.
 * Les identifiants sont chiffres au repos et ne sont jamais reaffiches.
 */
class ChannelRequestController extends Controller
{
    public function index(Request $request)
    {
        $status = $request->query('status');

        return view('admin.requests.index', [
            'requests' => ChannelRequest::withoutGlobalScopes()
                ->with(['bot' => fn ($q) => $q->withoutGlobalScopes(), 'workspace'])
                ->when($status, fn ($q) => $q->where('status', $status))
                ->latest()->paginate(25)->withQueryString(),
            'status' => $status,
        ]);
    }

    public function show(int $channelRequest)
    {
        $channelRequest = ChannelRequest::withoutGlobalScopes()
            ->with(['bot' => fn ($q) => $q->withoutGlobalScopes(), 'workspace', 'requester'])
            ->findOrFail($channelRequest);

        $channel = Channel::withoutGlobalScopes()
            ->where('bot_id', $channelRequest->bot_id)
            ->whereIn('type', [Channel::WHATSAPP_META, Channel::WHATSAPP_TWILIO])
            ->latest()->first();

        return view('admin.requests.show', [
            'req' => $channelRequest,
            'channel' => $channel,
            'metaWebhook' => url('/webhooks/whatsapp/meta'),
            'verifyToken' => config('platform.whatsapp.meta.verify_token'),
        ]);
    }

    public function update(Request $request, int $channelRequest): RedirectResponse
    {
        $channelRequest = ChannelRequest::withoutGlobalScopes()->findOrFail($channelRequest);

        $data = $request->validate([
            'provider' => ['required', Rule::in([Channel::WHATSAPP_META, Channel::WHATSAPP_TWILIO])],
            'status' => ['required', Rule::in([Channel::PENDING, Channel::ACTIVE, Channel::DISABLED])],
            'display_phone' => ['nullable', 'string', 'max:30'],
            'admin_notes' => ['nullable', 'string', 'max:2000'],
            'request_status' => ['required', Rule::in([ChannelRequest::REQUESTED, ChannelRequest::IN_PROGRESS, ChannelRequest::ACTIVE, ChannelRequest::REJECTED])],
            // Meta
            'phone_number_id' => ['nullable', 'string', 'max:40', 'regex:/^[0-9]+$/'],
            'waba_id' => ['nullable', 'string', 'max:40'],
            'access_token' => ['nullable', 'string', 'max:1000'],
            // Twilio
            'account_sid' => ['nullable', 'string', 'max:64'],
            'auth_token' => ['nullable', 'string', 'max:200'],
            'from' => ['nullable', 'string', 'max:30'],
            'messaging_service_sid' => ['nullable', 'string', 'max:64'],
        ]);

        $isMeta = $data['provider'] === Channel::WHATSAPP_META;

        // Les messages sont payes par la plateforme : sans identifiants propres au canal, on utilise ses comptes Meta ou Twilio.
        $platform = app(\App\Services\PlatformSettings::class);
        $platformMeta = $platform->has('whatsapp.meta.system_token');
        $platformTwilio = $platform->has('whatsapp.twilio.account_sid') && $platform->has('whatsapp.twilio.auth_token');

        if ($isMeta && empty($data['phone_number_id'])) {
            return back()->withInput()->with('error', "Meta : l'identifiant du numéro (phone_number_id) est obligatoire.");
        }
        if (! $isMeta && ((empty($data['account_sid']) && ! $platformTwilio) || (empty($data['from']) && empty($data['messaging_service_sid'])))) {
            return back()->withInput()->with('error', 'Twilio : un numéro expéditeur (ou un Messaging Service) est obligatoire, et le SID du compte aussi tant que le compte Twilio de la plateforme n\'est pas renseigné dans les Paramètres.');
        }

        $channel = Channel::withoutGlobalScopes()->firstOrNew(['bot_id' => $channelRequest->bot_id, 'type' => $data['provider']]);
        $existing = $channel->credentials ?? [];

        // Un secret laisse vide est conserve tel quel : on ne reaffiche jamais un jeton, on ne le force pas a etre ressaisi.
        $credentials = $isMeta
            ? ['access_token' => ($data['access_token'] ?? null) ?: ($existing['access_token'] ?? null), 'waba_id' => $data['waba_id'] ?? null]
            : [
                'account_sid' => $data['account_sid'] ?? null,
                'auth_token' => ($data['auth_token'] ?? null) ?: ($existing['auth_token'] ?? null),
                'from' => $data['from'] ?? null,
                'messaging_service_sid' => $data['messaging_service_sid'] ?? null,
            ];

        if ($data['status'] === Channel::ACTIVE) {
            $secret = $isMeta ? ($credentials['access_token'] ?: $platformMeta) : ($credentials['auth_token'] ?: $platformTwilio);
            if (! $secret) {
                return back()->withInput()->with('error', 'Impossible d\'activer un canal sans jeton d\'accès.');
            }
        }

        $channel->fill([
            'workspace_id' => $channelRequest->workspace_id,
            'status' => $data['status'],
            'display_phone' => $data['display_phone'] ?? $channelRequest->phone_number,
            'external_ref' => $isMeta ? $data['phone_number_id'] : ltrim((string) ($data['from'] ?: ($data['messaging_service_sid'] ?? '') ?: ($data['account_sid'] ?? '')), '+'),
            'credentials' => $credentials,
        ]);

        if ($data['status'] === Channel::ACTIVE && ! $channel->activated_at) {
            $channel->activated_by = $request->user()->id;
            $channel->activated_at = now();
        }
        $channel->save();

        // A l'activation d'un canal, les modeles de base partent a l'approbation sans rien demander au client.
        // Apres l'envoi de la reponse : les appels au fournisseur peuvent prendre quelques secondes.
        if ($channel->status === Channel::ACTIVE && ($channel->wasRecentlyCreated || $channel->wasChanged('status'))) {
            app()->terminating(function () use ($channel) {
                try {
                    app(TemplateProvisioner::class)->autoProvision($channel);
                } catch (\Throwable $e) {
                    report($e);
                }
            });

            // Le client apprend que son assistant répond sur WhatsApp (centre de notifications, téléphone, e-mail).
            if ($bot = $channel->bot()->withoutGlobalScopes()->first()) {
                app(\App\Notify\Events::class)->whatsappActivated(\App\Models\Workspace::withoutGlobalScopes()->find($channel->workspace_id), $bot);
            }
        }

        $channelRequest->forceFill([
            'status' => $data['request_status'],
            'admin_notes' => $data['admin_notes'] ?? null,
            'handled_by' => $request->user()->id,
        ])->save();

        return redirect()->route('admin.requests.show', $channelRequest->id)->with('status', 'Canal enregistré.');
    }

    /** Test de connexion avant activation : verifie le jeton aupres de Meta ou de Twilio. */
    public function test(int $channel, GatewayFactory $gateways): RedirectResponse
    {
        $channel = Channel::withoutGlobalScopes()->findOrFail($channel);
        $result = $gateways->for($channel)->checkConnection();

        return back()->with($result['ok'] ? 'status' : 'error', $result['detail']);
    }
}
