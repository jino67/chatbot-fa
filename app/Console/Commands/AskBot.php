<?php

namespace App\Console\Commands;

use App\Chat\ChatService;
use App\Models\Bot;
use App\Models\Conversation;
use Illuminate\Console\Command;

class AskBot extends Command
{
    protected $signature = 'platform:ask {bot : Id de l\'assistant} {question : La question a poser}';

    protected $description = 'Pose une question a un assistant en ligne de commande (diagnostic : extraits, score, jetons)';

    public function handle(ChatService $chat): int
    {
        $bot = Bot::withoutGlobalScopes()->with('workspace')->find($this->argument('bot'));
        if (! $bot) {
            $this->error('Assistant introuvable.');

            return self::FAILURE;
        }

        $conversation = Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $bot->workspace_id,
            'bot_id' => $bot->id,
            'channel' => 'playground',
            'external_id' => 'cli',
        ]);

        $reply = $chat->handleUserMessage($conversation, $this->argument('question'));

        $this->line('');
        $this->line($reply?->content ?? '(aucune reponse : un humain a la main)');
        $this->line('');
        $meta = $reply?->meta ?? [];
        $this->comment('grounded='.json_encode($meta['grounded'] ?? null).' score='.($meta['top_score'] ?? '-').' modele='.($meta['model'] ?? '-').' latence='.($meta['latency_ms'] ?? '-').'ms');
        foreach ($reply?->sources ?? [] as $source) {
            $this->comment(sprintf('  - %s > %s (%s)', $source['title'] ?? '?', $source['heading'] ?? '', $source['score']));
        }

        $conversation->delete();

        return self::SUCCESS;
    }
}
