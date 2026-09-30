<?php

namespace App\Console\Commands;

use App\Jobs\IngestSource;
use App\Models\FacebookConnection;
use App\Models\Source;
use App\Social\FacebookImporter;
use Illuminate\Console\Command;

class ResyncDueSources extends Command
{
    protected $signature = 'platform:resync-due';

    protected $description = 'Relance la lecture des sites et pages Facebook connectées dont la mise à jour automatique est échue';

    public function handle(FacebookImporter $facebook): int
    {
        $due = Source::withoutGlobalScopes()
            ->where('type', Source::TYPE_URL)
            ->whereIn('status', [Source::READY, Source::FAILED])
            ->where(function ($q) {
                $q->where(fn ($d) => $d->where('resync', 'daily')->where('last_synced_at', '<=', now()->subHours(23)))
                    ->orWhere(fn ($w) => $w->where('resync', 'weekly')->where('last_synced_at', '<=', now()->subDays(7)->addHour()));
            })
            ->get();

        foreach ($due as $source) {
            $source->forceFill(['status' => Source::PENDING])->save();
            IngestSource::dispatch($source->id);
        }

        // Pages Facebook connectees par l'API officielle : relues chaque semaine.
        $pages = FacebookConnection::withoutGlobalScopes()
            ->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('last_synced_at')->orWhere('last_synced_at', '<=', now()->subDays(7)->addHour()))
            ->get();

        foreach ($pages as $connection) {
            $facebook->refresh($connection);
        }

        $this->info($due->count().' site(s) et '.$pages->count().' page(s) Facebook relancé(s).');

        return self::SUCCESS;
    }
}
