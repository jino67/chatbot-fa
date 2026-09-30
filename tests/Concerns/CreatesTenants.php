<?php

namespace Tests\Concerns;

use App\Ingestion\IngestionPipeline;
use App\Models\Bot;
use App\Models\Source;
use App\Models\User;
use App\Models\Workspace;

trait CreatesTenants
{
    /** @return array{0: Workspace, 1: User, 2: Bot} */
    protected function tenant(string $name = 'Boutique Test', string $plan = 'pro'): array
    {
        $workspace = Workspace::create(['name' => $name, 'plan' => $plan]);
        $user = User::factory()->create(['workspace_id' => $workspace->id]);
        $bot = Bot::withoutGlobalScopes()->create(['workspace_id' => $workspace->id, 'name' => "Assistant {$name}"]);

        return [$workspace, $user, $bot];
    }

    protected function admin(): User
    {
        return $this->staff(User::SUPER_ADMIN);
    }

    /** Compte du personnel : super_admin (tout) ou admin (gere les espaces clients). */
    protected function staff(string $role = User::ADMIN): User
    {
        $user = User::factory()->create(['workspace_id' => null]);
        $user->role = $role;
        $user->save();

        return $user;
    }

    /** Ajoute un texte et l'indexe immediatement (LLM et embeddings hors ligne). */
    protected function teach(Bot $bot, string $title, string $content): Source
    {
        $source = Source::withoutGlobalScopes()->create([
            'workspace_id' => $bot->workspace_id,
            'bot_id' => $bot->id,
            'type' => Source::TYPE_TEXT,
            'name' => $title,
            'payload' => ['content' => $content],
        ]);

        app(IngestionPipeline::class)->run($source);

        return $source->fresh();
    }
}
