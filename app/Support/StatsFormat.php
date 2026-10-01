<?php

namespace App\Support;

/** Mise en forme des chiffres de la page Statistiques : espaces fines, durées lisibles, évolutions signées. */
final class StatsFormat
{
    public static function number(int|float|null $value): string
    {
        return number_format((float) $value, 0, ',', ' ');
    }

    public static function decimal(int|float|null $value): string
    {
        return number_format((float) $value, 1, ',', ' ');
    }

    public static function percent(int|float|null $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 1, ',', ''), '0'), ',').' %';
    }

    /** 75 devient « 1 min 15 s » ; moins d'une minute : « 42 s ». */
    public static function duration(int|float|null $seconds): string
    {
        $seconds = (int) round((float) $seconds);

        if ($seconds <= 0) {
            return '0 s';
        }

        return $seconds < 60 ? $seconds.' s' : intdiv($seconds, 60).' min'.($seconds % 60 ? ' '.($seconds % 60).' s' : '');
    }

    public static function value(int|float|null $value, string $format): string
    {
        return match ($format) {
            'percent' => self::percent($value),
            'duration' => self::duration($value),
            'decimal' => self::decimal($value),
            default => self::number($value),
        };
    }

    /** « +12 % » ou « -8 % » ; null quand la période précédente est vide. */
    public static function delta(?float $delta): ?string
    {
        if ($delta === null) {
            return null;
        }

        return ($delta > 0 ? '+' : ($delta < 0 ? '−' : '')).rtrim(rtrim(number_format(abs($delta), 1, ',', ''), '0'), ',').' %';
    }
}
