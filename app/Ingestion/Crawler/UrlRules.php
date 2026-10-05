<?php

namespace App\Ingestion\Crawler;

/**
 * Quelles pages valent la peine d'être lues, et dans quel ordre. Une boutique en ligne compte souvent des dizaines de
 * pages qui ne disent rien à un client (formulaires de commande, panier, connexion) : elles ne comptent pas dans la
 * limite de pages de l'offre et ne sont jamais lues. Le reste est classé : les pages d'information (contact, livraison,
 * FAQ) d'abord, puis les catégories, puis les produits, puis le reste, les articles de blog en dernier.
 */
final class UrlRules
{
    /** Segments d'adresse qui désignent une page sans contenu utile pour répondre à un client. */
    private const UTILITY = '/(?:^|\/)(?:login|logout|log-in|log-out|signin|sign-in|signup|sign-up|register|registration|connexion|deconnexion|inscription|mon-compte|compte|account|my-account|profile|profil|panier|cart|basket|checkout|paiement\/valider|commande|commandes|commander|order|orders|my-orders|suivi|suivre|track|tracking|wishlist|favoris|favorites|compare|comparer|search|recherche|password|forgot|reset|verify|unsubscribe|desinscription|admin|dashboard|wp-admin|wp-login|wp-json|cgi-bin|feed|rss|amp|print|api|ajax|callback|oauth|thank-you|merci|confirmation)(?:\/|\.|$)/i';

    /** Paramètres d'adresse qui ne changent que l'affichage ou déclenchent une action. */
    private const NOISY_QUERY = '/(?:^|&)(?:add-to-cart|add_to_cart|orderby|order|sort|sortby|filter|filters|view|display|layout|ref|replytocom|share|print|lang|currency|currencie|session|sid|token|action|redirect|returnurl|page_size|per_page|limit)=/i';

    private const INFO = '/(?:^|\/)(?:contact|contactez-nous|nous-contacter|a-propos|apropos|about|about-us|qui-sommes-nous|notre-histoire|faq|faqs|aide|help|livraison|delivery|expedition|shipping|paiement|payment|retour|retours|returns|cgv|cgu|conditions[\w-]*|politique[\w-]*|mentions-legales|horaires|tarifs|prix|pricing|services|nos-services|boutique-physique|magasin|points?-de-vente|adresse)(?:\/|\.|$)/i';

    private const CATEGORY = '/(?:^|\/)(?:categories?|categorie|collections?|gammes?|rayons?|shop|boutique|catalogue|catalog|produits|products|nos-produits|c)(?:\/|\.|$)/i';

    private const PRODUCT = '/(?:^|\/)(?:produits?|products?|article|articles|item|items|p|pd|shop\/[^\/]+)\/[^\/]+/i';

    private const BLOG = '/(?:^|\/)(?:blog|news|actualites?|actus?|articles?-blog|journal|presse|evenements?|events?)(?:\/|\.|$)/i';

    /**
     * Les réseaux sociaux ne se lisent pas automatiquement (conditions d'utilisation de Meta).
     *
     * @throws UnsafeUrlException
     */
    public static function assertCrawlable(string $url): void
    {
        $host = Url::host($url);

        foreach (config('platform.crawler.blocked_hosts') as $blocked) {
            if ($host === $blocked || str_ends_with($host, '.'.$blocked)) {
                throw new UnsafeUrlException(
                    "Les réseaux sociaux ne peuvent pas être explorés automatiquement (conditions d'utilisation de Meta). "
                    .'Utilisez « Page Facebook » pour coller le contenu de la page.'
                );
            }
        }
    }

    /** Raison pour laquelle une adresse n'est pas lue, ou null si elle l'est. */
    public static function skipReason(string $url): ?string
    {
        $path = rawurldecode((string) parse_url($url, PHP_URL_PATH));
        $query = (string) parse_url($url, PHP_URL_QUERY);

        if (Url::looksLikeBinary($url)) {
            return 'fichier';
        }
        if (preg_match(self::UTILITY, $path)) {
            return 'page utilitaire';
        }
        if ($query !== '' && preg_match(self::NOISY_QUERY, $query)) {
            return 'variante d\'affichage';
        }

        return null;
    }

    /** Clé de comparaison : « Duo-visage » et « duo-visage/ », avec ou sans www, sont la même page. */
    public static function dedupeKey(string $url): string
    {
        $parts = parse_url(Url::normalize($url)) ?: [];
        $host = strtolower((string) ($parts['host'] ?? ''));
        $host = str_starts_with($host, 'www.') ? substr($host, 4) : $host;
        $path = mb_strtolower(rawurldecode((string) ($parts['path'] ?? '/')));
        $path = preg_replace('#/(?:index|default)\.(?:html?|php)$#', '/', $path);
        $path = $path === '/' ? '/' : rtrim($path, '/');

        return $host.$path.(isset($parts['query']) ? '?'.$parts['query'] : '');
    }

    /** Plus le nombre est petit, plus la page est lue tôt. La page de départ passe toujours en premier. */
    public static function priority(string $url): int
    {
        $path = rawurldecode((string) parse_url($url, PHP_URL_PATH));

        return match (true) {
            $path === '' || $path === '/' => 0,
            (bool) preg_match(self::INFO, $path) => 1,
            (bool) preg_match(self::BLOG, $path) => 5,
            (bool) preg_match(self::PRODUCT, $path) => 3,
            (bool) preg_match(self::CATEGORY, $path) => 2,
            default => 4,
        };
    }
}
