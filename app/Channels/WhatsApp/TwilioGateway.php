<?php

namespace App\Channels\WhatsApp;

use App\Models\Channel;
use App\Services\PlatformSettings;
use App\Speech\SpeechAudio;
use App\Speech\VoiceMedia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * WhatsApp via Twilio : demarrage rapide (sandbox, numero deja approuve) au prix d'une marge par message.
 * Credentials du canal : account_sid, auth_token, from (numero WhatsApp) ou messaging_service_sid.
 */
class TwilioGateway implements WhatsAppGateway
{
    public function __construct(private readonly Channel $channel) {}

    public function provider(): string
    {
        return 'twilio';
    }

    public function channel(): Channel
    {
        return $this->channel;
    }

    /** Twilio n'affiche pas de boutons pour un texte libre : les reponses rapides sont ignorees. */
    public function sendText(string $to, string $text, array $buttons = []): string
    {
        return $this->post(['Body' => $text], $to);
    }

    /** Twilio va chercher le fichier lui-même : il doit être joignable publiquement (adresse signée et temporaire). */
    public function sendAudio(string $to, SpeechAudio $audio): string
    {
        return $this->post(['MediaUrl' => VoiceMedia::publicUrl(VoiceMedia::store($audio))], $to);
    }

    public function downloadMedia(InboundMessage $inbound): array
    {
        if (! $inbound->mediaRef || ! str_starts_with($inbound->mediaRef, 'https://')) {
            throw new GatewayException('Message vocal sans adresse de média.');
        }

        // Les médias Twilio se téléchargent avec les identifiants du compte.
        $file = $this->request()->withHeaders(['Accept' => '*/*'])->get($inbound->mediaRef);
        if (! $file->successful()) {
            throw new GatewayException('Twilio HTTP '.$file->status().' : média introuvable.');
        }

        return ['bytes' => $file->body(), 'mime' => (string) ($file->header('Content-Type') ?: $inbound->mediaMime ?: 'audio/ogg')];
    }

    // ---- Modeles (Twilio : « Content Templates ») -------------------------------------------------

    /** La creation se fait dans la console Twilio (Content Template Builder) ; l'application les synchronise ensuite. */
    public function supportsTemplateCreation(): bool
    {
        return false;
    }

    public function listTemplates(): array
    {
        $response = $this->request()->get('https://content.twilio.com/v1/ContentAndApprovals?PageSize=100');

        if (! $response->successful()) {
            throw new GatewayException('Twilio HTTP '.$response->status().' : '.$response->json('message', 'impossible de lire les modèles'));
        }

        $templates = [];
        foreach ($response->json('contents', []) as $content) {
            $whatsapp = $content['approval_requests']['whatsapp'] ?? null;
            $types = $content['types'] ?? [];
            $body = (string) ($types['twilio/text']['body'] ?? $types['twilio/quick-reply']['body'] ?? $types['twilio/call-to-action']['body'] ?? '');

            $templates[] = [
                'external_id' => $content['sid'],
                'name' => (string) ($whatsapp['name'] ?? $content['friendly_name'] ?? $content['sid']),
                'language' => (string) ($content['language'] ?? 'fr'),
                'category' => strtoupper((string) ($whatsapp['category'] ?? 'UTILITY')),
                'status' => $whatsapp ? strtoupper((string) ($whatsapp['status'] ?? 'PENDING')) : 'DRAFT',
                'components' => [['type' => 'BODY', 'text' => $body]],
                'body' => $body,
                'variables_count' => MetaCloudGateway::countVariables($body),
                'rejected_reason' => ! empty($whatsapp['rejection_reason']) ? (string) $whatsapp['rejection_reason'] : null,
            ];
        }

        return $templates;
    }

    public function createTemplate(array $definition): array
    {
        throw new GatewayException('Avec Twilio, créez le modèle dans la console Twilio (Content Template Builder) puis cliquez sur « Actualiser les statuts ».');
    }

    public function deleteTemplate(string $name, ?string $externalId = null): void
    {
        if (! $externalId) {
            return;
        }

        $response = $this->request()->delete('https://content.twilio.com/v1/Content/'.$externalId);

        if (! $response->successful() && $response->status() !== 404) {
            throw new GatewayException('Twilio HTTP '.$response->status().' : suppression impossible.');
        }
    }

    public function sendTemplate(string $to, string $name, string $language, array $variables, ?string $externalId = null): string
    {
        if (! $externalId) {
            throw new GatewayException("Ce modèle n'a pas d'identifiant Twilio (ContentSid) : synchronisez les modèles.");
        }

        $payload = ['ContentSid' => $externalId];
        if ($variables !== []) {
            // Twilio attend un objet JSON { "1": "valeur", "2": "valeur" }.
            $payload['ContentVariables'] = json_encode(array_combine(
                array_map(fn ($i) => (string) ($i + 1), array_keys($variables)),
                array_map(fn ($v) => trim(preg_replace('/\s+/u', ' ', (string) $v)), $variables),
            ), JSON_UNESCAPED_UNICODE);
        }

        return $this->post($payload, $to);
    }

    /** @param array<string,string> $payload */
    private function post(array $payload, string $to): string
    {
        $payload['To'] = 'whatsapp:+'.ltrim($to, '+');

        if ($service = $this->channel->credential('messaging_service_sid')) {
            $payload['MessagingServiceSid'] = $service;
        } else {
            $payload['From'] = 'whatsapp:+'.ltrim((string) $this->channel->credential('from'), '+');
        }

        $response = $this->request()->asForm()->post($this->endpoint('Messages.json'), $payload);

        if (! $response->successful()) {
            throw new GatewayException('Twilio HTTP '.$response->status().' : '.$response->json('message', 'erreur inconnue'));
        }

        return (string) $response->json('sid');
    }

    public function markRead(string $messageId): void
    {
        // Twilio n'expose pas l'accuse de lecture pour les messages entrants : rien a faire.
    }

    public function checkConnection(): array
    {
        try {
            $response = $this->request()->get($this->endpoint('.json', account: true));
        } catch (\Throwable $e) {
            return ['ok' => false, 'detail' => 'Twilio injoignable : '.$e->getMessage()];
        }

        if (! $response->successful()) {
            return ['ok' => false, 'detail' => 'Twilio HTTP '.$response->status().' : identifiants refusés.'];
        }

        return ['ok' => true, 'detail' => 'Compte Twilio « '.$response->json('friendly_name', '?').' » connecté (statut : '.$response->json('status', '?').').'];
    }

    /**
     * Signature X-Twilio-Signature : HMAC-SHA1 de l'URL complete suivie des parametres POST tries par nom.
     */
    public static function verifySignature(Request $request, Channel $channel): bool
    {
        $token = (string) ($channel->credential('auth_token') ?: app(PlatformSettings::class)->get('whatsapp.twilio.auth_token'));
        $header = (string) $request->header('X-Twilio-Signature');

        if ($token === '' || $header === '') {
            return false;
        }

        $data = self::publicUrl($request);
        $params = $request->post();
        ksort($params);
        foreach ($params as $key => $value) {
            $data .= $key.(is_array($value) ? implode('', $value) : $value);
        }

        return hash_equals(base64_encode(hash_hmac('sha1', $data, $token, true)), $header);
    }

    public static function parse(Request $request): ?InboundMessage
    {
        $sid = (string) $request->post('MessageSid', $request->post('SmsMessageSid', ''));
        $from = preg_replace('/\D/', '', (string) $request->post('From', ''));

        if ($sid === '' || $from === '') {
            return null;
        }

        $media = (int) $request->post('NumMedia', 0);
        $mime = (string) $request->post('MediaContentType0', '');
        $body = trim((string) $request->post('Body', ''));

        $type = match (true) {
            $media > 0 && str_starts_with($mime, 'image') => 'image',
            $media > 0 && str_starts_with($mime, 'audio') => 'audio',
            $media > 0 => 'document',
            $request->post('Latitude') !== null => 'location',
            default => 'text',
        };

        return new InboundMessage(
            $sid, $from, $request->post('ProfileName'), $type, $body !== '' ? $body : null,
            mediaRef: $type === 'audio' ? (string) $request->post('MediaUrl0') : null,
            mediaMime: $type === 'audio' ? $mime : null,
        );
    }

    /** L'URL signee est celle que Twilio a appelee : derriere un proxy, on la reconstruit depuis la config. */
    private static function publicUrl(Request $request): string
    {
        $base = config('platform.whatsapp.twilio.public_base_url');

        return $base ? rtrim($base, '/').'/'.ltrim($request->getRequestUri(), '/') : $request->fullUrl();
    }

    private function request()
    {
        // Compte du canal (client), sinon compte Twilio de la plateforme : les messages sont alors payes par la plateforme.
        return Http::withBasicAuth($this->accountSid(), (string) ($this->channel->credential('auth_token') ?: app(PlatformSettings::class)->get('whatsapp.twilio.auth_token')))
            ->timeout(15)
            ->acceptJson();
    }

    private function accountSid(): string
    {
        return (string) ($this->channel->credential('account_sid') ?: app(PlatformSettings::class)->get('whatsapp.twilio.account_sid'));
    }

    private function endpoint(string $path, bool $account = false): string
    {
        $sid = $this->accountSid();

        return "https://api.twilio.com/2010-04-01/Accounts/{$sid}".($account ? $path : '/'.$path);
    }
}
