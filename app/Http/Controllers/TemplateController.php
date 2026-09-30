<?php

namespace App\Http\Controllers;

use App\Channels\WhatsApp\GatewayException;
use App\Channels\WhatsApp\MetaCloudGateway;
use App\Channels\WhatsApp\TemplateManager;
use App\Models\AuditLog;
use App\Models\Bot;
use App\Models\Channel;
use App\Models\WhatsAppTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Modeles de messages WhatsApp : indispensables pour ecrire a un client hors de la fenetre de 24 h
 * (relance, suivi de commande, rappel de rendez-vous). Chaque modele est approuve par WhatsApp avant usage.
 */
class TemplateController extends Controller
{
    public function index(Request $request, Bot $bot)
    {
        $workspace = $request->user()->currentWorkspace();
        $channel = $this->channel($bot);

        return view('templates.index', [
            'bot' => $bot,
            'channel' => $channel,
            'allowed' => $workspace->hasFeature('templates'),
            'templates' => $channel ? WhatsAppTemplate::where('channel_id', $channel->id)->orderBy('name')->get() : collect(),
            'canCreate' => $channel && $channel->type === Channel::WHATSAPP_META,
        ]);
    }

    public function store(Request $request, Bot $bot, TemplateManager $manager): RedirectResponse
    {
        $channel = $this->requireChannel($request, $bot);
        $definition = $this->definition($request);

        try {
            $template = $manager->create($channel, $definition);
        } catch (GatewayException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        AuditLog::record('template.created', $template->name, ['bot' => $bot->id]);

        return back()->with('status', 'Modèle envoyé à WhatsApp pour approbation. Cela prend en général quelques minutes à quelques heures : cliquez sur « Actualiser les statuts ».');
    }

    public function sync(Request $request, Bot $bot, TemplateManager $manager): RedirectResponse
    {
        $channel = $this->requireChannel($request, $bot);

        try {
            $count = $manager->sync($channel);
        } catch (GatewayException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', "{$count} modèle(s) synchronisé(s) avec WhatsApp.");
    }

    public function destroy(Request $request, Bot $bot, WhatsAppTemplate $template, TemplateManager $manager): RedirectResponse
    {
        $this->requireChannel($request, $bot);
        abort_unless($template->channel_id === $this->channel($bot)?->id, 404);

        try {
            $manager->delete($template);
        } catch (GatewayException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Modèle supprimé.');
    }

    private function channel(Bot $bot): ?Channel
    {
        return Channel::where('bot_id', $bot->id)
            ->whereIn('type', [Channel::WHATSAPP_META, Channel::WHATSAPP_TWILIO])
            ->where('status', Channel::ACTIVE)
            ->latest()->first();
    }

    private function requireChannel(Request $request, Bot $bot): Channel
    {
        abort_unless($request->user()->currentWorkspace()->hasFeature('templates'), 403, 'Les modèles WhatsApp sont inclus dans les offres Pro et Business.');

        return $this->channel($bot) ?? abort(422, 'Aucun canal WhatsApp actif pour cet assistant.');
    }

    /**
     * Valide un modele selon les regles de Meta : variables numerotees {{1}}, {{2}}..., un exemple par variable,
     * corps de 1024 caracteres, un modele ne commence ni ne finit par une variable.
     *
     * @return array<string,mixed>
     */
    private function definition(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9_]+$/'],
            'language' => ['required', Rule::in(array_keys(WhatsAppTemplate::LANGUAGES))],
            'category' => ['required', Rule::in(['UTILITY', 'MARKETING'])],
            'body' => ['required', 'string', 'max:1024'],
            'body_examples' => ['nullable', 'array'],
            'body_examples.*' => ['nullable', 'string', 'max:100'],
            'header' => ['nullable', 'string', 'max:60'],
            'footer' => ['nullable', 'string', 'max:60'],
            'quick_replies' => ['nullable', 'string', 'max:200'],
            'url_text' => ['nullable', 'string', 'max:25'],
            'url' => ['nullable', 'url:https', 'max:200'],
            'phone_text' => ['nullable', 'string', 'max:25'],
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^\+[0-9]{6,15}$/'],
        ], [
            'name.regex' => 'Le nom ne peut contenir que des minuscules, des chiffres et des tirets bas (ex. suivi_commande).',
            'phone.regex' => 'Le numéro doit être au format international, par exemple +22670000000.',
        ]);

        $body = $data['body'];
        $count = MetaCloudGateway::countVariables($body);

        preg_match_all('/\{\{(\d+)\}\}/', $body, $m);
        $numbers = array_unique(array_map('intval', $m[1]));
        sort($numbers);
        if ($numbers !== [] && $numbers !== range(1, $count)) {
            throw ValidationException::withMessages(['body' => 'Les variables doivent être numérotées dans l\'ordre : {{1}}, {{2}}, {{3}}...']);
        }
        if ($count > 0 && (preg_match('/^\s*\{\{\d+\}\}/', $body) || preg_match('/\{\{\d+\}\}\s*$/', $body))) {
            throw ValidationException::withMessages(['body' => 'Le message ne peut pas commencer ni se terminer par une variable : ajoutez un mot avant ou après.']);
        }

        $examples = array_values(array_map('trim', $data['body_examples'] ?? []));
        for ($i = 0; $i < $count; $i++) {
            if (($examples[$i] ?? '') === '') {
                throw ValidationException::withMessages(['body_examples' => 'Renseignez un exemple pour chaque variable : WhatsApp l\'exige pour approuver le modèle.']);
            }
        }

        $buttons = [];
        foreach (array_slice(array_filter(array_map('trim', preg_split('/\R/u', (string) ($data['quick_replies'] ?? '')) ?: [])), 0, 3) as $text) {
            $buttons[] = ['type' => 'QUICK_REPLY', 'text' => mb_substr($text, 0, 25)];
        }
        if (! empty($data['url_text']) && ! empty($data['url'])) {
            $buttons[] = ['type' => 'URL', 'text' => $data['url_text'], 'url' => $data['url']];
        }
        if (! empty($data['phone_text']) && ! empty($data['phone'])) {
            $buttons[] = ['type' => 'PHONE_NUMBER', 'text' => $data['phone_text'], 'phone_number' => $data['phone']];
        }

        return [
            'name' => $data['name'],
            'language' => $data['language'],
            'category' => $data['category'],
            'body' => $body,
            'body_examples' => array_slice($examples, 0, max($count, 0)),
            'header' => $data['header'] ?? null,
            'footer' => $data['footer'] ?? null,
            'buttons' => $buttons,
        ];
    }
}
