<?php

namespace App\Support;

use App\Models\Bot;
use App\Models\Message;
use App\Services\Analytics\Stats;
use App\Services\Analytics\Tracker;
use Illuminate\Support\Carbon;

/**
 * Le rythme des clients d'un assistant : à quelles heures ils écrivent, par quel canal, avec quelle aide humaine.
 * Calculé à partir de ses propres conversations (le périmètre par entreprise s'applique : un client ne voit que les siennes),
 * sans rien emprunter à la mesure d'audience de la plateforme. Même forme que Stats::heatmap pour réutiliser la carte.
 */
final class CustomerRhythm
{
    /**
     * @return array{matrix:list<list<int>>, max:int, total:int, by_hour:list<int>, by_day:list<int>, peak:?array{day:string,hour:int,count:int}, peak_hour:?int, peak_day:?string, channels:array<string,int>}
     */
    public static function forBot(Bot $bot, int $days = 30): array
    {
        $tz = Tracker::timezone();
        $since = now()->subDays($days);
        $conversations = $bot->conversations()->real()->where('created_at', '>=', $since);

        $matrix = array_fill(0, 7, array_fill(0, 24, 0));

        // Les 20 000 derniers messages de clients suffisent : la carte montre une tendance, pas un inventaire.
        Message::whereIn('conversation_id', $bot->conversations()->real()->select('id'))
            ->where('role', Message::USER)->where('created_at', '>=', $since)
            ->latest('id')->limit(20000)->pluck('created_at')
            ->each(function ($at) use (&$matrix, $tz) {
                $local = Carbon::parse($at)->setTimezone($tz);
                $matrix[$local->dayOfWeekIso - 1][$local->hour]++;
            });

        $byDay = array_map('array_sum', $matrix);
        $byHour = array_map('array_sum', array_map(null, ...$matrix));
        $total = array_sum($byDay);
        $peak = null;

        foreach ($matrix as $day => $hours) {
            foreach ($hours as $hour => $count) {
                if ($count > 0 && (! $peak || $count > $peak['count'])) {
                    $peak = ['day' => Stats::DAYS[$day], 'hour' => $hour, 'count' => $count];
                }
            }
        }

        $labels = ['web' => 'Site web', 'whatsapp' => 'WhatsApp', 'api' => 'Application', 'facebook' => 'Facebook'];

        return [
            'matrix' => $matrix,
            'max' => max(1, ...array_map('max', $matrix)),
            'total' => $total,
            'by_hour' => array_values($byHour),
            'by_day' => array_values($byDay),
            'peak' => $peak,
            'peak_hour' => $total > 0 ? array_search(max($byHour), $byHour, true) : null,
            'peak_day' => $total > 0 ? Stats::DAYS[array_search(max($byDay), $byDay, true)] : null,
            'channels' => (clone $conversations)->selectRaw('channel, count(*) as n')->groupBy('channel')->orderByDesc('n')->pluck('n', 'channel')
                ->mapWithKeys(fn ($n, $channel) => [$labels[$channel] ?? ucfirst((string) $channel) => (int) $n])->all(),
        ];
    }
}
