<?php

namespace App\Speech;

/** Resultat d'une transcription : le texte, la duree ecoutee (facturee) et le moteur utilise. */
final class Transcription
{
    public function __construct(
        public readonly string $text,
        public readonly int $seconds,
        public readonly string $engine,
        public readonly ?string $language = null,
    ) {}
}
