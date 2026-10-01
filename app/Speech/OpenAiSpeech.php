<?php

namespace App\Speech;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Transcription et synthese vocale via une API compatible OpenAI (`/audio/transcriptions`, `/audio/speech`).
 * Le meme adaptateur parle donc a OpenAI et a un serveur libre auto-heberge (faster-whisper-server, vLLM, Speaches...)
 * qui expose le meme contrat : c'est ainsi qu'on branche un modele pour les langues locales.
 */
class OpenAiSpeech implements SpeechClient
{
    /** Voix de gpt-4o-mini-tts par style. */
    private const VOICES = ['feminine' => 'coral', 'masculine' => 'onyx', 'neutral' => 'alloy'];

    /** @param array{api_key:?string,base_url:string,stt_models:list<string>,tts_model:?string,label?:string,languages?:list<string>|null,timeout?:int} $config */
    public function __construct(private readonly array $config) {}

    public function name(): string
    {
        return $this->config['label'] ?? 'openai';
    }

    public function canTranscribe(?string $language = null): bool
    {
        if (! $this->config['api_key'] && ! $this->isLocalServer()) {
            return false;
        }

        // Un serveur dedie a certaines langues (ex. bambara) ne s'occupe que d'elles.
        $only = $this->config['languages'] ?? null;

        return $only === null || $language === null || in_array($language, $only, true);
    }

    public function canSpeak(): bool
    {
        return ! empty($this->config['tts_model']) && ! empty($this->config['api_key']);
    }

    public function transcribe(string $audio, string $mime, ?string $language = null): Transcription
    {
        if (! $this->canTranscribe($language)) {
            throw new SpeechException('Aucun moteur de transcription pour cette langue.', 'unavailable');
        }

        $seconds = AudioInfo::seconds($audio);
        $last = null;

        foreach ($this->config['stt_models'] as $model) {
            try {
                $response = $this->request()->attach('file', $audio, 'message.'.AudioInfo::extension($mime))
                    ->retry(2, 400, fn ($e) => $e instanceof ConnectionException, throw: false)
                    ->post($this->url('/audio/transcriptions'), array_filter([
                        'model' => $model,
                        'language' => $language && strlen($language) === 2 ? $language : null,
                        'response_format' => 'json',
                    ], fn ($v) => $v !== null));
            } catch (ConnectionException) {
                throw new SpeechException('Connexion impossible au service de transcription.', 'network');
            }

            if ($response->successful()) {
                $text = trim((string) $response->json('text', ''));
                if ($text === '') {
                    throw new SpeechException('Aucune parole reconnue.', 'empty');
                }

                $heard = (int) ceil((float) ($response->json('usage.seconds') ?? $response->json('duration') ?? $seconds));

                return new Transcription($text, max(1, $heard), $this->name().':'.$model, $language);
            }

            $last = $this->failure($response, 'transcription');

            // Modele absent chez ce fournisseur : on essaie le suivant ; toute autre panne est remontee telle quelle.
            if ($last->kind !== 'unsupported') {
                throw $last;
            }
        }

        throw $last ?? new SpeechException('Transcription impossible.', 'provider');
    }

    public function speak(string $text, string $voice = 'feminine', ?string $language = null): SpeechAudio
    {
        if (! $this->canSpeak()) {
            throw new SpeechException('La réponse audio n\'est pas disponible.', 'unavailable');
        }

        $payload = [
            'model' => $this->config['tts_model'],
            'voice' => self::VOICES[$voice] ?? self::VOICES['neutral'],
            'input' => $text,
            'response_format' => 'opus',
        ];

        // Seul gpt-4o-mini-tts accepte des consignes d'interpretation.
        if (str_contains((string) $this->config['tts_model'], 'gpt-4o')) {
            $payload['instructions'] = 'Parle sur un ton chaleureux et naturel, comme un conseiller de clientèle souriant, à un rythme posé. Prononce les prix et les nombres clairement.';
        }

        try {
            // Une coupure réseau brève (fréquente sur mobile et sur certaines liaisons) ne doit pas coûter la réponse audio :
            // un seul nouvel essai, puis l'échec habituel.
            $response = $this->request()->retry(2, 400, fn ($e) => $e instanceof ConnectionException, throw: false)->post($this->url('/audio/speech'), $payload);
        } catch (ConnectionException) {
            throw new SpeechException('Connexion impossible au service vocal.', 'network');
        }

        if (! $response->successful()) {
            throw $this->failure($response, 'synthèse vocale');
        }

        $bytes = $response->body();
        if ($bytes === '') {
            throw new SpeechException('Audio vide.', 'provider');
        }

        return new SpeechAudio($bytes, 'audio/ogg', AudioInfo::seconds($bytes), $this->name().':'.$this->config['tts_model']);
    }

    public function url(string $path): string
    {
        return rtrim($this->config['base_url'], '/').$path;
    }

    private function request(): PendingRequest
    {
        $request = Http::timeout($this->config['timeout'] ?? 60)->acceptJson();

        return $this->config['api_key'] ? $request->withToken($this->config['api_key']) : $request;
    }

    private function isLocalServer(): bool
    {
        return ($this->config['label'] ?? '') === 'local'
            || in_array(parse_url($this->config['base_url'], PHP_URL_HOST) ?: '', ['localhost', '127.0.0.1', '::1'], true);
    }

    private function failure(Response $response, string $what): SpeechException
    {
        $status = $response->status();
        $message = (string) ($response->json('error.message') ?? $response->json('detail') ?? '');
        $code = (string) $response->json('error.code', '');
        $billing = $status === 429 && ($code === 'insufficient_quota' || str_contains($message, 'quota'));

        $kind = match (true) {
            $status === 401, $status === 403 => 'auth',
            $billing => 'billing',
            $status === 429 => 'rate_limit',
            $status === 404, in_array($status, [400], true) && str_contains($message, 'model') => 'unsupported',
            default => 'provider',
        };

        return new SpeechException(ucfirst($what).' : HTTP '.$status.($message !== '' ? ' '.mb_substr($message, 0, 200) : ''), $kind);
    }
}
