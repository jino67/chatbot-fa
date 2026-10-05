<?php

namespace App\Http\Controllers;

use App\Models\CatalogItem;
use App\Models\Message;
use App\Services\ProductImages;
use App\Speech\VoiceMedia;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/** Sert un message vocal de reponse a Twilio (adresse signee, expiree apres deux heures). */
class MediaController extends Controller
{
    /** La photo qu'un client a envoyée, gardée en privé (voir CustomerImages) : jamais indexée, jamais mise en cache partagée. */
    public function chatImage(int $message): Response
    {
        $path = Message::withoutGlobalScopes()->where('role', Message::USER)->find($message)?->meta['image']['path'] ?? null;
        $disk = Storage::disk(config('platform.uploads.disk'));
        abort_unless(is_string($path) && $path !== '' && $disk->exists($path), 404);

        return response($disk->get($path), 200, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, max-age=3600', 'X-Robots-Tag' => 'noindex']);
    }

    /** La photo d'un produit, remise en JPEG (voir ProductImages). */
    public function product(int $item, ProductImages $images): Response
    {
        $row = CatalogItem::withoutGlobalScopes()->find($item);
        $bytes = $row ? $images->contents($row) : null;
        abort_unless($bytes !== null, 404);

        return response($bytes, 200, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'public, max-age=86400',
            'X-Robots-Tag' => 'noindex',
        ]);
    }

    public function voice(string $name): Response
    {
        abort_unless(VoiceMedia::exists($name), 404);

        return response(VoiceMedia::contents($name), 200, [
            'Content-Type' => str_ends_with($name, '.mp3') ? 'audio/mpeg' : (str_ends_with($name, '.wav') ? 'audio/wav' : 'audio/ogg'),
            'Cache-Control' => 'private, max-age=3600',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
