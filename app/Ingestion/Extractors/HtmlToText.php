<?php

namespace App\Ingestion\Extractors;

use App\Ingestion\Crawler\Url;
use App\Support\Text;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * HTML -> texte Markdown simplifie (titres, listes, tableaux) + liens sortants.
 * Le bruit (scripts, menus, formulaires, bandeaux cookies) est retire ; le pied de page est conserve
 * car il porte souvent l'adresse, les horaires et le telephone.
 */
class HtmlToText
{
    private const NOISE = '//script|//style|//noscript|//svg|//iframe|//nav|//form|//button|//select|//template|//canvas|//video|//audio|//*[@aria-hidden="true"]|//*[@hidden]';

    private const NOISE_CLASS = '/cookie|consent|gdpr|popup|modal|newsletter|breadcrumb|sr-only/i';

    /** @return array{title:?string, text:string, links:list<string>} */
    public function convert(string $html, ?string $baseUrl = null, ?PageTransformer $transformer = null): array
    {
        $html = $this->toUtf8($html);
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_COMPACT);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);
        $links = $baseUrl ? $this->links($xpath, $baseUrl) : [];
        $title = $this->title($xpath);
        $description = $xpath->evaluate('string(//meta[@name="description"]/@content)');

        $transformer?->before($dom, $xpath, $baseUrl);

        // On retire le bruit APRES avoir collecte liens et titre.
        foreach ($xpath->query(self::NOISE) as $node) {
            $node->parentNode?->removeChild($node);
        }
        foreach ($xpath->query('//*[@class or @id]') as $node) {
            if ($node instanceof DOMElement && $node->parentNode
                && preg_match(self::NOISE_CLASS, $node->getAttribute('class').' '.$node->getAttribute('id'))) {
                $node->parentNode->removeChild($node);
            }
        }

        $transformer?->after($dom, $xpath, $baseUrl);

        $body = $dom->getElementsByTagName('body')->item(0);
        $text = $body ? $this->render($body) : '';
        $text = preg_replace('/ *\| *(?=\n|$)/u', '', $text);
        $text = Text::clean($text);

        if ($description && ! str_contains($text, trim($description))) {
            $text = trim($description)."\n\n".$text;
        }

        return ['title' => $title, 'text' => $text, 'links' => $links];
    }

    private function title(DOMXPath $xpath): ?string
    {
        $title = trim((string) $xpath->evaluate('string(//title)'));
        if ($title === '') {
            $title = trim((string) $xpath->evaluate('string((//h1)[1])'));
        }

        return $title !== '' ? Text::limit(preg_replace('/\s+/u', ' ', $title), 160) : null;
    }

    /** @return list<string> */
    private function links(DOMXPath $xpath, string $baseUrl): array
    {
        $links = [];
        foreach ($xpath->query('//a[@href]') as $a) {
            $href = trim($a->getAttribute('href'));
            if ($href === '' || preg_match('/^(#|mailto:|tel:|javascript:|data:)/i', $href)) {
                continue;
            }
            $links[] = Url::resolve($baseUrl, $href);
        }

        return array_values(array_unique(array_filter($links)));
    }

    private function render(DOMNode $node): string
    {
        if ($node->nodeType === XML_TEXT_NODE) {
            return preg_replace('/\s+/u', ' ', $node->nodeValue);
        }
        if ($node->nodeType !== XML_ELEMENT_NODE) {
            return '';
        }

        $inner = '';
        foreach ($node->childNodes as $child) {
            $inner .= $this->render($child);
        }

        $name = strtolower($node->nodeName);

        return match (true) {
            (bool) preg_match('/^h([1-6])$/', $name, $m) => "\n\n".str_repeat('#', (int) $m[1]).' '.trim($inner)."\n\n",
            in_array($name, ['p', 'div', 'section', 'article', 'main', 'header', 'footer', 'aside', 'ul', 'ol', 'table', 'thead', 'tbody', 'blockquote', 'figure', 'dl', 'address'], true) => "\n".$inner."\n",
            $name === 'li' => "\n- ".trim($inner),
            $name === 'br' => "\n",
            $name === 'tr' => "\n".$inner,
            in_array($name, ['td', 'th'], true) => trim($inner).' | ',
            in_array($name, ['dt'], true) => "\n".trim($inner).' : ',
            $name === 'a' => $this->renderLink($node, $inner),
            $name === 'img' => $this->renderImage($node),
            default => $inner,
        };
    }

    private function renderLink(DOMNode $node, string $inner): string
    {
        $href = $node instanceof DOMElement ? trim($node->getAttribute('href')) : '';

        // Telephone et e-mail sont des informations de contact : on garde la valeur.
        if (preg_match('/^(tel|mailto):(.+)$/i', $href, $m) && ! str_contains($inner, $m[2])) {
            return trim($inner).' ('.trim(explode('?', $m[2])[0]).')';
        }

        return $inner;
    }

    private function renderImage(DOMNode $node): string
    {
        $alt = $node instanceof DOMElement ? trim($node->getAttribute('alt')) : '';

        return mb_strlen($alt) > 8 ? ' '.$alt.' ' : '';
    }

    private function toUtf8(string $html): string
    {
        if (mb_check_encoding($html, 'UTF-8')) {
            return $html;
        }

        return mb_convert_encoding($html, 'UTF-8', 'ISO-8859-1, Windows-1252');
    }
}
