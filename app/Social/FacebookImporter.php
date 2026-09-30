<?php

namespace App\Social;

use App\Jobs\IngestSource;
use App\Models\Bot;
use App\Models\FacebookConnection;
use App\Models\Source;

/** Cree ou met a jour la source « Page Facebook » d'un assistant a partir de la page connectee. */
class FacebookImporter
{
    public function __construct(private readonly FacebookGraph $graph) {}

    /**
     * @param  array{id:string,name:string,access_token:string,link:?string}  $page
     */
    public function import(Bot $bot, array $page, ?Source $source = null): Source
    {
        $content = $this->graph->pageContent($page['id'], $page['access_token']);

        $connection = FacebookConnection::withoutGlobalScopes()->updateOrCreate(
            ['bot_id' => $bot->id, 'page_id' => $page['id']],
            [
                'workspace_id' => $bot->workspace_id,
                'page_name' => $page['name'],
                'page_url' => $content['url'] ?? $page['link'],
                'access_token' => $page['access_token'],
                'status' => 'active',
                'last_synced_at' => now(),
            ]
        );

        $source ??= $connection->source_id ? Source::withoutGlobalScopes()->find($connection->source_id) : null;
        $source ??= new Source(['workspace_id' => $bot->workspace_id, 'bot_id' => $bot->id, 'type' => Source::TYPE_FACEBOOK]);

        $source->fill([
            'name' => 'Page Facebook : '.$content['title'],
            'status' => Source::PENDING,
            'error' => null,
            'resync' => 'weekly',
            'payload' => [
                'content' => $content['text'],
                'url' => $content['url'] ?? $page['link'],
                'page_id' => $page['id'],
                'connected' => true,
            ],
        ])->save();

        $connection->forceFill(['source_id' => $source->id])->save();

        IngestSource::dispatch($source->id);

        return $source;
    }

    /** Relit une page deja connectee (mise a jour hebdomadaire). En cas d'autorisation expiree, la connexion est marquee expiree. */
    public function refresh(FacebookConnection $connection): void
    {
        $bot = Bot::withoutGlobalScopes()->find($connection->bot_id);
        if (! $bot) {
            return;
        }

        try {
            $this->import($bot, [
                'id' => $connection->page_id,
                'name' => (string) $connection->page_name,
                'access_token' => (string) $connection->access_token,
                'link' => $connection->page_url,
            ], $connection->source_id ? Source::withoutGlobalScopes()->find($connection->source_id) : null);
        } catch (\Throwable $e) {
            $connection->forceFill(['status' => 'expired'])->save();

            if ($connection->source_id) {
                Source::withoutGlobalScopes()->whereKey($connection->source_id)->update([
                    'status' => Source::FAILED,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
