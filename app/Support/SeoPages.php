<?php

namespace App\Support;

use App\Models\Plan;
use App\Services\PlatformSettings;
use Illuminate\Support\Collection;

/**
 * Pages de contenu du site public (config/seo.php) : lecture, résolution des jetons de prix et de limites, sections
 * tirées des métiers (config/sectors.php) et données structurées. Les chiffres viennent des offres en base : quand
 * l'administrateur change un prix ou un quota, les pages suivent sans toucher au code.
 */
final class SeoPages
{
    /** @var Collection<string,Plan>|null */
    private ?Collection $plans = null;

    public function __construct(private readonly PlatformSettings $settings) {}

    /** @return array<string,array<string,mixed>> toutes les pages, indexées par clé */
    public static function all(): array
    {
        $pages = [];
        foreach ((array) config('seo.pages', []) as $key => $page) {
            $pages[$key] = $page + ['key' => $key];
        }

        return $pages;
    }

    /** @return array<string,mixed>|null */
    public static function find(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    public static function url(string $key): string
    {
        return url(self::all()[$key]['path']);
    }

    /** @return array<string,list<array<string,mixed>>> pages regroupées par type, dans l'ordre de config/seo.php */
    public static function grouped(): array
    {
        $groups = [];
        foreach (array_keys((array) config('seo.groups', [])) as $type) {
            $groups[$type] = array_values(array_filter(self::all(), fn ($p) => $p['type'] === $type));
        }

        return $groups;
    }

    /** Date de la mise à jour la plus récente (AAAA-MM-JJ), pour le plan du site. */
    public static function lastModified(): string
    {
        return collect(self::all())->pluck('updated')->max() ?: now()->toDateString();
    }

    /* =========================== Une page, prête à afficher =========================== */

    /** @return array<string,mixed> */
    public function page(string $key): array
    {
        $page = self::find($key) ?? abort(404);
        $currency = $page['currency'] ?? Currency::default();
        $r = fn (string $text) => $this->resolve($text, $currency);

        $sections = array_merge($page['sections'] ?? [], $this->sectorSections($page), $page['type'] === 'sector' ? [$this->priceSection()] : []);
        $sections = array_map(fn (array $s) => [
            'title' => $r($s['title']),
            'text' => array_map($r, $s['text'] ?? []),
            'list' => array_map($r, $s['list'] ?? []),
            'steps' => array_map($r, $s['steps'] ?? []),
        ], $sections);

        $resolved = [
            'key' => $key,
            'type' => $page['type'],
            'label' => $page['label'],
            'url' => self::url($key),
            'updated' => $page['updated'],
            'title' => $r($page['title']),
            'description' => $r($page['description']),
            'h1' => $r($page['h1']),
            'lead' => $r($page['lead']),
            'facts' => array_map($r, $page['facts'] ?? []),
            'dialog' => $page['dialog'] ?? null,
            'sections' => $sections,
            'faq' => array_map(fn ($qa) => [$r($qa[0]), $r($qa[1])], $page['faq'] ?? []),
            'cta' => array_map($r, $page['cta'] ?? ['Essayez avec vos propres documents', 'Essai gratuit de {trial_days} jours, sans carte bancaire.']),
            'related' => $this->related($page['related'] ?? []),
        ];
        $resolved['graph'] = $this->graph($resolved);

        return $resolved;
    }

    /** @return array<string,mixed> page « Ressources » : toutes les pages par groupe */
    public function hub(): array
    {
        $groups = [];
        foreach (self::grouped() as $type => $pages) {
            $groups[$type] = [
                'title' => config("seo.groups.{$type}"),
                'pages' => array_map(fn ($p) => ['label' => $p['label'], 'url' => self::url($p['key']), 'description' => $this->resolve($p['description'], $p['currency'] ?? Currency::default())], $pages),
            ];
        }

        $brand = $this->brandName();
        $data = [
            'title' => 'Ressources : guides et solutions pour un chatbot WhatsApp',
            'description' => "Guides pratiques, solutions et exemples par métier et par pays pour répondre à vos clients sur WhatsApp et sur votre site avec {$brand}.",
            'groups' => $groups,
        ];
        $data['graph'] = [
            $this->breadcrumbs([['Ressources', route('seo.hub')]]),
            ['@type' => 'CollectionPage', 'name' => $data['title'], 'description' => $data['description'], 'url' => route('seo.hub'), 'inLanguage' => 'fr', 'isPartOf' => $this->webSite(),
                'mainEntity' => ['@type' => 'ItemList', 'itemListElement' => collect($groups)->pluck('pages')->flatten(1)->values()->map(fn ($p, $i) => ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $p['label'], 'url' => $p['url']])->all()]],
        ];

        return $data;
    }

    /* ================================ Jetons ================================ */

    /**
     * Remplace {brand}, {trial_days}, {price:offre} et {limit:offre:quota}. Un jeton inconnu est laissé tel quel
     * (les tests le signalent) ; un prix d'offre introuvable devient « voir les tarifs ».
     */
    public function resolve(string $text, ?string $currency = null): string
    {
        $currency ??= Currency::default();

        return preg_replace_callback('/\{(brand|trial_days|price:[a-z0-9_-]+|limit:[a-z0-9_-]+:[a-z_]+)\}/', function (array $m) use ($currency) {
            $token = $m[1];
            if ($token === 'brand') {
                return $this->brandName();
            }
            if ($token === 'trial_days') {
                return (string) ($this->plans()->first(fn (Plan $p) => $p->hasTrial())?->trial_days ?? 14);
            }
            $parts = explode(':', $token);
            $plan = $this->plans()->get($parts[1]);

            if ($parts[0] === 'price') {
                if (! $plan) {
                    return '(voir les tarifs)';
                }
                $code = $plan->currencyFor($currency);

                return Currency::format($plan->priceIn($code) ?? 0, $code);
            }

            return $plan ? number_format($plan->limit($parts[2]), 0, ',', "\u{202F}") : '';
        }, $text);
    }

    /** @return Collection<string,Plan> offres publiques, par identifiant */
    private function plans(): Collection
    {
        return $this->plans ??= Plan::forBusiness()->where('is_public', true)->get()->keyBy('slug');
    }

    private function brandName(): string
    {
        return $this->settings->brand()['name'];
    }

    /* ============================ Sections générées ============================ */

    /** @return list<array<string,mixed>> sections tirées de la fiche du métier, pour les pages « par métier » */
    private function sectorSections(array $page): array
    {
        $sector = $page['type'] === 'sector' ? config('sectors.'.($page['sector'] ?? '')) : null;
        if (! $sector) {
            return [];
        }

        $sections = [['title' => 'Ce que fait votre assistant', 'text' => ['Métiers concernés : '.lcfirst($sector['exemples']).'.'], 'list' => $sector['objectifs']]];

        if (! empty($sector['questions'])) {
            $sections[] = ['title' => 'Des réponses rapides dès l\'ouverture', 'text' => ['À l\'ouverture de la discussion, l\'assistant propose ces réponses rapides. Vous pouvez les modifier.'], 'list' => $sector['questions']];
        }
        if (! empty($sector['transfert'])) {
            $sections[] = ['title' => 'Quand il vous passe la main', 'text' => array_map(fn ($t) => 'Il vous prévient notamment pour : '.lcfirst(rtrim($t, '.')).'.', $sector['transfert'])];
        }

        return $sections;
    }

    /** @return array<string,mixed> */
    private function priceSection(): array
    {
        return ['title' => 'Combien ça coûte ?', 'text' => [
            'L\'essai gratuit dure {trial_days} jours, sans carte bancaire. L\'offre Bon plan ({price:bonplan} par mois) donne un assistant sur votre site et sur WhatsApp ; l\'offre Pro ({price:pro} par mois) ajoute {limit:pro:bots} assistants, pour plusieurs points de vente.',
        ]];
    }

    /** @param list<string> $keys @return list<array{label:string,url:string,type:string}> */
    private function related(array $keys): array
    {
        $related = [];
        foreach ($keys as $key) {
            if ($page = self::find($key)) {
                $related[] = ['label' => $page['label'], 'url' => self::url($key), 'type' => $page['type']];
            }
        }

        return $related;
    }

    /* ============================ Données structurées ============================ */

    /** @param array<string,mixed> $p page résolue @return list<array<string,mixed>> */
    private function graph(array $p): array
    {
        $brand = $this->brandName();
        $blocks = [$this->breadcrumbs([['Ressources', route('seo.hub')], [$p['label'], $p['url']]])];

        if ($p['type'] === 'guide') {
            $blocks[] = [
                '@type' => 'Article',
                'headline' => $p['h1'],
                'description' => $p['description'],
                'inLanguage' => 'fr',
                'mainEntityOfPage' => $p['url'],
                'image' => url('og-image.png'),
                'datePublished' => $p['updated'],
                'dateModified' => $p['updated'],
                'author' => ['@type' => 'Organization', 'name' => $brand, 'url' => url('/')],
                'publisher' => $this->organization(),
            ];
        } else {
            $blocks[] = [
                '@type' => 'WebPage',
                'name' => $p['h1'],
                'description' => $p['description'],
                'url' => $p['url'],
                'inLanguage' => 'fr',
                'dateModified' => $p['updated'],
                'isPartOf' => $this->webSite(),
                'about' => ['@type' => 'SoftwareApplication', 'name' => $brand, 'applicationCategory' => 'BusinessApplication', 'operatingSystem' => 'Web'],
            ];
        }

        if ($p['faq']) {
            $blocks[] = [
                '@type' => 'FAQPage',
                'mainEntity' => array_map(fn ($qa) => ['@type' => 'Question', 'name' => $qa[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $qa[1]]], $p['faq']),
            ];
        }

        return $blocks;
    }

    /** @param list<array{0:string,1:string}> $trail fil d'Ariane sans l'accueil @return array<string,mixed> */
    private function breadcrumbs(array $trail): array
    {
        $items = array_merge([['Accueil', url('/')]], $trail);

        return [
            '@type' => 'BreadcrumbList',
            'itemListElement' => array_map(fn ($item, $i) => ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $item[0], 'item' => $item[1]], $items, array_keys($items)),
        ];
    }

    /** @return array<string,string> */
    private function webSite(): array
    {
        return ['@type' => 'WebSite', 'name' => $this->brandName(), 'url' => url('/')];
    }

    /** @return array<string,mixed> */
    public function organization(): array
    {
        return ['@type' => 'Organization', 'name' => $this->brandName(), 'url' => url('/'), 'logo' => ['@type' => 'ImageObject', 'url' => url('icon-512.png'), 'width' => 512, 'height' => 512]];
    }
}
