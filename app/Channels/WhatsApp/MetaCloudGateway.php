<?php

namespace App\Channels\WhatsApp;

use App\Models\Channel;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * WhatsApp Cloud API de Meta, en direct : aucune marge d'intermediaire, un seul webhook pour tous les clients.
 * Credentials du canal : access_token (jeton d'utilisateur systeme), waba_id (pour les modeles).
 * external_ref : phone_number_id.
 */
class MetaCloudGateway implements WhatsAppGateway
{
    public function __construct(private readonly Channel $channel) {}

    public function sendText(string $to, string $text, array $buttons = []): string
    {
        $buttons = array_values(array_slice(array_unique(array_filter(array_map(fn ($b) => trim((string) $b), $buttons))), 0, 3));

        // Reponses rapides : boutons interactifs (3 maximum, 20 caracteres chacun, corps de 1024 caracteres).
        $payload = $buttons !== [] && mb_strlen($text) <= 1024
            ? [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => ltrim($to, '+'),
                'type' => 'interactive',
                'interactive' => [
                    'type' => 'button',
                    'body' => ['text' => $text],
                    'action' => ['buttons' => array_map(
                        fn ($title, $i) => ['type' => 'reply', 'reply' => ['id' => 'r'.($i + 1), 'title' => mb_substr($title, 0, 20)]],
                        $buttons,
                        array_keys($buttons),
                    )],
                ],
            ]
            : [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => ltrim($to, '+'),
                'type' => 'text',
                'text' => ['preview_url' => true, 'body' => $text],
            ];

        return $this->send($payload);
    }

    public function markRead(string $messageId): void
    {
        $this->request()->post($this->url('messages'), [
            'messaging_product' => 'whatsapp',
            'status' => 'read',
            'message_id' => $messageId,
        ]);
    }

    public function checkConnection(): array
    {
        try {
            $response = $this->request()->get($this->url('').'?fields=display_phone_number,verified_name,quality_rating');
        } catch (\Throwable $e) {
            return ['ok' => false, 'detail' => 'Meta injoignable : '.$e->getMessage()];
        }

        if (! $response->successful()) {
            return ['ok' => false, 'detail' => $this->errorMessage($response)];
        }

        return [
            'ok' => true,
            'detail' => sprintf(
                'Numéro %s connecté (nom vérifié : %s, qualité : %s).',
                $response->json('display_phone_number', '?'),
                $response->json('verified_name', '?'),
                $response->json('quality_rating', '?'),
            ),
        ];
    }

    // ---- Modeles --------------------------------------------------------------------------------

    public function supportsTemplateCreation(): bool
    {
        return true;
    }

    public function listTemplates(): array
    {
        $url = $this->wabaUrl('message_templates').'?fields=id,name,status,category,language,components,rejected_reason&limit=100';
        $templates = [];

        // Pagination : 5 pages de 100 modeles suffisent largement pour une PME.
        for ($page = 0; $page < 5 && $url; $page++) {
            $response = $this->request()->get($url);
            if (! $response->successful()) {
                throw new GatewayException($this->errorMessage($response));
            }

            foreach ($response->json('data', []) as $item) {
                $components = $item['components'] ?? [];
                $body = '';
                foreach ($components as $component) {
                    if (($component['type'] ?? '') === 'BODY') {
                        $body = (string) ($component['text'] ?? '');
                    }
                }

                $templates[] = [
                    'external_id' => isset($item['id']) ? (string) $item['id'] : null,
                    'name' => (string) $item['name'],
                    'language' => (string) ($item['language'] ?? 'fr'),
                    'category' => (string) ($item['category'] ?? 'UTILITY'),
                    'status' => strtoupper((string) ($item['status'] ?? 'PENDING')),
                    'components' => $components,
                    'body' => $body,
                    'variables_count' => self::countVariables($body),
                    'rejected_reason' => isset($item['rejected_reason']) && $item['rejected_reason'] !== 'NONE' ? (string) $item['rejected_reason'] : null,
                ];
            }

            $url = $response->json('paging.next');
        }

        return $templates;
    }

    public function createTemplate(array $definition): array
    {
        $components = [];

        if (! empty($definition['header'])) {
            $header = ['type' => 'HEADER', 'format' => 'TEXT', 'text' => $definition['header']];
            $components[] = $header;
        }

        $body = ['type' => 'BODY', 'text' => $definition['body']];
        if (($count = self::countVariables($definition['body'])) > 0) {
            // Meta exige un exemple par variable pour approuver un modele.
            $body['example'] = ['body_text' => [array_slice(array_pad($definition['body_examples'], $count, 'exemple'), 0, $count)]];
        }
        $components[] = $body;

        if (! empty($definition['footer'])) {
            $components[] = ['type' => 'FOOTER', 'text' => $definition['footer']];
        }

        if (! empty($definition['buttons'])) {
            $components[] = ['type' => 'BUTTONS', 'buttons' => array_map(fn ($b) => array_filter([
                'type' => $b['type'],
                'text' => $b['text'],
                'url' => $b['url'] ?? null,
                'phone_number' => $b['phone_number'] ?? null,
            ]), $definition['buttons'])];
        }

        $response = $this->request()->post($this->wabaUrl('message_templates'), [
            'name' => $definition['name'],
            'language' => $definition['language'],
            'category' => $definition['category'],
            'components' => $components,
        ]);

        if (! $response->successful()) {
            throw new GatewayException($this->errorMessage($response));
        }

        return [
            'external_id' => $response->json('id') ? (string) $response->json('id') : null,
            'status' => strtoupper((string) $response->json('status', 'PENDING')),
        ];
    }

    public function deleteTemplate(string $name, ?string $externalId = null): void
    {
        $response = $this->request()->delete($this->wabaUrl('message_templates').'?name='.urlencode($name));

        if (! $response->successful()) {
            throw new GatewayException($this->errorMessage($response));
        }
    }

    public function sendTemplate(string $to, string $name, string $language, array $variables, ?string $externalId = null): string
    {
        $components = [];
        if ($variables !== []) {
            $components[] = [
                'type' => 'body',
                // Meta refuse les retours a la ligne, tabulations et series d'espaces dans une variable.
                'parameters' => array_map(fn ($v) => ['type' => 'text', 'text' => trim(preg_replace('/\s+/u', ' ', (string) $v))], $variables),
            ];
        }

        return $this->send([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => ltrim($to, '+'),
            'type' => 'template',
            'template' => ['name' => $name, 'language' => ['code' => $language], 'components' => $components],
        ]);
    }

    public static function countVariables(string $body): int
    {
        preg_match_all('/\{\{(\d+)\}\}/', $body, $m);

        return $m[1] ? max(array_map('intval', $m[1])) : 0;
    }

    // ---- Webhooks -------------------------------------------------------------------------------

    /** Verifie la signature X-Hub-Signature-256 posee par Meta avec le secret de notre application. */
    public static function verifySignature(Request $request): bool
    {
        $secret = (string) config('platform.whatsapp.meta.app_secret');
        $header = (string) $request->header('X-Hub-Signature-256');

        if ($secret === '' || $header === '') {
            return false;
        }

        return hash_equals('sha256='.hash_hmac('sha256', $request->getContent(), $secret), $header);
    }

    /**
     * Normalise le corps d'un webhook Meta.
     *
     * @param  array<string,mixed>  $payload
     * @return list<InboundMessage>
     */
    public static function parse(array $payload): array
    {
        $messages = [];

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];
                $phoneNumberId = $value['metadata']['phone_number_id'] ?? null;
                $names = [];
                foreach ($value['contacts'] ?? [] as $contact) {
                    $names[$contact['wa_id'] ?? ''] = $contact['profile']['name'] ?? null;
                }

                foreach ($value['messages'] ?? [] as $message) {
                    $type = $message['type'] ?? 'other';
                    $text = match ($type) {
                        'text' => $message['text']['body'] ?? null,
                        'image', 'document', 'video' => $message[$type]['caption'] ?? null,
                        'interactive' => $message['interactive']['button_reply']['title']
                            ?? $message['interactive']['list_reply']['title'] ?? null,
                        'button' => $message['button']['text'] ?? null,
                        default => null,
                    };

                    $messages[] = new InboundMessage(
                        providerMessageId: (string) ($message['id'] ?? ''),
                        from: (string) ($message['from'] ?? ''),
                        name: $names[$message['from'] ?? ''] ?? null,
                        type: in_array($type, ['text', 'image', 'audio', 'document', 'location'], true) ? $type : ($text ? 'text' : 'other'),
                        text: $text,
                        channelRef: $phoneNumberId,
                    );
                }
            }
        }

        return array_values(array_filter($messages, fn (InboundMessage $m) => $m->providerMessageId !== '' && $m->from !== ''));
    }

    // ---- Interne --------------------------------------------------------------------------------

    /** @param array<string,mixed> $payload */
    private function send(array $payload): string
    {
        $response = $this->request()->post($this->url('messages'), $payload);

        if (! $response->successful()) {
            throw new GatewayException($this->errorMessage($response));
        }

        return (string) $response->json('messages.0.id');
    }

    private function request(): PendingRequest
    {
        return Http::withToken((string) $this->channel->credential('access_token'))->timeout(15)->acceptJson();
    }

    private function url(string $path): string
    {
        $version = config('platform.whatsapp.meta.graph_version');

        return "https://graph.facebook.com/{$version}/{$this->channel->external_ref}".($path ? '/'.$path : '');
    }

    /** Les modeles appartiennent au compte WhatsApp Business (WABA), pas au numero. */
    private function wabaUrl(string $path): string
    {
        $waba = $this->channel->credential('waba_id');
        if (! $waba) {
            throw new GatewayException("L'identifiant du compte WhatsApp Business (WABA ID) est manquant : l'équipe technique doit le renseigner pour gérer les modèles.");
        }

        return 'https://graph.facebook.com/'.config('platform.whatsapp.meta.graph_version')."/{$waba}/{$path}";
    }

    private function errorMessage(Response $response): string
    {
        $detail = $response->json('error.error_user_msg') ?: $response->json('error.message', 'erreur inconnue');

        return 'Meta HTTP '.$response->status().' : '.$detail;
    }
}
