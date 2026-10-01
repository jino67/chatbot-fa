<?php

namespace App\Support;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Source;
use App\Models\User;

/**
 * La mise en route d'un assistant, calculée à partir de ce qui existe réellement (rien n'est stocké : la progression ne
 * peut donc pas être fausse, et la personne reprend où elle s'était arrêtée, sur n'importe quel appareil).
 */
final class Onboarding
{
    /**
     * @return array{steps:list<array{key:string,label:string,hint:string,done:bool,url:string}>, done:int, total:int, percent:int, next:?array}
     */
    public static function for(Bot $bot, ?User $user = null): array
    {
        $workspace = $bot->workspace ?? $user?->currentWorkspace();
        $profile = $bot->profile ?? [];

        $described = trim((string) ($profile['description'] ?? '')) !== '' || trim((string) ($profile['offers'] ?? '')) !== '';
        $learned = Source::withoutGlobalScopes()->where('bot_id', $bot->id)->where('status', Source::READY)->exists();
        $tested = Conversation::withoutGlobalScopes()->where('bot_id', $bot->id)->where('channel', 'playground')->exists();
        $live = Conversation::withoutGlobalScopes()->where('bot_id', $bot->id)->whereIn('channel', ['web', 'whatsapp', 'api'])->exists()
            || $bot->channels()->withoutGlobalScopes()->where('status', 'active')->exists();
        $alerts = (bool) ($workspace?->alertSettings()['chosen'] ?? false);

        $steps = [
            ['key' => 'profile', 'label' => 'Décrire votre entreprise', 'hint' => 'Activité, horaires, produits : la consigne devient précise.', 'done' => $described, 'url' => route('instructions.edit', $bot)],
            ['key' => 'knowledge', 'label' => 'Ajouter vos connaissances', 'hint' => 'Documents, catalogue, site web, photos, questions / réponses.', 'done' => $learned, 'url' => route('sources.index', $bot)],
            ['key' => 'test', 'label' => 'Tester comme un client', 'hint' => 'Posez vos questions les plus fréquentes dans la zone de test.', 'done' => $tested, 'url' => route('playground.show', $bot)],
            ['key' => 'live', 'label' => 'Mettre en ligne', 'hint' => 'Sur votre site en une ligne de code, ou sur WhatsApp avec notre équipe.', 'done' => $live, 'url' => route('channels.show', $bot)],
            ['key' => 'alerts', 'label' => 'Choisir vos alertes', 'hint' => 'Soyez prévenu d\'une commande ou d\'un client qui attend.', 'done' => $alerts, 'url' => route('alerts.edit')],
        ];

        $done = count(array_filter($steps, fn ($s) => $s['done']));

        return [
            'steps' => $steps,
            'done' => $done,
            'total' => count($steps),
            'percent' => (int) round(100 * $done / count($steps)),
            'next' => collect($steps)->firstWhere('done', false),
        ];
    }
}
