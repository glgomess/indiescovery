<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Covers catalog:fetch-details filling store details for queued games, including Steam's failure modes. */
class CatalogFetchDetailsTest extends TestCase
{
    use RefreshDatabase;

    /** An appdetails payload for one app, as the store API returns it. */
    private function details(int $appId): array
    {
        return [(string) $appId => ['success' => true, 'data' => [
            'name' => 'Hollow Knight',
            'short_description' => 'Bugs and swords.',
            'release_date' => ['coming_soon' => false, 'date' => 'Feb 24, 2017'],
            'developers' => ['Team Cherry'],
            'publishers' => ['Team Cherry'],
            'is_free' => false,
            'price_overview' => ['final' => 1499],
        ]]];
    }

    /** A successful fetch fills the details columns and stamps the row. */
    public function test_it_fills_details(): void
    {
        DB::table('games')->insert(['app_id' => 367520, 'name' => 'Hollow Knight']);
        Http::fake(['*/appdetails*' => Http::response($this->details(367520))]);

        $this->artisan('catalog:fetch-details')->assertSuccessful();

        $game = DB::table('games')->where('app_id', 367520)->first();
        $this->assertSame('Bugs and swords.', $game->short_description);
        $this->assertSame('Team Cherry', $game->developer);
        $this->assertSame(1499, (int) $game->price_cents);
        $this->assertStringStartsWith('2017-02-24', $game->release_date);
        $this->assertNotNull($game->details_fetched_at);
        $this->assertSame(0, (int) $game->details_attempts);
    }

    /** A delisted app (success=false) is retired after the configured number of attempts. */
    public function test_it_retires_app_after_max_attempts(): void
    {
        DB::table('games')->insert(['app_id' => 1, 'name' => 'Gone']);
        Http::fake(['*/appdetails*' => Http::response(['1' => ['success' => false]])]);

        foreach (range(1, 4) as $_) {
            $this->artisan('catalog:fetch-details')->assertSuccessful();
        }

        Http::assertSentCount(3);
        $game = DB::table('games')->where('app_id', 1)->first();
        $this->assertSame(3, (int) $game->details_attempts);
        $this->assertNull($game->details_fetched_at);
        $this->assertNotNull($game->details_error);
    }

    /** A 429 stops the batch and does not burn an attempt, so the rate limit never retires games. */
    public function test_rate_limit_stops_batch_without_burning_attempts(): void
    {
        DB::table('games')->insert([['app_id' => 1, 'name' => 'A'], ['app_id' => 2, 'name' => 'B']]);
        Http::fake(['*/appdetails*' => Http::response('', 429)]);

        $this->artisan('catalog:fetch-details')->assertSuccessful();

        Http::assertSentCount(1);
        $this->assertSame(0, (int) DB::table('games')->sum('details_attempts'));
        $this->assertSame(0, DB::table('games')->whereNotNull('details_fetched_at')->count());
    }
}
