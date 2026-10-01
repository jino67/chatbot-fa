<?php

namespace App\Speech;

/** Audio synthetise. Toujours au format ogg/opus : c'est celui que WhatsApp accepte comme message vocal. */
final class SpeechAudio
{
    public function __construct(
        public readonly string $bytes,
        public readonly string $mime,
        public readonly int $seconds,
        public readonly string $engine,
    ) {}

    public function extension(): string
    {
        return match (true) {
            str_contains($this->mime, 'ogg') => 'ogg',
            str_contains($this->mime, 'mpeg') => 'mp3',
            str_contains($this->mime, 'wav') => 'wav',
            default => 'bin',
        };
    }

    public function dataUri(): string
    {
        return 'data:'.$this->mime.';base64,'.base64_encode($this->bytes);
    }
}
