<?php

namespace App\Http\Controllers;

use App\Chat\InstructionGenerator;
use App\Models\AuditLog;
use App\Models\Bot;
use App\Services\UsageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BotController extends Controller
{
    public function index()
    {
        return view('bots.index', ['bots' => Bot::withCount('sources')->orderBy('name')->get()]);
    }

    public function create(Request $request, UsageService $usage, InstructionGenerator $generator)
    {
        if (! $usage->canAddBot($request->user()->currentWorkspace())) {
            return redirect()->route('billing.show')->with('error', "Votre offre ne permet pas d'ajouter d'autre assistant. Passez à une offre supérieure pour en créer davantage.");
        }

        return view('bots.create', [
            'sectors' => $generator->sectors(),
            'defaults' => $generator->defaultProfile(),
            'company' => $request->user()->currentWorkspace()->name,
        ]);
    }

    public function store(Request $request, UsageService $usage, InstructionGenerator $generator): RedirectResponse
    {
        $workspace = $request->user()->currentWorkspace();
        abort_unless($usage->canAddBot($workspace), 403, "Limite d'assistants atteinte pour votre offre.");

        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'language' => ['required', Rule::in(['fr', 'en', 'ar'])],
            'sector' => ['required', Rule::in(array_keys($generator->sectors()))],
            ...InstructionController::profileRules(),
        ]);

        $profile = InstructionController::profileFrom($data);
        $instructions = $generator->generate($data['name'], $workspace->name, $data['sector'], $profile);

        $bot = Bot::create([
            'workspace_id' => $workspace->id,
            'name' => $data['name'],
            'language' => $data['language'],
            'sector' => $data['sector'],
            'profile' => $profile,
            'instructions' => $instructions,
            'instructions_default' => $instructions,
            'welcome_message' => $generator->welcome($data['name'], $workspace->name, $profile),
            'suggested_questions' => $generator->suggestions($data['sector']),
            'handoff_email' => $request->user()->isClient() ? $request->user()->email : ($workspace->owner()?->email),
            'theme' => ['color' => '#2340D9', 'position' => 'right', 'title' => $data['name']],
        ]);

        AuditLog::record('bot.created', $bot->name, ['sector' => $data['sector']], $workspace->id);

        return redirect()->route('instructions.edit', $bot)->with('status', 'Assistant créé. Voici la consigne préparée pour votre entreprise : relisez-la, ajustez-la si besoin, puis ajoutez vos documents.');
    }

    /** Page de reglages de l'assistant (messages, apparence, securite). */
    public function edit(Bot $bot)
    {
        return view('bots.edit', ['bot' => $bot]);
    }

    public function update(Request $request, Bot $bot): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'language' => ['required', Rule::in(['fr', 'en', 'ar'])],
            'welcome_message' => ['nullable', 'string', 'max:400'],
            'fallback_message' => ['nullable', 'string', 'max:400'],
            'suggested_questions' => ['nullable', 'string', 'max:1000'],
            'color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'position' => ['required', Rule::in(['right', 'left'])],
            'title' => ['nullable', 'string', 'max:60'],
            'allowed_origins' => ['nullable', 'string', 'max:1000'],
            'handoff_email' => ['nullable', 'email', 'max:190'],
            'collect_contact' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $bot->update([
            'name' => $data['name'],
            'language' => $data['language'],
            'welcome_message' => $data['welcome_message'] ?? null,
            'fallback_message' => $data['fallback_message'] ?? null,
            'suggested_questions' => $this->lines($data['suggested_questions'] ?? '', 4, 80),
            'allowed_origins' => $this->origins($data['allowed_origins'] ?? ''),
            'handoff_email' => $data['handoff_email'] ?? null,
            'collect_contact' => $request->boolean('collect_contact'),
            'is_active' => $request->boolean('is_active'),
            'theme' => ['color' => $data['color'], 'position' => $data['position'], 'title' => $data['title'] ?: $data['name']],
        ]);

        return redirect()->route('bots.edit', $bot)->with('status', 'Réglages enregistrés.');
    }

    public function destroy(Bot $bot): RedirectResponse
    {
        AuditLog::record('bot.deleted', $bot->name);
        $bot->delete();

        return redirect()->route('bots.index')->with('status', 'Assistant supprimé avec toutes ses données.');
    }

    /** @return list<string> */
    private function lines(string $text, int $max, int $length): array
    {
        return collect(preg_split('/\R/u', $text))
            ->map(fn ($l) => mb_substr(trim($l), 0, $length))
            ->filter()
            ->take($max)
            ->values()
            ->all();
    }

    /** Normalise la liste blanche : une origine par ligne, sans chemin (https://exemple.com). */
    private function origins(string $text): array
    {
        return collect(preg_split('/[\s,]+/u', $text))
            ->map(function ($origin) {
                $origin = trim($origin);
                if ($origin === '') {
                    return null;
                }
                $parts = parse_url(str_contains($origin, '://') ? $origin : 'https://'.$origin);

                return isset($parts['host']) ? ($parts['scheme'] ?? 'https').'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '') : null;
            })
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
