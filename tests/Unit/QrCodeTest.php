<?php

namespace Tests\Unit;

use App\Support\QrCode;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Le générateur de QR code. Les empreintes ci-dessous ont été relevées sur des grilles relues avec un lecteur indépendant
 * (jsQR) : si l'une change, le tracé a changé et il faut le relire avec un lecteur avant de mettre à jour l'empreinte.
 */
class QrCodeTest extends TestCase
{
    public function test_the_size_follows_the_text_length(): void
    {
        $this->assertCount(21, QrCode::matrix('A'));
        $this->assertCount(33, QrCode::matrix('https://kouma.site/chat/pk_abcdefghijklmnopqrstuvwx'));
        $this->assertCount(57, QrCode::matrix(str_repeat('x', 213)));
    }

    public function test_known_grids_do_not_change(): void
    {
        $this->assertSame('6827a4d2a6656937b79ee6e79ac36e0189e746b8', sha1(json_encode(QrCode::matrix('A'))));
        $this->assertSame('16f1e39f4c0930f0f4d17d683e693250f373dde8', sha1(json_encode(QrCode::matrix('https://kouma.site/chat/pk_abcdefghijklmnopqrstuvwx'))));
    }

    public function test_the_three_finder_patterns_and_the_timing_line_are_in_place(): void
    {
        $grid = QrCode::matrix('https://kouma.site');
        $n = count($grid);

        foreach ([[0, 0], [$n - 7, 0], [0, $n - 7]] as [$x, $y]) {
            for ($i = 0; $i < 7; $i++) {
                $this->assertTrue($grid[$y][$x + $i] && $grid[$y + 6][$x + $i] && $grid[$y + $i][$x] && $grid[$y + $i][$x + 6], 'le cadre du motif de repérage est plein');
            }
            $this->assertTrue($grid[$y + 3][$x + 3], 'le centre est plein');
            $this->assertFalse($grid[$y + 1][$x + 1], 'l\'anneau intérieur est vide');
        }

        for ($i = 8; $i < $n - 8; $i++) {
            $this->assertSame($i % 2 === 0, $grid[6][$i], 'la ligne de synchronisation alterne');
        }
        $this->assertTrue($grid[$n - 8][8], 'le module sombre fixe');
    }

    public function test_a_text_over_the_capacity_is_refused(): void
    {
        $this->assertSame(213, QrCode::MAX_BYTES);
        $this->expectException(InvalidArgumentException::class);
        QrCode::matrix(str_repeat('x', 214));
    }

    public function test_the_svg_is_a_single_path_with_a_quiet_zone(): void
    {
        $svg = QrCode::svg('A');

        $this->assertStringStartsWith('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 29 29"', $svg);
        $this->assertSame(1, substr_count($svg, '<path'));
        $this->assertStringContainsString('fill="#ffffff"', $svg);
        // Le premier module du motif de repérage est décalé de la zone blanche (4 modules).
        $this->assertStringContainsString('M4 4h7', $svg);
    }

    public function test_accented_text_is_encoded_as_utf8_bytes(): void
    {
        // « é » pèse deux octets : 100 caractères accentués dépassent déjà la version 5.
        $this->assertGreaterThan(count(QrCode::matrix(str_repeat('e', 100))), count(QrCode::matrix(str_repeat('é', 100))));
    }
}
