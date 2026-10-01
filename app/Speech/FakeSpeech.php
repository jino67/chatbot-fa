<?php

namespace App\Speech;

/**
 * Voix hors ligne : aucun appel reseau, resultats previsibles (tests, demonstration sans cle).
 * Un audio dont le contenu commence par « FAKE: » est « transcrit » en ce qui suit ; la synthese renvoie un court
 * silence ogg valide. `failWith` simule une panne.
 */
class FakeSpeech implements SpeechClient
{
    public static ?string $failWith = null;

    /** @var list<array{text:string,voice:string}> */
    public static array $spoken = [];

    /** @var list<string|null> langues demandees a la transcription */
    public static array $heard = [];

    public static function reset(): void
    {
        self::$failWith = null;
        self::$spoken = [];
        self::$heard = [];
    }

    /** Un « message vocal » de test : ogg minimal dont le contenu est le texte que l'on veut entendre. */
    public static function voiceNote(string $says): string
    {
        return 'FAKE:'.$says;
    }

    public function name(): string
    {
        return 'fake';
    }

    public function canTranscribe(?string $language = null): bool
    {
        return true;
    }

    public function canSpeak(): bool
    {
        return true;
    }

    public function transcribe(string $audio, string $mime, ?string $language = null): Transcription
    {
        if (self::$failWith) {
            throw new SpeechException('Panne simulée.', self::$failWith);
        }

        self::$heard[] = $language;

        $text = str_starts_with($audio, 'FAKE:') ? trim(substr($audio, 5)) : '';
        if ($text === '') {
            throw new SpeechException('Aucune parole reconnue.', 'empty');
        }

        return new Transcription($text, AudioInfo::seconds($audio), 'fake', $language);
    }

    public function speak(string $text, string $voice = 'feminine', ?string $language = null): SpeechAudio
    {
        if (self::$failWith) {
            throw new SpeechException('Panne simulée.', self::$failWith);
        }

        self::$spoken[] = ['text' => $text, 'voice' => $voice];

        // Une page ogg minimale : en-tete « OggS », duree de 2 secondes (96 000 echantillons a 48 kHz).
        $page = 'OggS'."\x00\x04".pack('P', 96000).pack('V', 1).pack('V', 0).pack('V', 0)."\x00";

        return new SpeechAudio($page, 'audio/ogg', max(1, (int) ceil(mb_strlen($text) / 15)), 'fake');
    }
}
