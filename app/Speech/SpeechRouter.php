<?php

namespace App\Speech;

use App\Support\Languages;

/**
 * Choisit le moteur selon la langue : le moteur de la plateforme (OpenAI) pour le francais, l'anglais, l'arabe et le
 * swahili ; un serveur libre pour les langues locales (bambara, dioula, peul, wolof, moore) quand il est configure.
 * La synthese vocale reste celle du moteur principal, qui ne la prononce bien que dans ses langues fortes.
 */
class SpeechRouter implements SpeechClient
{
    public function __construct(private readonly SpeechClient $main, private readonly ?SpeechClient $local = null) {}

    public function name(): string
    {
        return $this->main->name();
    }

    public function canTranscribe(?string $language = null): bool
    {
        return $this->engineFor($language)->canTranscribe($language);
    }

    public function canSpeak(): bool
    {
        return $this->main->canSpeak();
    }

    public function transcribe(string $audio, string $mime, ?string $language = null): Transcription
    {
        return $this->engineFor($language)->transcribe($audio, $mime, $language);
    }

    public function speak(string $text, string $voice = 'feminine', ?string $language = null): SpeechAudio
    {
        return $this->main->speak($text, $voice, $language);
    }

    /** Une langue peut-elle etre ecoutee ? Les langues « locales » exigent le serveur libre. */
    public function supportsListening(string $language): bool
    {
        return match (Languages::all()[$language]['stt'] ?? null) {
            'openai' => $this->main->canTranscribe($language),
            'local' => (bool) $this->local?->canTranscribe($language),
            default => false,
        };
    }

    /** Une langue locale va au serveur libre s'il existe ; sinon au moteur principal, qui fera de son mieux. */
    private function engineFor(?string $language): SpeechClient
    {
        $needsLocal = $language !== null && (Languages::all()[$language]['stt'] ?? null) === 'local';

        return $needsLocal && $this->local?->canTranscribe($language) ? $this->local : $this->main;
    }
}
