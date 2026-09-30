<?php

namespace Tests\Unit;

use App\Ingestion\Chunker;
use PHPUnit\Framework\TestCase;

class ChunkerTest extends TestCase
{
    public function test_keeps_heading_path_with_each_chunk(): void
    {
        $chunks = (new Chunker(400, 0))->split("# Livraison\n\n## Tarifs\nOuagadougou : 1 000 FCFA.\n\n## Délais\nSous 24 h.");

        $headings = array_column($chunks, 'heading');

        $this->assertContains('Livraison > Tarifs', $headings);
        $this->assertNotEmpty($chunks);
    }

    public function test_never_exceeds_the_size_limit_even_for_one_huge_paragraph(): void
    {
        $text = str_repeat('Une phrase assez longue pour remplir la limite. ', 200);

        foreach ((new Chunker(300, 40))->split($text) as $chunk) {
            $this->assertLessThanOrEqual(300 + 40 + 4, mb_strlen($chunk['content']));
        }
    }

    public function test_positions_are_sequential_and_no_chunk_is_empty(): void
    {
        $chunks = (new Chunker(200, 30))->split(str_repeat("Paragraphe de test numéro un.\n\n", 40));

        foreach ($chunks as $i => $chunk) {
            $this->assertSame($i, $chunk['position']);
            $this->assertNotSame('', trim($chunk['content']));
        }
    }

    public function test_small_sections_are_merged_instead_of_producing_tiny_chunks(): void
    {
        $chunks = (new Chunker(1200, 0))->split("# Horaires\nOuvert de 8 h à 19 h.\n\n# Adresse\nAvenue Kwame N'Krumah.\n\n# Contact\n+226 70 00 00 00");

        $this->assertCount(1, $chunks);
        $this->assertStringContainsString('Adresse', $chunks[0]['content']);
        $this->assertStringContainsString('Contact', $chunks[0]['content']);
    }

    public function test_overlap_repeats_the_end_of_the_previous_chunk_inside_a_section(): void
    {
        $paragraphs = [];
        for ($i = 1; $i <= 6; $i++) {
            $paragraphs[] = "Paragraphe {$i} ".str_repeat('mot ', 25).'fin'.$i.'.';
        }
        $chunks = (new Chunker(300, 60))->split(implode("\n\n", $paragraphs));

        $this->assertGreaterThan(1, count($chunks));
        // La fin du 1er extrait reapparait au debut du 2e.
        $this->assertStringContainsString('fin', mb_substr($chunks[1]['content'], 0, 80));
    }

    public function test_empty_text_gives_no_chunk(): void
    {
        $this->assertSame([], (new Chunker(300, 30))->split("   \n\n  "));
    }
}
