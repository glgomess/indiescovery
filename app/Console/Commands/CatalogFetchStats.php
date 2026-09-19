<?php

namespace App\Console\Commands;

use App\Services\Steam\SteamStore;
use App\Services\SteamSpy\SteamSpyClient;
use App\Services\UpstreamUnavailable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/**
 * Fills tags, review count and positive ratio for one batch of games, from the source set in
 * catalog.stats_source. Progress lives in each row, so switching source resumes on the same rows.
 */
#[Signature('catalog:fetch-stats')]
#[Description('Fetch tags and review counts for the next batch of catalog games')]
class CatalogFetchStats extends Command
{
    /** Processes one batch from the stats queue; an upstream outage stops the batch without penalizing games. */
    public function handle(SteamSpyClient $steamSpy, SteamStore $store): int
    {
        $source = config('catalog.stats_source');

        foreach ($this->queue($source, config("catalog.batch.stats_$source")) as $appId) {
            // Counted before the call, so an app that crashes the process is still retired eventually.
            DB::table('games')->where('app_id', $appId)->increment('stats_attempts');

            try {
                $stats = $source === 'steam' ? $this->fromSteam($store, $appId) : $this->fromSteamSpy($steamSpy, $appId);
            } catch (UpstreamUnavailable $e) {
                DB::table('games')->where('app_id', $appId)->decrement('stats_attempts');
                Log::warning('catalog:fetch-stats stopped', ['source' => $source, 'error' => $e->getMessage()]);
                break;
            }

            DB::table('games')->where('app_id', $appId)->update($stats === null
                ? ['stats_error' => "$source returned no data", 'updated_at' => now()]
                : $this->columns($stats, $source));
        }

        return self::SUCCESS;
    }

    /**
     * App ids never fetched or stale, plus (when SteamSpy is the source) rows the coarse Steam fallback
     * filled. The fallback never re-fetches SteamSpy rows, so switching to it cannot downgrade data.
     */
    private function queue(string $source, int $limit): array
    {
        return DB::table('games')
            ->where('stats_attempts', '<', config('catalog.max_attempts'))
            ->where(fn ($q) => $q->whereNull('stats_fetched_at')
                ->orWhere('stats_fetched_at', '<', now()->subDays(config('catalog.refresh_days')))
                ->when($source === 'steamspy', fn ($q) => $q->orWhere('stats_source', 'steam')))
            ->orderByRaw('stats_fetched_at IS NOT NULL, stats_fetched_at')
            ->limit($limit)
            ->pluck('app_id')
            ->all();
    }

    /** Tags and reviews from SteamSpy, throttled to its ~1 request/second limit. */
    private function fromSteamSpy(SteamSpyClient $steamSpy, int $appId): ?array
    {
        Sleep::for(config('catalog.steamspy_delay_ms'))->milliseconds();

        return $steamSpy->appDetails($appId);
    }

    /** Fallback: Steam genres as tags (coarse) plus appreviews totals, two store calls per app. */
    private function fromSteam(SteamStore $store, int $appId): ?array
    {
        $details = $store->appDetails($appId);
        $reviews = $store->appReviews($appId);
        if ($details === null || $reviews === null) {
            return null;
        }

        return [
            'tags' => array_column($details['genres'] ?? [], 'description'),
            'positive' => $reviews[0],
            'negative' => $reviews[1],
        ];
    }

    /** Maps normalized stats to games columns, stamping the row fresh in the same update. */
    private function columns(array $stats, string $source): array
    {
        $total = $stats['positive'] + $stats['negative'];

        return [
            'tags' => json_encode($stats['tags']),
            'review_count' => $total,
            'positive_ratio' => $total > 0 ? round($stats['positive'] / $total, 3) : null,
            'stats_source' => $source,
            'stats_fetched_at' => now(),
            'stats_attempts' => 0,
            'stats_error' => null,
            'updated_at' => now(),
        ];
    }
}
