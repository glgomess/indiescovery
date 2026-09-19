<?php

namespace App\Console\Commands;

use App\Services\Steam\SteamClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Walks Steam's game list into the games table, resuming from a stored cursor after any failure. */
#[Signature('catalog:discover')]
#[Description('Discover Steam games and add new ones to the catalog')]
class CatalogDiscover extends Command
{
    /**
     * Pages through GetAppList, saving the cursor after every page. The watermark only advances after a
     * full sweep, so a crash mid-sweep never skips apps. A crash between the upsert and the cursor save
     * re-fetches that page, which is harmless because the upsert is idempotent.
     */
    public function handle(SteamClient $steam): int
    {
        $state = DB::table('crawl_state')->where('crawl_type', 'discovery')->first();
        $cursor = $state?->last_app_id;
        $watermark = $state?->last_modified_since;
        $sweepStartedAt = now();

        do {
            $page = $steam->getAppList($cursor, $watermark ? strtotime($watermark) : null, config('catalog.batch.discover_page'));
            if ($page === null) {
                Log::warning('catalog:discover stopped: Steam unavailable', ['cursor' => $cursor]);

                return self::SUCCESS;
            }

            $rows = array_map(fn ($app) => [
                'app_id' => $app['appid'],
                'name' => $app['name'],
                'created_at' => now(),
                'updated_at' => now(),
            ], $page['apps'] ?? []);
            DB::table('games')->upsert($rows, ['app_id'], ['name', 'updated_at']);

            $cursor = $page['last_appid'] ?? $cursor;
            $more = $page['have_more_results'] ?? false;
            $this->saveState($more ? ['last_app_id' => $cursor] : [
                'last_app_id' => null,
                'last_modified_since' => $sweepStartedAt,
                'last_completed_at' => now(),
            ]);
        } while ($more);

        return self::SUCCESS;
    }

    /** Upserts the discovery row of crawl_state with the given columns. */
    private function saveState(array $values): void
    {
        DB::table('crawl_state')->updateOrInsert(['crawl_type' => 'discovery'], $values);
    }
}
