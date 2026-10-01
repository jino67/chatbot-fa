<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Le dialogue de la démonstration interactive de la page d'accueil (resources/js/playground/nlu.js) est du JavaScript
 * pur : ses contrôles tournent sous Node (tests/Js/playground-nlu.mjs), lancés ici pour que `php artisan test` les couvre.
 */
class PlaygroundDialogueTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_local_dialogue_engine_answers_orders_prices_delivery_and_human_requests(): void
    {
        $node = (new ExecutableFinder)->find('node');
        if ($node === null) {
            $this->markTestSkipped('Node n\'est pas installé sur ce poste.');
        }

        $process = new Process([$node, base_path('tests/Js/playground-nlu.mjs')], base_path(), null, null, 60);
        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        $this->assertStringContainsString('TOUT VA BIEN', $process->getOutput());
    }

    public function test_the_landing_page_carries_the_interactive_fold_with_its_static_fallback(): void
    {
        $this->withoutVite();

        $this->get('/')->assertOk()
            ->assertSee('data-fold-play', false)->assertSee('data-pg-input', false)->assertSee('data-pg-cover', false)
            ->assertSee('Rejouer l\'histoire', false)->assertSee('Repartir de zéro', false)
            ->assertSee('data-static', false)->assertSee('Bonsoir, vous livrez à Bobo ?');
    }
}