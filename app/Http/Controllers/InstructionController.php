<?php

namespace App\Http\Controllers;

use App\Chat\InstructionGenerator;
use App\Models\AuditLog;
use App\Models\Bot;
use App\Support\Languages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Consigne de l'assistant : profil de l'entreprise, personnalite, et texte de la consigne (modifiable librement). */
class InstructionController extends Controller
{
    /** Regles de validation communes a la creation et a l'edition du profil. */
    public static function profileRules(): array
    {
        return [
            'description' => ['nullable', 'string', 'max:600'],
            'city' => ['nullable', 'string', 'max:80'],
            'country' => ['nullable', 'string', 'max:80'],
            'hours' => ['nullable', 'string', 'max:300'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:190'],
            'website' => ['nullable', 'string', 'max:190'],
            'offers' => ['nullable', 'string', 'max:500'],
            'extra_rules' => ['nullable', 'string', 'max:1000'],
            'tone' => ['required', Rule::in(array_keys(InstructionGenerator::TONES))],
            'formality' => ['required', Rule::in(array_keys(InstructionGenerator::FORMALITY))],
            'emojis' => ['required', Rule::in(array_keys(InstructionGenerator::EMOJIS))],
            'length' => ['required', Rule::in(array_keys(InstructionGenerator::LENGTHS))],
            'languages' => ['required', 'array', 'min:1'],
            'languages.*' => [Rule::in(Languages::codes())],
        ];
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    public static function profileFrom(array $data): array
    {
        return array_intersect_key($data, array_flip([
            'description', 'city', 'country', 'hours', 'phone', 'email', 'website', 'offers', 'extra_rules',
            'tone', 'formality', 'emojis', 'length', 'languages',
        ]));
    }

    public function edit(Bot $bot, InstructionGenerator $generator)
    {
        return view('instructions.edit', [
            'bot' => $bot,
            'sectors' => $generator->sectors(),
            'profile' => array_replace($generator->defaultProfile(), $bot->profile ?? []),
        ]);
    }

    /** Enregistre le texte de la consigne tel que le client l'a ecrit. */
    public function update(Request $request, Bot $bot): RedirectResponse
    {
        $data = $request->validate(['instructions' => ['nullable', 'string', 'max:12000']]);

        $bot->update(['instructions' => trim((string) ($data['instructions'] ?? ''))]);
        AuditLog::record('bot.instructions_updated', $bot->name);

        return back()->with('status', 'Consigne enregistrée : elle s\'applique à la prochaine conversation.');
    }

    /** Met a jour le profil et, si demande, regenere la consigne (ce qui remplace le texte actuel). */
    public function profile(Request $request, Bot $bot, InstructionGenerator $generator): RedirectResponse
    {
        $data = $request->validate([
            'sector' => ['required', Rule::in(array_keys($generator->sectors()))],
            ...self::profileRules(),
        ]);

        // Les langues se règlent dans les paramètres de l'assistant : le profil en garde la liste, sans la changer ici.
        $profile = self::profileFrom($data);
        $profile['languages'] = $bot->spokenLanguages();
        $updates = ['sector' => $data['sector'], 'profile' => $profile];

        if ($request->boolean('regenerate')) {
            $text = $generator->generate($bot->name, $bot->company(), $data['sector'], $profile);
            $updates += ['instructions' => $text, 'instructions_default' => $text];
        }

        $bot->update($updates);

        return back()->with('status', $request->boolean('regenerate')
            ? 'Consigne régénérée à partir de votre profil.'
            : 'Profil enregistré. Cochez « Régénérer la consigne » pour l\'appliquer à la consigne.');
    }

    /** Revient a la derniere consigne generee par la plateforme. */
    public function reset(Bot $bot): RedirectResponse
    {
        abort_unless($bot->instructions_default, 422);

        $bot->update(['instructions' => $bot->instructions_default]);

        return back()->with('status', 'Consigne d\'origine restaurée.');
    }

    /** Propose une version amelioree par l'IA, sans l'enregistrer : le client decide de la garder ou non. */
    public function polish(Request $request, Bot $bot, InstructionGenerator $generator): JsonResponse
    {
        $data = $request->validate(['instructions' => ['required', 'string', 'max:12000']]);

        $improved = $generator->polish($data['instructions'], $bot->company(), $bot->sector, $bot->profile ?? []);

        return response()->json([
            'text' => $improved,
            'changed' => trim($improved) !== trim($data['instructions']),
        ]);
    }
}
