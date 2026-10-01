<?php

namespace App\Support;

/** Catalogue des langues que l'assistant peut parler (config/languages.php) et regles de prompt associees. */
class Languages
{
    /** @return array<string,array<string,mixed>> */
    public static function all(): array
    {
        return collect(config('languages'))->except('tiers')->all();
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::all());
    }

    public static function has(?string $code): bool
    {
        return $code !== null && isset(self::all()[$code]);
    }

    public static function name(string $code): string
    {
        return self::all()[$code]['name'] ?? $code;
    }

    /** Nom de la langue precede de son article, pour une phrase du prompt (« l'arabe », « le bambara »). */
    public static function phrase(string $code): string
    {
        $name = mb_strtolower(self::name($code));

        return (preg_match('/^[aeiouyéèh]/u', $name) ? "l'" : 'le ').$name;
    }

    /** « en français », « en bambara » : la langue dans laquelle on répond. */
    public static function in(string $code): string
    {
        return 'en '.mb_strtolower(self::name($code));
    }

    public static function tier(string $code): string
    {
        return self::all()[$code]['tier'] ?? 'experimental';
    }

    /** @return array<string,array<string,string>> */
    public static function tiers(): array
    {
        return config('languages.tiers');
    }

    public static function isRtl(?string $code): bool
    {
        return (bool) (self::all()[$code] ?? [])['rtl'] ?? false;
    }

    /**
     * Liste propre : codes connus, sans doublon, langue principale en tete. Jamais vide (francais par defaut).
     *
     * @param  array<int,mixed>  $codes
     * @return list<string>
     */
    public static function normalize(array $codes, ?string $primary = null): array
    {
        $codes = array_values(array_unique(array_filter(array_map('strval', $codes), fn ($c) => self::has($c))));

        if ($primary !== null && self::has($primary)) {
            $codes = array_values(array_unique([$primary, ...$codes]));
        }

        return $codes === [] ? ['fr'] : $codes;
    }

    /** Les langues de la liste que le modele ecrit moins bien (a signaler dans le prompt). */
    public static function local(array $codes): array
    {
        return array_values(array_filter($codes, fn ($c) => in_array(self::tier($c), ['assisted', 'experimental'], true)));
    }

    /**
     * Paragraphe « Langue » du prompt de la plateforme, construit a partir de la liste choisie par le client.
     *
     * @param  list<string>  $codes  langue principale en tete
     */
    public static function promptRule(array $codes): string
    {
        $codes = self::normalize($codes);
        $primary = self::phrase($codes[0]);
        $lines = [];

        if (count($codes) === 1) {
            $lines[] = '- Réponds '.self::in($codes[0]).'.';
        } else {
            $others = implode(', ', array_map(fn ($c) => self::phrase($c), array_slice($codes, 1)));
            $lines[] = "- Tu parles {$primary} (langue principale), puis {$others}. Réponds dans la langue du dernier message du visiteur quand elle fait partie de cette liste ; sinon, réponds ".self::in($codes[0]).'.';
        }

        $local = self::local($codes);
        if ($local !== []) {
            $names = implode(', ', array_map(fn ($c) => self::phrase($c), $local));
            $fallback = self::in($codes[0] !== $local[0] ? $codes[0] : 'fr');
            $lines[] = "- Pour {$names} : écris des phrases courtes et simples, reprends tels quels les prix, chiffres et noms propres des extraits, et n'invente pas de mots. Si tu n'es pas sûr de ta formulation, réponds {$fallback}, puis propose de continuer dans cette langue sur demande.";
        }

        return implode("\n", $lines);
    }
}
