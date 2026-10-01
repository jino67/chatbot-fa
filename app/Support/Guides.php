<?php

namespace App\Support;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use League\CommonMark\MarkdownConverter;
use League\CommonMark\Normalizer\TextNormalizerInterface;

/**
 * Les guides de la plateforme : quatre documents Markdown dans resources/guides/, lus à la volée pour les pages d'aide
 * du site et les écrans du personnel, et mis en page en PDF par `php artisan guides:build`.
 *
 *   client       guide d'utilisation pour les entreprises clientes (public, avec PDF)
 *   developpeur  widget, API développeur, API du widget (public, avec PDF)
 *   admin        équipe de la plateforme (interne)
 *   super-admin  propriétaire de la plateforme (interne, super admin seulement)
 *
 * Aucun prix ni quota n'est écrit dans ces textes : les offres changent, la page des tarifs fait foi.
 */
final class Guides
{
    /** Mention de signature, une seule fois ici : page d'aide, PDF et guides internes la reprennent. */
    public const SIGNATURE = 'Tout droit de Kouma';

    /** @return array<string,array{title:string, subtitle:string, file:string, public:bool, pdf:string, route:?string, description:string}> */
    public static function all(): array
    {
        return [
            'client' => [
                'title' => "Guide d'utilisation",
                'subtitle' => 'Pour les entreprises qui utilisent Kouma',
                'file' => 'client.md',
                'public' => true,
                'pdf' => 'Kouma-Guide-Client.pdf',
                'route' => 'help.client',
                'description' => "Créer votre assistant, lui donner vos documents, le mettre sur votre site et sur WhatsApp, suivre vos clients : le guide complet, pas à pas.",
            ],
            'developpeur' => [
                'title' => 'Guide du développeur',
                'subtitle' => 'Widget de chat, API développeur et API du widget',
                'file' => 'developpeur.md',
                'public' => true,
                'pdf' => 'Kouma-Guide-Developpeur.pdf',
                'route' => 'help.developer',
                'description' => "Installer le widget, appeler l'assistant depuis votre application avec une clé d'API, gérer les erreurs et les quotas : le guide technique.",
            ],
            'admin' => [
                'title' => "Guide de l'équipe",
                'subtitle' => "Pour l'équipe qui accompagne les clients",
                'file' => 'admin.md',
                'public' => false,
                'pdf' => 'Kouma-Guide-Equipe.pdf',
                'route' => null,
                'description' => "Gérer les espaces clients, enregistrer les paiements, activer WhatsApp et accompagner chaque entreprise.",
            ],
            'super-admin' => [
                'title' => 'Guide du super admin',
                'subtitle' => 'Pour le propriétaire de la plateforme',
                'file' => 'super-admin.md',
                'public' => false,
                'pdf' => 'Kouma-Guide-Super-Admin.pdf',
                'route' => null,
                'description' => "Offres, fournisseurs d'IA, coûts, paramètres, mise en ligne et exploitation de la plateforme.",
            ],
        ];
    }

    public static function exists(string $key): bool
    {
        return isset(self::all()[$key]);
    }

    public static function path(string $key): string
    {
        return resource_path('guides/'.self::all()[$key]['file']);
    }

    /** Le PDF d'un guide : public/documents/ pour les guides publics, resources/guides/pdf/ pour les guides internes. */
    public static function pdfPath(string $key): string
    {
        $guide = self::all()[$key];

        return $guide['public'] ? public_path('documents/'.$guide['pdf']) : resource_path('guides/pdf/'.$guide['pdf']);
    }

    /** Adresse publique du PDF (guides publics seulement, et seulement s'il a été fabriqué). */
    public static function pdfUrl(string $key): ?string
    {
        $guide = self::all()[$key];

        return $guide['public'] && is_file(self::pdfPath($key)) ? url('documents/'.$guide['pdf']) : null;
    }

    /** Date de dernière mise à jour du texte (jamais dans le futur). */
    public static function updated(string $key): string
    {
        return date('Y-m-d', min(filemtime(self::path($key)), time()));
    }

    /**
     * Le guide en HTML, avec son sommaire.
     *
     * @return array{title:string, html:string, toc:list<array{id:string, title:string, level:int}>, words:int, updated:string}
     */
    public static function render(string $key): array
    {
        $markdown = (string) file_get_contents(self::path($key));
        $html = self::converter()->convert($markdown)->getContent();

        // Premier titre : celui du document, repris par la mise en page. Il ne reste pas dans le corps.
        $title = self::all()[$key]['title'];
        if (preg_match('/^<h1[^>]*>(.*?)<\/h1>\s*/s', $html, $m)) {
            $title = trim(strip_tags($m[1])) ?: $title;
            $html = substr($html, strlen($m[0]));
        }

        $html = self::typography(self::callouts($html));

        preg_match_all('/<h([23]) id="([^"]+)">(.*?)<\/h\1>/s', $html, $headings, PREG_SET_ORDER);
        $toc = array_map(fn ($h) => [
            'id' => $h[2],
            'title' => trim(preg_replace('/\s+/u', ' ', strip_tags(preg_replace('/<a [^>]*class="guide-anchor"[^>]*>.*?<\/a>/s', '', $h[3])))),
            'level' => (int) $h[1],
        ], $headings);

        return [
            'title' => $title,
            'html' => $html,
            'toc' => $toc,
            'words' => str_word_count(strip_tags($html), 0, 'àâäçéèêëîïôöùûüÿœæÀÂÄÇÉÈÊËÎÏÔÖÙÛÜŸŒÆ'),
            'updated' => self::updated($key),
        ];
    }

    private static function converter(): MarkdownConverter
    {
        $environment = new Environment([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'heading_permalink' => [
                'html_class' => 'guide-anchor',
                'id_prefix' => '',
                'fragment_prefix' => '',
                'apply_id_to_heading' => true,
                'insert' => 'after',
                'min_heading_level' => 2,
                'max_heading_level' => 3,
                'title' => 'Lien vers cette section',
                'symbol' => '#',
                'aria_hidden' => true,
            ],
            'slug_normalizer' => ['instance' => new class implements TextNormalizerInterface
            {
                /** « Créer votre assistant » devient « creer-votre-assistant » : des adresses sans accents. */
                public function normalize(string $text, array $context = []): string
                {
                    $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', Text::fold($text)), '-');

                    return $slug !== '' ? $slug : 'section';
                }
            }],
        ]);
        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new GithubFlavoredMarkdownExtension);
        $environment->addExtension(new HeadingPermalinkExtension);

        return new MarkdownConverter($environment);
    }

    /**
     * Typographie française : espace insécable avant « : ; ! ? » et à l'intérieur des guillemets « », pour qu'un signe
     * ne se retrouve jamais seul au début d'une ligne. Les blocs de code et les balises ne sont pas touchés.
     */
    private static function typography(string $html): string
    {
        $parts = preg_split('#(<pre.*?</pre>|<code.*?</code>|<[^>]+>)#s', $html, -1, PREG_SPLIT_DELIM_CAPTURE);

        foreach ($parts as $i => $part) {
            if ($part === '' || $part[0] === '<') {
                continue;
            }
            $part = preg_replace('/(\S) ([:;!?»])/u', "$1\u{00A0}$2", $part);
            $parts[$i] = preg_replace('/« /u', "«\u{00A0}", $part);
        }

        return implode('', $parts);
    }

    /** « > **Astuce :** ... » devient un encadré : À savoir, Astuce, Attention, Important. */
    private static function callouts(string $html): string
    {
        $kinds = ['À savoir' => 'note', 'Astuce' => 'tip', 'Attention' => 'warning', 'Important' => 'warning', 'Exemple' => 'example', 'On s\'occupe de tout' => 'help'];

        $html = preg_replace_callback(
            '/<blockquote>\s*<p><strong>('.implode('|', array_map(fn ($k) => preg_quote($k, '/'), array_keys($kinds))).')\s*:?\s*<\/strong>/u',
            fn ($m) => '<blockquote class="callout callout-'.$kinds[$m[1]].'"><p><strong class="callout-title">'.$m[1].'</strong>',
            $html
        );

        return preg_replace_callback('/(<strong class="callout-title">[^<]+<\/strong>)\s*(\p{Ll})/u', fn ($m) => $m[1].' '.mb_strtoupper($m[2]), $html);
    }
}
