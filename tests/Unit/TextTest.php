<?php

namespace Tests\Unit;

use App\Support\Text;
use PHPUnit\Framework\TestCase;

class TextTest extends TestCase
{
    public function test_fold_removes_accents_and_case(): void
    {
        $this->assertSame('ecole ete', Text::fold('École ÉTÉ'));
    }

    public function test_tokens_drop_stopwords_and_light_plurals_but_keep_numbers(): void
    {
        $tokens = Text::tokens('Les robes de la boutique coûtent 18000 FCFA');

        $this->assertContains('robe', $tokens);
        $this->assertContains('18000', $tokens);
        $this->assertNotContains('les', $tokens);
        $this->assertNotContains('de', $tokens);
    }

    public function test_arabic_letters_are_preserved(): void
    {
        $this->assertNotEmpty(Text::tokens('مرحبا بكم في متجرنا'));
    }

    /** @dataProvider smallTalk */
    public function test_small_talk_detection(string $text, bool $expected): void
    {
        $this->assertSame($expected, Text::isSmallTalk($text));
    }

    public static function smallTalk(): array
    {
        return [
            'salutation' => ['Bonjour !', true],
            'merci' => ['Merci beaucoup', true],
            'anglais' => ['hello', true],
            'salutation et nouvelles' => ['Bonsoir comment tu vas ?', true],
            'nouvelles formelles' => ['Bonjour, comment allez-vous ?', true],
            'nouvelles seules' => ['Ça va ?', true],
            'apostrophe courbe' => ['D’accord', true],
            'ponctuation seule' => ['!!!', false],
            'nouvelles puis question' => ['Comment ça va et vous livrez où ?', false],
            'question avec salutation' => ['Bonjour, vous livrez à Bobo ?', false],
            'question' => ['Quels sont vos horaires ?', false],
            'long texte' => [str_repeat('bonjour ', 20), false],
        ];
    }

    /** @dataProvider humanRequests */
    public function test_explicit_human_requests_are_detected(string $text, bool $expected): void
    {
        $this->assertSame($expected, Text::wantsHuman($text), $text);
    }

    public static function humanRequests(): array
    {
        return [
            ['Je veux parler à quelqu\'un', true],
            ['Puis-je parler à un conseiller ?', true],
            ['Passez-moi un responsable svp', true],
            ['Rappelez-moi demain', true],
            ['I want to speak to a human', true],
            ['customer service please', true],
            ['أريد التحدث إلى موظف', true],
            ['Quels sont vos horaires ?', false],
            ['Je voudrais parler de la livraison', false],
            ['Vous avez un agent immobilier à Bobo ?', false],
            ['Bonjour', false],
        ];
    }

    public function test_complaints_are_flagged_as_sensitive(): void
    {
        $this->assertTrue(Text::isSensitive("C'est une arnaque !"));
        $this->assertTrue(Text::isSensitive('Je veux un remboursement'));
        $this->assertTrue(Text::isSensitive('Ma commande est arrivée cassée'));
        $this->assertFalse(Text::isSensitive('Quels sont vos horaires ?'));
    }

    public function test_breadcrumb_avoids_duplicates_and_angle_brackets(): void
    {
        $this->assertSame('Livraison', Text::breadcrumb('Livraison', 'Livraison'));
        $this->assertSame('Guide › Tarifs › Ville', Text::breadcrumb('Guide', 'Tarifs > Ville'));
        $this->assertStringNotContainsString('>', Text::breadcrumb('A > B', 'C > D'));
    }

    public function test_clean_normalizes_whitespace_and_repairs_latin1(): void
    {
        $this->assertSame("a b\n\nc", Text::clean("a   b\r\n\r\n\r\n\r\nc"));
        $this->assertSame('café', Text::clean(mb_convert_encoding('café', 'ISO-8859-1', 'UTF-8')));
    }

    public function test_limit_cuts_on_a_word_boundary(): void
    {
        $this->assertSame('un deux…', Text::limit('un deux trois quatre', 10));
    }
}
