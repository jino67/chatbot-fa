<?php

namespace App\Http\Controllers;

use App\Services\PlatformSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Fichier de description de l'application installable (« ajouter à l'écran d'accueil »). Il suit le nom de la marque. */
class PwaController extends Controller
{
    /** L'application se signale quand elle s'ouvre en mode application : l'invitation à l'installer ne revient plus. */
    public function installed(Request $request): Response
    {
        $user = $request->user();
        if (! $user->pwa_installed_at) {
            $user->forceFill(['pwa_installed_at' => now()])->save();
        }

        return response()->noContent();
    }

    public function manifest(PlatformSettings $settings): JsonResponse
    {
        $brand = $settings->brand();

        return response()->json([
            'name' => $brand['name'],
            'short_name' => mb_substr($brand['name'], 0, 12),
            'description' => 'Votre assistant qui répond aux clients sur votre site et sur WhatsApp.',
            'lang' => 'fr',
            'id' => '/dashboard',
            'start_url' => '/dashboard?source=app',
            'scope' => '/',
            'display' => 'standalone',
            'orientation' => 'any',
            'background_color' => '#2340D9',
            'theme_color' => '#2340D9',
            'categories' => ['business', 'productivity'],
            'icons' => [
                ['src' => '/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
                ['src' => '/favicon.svg', 'sizes' => 'any', 'type' => 'image/svg+xml', 'purpose' => 'any'],
            ],
            'shortcuts' => [
                ['name' => 'Demandes à traiter', 'url' => '/demandes', 'icons' => [['src' => '/icon-192.png', 'sizes' => '192x192']]],
                ['name' => 'Assistants', 'url' => '/bots', 'icons' => [['src' => '/icon-192.png', 'sizes' => '192x192']]],
            ],
        ], 200, ['Content-Type' => 'application/manifest+json', 'Cache-Control' => 'public, max-age=3600'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
