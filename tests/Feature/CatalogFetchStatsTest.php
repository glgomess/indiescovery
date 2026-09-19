<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/** Covers catalog:fetch-stats filling tags and review counts from SteamSpy, or from Steam as the fallback. */
class CatalogFetchStatsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
    }

    /** Fakes both sources: SteamSpy with real user tags, Steam with coarse genres and review counts. */
    private function fakeSources(): void
    {
        Http::fake([
            '*steamspy*' => Http::response([
                'appid' => 1, 'name' => 'A', 'positive' => 90, 'negative' => 10,
                'tags' => ['Metroidvania' => 500, 'Souls-like' => 300],
            ]),
            '*/appdetails*' => fn ($r) => Http::response([
                (string) $r->data()['appids'] => ['success' => true, 'data' => ['genres' => [['description' => 'Indie']]]],
            ]),
            '*/appreviews/*' => Http::response([
                'success' => 1, 'query_summary' => ['total_positive' => 30, 'total_negative' => 10],
            ]),
        ]);
    }

    /** SteamSpy fills tags in vote order, review count and positive ratio. */
    public function test_it_fills_stats_from_steamspy(): void
    {
        DB::table('games')->insert(['app_id' => 1, 'name' => 'A']);
        $this->fakeSources();

        $this->artisan('catalog:fetch-stats')->assertSuccessful();

        $game = DB::table('games')->where('app_id', 1)->first();
        $this->assertSame(['Metroidvania', 'Souls-like'], json_decode($game->tags));
        $this->assertSame(100, (int) $game->review_count);
        $this->assertEqualsWithDelta(0.9, (float) $game->positive_ratio, 0.001);
        $this->assertSame('steamspy', $game->stats_source);
        $this->assertNotNull($game->stats_fetched_at);
    }

    /** Falling back to Steam continues with unfilled rows only and never downgrades SteamSpy data. */
    public function test_fallback_continues_from_same_place(): void
    {
        DB::table('games')->insert([['app_id' => 1, 'name' => 'A'], ['app_id' => 2, 'name' => 'B']]);
        $this->fakeSources();
        config(['catalog.batch.stats_steamspy' => 1]);
        $this->artisan('catalog:fetch-stats')->assertSuccessful();

        config(['catalog.stats_source' => 'steam']);
        $this->artisan('catalog:fetch-stats')->assertSuccessful();

        $this->assertSame('steamspy', DB::table('games')->where('app_id', 1)->value('stats_source'));
        $game = DB::table('games')->where('app_id', 2)->first();
        $this->assertSame('steam', $game->stats_source);
        $this->assertSame(['Indie'], json_decode($game->tags));
        $this->assertSame(40, (int) $game->review_count);
    }

    /** Switching back to SteamSpy re-queues rows the coarse fallback filled. */
    public function test_switching_back_upgrades_fallback_rows(): void
    {
        DB::table('games')->insert(['app_id' => 1, 'name' => 'A', 'stats_source' => 'steam',
            'stats_fetched_at' => now(), 'tags' => '["Indie"]']);
        $this->fakeSources();

        $this->artisan('catalog:fetch-stats')->assertSuccessful();

        $this->assertSame('steamspy', DB::table('games')->where('app_id', 1)->value('stats_source'));
    }

    /** SteamSpy answers unknown apps with zeroed counts and a null name; that is "no data", not a fetch. */
    public function test_unknown_app_on_steamspy_is_not_stamped(): void
    {
        DB::table('games')->insert(['app_id' => 999, 'name' => 'Bogus']);
        Http::fake(['*steamspy*' => Http::response(['appid' => 999, 'name' => null, 'positive' => 0, 'negative' => 0, 'tags' => []])]);

        $this->artisan('catalog:fetch-stats')->assertSuccessful();

        $game = DB::table('games')->where('app_id', 999)->first();
        $this->assertNull($game->stats_fetched_at);
        $this->assertSame(1, (int) $game->stats_attempts);
        $this->assertNotNull($game->stats_error);
    }

    /** A SteamSpy outage stops the batch without burning attempts, so the fallback can still pick every row up. */
    public function test_outage_does_not_burn_attempts(): void
    {
        DB::table('games')->insert([['app_id' => 1, 'name' => 'A'], ['app_id' => 2, 'name' => 'B']]);
        Http::fake(['*steamspy*' => Http::response('', 503)]);

        $this->artisan('catalog:fetch-stats')->assertSuccessful();

        Http::assertSentCount(1);
        $this->assertSame(0, (int) DB::table('games')->sum('stats_attempts'));
        $this->assertSame(0, DB::table('games')->whereNotNull('stats_fetched_at')->count());
    }
}
