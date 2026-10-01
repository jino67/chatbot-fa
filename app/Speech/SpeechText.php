<?php

namespace App\Speech;

/** Prepare le texte d'une reponse pour la lecture a voix haute : ni mise en forme, ni marqueurs, ni adresses. */
final class SpeechText
{
    public static function forSpeech(string $text): string
    {
        $text = preg_replace('/\[\[[A-Z_]+[^\]]*\]\]/u', '', $text);                  // marqueurs internes
        $text = preg_replace('/\[([^\]]+)\]\((https?:[^)]+)\)/u', '$1', $text);       // [texte](lien) : le texte seul
        $text = preg_replace('#https?://\S+#u', '', $text);                           // adresses : illisibles a l'oral
        $text = preg_replace('/[*_`#>~]+/u', '', $text);                              // gras, italique, titres
        $text = preg_replace('/^\s*[•\-–]\s+/mu', '', $text);                         // puces
        $text = preg_replace('/^\s*(\d+)[.)]\s+/mu', '$1. ', $text);
        $text = preg_replace('/\n{2,}/u', ". \n", $text);
        $text = preg_replace('/([^.!?…:;\s])\s*\n/u', '$1. ', $text);                 // une ligne = une phrase
        $text = preg_replace('/\p{So}|\x{FE0F}|\x{200D}/u', '', $text);               // emojis
        $text = preg_replace('/\s+/u', ' ', $text);

        return trim($text);
    }
}
