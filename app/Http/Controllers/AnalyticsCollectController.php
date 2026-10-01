<?php

namespace App\Http\Controllers;

use App\Services\Analytics\Tracker;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Réception des lots d'événements du navigateur (navigator.sendBeacon). Toujours 204, même quand le lot est ignoré :
 * le navigateur n'a rien à savoir de la mesure. La taille du lot est plafonnée et le débit limité (voir AppServiceProvider).
 */
class AnalyticsCollectController extends Controller
{
    public function store(Request $request, Tracker $tracker): Response
    {
        // sendBeacon envoie du texte : on lit le corps brut, quel que soit son type déclaré.
        $body = $request->getContent();

        if (strlen($body) <= 20000) {
            $payload = json_decode($body, true);

            if (is_array($payload)) {
                // Mesurer ne doit jamais casser quoi que ce soit : une erreur (tables absentes, base saturée) est journalisée, pas montrée.
                try {
                    $tracker->collect($request, $payload);
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        }

        return response()->noContent();
    }
}
