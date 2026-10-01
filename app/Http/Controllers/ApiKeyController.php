<?php

namespace App\Http\Controllers;

use App\Models\ApiKey;
use App\Models\AuditLog;
use App\Models\Bot;
use App\Services\UsageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Cles d'API de l'offre developpeurs : creation (la cle n'est montree qu'une fois), liste, revocation. */
class ApiKeyController extends Controller
{
    public function index(Request $request, UsageService $usage)
    {
        $workspace = $request->user()->currentWorkspace();

        if ($denied = $this->requireApi($workspace)) {
            return $denied;
        }

        return view('api-keys.index', [
            'keys' => ApiKey::where('workspace_id', $workspace->id)->with('bot')->latest()->get(),
            'bots' => Bot::orderBy('name')->get(),
            'usage' => $usage->summary($workspace)['messages'],
            'plan' => $workspace->planModel(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $workspace = $request->user()->currentWorkspace();

        if ($denied = $this->requireApi($workspace)) {
            return $denied;
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'bot_id' => ['required', 'integer'],
        ]);

        $bot = Bot::findOrFail($data['bot_id']);

        // Dix cles actives au plus : assez pour un usage normal, pas pour une fuite silencieuse.
        if (ApiKey::where('workspace_id', $workspace->id)->whereNull('revoked_at')->count() >= 10) {
            return back()->with('error', 'Vous avez déjà 10 clés actives. Révoquez-en une avant d\'en créer une nouvelle.');
        }

        [$key, $plain] = ApiKey::issue($workspace->id, $bot->id, $data['name']);
        AuditLog::record('api_key.created', $key->name, ['prefix' => $key->prefix]);

        // La cle en clair n'est affichee qu'une fois, dans cette reponse (session flash).
        return back()->with('status', 'Clé créée. Copiez-la maintenant : elle ne sera plus affichée.')->with('new_api_key', $plain);
    }

    public function destroy(ApiKey $apiKey): RedirectResponse
    {
        $apiKey->forceFill(['revoked_at' => now()])->save();
        AuditLog::record('api_key.revoked', $apiKey->name, ['prefix' => $apiKey->prefix]);

        return back()->with('status', 'Clé révoquée : elle ne fonctionne plus.');
    }

    private function requireApi($workspace): ?RedirectResponse
    {
        if (! $workspace->hasFeature('api')) {
            return redirect()->route('billing.show')->with('error', "L'API est réservée à l'offre API Développeur.");
        }

        return null;
    }
}
