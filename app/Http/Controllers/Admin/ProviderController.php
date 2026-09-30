<?php

namespace App\Http\Controllers\Admin;

use App\Ai\Embeddings\EmbeddingFactory;
use App\Ai\Llm\ProviderRegistry;
use App\Http\Controllers\Controller;
use App\Jobs\ReindexEmbeddings;
use App\Models\AiProvider;
use App\Models\AuditLog;
use App\Models\Chunk;
use App\Services\PlatformSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * IA et fournisseurs : chaine de bascule (Claude, puis OpenAI, puis Llama par defaut), etat de chaque fournisseur,
 * epinglage manuel, embeddings. Les cles sont chiffrees et jamais reaffichees.
 */
class ProviderController extends Controller
{
    public function index(ProviderRegistry $registry, PlatformSettings $settings, EmbeddingFactory $embeddings)
    {
        $chunks = Chunk::withoutGlobalScopes()->selectRaw('embedding_model, count(*) as total')->groupBy('embedding_model')->pluck('total', 'embedding_model');
        $current = $embeddings->make()->model();

        return view('admin.ai.index', [
            'providers' => $registry->all(),
            'active' => $registry->active(),
            'offline' => $registry->isOffline(),
            'presets' => AiProvider::PRESETS,
            'mode' => $settings->get('ai.mode'),
            'forcedId' => (int) $settings->get('ai.forced_provider_id'),
            'strict' => (bool) $settings->get('ai.strict_forced'),
            'embeddingDriver' => $settings->get('embeddings.driver') ?: config('platform.ai.embeddings'),
            'embeddingModel' => $settings->get('embeddings.model'),
            'embeddingKeySet' => $settings->has('embeddings.api_key'),
            'embeddingCurrent' => $current,
            'chunksByModel' => $chunks,
            'staleChunks' => $chunks->filter(fn ($n, $model) => $model !== $current)->sum(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'preset' => ['required', Rule::in(array_keys(AiProvider::PRESETS))],
            'name' => ['nullable', 'string', 'max:80'],
            'api_key' => ['nullable', 'string', 'max:300'],
            'model' => ['nullable', 'string', 'max:120'],
            'base_url' => ['nullable', 'url', 'max:200'],
        ]);

        $preset = AiProvider::PRESETS[$data['preset']];

        $provider = AiProvider::create([
            'name' => ($data['name'] ?? null) ?: $preset['label'],
            'driver' => $preset['driver'],
            'preset' => $data['preset'],
            'api_key' => ($data['api_key'] ?? null) ?: null,
            'base_url' => ($data['base_url'] ?? null) ?: $preset['base_url'],
            'model' => ($data['model'] ?? null) ?: $preset['model'],
            'supports_vision' => $preset['vision'],
            'price_in' => $preset['price_in'],
            'price_out' => $preset['price_out'],
            'priority' => (AiProvider::max('priority') ?? 0) + 1,
        ]);

        AuditLog::record('ai.provider_added', $provider->name);

        return back()->with('status', "Fournisseur « {$provider->name} » ajouté en fin de chaîne. Testez-le avant de compter dessus.");
    }

    public function update(Request $request, AiProvider $provider): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'model' => ['required', 'string', 'max:120'],
            'vision_model' => ['nullable', 'string', 'max:120'],
            'base_url' => ['nullable', 'url', 'max:200'],
            'api_key' => ['nullable', 'string', 'max:300'],
            'price_in' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'price_out' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'priority' => ['required', 'integer', 'min:1', 'max:99'],
        ]);

        $update = [
            'name' => $data['name'],
            'model' => $data['model'],
            'vision_model' => ($data['vision_model'] ?? null) ?: null,
            'base_url' => ($data['base_url'] ?? null) ?: $provider->base_url,
            'price_in' => $data['price_in'] ?? null,
            'price_out' => $data['price_out'] ?? null,
            'priority' => $data['priority'],
            'enabled' => $request->boolean('enabled'),
            'supports_vision' => $request->boolean('supports_vision'),
        ];

        // Cle laissee vide : on conserve l'existante. « Effacer » la retire (retour a la cle de l'environnement).
        if (filled($data['api_key'] ?? null)) {
            $update['api_key'] = $data['api_key'];
        } elseif ($request->boolean('clear_key')) {
            $update['api_key'] = null;
        }

        $provider->update($update);
        // Un changement de cle ou de modele merite un nouvel essai immediat.
        $provider->forceFill(['disabled_until' => null, 'status' => 'unknown'])->save();

        AuditLog::record('ai.provider_updated', $provider->name, ['model' => $provider->model, 'enabled' => $provider->enabled, 'key_changed' => filled($data['api_key'] ?? null)]);

        return back()->with('status', "« {$provider->name} » enregistré.");
    }

    public function destroy(AiProvider $provider): RedirectResponse
    {
        AuditLog::record('ai.provider_removed', $provider->name);
        $provider->delete();

        return back()->with('status', 'Fournisseur retiré de la chaîne.');
    }

    public function test(AiProvider $provider, ProviderRegistry $registry): RedirectResponse
    {
        $result = $registry->test($provider);

        return back()->with($result['ok'] ? 'status' : 'error', "{$provider->name} : {$result['detail']}");
    }

    /** Leve la mise en pause apres une panne : utile apres avoir recharge un compte. */
    public function reset(AiProvider $provider): RedirectResponse
    {
        $provider->forceFill(['disabled_until' => null, 'status' => 'unknown'])->save();
        AuditLog::record('ai.provider_reset', $provider->name);

        return back()->with('status', "« {$provider->name} » remis en service : il sera réessayé à la prochaine réponse.");
    }

    /** Bascule manuelle : epingler un fournisseur, ou revenir a la chaine automatique. */
    public function mode(Request $request, PlatformSettings $settings): RedirectResponse
    {
        $data = $request->validate([
            'mode' => ['required', Rule::in(['auto', 'forced'])],
            'forced_provider_id' => ['nullable', 'integer', 'exists:ai_providers,id'],
        ]);

        if ($data['mode'] === 'forced' && empty($data['forced_provider_id'])) {
            return back()->with('error', 'Choisissez le fournisseur à utiliser.');
        }

        $settings->set('ai.mode', $data['mode']);
        $settings->set('ai.forced_provider_id', $data['mode'] === 'forced' ? (int) $data['forced_provider_id'] : null);
        $settings->set('ai.strict_forced', $request->boolean('strict_forced'));

        AuditLog::record('ai.mode_changed', $data['mode'], ['provider' => $data['forced_provider_id'] ?? null, 'strict' => $request->boolean('strict_forced')]);

        return back()->with('status', $data['mode'] === 'forced'
            ? 'Fournisseur épinglé : il répond en premier'.($request->boolean('strict_forced') ? ', sans bascule automatique.' : ', avec bascule automatique en cas de panne.')
            : 'Mode automatique : la chaîne est parcourue dans l\'ordre, avec bascule en cas de panne.');
    }

    public function embeddings(Request $request, PlatformSettings $settings): RedirectResponse
    {
        $data = $request->validate([
            'driver' => ['required', Rule::in(['hashing', 'voyage', 'openai'])],
            'model' => ['nullable', 'string', 'max:120'],
            'api_key' => ['nullable', 'string', 'max:300'],
        ]);

        $settings->set('embeddings.driver', $data['driver']);
        $settings->set('embeddings.model', $data['model'] ?? null);
        if (filled($data['api_key'] ?? null)) {
            $settings->set('embeddings.api_key', $data['api_key'], secret: true);
        }

        AuditLog::record('ai.embeddings_changed', $data['driver'], ['model' => $data['model'] ?? null]);

        return back()->with('status', 'Moteur d\'embeddings enregistré. Si le modèle a changé, cliquez sur « Recalculer les vecteurs » : en attendant, la recherche s\'appuie sur les mots exacts.');
    }

    public function reindex(): RedirectResponse
    {
        ReindexEmbeddings::dispatch();
        AuditLog::record('ai.reindex_started');

        return back()->with('status', 'Recalcul lancé en arrière-plan.');
    }
}
