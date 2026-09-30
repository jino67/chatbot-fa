<?php

namespace App\Http\Controllers;

use App\Models\Bot;
use App\Models\Plan;

/**
 * Page publique de demonstration : montre le widget d'un assistant sur une page vierge, partageable par lien.
 * C'est aussi notre vitrine : elle invite le visiteur a creer son propre assistant.
 */
class DemoController extends Controller
{
    public function show(string $publicKey)
    {
        $bot = Bot::withoutGlobalScopes()->with('workspace')
            ->where('public_key', $publicKey)->where('is_active', true)->firstOrFail();

        return view('demo', [
            'bot' => $bot,
            'freePlan' => Plan::default(),
        ]);
    }
}
