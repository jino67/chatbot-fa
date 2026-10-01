<?php

namespace App\Http\Controllers;

use App\Import\ChatImporter;
use App\Jobs\IngestSource;
use App\Models\AuditLog;
use App\Models\Bot;
use App\Models\PlanRequest;
use App\Models\Source;
use App\Services\PlatformSettings;
use App\Services\UsageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Import des discussions WhatsApp du client : l'assistant apprend son style et ses réponses habituelles.
 * Étapes : dépôt du fichier (consentement, aucun fichier conservé) -> « qui êtes-vous ? » -> validation du style et des
 * paires question / réponse -> enregistrement. Entre les étapes, seule une version pseudonymisée reste 30 minutes.
 */
class ChatImportController extends Controller
{
    private const TTL_MINUTES = 30;

    private const SOURCE_NAME = 'Réponses habituelles (import WhatsApp)';

    public function show(Request $request, Bot $bot)
    {
        $workspace = $request->user()->currentWorkspace();
        $state = $this->state($request, $bot);

        return view('import.show', [
            'bot' => $bot,
            'allowed' => $workspace->hasFeature('chat_import'),
            'addon' => config('platform.billing.addons.chat_import'),
            'pending' => PlanRequest::where('workspace_id', $workspace->id)->where('status', PlanRequest::REQUESTED)->where('plan', PlanRequest::ADDON_PREFIX.'chat_import')->exists(),
            'step' => $state['analysis'] ? 'review' : ($state['prepared'] ? 'owner' : 'upload'),
            'prepared' => $state['prepared'],
            'analysis' => $state['analysis'],
            'imported' => $bot->profile('imported'),
        ]);
    }

    /** Étape 1 : le fichier est lu, pseudonymisé, puis jeté. */
    public function upload(Request $request, Bot $bot, ChatImporter $importer): RedirectResponse
    {
        $this->authorizeFeature($request);

        $request->validate([
            'export' => ['required', 'file', 'max:10240', 'mimes:txt,zip'],
            'consent' => ['accepted'],
        ], [
            'export.required' => 'Choisissez le fichier exporté depuis WhatsApp (.txt ou .zip).',
            'export.mimes' => 'Le fichier doit être un export WhatsApp au format .txt ou .zip.',
            'export.max' => 'Le fichier dépasse 10 Mo : exportez la discussion sans les médias.',
            'consent.accepted' => 'Cochez la case pour confirmer que vous avez le droit d\'utiliser ces conversations.',
        ]);

        $text = $this->readExport($request->file('export')->getRealPath(), strtolower($request->file('export')->getClientOriginalExtension()));
        if ($text === null) {
            return back()->withErrors(['export' => 'Impossible de lire ce fichier. Dans WhatsApp : ouvrez la discussion, « Exporter la discussion », « Sans médias », puis envoyez le fichier obtenu.']);
        }

        $prepared = $importer->prepare($text);
        unset($text);

        if (count($prepared['authors']) < 2 || count($prepared['messages']) < 10) {
            return back()->withErrors(['export' => 'Nous n\'avons pas trouvé de vraie discussion dans ce fichier (il faut au moins deux personnes et une dizaine de messages). Vérifiez qu\'il s\'agit bien d\'un export de discussion WhatsApp.']);
        }

        $token = Str::random(32);
        Cache::put($this->key($bot, $token, 'prepared'), $prepared, now()->addMinutes(self::TTL_MINUTES));
        $request->session()->put($this->sessionKey($bot), $token);
        Cache::forget($this->key($bot, $token, 'analysis'));

        AuditLog::record('bot.chat_import_started', $bot->name, ['messages' => count($prepared['messages'])], $bot->workspace_id);

        return redirect()->route('import.show', $bot);
    }

    /** Étape 2 : le client dit qui il est ; le style et les paires sont préparés. */
    public function analyze(Request $request, Bot $bot, ChatImporter $importer): RedirectResponse
    {
        $this->authorizeFeature($request);
        $state = $this->state($request, $bot);

        if (! $state['prepared']) {
            return redirect()->route('import.show', $bot)->with('error', 'Le délai est dépassé : envoyez de nouveau le fichier.');
        }

        $data = $request->validate(['owner' => ['required', 'string', 'in:'.implode(',', array_keys($state['prepared']['authors']))]]);

        \App\Support\Runtime::allowLongRequest();
        $analysis = $importer->analyze($state['prepared'], $data['owner'], $bot);
        Cache::put($this->key($bot, $state['token'], 'analysis'), $analysis, now()->addMinutes(self::TTL_MINUTES));

        return redirect()->route('import.show', $bot);
    }

    /** Étape 3 : ce que le client a validé est enregistré ; tout le reste est effacé. */
    public function commit(Request $request, Bot $bot, UsageService $usage): RedirectResponse
    {
        $this->authorizeFeature($request);
        $state = $this->state($request, $bot);

        if (! $state['analysis']) {
            return redirect()->route('import.show', $bot)->with('error', 'Le délai est dépassé : envoyez de nouveau le fichier.');
        }

        $data = $request->validate([
            'use_style' => ['nullable', 'boolean'],
            'style' => ['nullable', 'string', 'max:1500'],
            'pairs' => ['nullable', 'array', 'max:'.ChatImporter::MAX_PAIRS],
            'pairs.*.keep' => ['nullable', 'boolean'],
            'pairs.*.q' => ['required_with:pairs.*.keep', 'string', 'max:300'],
            'pairs.*.a' => ['required_with:pairs.*.keep', 'string', 'max:900'],
        ]);

        $kept = collect($data['pairs'] ?? [])
            ->filter(fn ($p) => ! empty($p['keep']) && trim($p['q'] ?? '') !== '' && trim($p['a'] ?? '') !== '')
            ->map(fn ($p) => ['q' => trim($p['q']), 'a' => trim($p['a'])])
            ->values();

        $workspace = $request->user()->currentWorkspace();
        $existing = Source::where('bot_id', $bot->id)->where('name', self::SOURCE_NAME)->first();

        if ($kept->isNotEmpty() && ! $existing && ! $usage->canAddSource($workspace)) {
            return back()->with('error', 'Votre offre a atteint sa limite de sources : supprimez-en une pour ajouter ces réponses, ou décochez les paires.');
        }

        if ($kept->isNotEmpty()) {
            $content = $kept->map(fn ($p) => "Question : {$p['q']}\nRéponse : {$p['a']}")->implode("\n\n");
            $payload = ['content' => $content, 'origin' => 'whatsapp_import'];

            if ($existing) {
                $existing->forceFill(['payload' => $payload, 'status' => Source::PENDING, 'error' => null])->save();
                $source = $existing;
            } else {
                $source = Source::create([
                    'workspace_id' => $bot->workspace_id, 'bot_id' => $bot->id, 'type' => Source::TYPE_TEXT,
                    'name' => self::SOURCE_NAME, 'payload' => $payload,
                ]);
            }
            IngestSource::dispatch($source->id);
        }

        $style = trim((string) ($data['style'] ?? ''));
        $profile = $bot->profile ?? [];
        $profile['imported'] = [
            'enabled' => $request->boolean('use_style') && $style !== '',
            'style' => $style,
            'examples' => $state['analysis']['examples'],
            'pairs' => $kept->count(),
            'at' => now()->toIso8601String(),
        ];
        $bot->update(['profile' => $profile]);

        $this->forget($request, $bot, $state['token']);
        AuditLog::record('bot.chat_import_done', $bot->name, ['pairs' => $kept->count(), 'style' => $profile['imported']['enabled']], $bot->workspace_id);

        return redirect()->route('import.show', $bot)->with('status', $kept->isNotEmpty()
            ? "Import terminé : {$kept->count()} réponses habituelles sont en cours d'indexation".($profile['imported']['enabled'] ? ', et votre style est activé.' : '.')
            : ($profile['imported']['enabled'] ? 'Votre style est activé.' : 'Rien n\'a été enregistré.'));
    }

    /** Abandonne l'import en cours : la version pseudonymisée est effacée tout de suite. */
    public function cancel(Request $request, Bot $bot): RedirectResponse
    {
        $token = $request->session()->get($this->sessionKey($bot));
        if ($token) {
            $this->forget($request, $bot, $token);
        }

        return redirect()->route('import.show', $bot)->with('status', 'Import annulé : rien n\'a été conservé.');
    }

    /** Active ou coupe le style appris, ou l'efface. */
    public function style(Request $request, Bot $bot): RedirectResponse
    {
        $this->authorizeFeature($request);
        $profile = $bot->profile ?? [];

        if ($request->input('action') === 'delete') {
            unset($profile['imported']);
            $message = 'Le style appris a été effacé.';
        } else {
            $profile['imported'] = array_merge($profile['imported'] ?? [], ['enabled' => $request->boolean('enabled')]);
            $message = $request->boolean('enabled') ? 'Style activé.' : 'Style désactivé : l\'assistant reprend sa façon d\'écrire habituelle.';
        }

        $bot->update(['profile' => $profile]);

        return back()->with('status', $message);
    }

    /** Un client dont l'offre n'inclut pas l'import peut le demander à la carte : l'équipe l'active après paiement. */
    public function requestOption(Request $request, PlatformSettings $settings): RedirectResponse
    {
        $workspace = $request->user()->currentWorkspace();

        if ($workspace->hasFeature('chat_import')) {
            return back()->with('status', 'Cette option est déjà active.');
        }

        $plan = PlanRequest::ADDON_PREFIX.'chat_import';
        if (! PlanRequest::where('workspace_id', $workspace->id)->where('status', PlanRequest::REQUESTED)->where('plan', $plan)->exists()) {
            PlanRequest::create(['workspace_id' => $workspace->id, 'requester_id' => $request->user()->id, 'plan' => $plan, 'message' => 'Option : import des discussions WhatsApp']);

            if ($to = config('platform.admin_email') ?: $settings->get('brand.email')) {
                try {
                    Mail::raw("Demande d'option à la carte\n\nEspace : {$workspace->name}\nOption : import des discussions WhatsApp\nDemandeur : {$request->user()->name} ({$request->user()->email})\n\n".route('admin.plan-requests.index'), fn ($m) => $m->to($to)->subject("Demande d'option : {$workspace->name}"));
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        }

        return back()->with('status', 'Demande enregistrée. Payez l\'option selon les indications de la page Abonnement : elle est activée dès réception du paiement.');
    }

    /* ---------------------------------------------------------------------------------------------- */

    private function authorizeFeature(Request $request): void
    {
        abort_unless($request->user()->currentWorkspace()->hasFeature('chat_import'), 403, 'L\'import des discussions WhatsApp n\'est pas inclus dans votre offre.');
    }

    /** @return array{token:?string, prepared:?array, analysis:?array} */
    private function state(Request $request, Bot $bot): array
    {
        $token = $request->session()->get($this->sessionKey($bot));

        return [
            'token' => $token,
            'prepared' => $token ? Cache::get($this->key($bot, $token, 'prepared')) : null,
            'analysis' => $token ? Cache::get($this->key($bot, $token, 'analysis')) : null,
        ];
    }

    private function forget(Request $request, Bot $bot, string $token): void
    {
        Cache::forget($this->key($bot, $token, 'prepared'));
        Cache::forget($this->key($bot, $token, 'analysis'));
        $request->session()->forget($this->sessionKey($bot));
    }

    private function key(Bot $bot, string $token, string $part): string
    {
        return "chat_import:{$bot->workspace_id}:{$bot->id}:{$token}:{$part}";
    }

    private function sessionKey(Bot $bot): string
    {
        return 'chat_import.'.$bot->id;
    }

    /** Lit le .txt, ou le .txt contenu dans un .zip d'export WhatsApp (les médias éventuels sont ignorés). */
    private function readExport(string $path, string $extension): ?string
    {
        $raw = null;

        if ($extension === 'zip') {
            if (! class_exists(\ZipArchive::class)) {
                return null;
            }

            $zip = new \ZipArchive;
            if ($zip->open($path) !== true) {
                return null;
            }

            $entry = null;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if ($stat && str_ends_with(strtolower($stat['name']), '.txt') && $stat['size'] <= 15_000_000 && ! str_contains($stat['name'], '..')) {
                    // Le fichier « _chat.txt » (ou « Discussion WhatsApp avec... ») est le plus gros texte de l'archive.
                    if ($entry === null || $stat['size'] > $entry['size']) {
                        $entry = ['index' => $i, 'size' => $stat['size']];
                    }
                }
            }
            $raw = $entry ? $zip->getFromIndex($entry['index']) : null;
            $zip->close();
        } else {
            $raw = file_get_contents($path);
        }

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        if (str_starts_with($raw, "\xFF\xFE") || str_starts_with($raw, "\xFE\xFF")) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'UTF-16');
        }

        return mb_check_encoding($raw, 'UTF-8') ? $raw : mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1');
    }
}
