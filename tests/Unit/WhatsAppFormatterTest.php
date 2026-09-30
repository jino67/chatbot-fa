<?php

namespace Tests\Unit;

use App\Channels\WhatsApp\WhatsAppFormatter;
use PHPUnit\Framework\TestCase;

class WhatsAppFormatterTest extends TestCase
{
    public function test_markdown_is_converted_to_whatsapp_syntax(): void
    {
        $out = WhatsAppFormatter::format("## Livraison\nC'est **gratuit** dès 25 000 FCFA.\n* Ouaga : 1 000\n[Voir](https://exemple.com/x)");

        $this->assertStringContainsString('*Livraison*', $out);
        $this->assertStringContainsString("C'est *gratuit*", $out);
        $this->assertStringContainsString('- Ouaga : 1 000', $out);
        $this->assertStringContainsString('Voir (https://exemple.com/x)', $out);
        $this->assertStringNotContainsString('**', $out);
    }

    public function test_short_text_is_a_single_part(): void
    {
        $this->assertCount(1, WhatsAppFormatter::parts('Bonjour'));
    }

    public function test_long_text_is_split_under_the_provider_limit_without_losing_content(): void
    {
        $text = implode("\n\n", array_map(fn ($i) => "Paragraphe {$i} ".str_repeat('lorem ', 60), range(1, 12)));

        $parts = WhatsAppFormatter::parts($text, 500);

        $this->assertGreaterThan(1, count($parts));
        foreach ($parts as $part) {
            $this->assertLessThanOrEqual(500, mb_strlen($part));
        }
        $this->assertStringContainsString('Paragraphe 12', end($parts));
    }
}
