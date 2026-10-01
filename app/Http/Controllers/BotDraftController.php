<?php

namespace App\Http\Controllers;

use App\Support\BotDraft;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Enregistrement du brouillon de création d'un assistant, au fil de la saisie ou à la demande (« continuer plus tard »). */
class BotDraftController extends Controller
{
    public function save(Request $request): JsonResponse|RedirectResponse
    {
        $workspace = $request->user()->currentWorkspace();
        $draft = BotDraft::put($request->user(), $workspace, $request->except(['_token', '_method', 'leave']));

        if ($request->boolean('leave')) {
            return redirect()->route('dashboard')->with('status', $draft
                ? 'Brouillon enregistré. Vous le retrouvez sur votre tableau de bord et dans « Assistants » : « Reprendre la création ».'
                : 'Rien à enregistrer pour le moment.');
        }

        return response()->json([
            'saved' => $draft !== null,
            'saved_at' => $draft['saved_at'] ?? null,
            'label' => $draft ? 'Brouillon enregistré à '.now()->format('H:i') : 'Rien à enregistrer',
        ]);
    }

    public function discard(Request $request): RedirectResponse
    {
        BotDraft::forget($request->user(), $request->user()->currentWorkspace());

        return redirect()->route('bots.create')->with('status', 'Brouillon supprimé : vous repartez d\'une page vierge.');
    }
}
