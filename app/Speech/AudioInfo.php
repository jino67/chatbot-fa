<?php

namespace App\Speech;

/** Duree d'un enregistrement, sans outil externe : les messages vocaux sont presque toujours de l'ogg/opus. */
final class AudioInfo
{
    public static function seconds(string $audio): int
    {
        // Ogg : la derniere page porte la position finale, en echantillons de 48 kHz (opus).
        if (str_starts_with($audio, 'OggS')) {
            $pos = strrpos($audio, 'OggS');
            if ($pos !== false && strlen($audio) >= $pos + 14) {
                $granule = unpack('P', substr($audio, $pos + 6, 8))[1] ?? 0;
                if ($granule > 0 && $granule < 48000 * 3600) {
                    return max(1, (int) ceil($granule / 48000));
                }
            }
        }

        // WAV : debit et taille des donnees dans l'en-tete.
        if (str_starts_with($audio, 'RIFF') && substr($audio, 8, 4) === 'WAVE' && strlen($audio) > 44) {
            $rate = unpack('V', substr($audio, 28, 4))[1] ?? 0;
            if ($rate > 0) {
                return max(1, (int) ceil((strlen($audio) - 44) / $rate));
            }
        }

        // Autres formats (webm, mp4, mp3) : estimation a 32 kbit/s, le debit courant d'un message vocal.
        return max(1, (int) ceil(strlen($audio) / 4000));
    }

    /** Extension acceptee par les API de transcription, deduite du type MIME. */
    public static function extension(string $mime): string
    {
        $mime = strtolower(explode(';', $mime)[0]);

        return match (true) {
            str_contains($mime, 'ogg'), str_contains($mime, 'opus') => 'ogg',
            str_contains($mime, 'webm') => 'webm',
            str_contains($mime, 'mp4'), str_contains($mime, 'm4a'), str_contains($mime, 'aac') => 'm4a',
            str_contains($mime, 'mpeg'), str_contains($mime, 'mp3') => 'mp3',
            str_contains($mime, 'wav') => 'wav',
            str_contains($mime, 'flac') => 'flac',
            str_contains($mime, 'amr') => 'amr',
            default => 'ogg',
        };
    }
}
