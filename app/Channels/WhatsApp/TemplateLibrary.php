<?php

namespace App\Channels\WhatsApp;

use App\Models\Bot;
use App\Models\Channel;
use App\Models\WhatsAppTemplate;

/**
 * Bibliothèque de modèles prêts à l'emploi (config/whatsapp_templates.php) : lecture, aperçus, et fabrication de la
 * définition envoyée au fournisseur (nom de l'entreprise inscrit dans le texte, boutons « lien » et « appel » retirés
 * quand l'entreprise n'a pas de site ou de numéro).
 */
class TemplateLibrary
{
    /** Langues de la bibliothèque (le français est complet, l'anglais couvre les modèles les plus courants). */
    public const LANGUAGES = ['fr', 'en'];

    /** @return array<string,string> */
    public function groups(): array
    {
        return config('whatsapp_templates.groups', []);
    }

    /** @return array<string,array{label:string, description:string, keys:list<string>}> */
    public function packs(): array
    {
        return config('whatsapp_templates.packs', []);
    }

    /** Clés d'un paquet, précédées des modèles « essentiel » (sans doublon). @return list<string> */
    public function packKeys(string $pack): array
    {
        $packs = $this->packs();
        if (! isset($packs[$pack])) {
            return [];
        }

        return array_values(array_unique([...($packs['essentiel']['keys'] ?? []), ...$packs[$pack]['keys']]));
    }

    /** Paquet conseillé pour le secteur d'un assistant (« autre » : l'essentiel). */
    public function packFor(?string $sector): string
    {
        return config('whatsapp_templates.pack_by_sector.'.($sector ?: 'autre'), 'essentiel');
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys(config('whatsapp_templates.templates', []));
    }

    public function has(string $key, string $language = 'fr'): bool
    {
        return isset(config('whatsapp_templates.templates', [])[$key][$language]);
    }

    /** Langue de bibliothèque la plus proche de celle de l'assistant (français par défaut). */
    public function languageFor(?string $botLanguage): string
    {
        return in_array($botLanguage, self::LANGUAGES, true) ? $botLanguage : 'fr';
    }

    /**
     * Les modèles dans une langue, prêts pour l'écran : groupe, titre, catégorie, texte avec exemples, boutons.
     *
     * @param  array<string,string>  $context
     * @return array<string,array<string,mixed>>
     */
    public function items(string $language, array $context): array
    {
        $items = [];

        foreach (config('whatsapp_templates.templates', []) as $key => $template) {
            if (! isset($template[$language])) {
                continue;
            }

            $definition = $this->definition($key, $language, $context);
            $items[$key] = [
                'key' => $key,
                'group' => $template['group'],
                'title' => $template['title'],
                'category' => $template['category'],
                'body' => $definition['body'],
                'preview' => $this->fill($definition['body'], $definition['body_examples']),
                'vars' => $template[$language]['vars'] ?? [],
                'footer' => $definition['footer'],
                'buttons' => $definition['buttons'],
                'languages' => array_values(array_filter(self::LANGUAGES, fn ($l) => isset($template[$l]))),
            ];
        }

        return $items;
    }

    /**
     * La définition d'un modèle, telle que TemplateManager::create l'attend.
     *
     * @param  array<string,string>  $context  company, site, phone
     * @return array{name:string, language:string, category:string, body:string, body_examples:list<string>, header:?string, footer:?string, buttons:list<array<string,string>>}
     */
    public function definition(string $key, string $language, array $context): array
    {
        $template = config('whatsapp_templates.templates.'.$key);
        $text = $template[$language] ?? null;

        if (! $template || ! $text) {
            throw new \InvalidArgumentException("Modèle inconnu : {$key} ({$language}).");
        }

        $buttons = [];
        foreach ($text['buttons'] ?? [] as $button) {
            $button = array_map(fn ($v) => $this->bake((string) $v, $context), $button);

            // Un bouton lien ou appel sans adresse ou sans numéro n'a pas de sens : il disparaît.
            if (($button['type'] === 'URL' && ($button['url'] ?? '') === '') || ($button['type'] === 'PHONE_NUMBER' && ($button['phone_number'] ?? '') === '')) {
                continue;
            }
            $button['text'] = mb_substr($button['text'], 0, 25);
            $buttons[] = $button;
        }

        $footer = isset($text['footer']) ? trim($this->bake($text['footer'], $context)) : null;

        return [
            'name' => $key,
            'language' => $language,
            'category' => $template['category'],
            'body' => $this->bake($text['body'], $context),
            'body_examples' => $text['examples'] ?? [],
            'header' => null,
            'footer' => $footer !== null && $footer !== '' ? mb_substr($footer, 0, 60) : null,
            'buttons' => $buttons,
        ];
    }

    /**
     * Ce que le client voit pour chaque modèle de la bibliothèque : sa version déjà créée sur ce canal, s'il y en a une.
     *
     * @return array<string,WhatsAppTemplate> nom => modèle
     */
    public function existing(Channel $channel, string $language): array
    {
        return WhatsAppTemplate::withoutGlobalScopes()
            ->where('channel_id', $channel->id)->where('language', $language)
            ->get()->keyBy('name')->all();
    }

    /**
     * @return array<string,string> company, site, phone
     */
    public function context(Bot $bot, ?Channel $channel = null): array
    {
        $site = trim((string) $bot->profile('website'));
        if ($site !== '' && ! preg_match('#^https?://#i', $site)) {
            $site = 'https://'.$site;
        }

        $phone = preg_replace('/[^\d+]/', '', (string) ($channel?->display_phone ?: $bot->profile('phone')));
        $phone = $phone !== '' && ! str_starts_with($phone, '+') ? '+'.$phone : $phone;

        return [
            'company' => trim($bot->company()),
            'site' => filter_var($site, FILTER_VALIDATE_URL) && str_starts_with($site, 'https://') ? $site : '',
            'phone' => preg_match('/^\+[0-9]{6,15}$/', $phone) ? $phone : '',
        ];
    }

    /** Remplace {{1}}, {{2}}... par des valeurs (aperçu). @param list<string> $values */
    public function fill(string $text, array $values): string
    {
        return preg_replace_callback('/\{\{(\d+)\}\}/', fn ($m) => $values[(int) $m[1] - 1] ?? $m[0], $text);
    }

    /** @param array<string,string> $context */
    private function bake(string $text, array $context): string
    {
        return str_replace(
            ['{company}', '{site}', '{phone}'],
            [$context['company'] ?? '', $context['site'] ?? '', $context['phone'] ?? ''],
            $text
        );
    }
}
