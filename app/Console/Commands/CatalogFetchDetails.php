<?php

namespace App\Console\Commands;

use App\Services\Steam\SteamStore;
use App\Services\UpstreamUnavailable;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Fills description, dates, developer and price for one batch of games from Steam appdetails. */
#[Signature('catalog:fetch-details')]
#[Description('Fetch Steam store details for the next batch of catalog games')]
class CatalogFetchDetails extends Command
{
    /** Processes one batch from the details queue; an upstream outage stops the batch without penalizing games. */
    public function handle(SteamStore $store): int
    {
        $batch = config('catalog.stats_source') === 'steam'
            ? config('catalog.batch.details_with_steam_stats')
            : config('catalog.batch.details');

        foreach ($this->queue($batch) as $appId) {
            // Counted before the call, so an app that crashes the process is still retired eventually.
            DB::table('games')->where('app_id', $appId)->increment('details_attempts');

            try {
                $data = $store->appDetails($appId);
            } catch (UpstreamUnavailable $e) {
                DB::table('games')->where('app_id', $appId)->decrement('details_attempts');
                Log::warning('catalog:fetch-details stopped', ['error' => $e->getMessage()]);
                break;
            }

            DB::table('games')->where('app_id', $appId)->update($data === null
                ? ['details_error' => 'appdetails returned no data', 'updated_at' => now()]
                : $this->columns($data));
        }

        return self::SUCCESS;
    }

    /** App ids never fetched or stale, skipping those retired after too many failures. */
    private function queue(int $limit): array
    {
        return DB::table('games')
            ->where('details_attempts', '<', config('catalog.max_attempts'))
            ->where(fn ($q) => $q->whereNull('details_fetched_at')
                ->orWhere('details_fetched_at', '<', now()->subDays(config('catalog.refresh_days'))))
            ->orderByRaw('details_fetched_at IS NOT NULL, details_fetched_at')
            ->limit($limit)
            ->pluck('app_id')
            ->all();
    }

    /** Maps an appdetails payload to games columns, stamping the row fresh in the same update. */
    private function columns(array $data): array
    {
        return [
            'short_description' => $data['short_description'] ?? null,
            'release_date' => $this->date($data['release_date']['date'] ?? null),
            'developer' => $data['developers'][0] ?? null,
            'publisher' => $data['publishers'][0] ?? null,
            'price_cents' => ($data['is_free'] ?? false) ? 0 : ($data['price_overview']['final'] ?? null),
            'raw_details' => json_encode($data),
            'details_fetched_at' => now(),
            'details_attempts' => 0,
            'details_error' => null,
            'updated_at' => now(),
        ];
    }

    /** Parses Steam's display date ("Feb 24, 2017"); "Coming soon" and similar become null. */
    private function date(?string $value): ?string
    {
        try {
            return $value ? CarbonImmutable::parse($value)->toDateString() : null;
        } catch (Throwable) {
            return null;
        }
    }
}
