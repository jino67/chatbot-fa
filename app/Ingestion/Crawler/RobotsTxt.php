<?php

namespace App\Ingestion\Crawler;

/** Lecture minimale mais correcte de robots.txt : groupes, Allow/Disallow, jokers * et $, plus long motif gagnant. */
final class RobotsTxt
{
    /** @param list<array{type:string, pattern:string}> $rules @param list<string> $sitemaps */
    public function __construct(private readonly array $rules = [], private readonly array $sitemaps = []) {}

    public static function fetch(string $url): self
    {
        $p = parse_url($url);
        $robotsUrl = $p['scheme'].'://'.$p['host'].(isset($p['port']) ? ':'.$p['port'] : '').'/robots.txt';

        try {
            $res = SafeHttp::get($robotsUrl, 2, 500_000);
        } catch (\Throwable) {
            return new self;
        }

        return $res['status'] === 200 ? self::parse($res['body']) : new self;
    }

    public static function parse(string $content, ?string $agent = null): self
    {
        $agent = strtolower($agent ?? explode('/', (string) config('platform.crawler.user_agent'))[0]);
        $groups = [];
        $sitemaps = [];
        $current = null;
        $expectingAgents = false;

        foreach (preg_split('/\r\n|\r|\n/', $content) as $line) {
            $line = trim(preg_replace('/#.*$/', '', $line));
            if ($line === '' || ! str_contains($line, ':')) {
                continue;
            }
            [$field, $value] = array_map('trim', explode(':', $line, 2));
            $field = strtolower($field);

            if ($field === 'sitemap') {
                $sitemaps[] = $value;
            } elseif ($field === 'user-agent') {
                if (! $expectingAgents) {
                    $current = count($groups);
                    $groups[$current] = ['agents' => [], 'rules' => []];
                }
                $groups[$current]['agents'][] = strtolower($value);
                $expectingAgents = true;
            } elseif (in_array($field, ['allow', 'disallow'], true) && $current !== null) {
                $expectingAgents = false;
                if ($value !== '') { // "Disallow:" vide = tout autorise
                    $groups[$current]['rules'][] = ['type' => $field, 'pattern' => $value];
                }
            } else {
                $expectingAgents = false;
            }
        }

        // Le groupe le plus specifique pour notre agent, sinon "*".
        $rules = [];
        foreach ([$agent, '*'] as $wanted) {
            foreach ($groups as $group) {
                foreach ($group['agents'] as $a) {
                    if ($wanted === '*' ? $a === '*' : str_contains($agent, $a) && $a !== '*') {
                        $rules = $group['rules'];
                        break 3;
                    }
                }
            }
        }

        return new self($rules, $sitemaps);
    }

    public function allows(string $url): bool
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $path = $path === '' ? '/' : $path;
        if ($query = parse_url($url, PHP_URL_QUERY)) {
            $path .= '?'.$query;
        }

        $best = null;
        foreach ($this->rules as $rule) {
            if ($this->matches($rule['pattern'], $path)) {
                $length = strlen($rule['pattern']);
                // Plus long motif gagnant ; a egalite, Allow l'emporte.
                if ($best === null || $length > $best['length'] || ($length === $best['length'] && $rule['type'] === 'allow')) {
                    $best = ['length' => $length, 'type' => $rule['type']];
                }
            }
        }

        return $best === null || $best['type'] === 'allow';
    }

    /** @return list<string> */
    public function sitemaps(): array
    {
        return $this->sitemaps;
    }

    private function matches(string $pattern, string $path): bool
    {
        $regex = '#^'.str_replace(['\*', '\$'], ['.*', '$'], preg_quote($pattern, '#')).'#';

        return (bool) preg_match($regex, $path);
    }
}
