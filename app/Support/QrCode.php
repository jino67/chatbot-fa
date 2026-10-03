<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Un générateur de QR code sans bibliothèque (mode octets, correction d'erreur M, versions 1 à 10 : jusqu'à 213 octets).
 * Il sert à partager le lien de discussion d'un assistant : affiche à imprimer, vitrine, carte de visite. Le tracé suit
 * la norme ISO/IEC 18004 ; le masque retenu est celui qui a la plus faible pénalité.
 */
final class QrCode
{
    /** Par version (1 à 10) : octets de correction par bloc, nombre de blocs. Niveau de correction M. */
    private const ECC_PER_BLOCK = [1 => 10, 16, 26, 18, 24, 16, 18, 22, 22, 26];

    private const BLOCKS = [1 => 1, 1, 1, 2, 2, 4, 4, 4, 5, 5];

    public const MAX_BYTES = 213;

    /** @var list<list<bool>> */
    private array $modules = [];

    /** @var list<list<bool>> */
    private array $isFunction = [];

    private int $size;

    private function __construct(private readonly int $version)
    {
        $this->size = $version * 4 + 17;
    }

    /**
     * La grille du QR code : true pour un module sombre.
     *
     * @return list<list<bool>>
     */
    public static function matrix(string $text): array
    {
        $bytes = array_values(unpack('C*', $text) ?: []);
        $version = self::pickVersion(count($bytes));
        $qr = new self($version);

        return $qr->build($bytes);
    }

    /** Le QR code en SVG (modules rassemblés en un seul tracé), avec une zone blanche de 4 modules autour. */
    public static function svg(string $text, string $dark = '#0b1240', string $light = '#ffffff', int $quiet = 4): string
    {
        $grid = self::matrix($text);
        $n = count($grid);
        $total = $n + 2 * $quiet;
        $path = '';

        foreach ($grid as $y => $row) {
            $x = 0;
            while ($x < $n) {
                if (! $row[$x]) {
                    $x++;

                    continue;
                }
                $start = $x;
                while ($x < $n && $row[$x]) {
                    $x++;
                }
                $path .= 'M'.($start + $quiet).' '.($y + $quiet).'h'.($x - $start).'v1h-'.($x - $start).'z';
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$total.' '.$total.'" shape-rendering="crispEdges" role="img" aria-label="QR code">'
            .'<rect width="'.$total.'" height="'.$total.'" fill="'.$light.'"/><path d="'.$path.'" fill="'.$dark.'"/></svg>';
    }

    private static function pickVersion(int $length): int
    {
        for ($version = 1; $version <= 10; $version++) {
            // 4 bits de mode + compteur (8 bits jusqu'à la version 9, 16 ensuite) + les octets.
            $bits = 4 + ($version <= 9 ? 8 : 16) + 8 * $length;
            if ($bits <= self::dataCodewords($version) * 8) {
                return $version;
            }
        }

        throw new InvalidArgumentException('Texte trop long pour le QR code ('.self::MAX_BYTES.' octets au plus).');
    }

    private static function rawModules(int $version): int
    {
        $result = (16 * $version + 128) * $version + 64;

        if ($version >= 2) {
            $align = intdiv($version, 7) + 2;
            $result -= (25 * $align - 10) * $align - 55;
            if ($version >= 7) {
                $result -= 36;
            }
        }

        return $result;
    }

    private static function dataCodewords(int $version): int
    {
        return intdiv(self::rawModules($version), 8) - self::ECC_PER_BLOCK[$version] * self::BLOCKS[$version];
    }

    /** @param list<int> $bytes @return list<list<bool>> */
    private function build(array $bytes): array
    {
        $this->modules = array_fill(0, $this->size, array_fill(0, $this->size, false));
        $this->isFunction = array_fill(0, $this->size, array_fill(0, $this->size, false));

        $this->drawFunctionPatterns();
        $this->drawCodewords($this->addEccAndInterleave($this->dataCodewordsFor($bytes)));

        $best = 0;
        $bestPenalty = PHP_INT_MAX;
        for ($mask = 0; $mask < 8; $mask++) {
            $this->applyMask($mask);
            $this->drawFormatBits($mask);
            $penalty = $this->penalty();
            if ($penalty < $bestPenalty) {
                $bestPenalty = $penalty;
                $best = $mask;
            }
            $this->applyMask($mask); // le masque est son propre inverse
        }

        $this->applyMask($best);
        $this->drawFormatBits($best);

        return $this->modules;
    }

    /** Mode octets : 0100, compteur, données, terminateur, bourrage (0xEC, 0x11). @param list<int> $bytes @return list<int> */
    private function dataCodewordsFor(array $bytes): array
    {
        $bits = [];
        $push = function (int $value, int $count) use (&$bits) {
            for ($i = $count - 1; $i >= 0; $i--) {
                $bits[] = ($value >> $i) & 1;
            }
        };

        $push(0b0100, 4);
        $push(count($bytes), $this->version <= 9 ? 8 : 16);
        foreach ($bytes as $byte) {
            $push($byte, 8);
        }

        $capacity = self::dataCodewords($this->version) * 8;
        $push(0, min(4, $capacity - count($bits)));
        while (count($bits) % 8 !== 0) {
            $bits[] = 0;
        }

        $codewords = [];
        for ($i = 0; $i < count($bits); $i += 8) {
            $codewords[] = bindec(implode('', array_slice($bits, $i, 8)));
        }

        for ($pad = 0xEC; count($codewords) < self::dataCodewords($this->version); $pad ^= 0xEC ^ 0x11) {
            $codewords[] = $pad;
        }

        return $codewords;
    }

    /** @param list<int> $data @return list<int> */
    private function addEccAndInterleave(array $data): array
    {
        $blocks = self::BLOCKS[$this->version];
        $eccLen = self::ECC_PER_BLOCK[$this->version];
        $raw = intdiv(self::rawModules($this->version), 8);
        $shortBlocks = $blocks - $raw % $blocks;
        $shortLen = intdiv($raw, $blocks);

        $divisor = $this->rsDivisor($eccLen);
        $list = [];
        $k = 0;

        for ($i = 0; $i < $blocks; $i++) {
            $dat = array_slice($data, $k, $shortLen - $eccLen + ($i < $shortBlocks ? 0 : 1));
            $k += count($dat);
            $ecc = $this->rsRemainder($dat, $divisor);
            if ($i < $shortBlocks) {
                $dat[] = 0; // octet de remplissage, retiré à l'entrelacement
            }
            $list[] = array_merge($dat, $ecc);
        }

        $result = [];
        for ($i = 0; $i < count($list[0]); $i++) {
            foreach ($list as $j => $block) {
                if ($i !== $shortLen - $eccLen || $j >= $shortBlocks) {
                    $result[] = $block[$i];
                }
            }
        }

        return $result;
    }

    private function gfMultiply(int $x, int $y): int
    {
        $z = 0;
        for ($i = 7; $i >= 0; $i--) {
            $z = ($z << 1) ^ (($z >> 7) * 0x11D);
            $z ^= (($y >> $i) & 1) * $x;
        }

        return $z;
    }

    /** @return list<int> */
    private function rsDivisor(int $degree): array
    {
        $result = array_fill(0, $degree, 0);
        $result[$degree - 1] = 1;
        $root = 1;

        for ($i = 0; $i < $degree; $i++) {
            for ($j = 0; $j < $degree; $j++) {
                $result[$j] = $this->gfMultiply($result[$j], $root);
                if ($j + 1 < $degree) {
                    $result[$j] ^= $result[$j + 1];
                }
            }
            $root = $this->gfMultiply($root, 0x02);
        }

        return $result;
    }

    /** @param list<int> $data @param list<int> $divisor @return list<int> */
    private function rsRemainder(array $data, array $divisor): array
    {
        $result = array_fill(0, count($divisor), 0);

        foreach ($data as $byte) {
            $factor = $byte ^ array_shift($result);
            $result[] = 0;
            foreach ($divisor as $i => $coef) {
                $result[$i] ^= $this->gfMultiply($coef, $factor);
            }
        }

        return $result;
    }

    private function setFunction(int $x, int $y, bool $dark): void
    {
        $this->modules[$y][$x] = $dark;
        $this->isFunction[$y][$x] = true;
    }

    private function drawFunctionPatterns(): void
    {
        for ($i = 0; $i < $this->size; $i++) {
            $this->setFunction(6, $i, $i % 2 === 0);
            $this->setFunction($i, 6, $i % 2 === 0);
        }

        $this->drawFinder(3, 3);
        $this->drawFinder($this->size - 4, 3);
        $this->drawFinder(3, $this->size - 4);

        $positions = $this->alignmentPositions();
        $count = count($positions);
        for ($i = 0; $i < $count; $i++) {
            for ($j = 0; $j < $count; $j++) {
                if (! (($i === 0 && $j === 0) || ($i === 0 && $j === $count - 1) || ($i === $count - 1 && $j === 0))) {
                    $this->drawAlignment($positions[$i], $positions[$j]);
                }
            }
        }

        $this->drawFormatBits(0);
        $this->drawVersion();
    }

    private function drawFinder(int $x, int $y): void
    {
        for ($dy = -4; $dy <= 4; $dy++) {
            for ($dx = -4; $dx <= 4; $dx++) {
                $xx = $x + $dx;
                $yy = $y + $dy;
                if ($xx >= 0 && $xx < $this->size && $yy >= 0 && $yy < $this->size) {
                    $dist = max(abs($dx), abs($dy));
                    $this->setFunction($xx, $yy, $dist !== 2 && $dist !== 4);
                }
            }
        }
    }

    private function drawAlignment(int $x, int $y): void
    {
        for ($dy = -2; $dy <= 2; $dy++) {
            for ($dx = -2; $dx <= 2; $dx++) {
                $this->setFunction($x + $dx, $y + $dy, max(abs($dx), abs($dy)) !== 1);
            }
        }
    }

    /** @return list<int> */
    private function alignmentPositions(): array
    {
        if ($this->version === 1) {
            return [];
        }

        $count = intdiv($this->version, 7) + 2;
        $step = (int) (ceil(($this->version * 4 + 4) / ($count * 2 - 2)) * 2);
        $result = [6];
        for ($pos = $this->size - 7; count($result) < $count; $pos -= $step) {
            array_splice($result, 1, 0, [$pos]);
        }

        return $result;
    }

    private function drawFormatBits(int $mask): void
    {
        // Niveau de correction M : 00.
        $data = (0b00 << 3) | $mask;
        $rem = $data;
        for ($i = 0; $i < 10; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
        }
        $bits = (($data << 10) | $rem) ^ 0x5412;
        $bit = fn (int $i): bool => (($bits >> $i) & 1) === 1;

        for ($i = 0; $i <= 5; $i++) {
            $this->setFunction(8, $i, $bit($i));
        }
        $this->setFunction(8, 7, $bit(6));
        $this->setFunction(8, 8, $bit(7));
        $this->setFunction(7, 8, $bit(8));
        for ($i = 9; $i < 15; $i++) {
            $this->setFunction(14 - $i, 8, $bit($i));
        }

        for ($i = 0; $i < 8; $i++) {
            $this->setFunction($this->size - 1 - $i, 8, $bit($i));
        }
        for ($i = 8; $i < 15; $i++) {
            $this->setFunction(8, $this->size - 15 + $i, $bit($i));
        }
        $this->setFunction(8, $this->size - 8, true);
    }

    private function drawVersion(): void
    {
        if ($this->version < 7) {
            return;
        }

        $rem = $this->version;
        for ($i = 0; $i < 12; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
        }
        $bits = ($this->version << 12) | $rem;

        for ($i = 0; $i < 18; $i++) {
            $dark = (($bits >> $i) & 1) === 1;
            $a = $this->size - 11 + $i % 3;
            $b = intdiv($i, 3);
            $this->setFunction($a, $b, $dark);
            $this->setFunction($b, $a, $dark);
        }
    }

    /** @param list<int> $data */
    private function drawCodewords(array $data): void
    {
        $i = 0;
        $total = count($data) * 8;

        for ($right = $this->size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }
            for ($vert = 0; $vert < $this->size; $vert++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    $upward = (($right + 1) & 2) === 0;
                    $y = $upward ? $this->size - 1 - $vert : $vert;
                    if (! $this->isFunction[$y][$x] && $i < $total) {
                        $this->modules[$y][$x] = (($data[$i >> 3] >> (7 - ($i & 7))) & 1) === 1;
                        $i++;
                    }
                }
            }
        }
    }

    private function applyMask(int $mask): void
    {
        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                $invert = match ($mask) {
                    0 => ($x + $y) % 2 === 0,
                    1 => $y % 2 === 0,
                    2 => $x % 3 === 0,
                    3 => ($x + $y) % 3 === 0,
                    4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
                    5 => $x * $y % 2 + $x * $y % 3 === 0,
                    6 => ($x * $y % 2 + $x * $y % 3) % 2 === 0,
                    default => (($x + $y) % 2 + $x * $y % 3) % 2 === 0,
                };
                if ($invert && ! $this->isFunction[$y][$x]) {
                    $this->modules[$y][$x] = ! $this->modules[$y][$x];
                }
            }
        }
    }

    /** Pénalité de la norme : séries, blocs 2x2, motifs de repérage, équilibre clair/sombre. */
    private function penalty(): int
    {
        $n = $this->size;
        $result = 0;

        for ($pass = 0; $pass < 2; $pass++) {
            for ($a = 0; $a < $n; $a++) {
                $color = false;
                $run = 0;
                $history = array_fill(0, 7, 0);
                for ($b = 0; $b < $n; $b++) {
                    $cell = $pass === 0 ? $this->modules[$a][$b] : $this->modules[$b][$a];
                    if ($cell === $color) {
                        $run++;
                        if ($run === 5) {
                            $result += 3;
                        } elseif ($run > 5) {
                            $result++;
                        }
                    } else {
                        $this->historyAdd($run, $history);
                        if (! $color) {
                            $result += $this->finderCount($history) * 40;
                        }
                        $color = $cell;
                        $run = 1;
                    }
                }
                $result += $this->finderTerminate($color, $run, $history) * 40;
            }
        }

        for ($y = 0; $y < $n - 1; $y++) {
            for ($x = 0; $x < $n - 1; $x++) {
                $c = $this->modules[$y][$x];
                if ($c === $this->modules[$y][$x + 1] && $c === $this->modules[$y + 1][$x] && $c === $this->modules[$y + 1][$x + 1]) {
                    $result += 3;
                }
            }
        }

        $dark = 0;
        foreach ($this->modules as $row) {
            $dark += count(array_filter($row));
        }
        $total = $n * $n;
        $result += ((int) ceil(abs($dark * 20 - $total * 10) / $total) - 1) * 10;

        return $result;
    }

    /** @param list<int> $history */
    private function historyAdd(int $run, array &$history): void
    {
        if ($history[0] === 0) {
            $run += $this->size;
        }
        array_pop($history);
        array_unshift($history, $run);
    }

    /** @param list<int> $history */
    private function finderCount(array $history): int
    {
        $n = $history[1];
        $core = $n > 0 && $history[2] === $n && $history[3] === $n * 3 && $history[4] === $n && $history[5] === $n;

        return ($core && $history[0] >= $n * 4 && $history[6] >= $n ? 1 : 0) + ($core && $history[6] >= $n * 4 && $history[0] >= $n ? 1 : 0);
    }

    /** @param list<int> $history */
    private function finderTerminate(bool $color, int $run, array $history): int
    {
        if ($color) {
            $this->historyAdd($run, $history);
            $run = 0;
        }
        $run += $this->size;
        $this->historyAdd($run, $history);

        return $this->finderCount($history);
    }
}
