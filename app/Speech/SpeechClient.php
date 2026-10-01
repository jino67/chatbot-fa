<?php

namespace App\Speech;

/**
 * Voix : comprendre un message vocal (transcription) et repondre en audio (synthese vocale).
 * Comme pour les modeles de langage, le reste de l'application ne connait que cette interface :
 * OpenAI, un serveur libre auto-heberge (Whisper, Omnilingual ASR...) ou le faux client des tests.
 */
interface SpeechClient
{
    public function name(): string;

    /** Peut-on ecouter un message dans cette langue (code du catalogue) ? Null : langue non precisee. */
    public function canTranscribe(?string $language = null): bool;

    public function canSpeak(): bool;

    /** @throws SpeechException */
    public function transcribe(string $audio, string $mime, ?string $language = null): Transcription;

    /**
     * @param  string  $voice  feminine | masculine | neutral
     * @param  string|null  $language  code de la langue du texte (oriente la prononciation)
     *
     * @throws SpeechException
     */
    public function speak(string $text, string $voice = 'feminine', ?string $language = null): SpeechAudio;
}
