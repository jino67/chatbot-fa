<?php

namespace App\Retrieval;

/**
 * Vecteurs float32 : encodage compact (pack + base64) pour rester portable entre SQLite, MySQL et PostgreSQL.
 */
final class Vector
{
    /** @param list<float> $vector */
    public static function encode(array $vector): string
    {
        return base64_encode(pack('g*', ...$vector));
    }

    /** @return list<float> */
    public static function decode(string $encoded): array
    {
        $raw = base64_decode($encoded, true);

        return $raw === false || $raw === '' ? [] : array_values(unpack('g*', $raw));
    }

    /** @param list<float> $vector @return list<float> */
    public static function normalize(array $vector): array
    {
        $norm = sqrt(array_sum(array_map(fn ($v) => $v * $v, $vector)));

        return $norm > 0 ? array_map(fn ($v) => $v / $norm, $vector) : $vector;
    }

    /** @param list<float> $a @param list<float> $b */
    public static function cosine(array $a, array $b): float
    {
        $n = min(count($a), count($b));
        if ($n === 0) {
            return 0.0;
        }

        $dot = $na = $nb = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $dot += $a[$i] * $b[$i];
            $na += $a[$i] * $a[$i];
            $nb += $b[$i] * $b[$i];
        }

        return ($na > 0 && $nb > 0) ? $dot / (sqrt($na) * sqrt($nb)) : 0.0;
    }
}
