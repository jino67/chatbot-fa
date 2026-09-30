<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Bot;
use App\Models\Source;
use App\Services\UsageService;
use App\Social\FacebookGraph;
use App\Social\FacebookImporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * Connexion officielle d'une page Facebook (API Graph) : le client autorise l'acces, choisit sa page,
 * et son contenu est importe puis relu chaque semaine. Disponible quand l'application Meta est configuree.
 */
class FacebookController extends Controller
{
    public function connect(Request $request, Bot $bot, FacebookGraph $facebook): RedirectResponse
    {
        if (! $facebook->isConfigured()) {
            return back()->with('error', "La connexion automatique à Facebook n'est pas encore activée sur cette plateforme. Collez le contenu de votre page à la place.");
        }

        $state = Str::random(40);
        $request->session()->put('facebook_oauth', ['state' => $state, 'bot' => $bot->id]);

        return redirect()->away($facebook->loginUrl(route('facebook.callback'), $state));
    }

    /** Retour de Facebook : on recupere les pages de l'utilisateur et on lui laisse choisir. */
    public function callback(Request $request, FacebookGraph $facebook)
    {
        $oauth = $request->session()->pull('facebook_oauth');

        if (! $oauth || ! hash_equals($oauth['state'], (string) $request->query('state'))) {
            abort(419, 'Session de connexion Facebook expirée : recommencez.');
        }

        $bot = Bot::findOrFail($oauth['bot']);

        if ($request->query('error') || ! $request->query('code')) {
            return redirect()->route('sources.index', $bot)->with('error', 'La connexion à Facebook a été annulée.');
        }

        try {
            $token = $facebook->exchangeCode((string) $request->query('code'), route('facebook.callback'));
            $pages = $facebook->pages($token);
        } catch (\Throwable $e) {
            return redirect()->route('sources.index', $bot)->with('error', $e->getMessage());
        }

        if ($pages === []) {
            return redirect()->route('sources.index', $bot)->with('error', "Aucune page Facebook n'est administrée par ce compte. Utilisez le compte qui gère votre page.");
        }

        // Les jetons de page restent chiffres cote serveur (jamais dans le navigateur), le temps du choix.
        $key = Str::random(32);
        Cache::put('facebook_pages:'.$key, Crypt::encrypt($pages), 900);
        $request->session()->put('facebook_pages_key', $key);

        return view('facebook.select', [
            'bot' => $bot,
            'pages' => array_map(fn ($p) => ['id' => $p['id'], 'name' => $p['name'], 'category' => $p['category']], $pages),
        ]);
    }

    public function select(Request $request, Bot $bot, FacebookImporter $importer, UsageService $usage): RedirectResponse
    {
        $data = $request->validate(['page_id' => ['required', 'string', 'max:40']]);

        $key = $request->session()->pull('facebook_pages_key');
        $stored = $key ? Cache::pull('facebook_pages:'.$key) : null;
        abort_unless($stored, 419, 'Sélection expirée : recommencez la connexion.');

        $page = collect(Crypt::decrypt($stored))->firstWhere('id', $data['page_id']);
        abort_unless($page, 422);

        $existing = Source::where('bot_id', $bot->id)->where('type', Source::TYPE_FACEBOOK)->where('status', Source::NEEDS_CONTENT)->first();

        if (! $existing && ! $usage->canAddSource($request->user()->currentWorkspace())) {
            return redirect()->route('sources.index', $bot)->with('error', 'Votre offre a atteint sa limite de sources.');
        }

        try {
            $importer->import($bot, $page, $existing);
        } catch (\Throwable $e) {
            return redirect()->route('sources.index', $bot)->with('error', $e->getMessage());
        }

        AuditLog::record('facebook.connected', $page['name']);

        return redirect()->route('sources.index', $bot)->with('status', 'Page « '.$page['name'].' » connectée : son contenu est en cours d\'indexation et sera actualisé chaque semaine.');
    }
}
