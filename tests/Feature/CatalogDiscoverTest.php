<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Covers catalog:discover walking Steam's app list into the games table, resumably. */
class CatalogDiscoverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.steam.key' => 'test-key']);
    }

    /** One page of GetAppList as Steam returns it. */
    private function page(array $apps, bool $more): array
    {
        return ['response' => [
            'apps' => array_map(fn ($id) => ['appid' => $id, 'name' => "Game $id"], $apps),
            'have_more_results' => $more,
            'last_appid' => end($apps),
        ]];
    }

    /** A full sweep inserts every game, clears the cursor and sets the watermark. */
    public function test_full_sweep_inserts_games_and_sets_watermark(): void
    {
        Http::fake(['*/GetAppList/*' => Http::sequence()
            ->push($this->page([10, 20], true))
            ->push($this->page([30], false))]);

        $this->artisan('catalog:discover')->assertSuccessful();

        $this->assertSame([10, 20, 30], DB::table('games')->orderBy('app_id')->pluck('app_id')->all());
        $this->assertSame('Game 30', DB::table('games')->where('app_id', 30)->value('name'));

        $state = DB::table('crawl_state')->where('crawl_type', 'discovery')->first();
        $this->assertNull($state->last_app_id);
        $this->assertNotNull($state->last_modified_since);
    }

    /** Steam failing mid-sweep keeps the cursor and does NOT advance the watermark, so nothing is skipped. */
    public function test_partial_sweep_keeps_cursor_and_watermark(): void
    {
        Http::fake(['*/GetAppList/*' => Http::sequence()
            ->push($this->page([10, 20], true))
            ->push('', 500)]);

        $this->artisan('catalog:discover')->assertSuccessful();

        $state = DB::table('crawl_state')->where('crawl_type', 'discovery')->first();
        $this->assertSame(20, (int) $state->last_app_id);
        $this->assertNull($state->last_modified_since);
    }

    /** The next run resumes from the stored cursor instead of starting over. */
    public function test_it_resumes_from_cursor(): void
    {
        DB::table('crawl_state')->insert(['crawl_type' => 'discovery', 'last_app_id' => 20]);
        Http::fake(['*/GetAppList/*' => Http::response($this->page([30], false))]);

        $this->artisan('catalog:discover')->assertSuccessful();

        Http::assertSent(fn (Request $r) => ($r->data()['last_appid'] ?? null) == 20);
        $this->assertSame([30], DB::table('games')->pluck('app_id')->all());
    }
}
