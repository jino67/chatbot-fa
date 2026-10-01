<?php

namespace App\Speech;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Fichiers audio de reponse que Twilio doit pouvoir telecharger : deposes dans le stockage prive, servis par une
 * adresse signee qui expire. Les fichiers de plus de deux heures sont effaces a chaque nouveau depot (aucune tache planifiee).
 */
final class VoiceMedia
{
    private const DIR = 'voice';

    private const TTL_HOURS = 2;

    /** @return string nom du fichier */
    public static function store(SpeechAudio $audio): string
    {
        $disk = Storage::disk('local');
        self::purge();

        $name = Str::random(40).'.'.$audio->extension();
        $disk->put(self::DIR.'/'.$name, $audio->bytes);

        return $name;
    }

    /** Adresse publique signee : la signature couvre le chemin, l'hote est celui que Twilio peut joindre. */
    public static function publicUrl(string $name): string
    {
        $path = URL::temporarySignedRoute('media.voice', now()->addHours(self::TTL_HOURS), ['name' => $name], absolute: false);
        $base = config('platform.whatsapp.twilio.public_base_url') ?: config('app.url');

        return rtrim((string) $base, '/').$path;
    }

    public static function exists(string $name): bool
    {
        return Storage::disk('local')->exists(self::DIR.'/'.$name);
    }

    public static function contents(string $name): string
    {
        return Storage::disk('local')->get(self::DIR.'/'.$name);
    }

    private static function purge(): void
    {
        $disk = Storage::disk('local');
        $limit = now()->subHours(self::TTL_HOURS)->getTimestamp();

        foreach ($disk->files(self::DIR) as $file) {
            if ($disk->lastModified($file) < $limit) {
                $disk->delete($file);
            }
        }
    }
}
