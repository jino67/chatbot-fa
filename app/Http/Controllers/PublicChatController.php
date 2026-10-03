<?php

namespace App\Http\Controllers;

use App\Models\Bot;
use App\Models\Workspace;
use Illuminate\Support\Str;

/**
 * Le lien de discussion d'un assistant : une page légère que son propriétaire partage (WhatsApp, Facebook, QR code, carte
 * de visite). Elle charge le widget et rien d'autre : pas de mesure d'audience, pas de publicité pour la plateforme au-delà
 * de la mention « propulsé par » que l'option de l'offre peut retirer. Un assistant en pause affiche un message, pas une erreur.
 */
class PublicChatController extends Controller
{
    public function show(string $publicKey)
    {
        $bot = Bot::withoutGlobalScopes()->with('workspace')->where('public_key', $publicKey)->firstOrFail();

        // Aucune page publique pour un espace suspendu ou un essai terminé : le client n'a pas à voir pourquoi.
        $available = $bot->is_active && ! ($bot->workspace?->is_suspended ?? false) && $bot->workspace?->subscription_status !== Workspace::EXPIRED;

        return response()->view('chat.public', [
            'bot' => $bot,
            'available' => $available,
            'company' => $bot->company(),
            'welcome' => Str::limit($bot->welcome(), 220),
            'color' => preg_match('/^#[0-9a-fA-F]{6}$/', (string) $bot->theme('color')) ? $bot->theme('color') : '#2340D9',
            'whatsapp' => $available ? $bot->whatsappNumber() : null,
            'branding' => ! ($bot->workspace?->hasFeature('remove_branding') ?? false),
        ])->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
