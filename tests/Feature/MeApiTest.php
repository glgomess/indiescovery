<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Covers the session-backed profile API the React app reads: /api/me and /api/logout. */
class MeApiTest extends TestCase
{
    use RefreshDatabase;

    private const STEAM_ID = '76561197960287930';

    /** Seeds a user with two owned games, one of which the catalog knows about. */
    private function seedProfile(): void
    {
        // ponytail: the games table ships with feat/catalog-crawl; drop this once that branch merges.
        if (! Schema::hasTable('games')) {
            Schema::create('games', function (Blueprint $table) {
                $table->unsignedInteger('app_id')->primary();
                $table->string('name');
                $table->json('tags')->nullable();
                $table->unsignedInteger('review_count')->nullable();
                $table->decimal('positive_ratio', 4, 3)->nullable();
            });
        }

        DB::table('users')->insert([
            'steam_id' => self::STEAM_ID,
            'persona_name' => 'Robin',
            'avatar_url' => 'https://avatars.test/full.jpg',
            'profile_url' => 'https://steamcommunity.com/id/robin/',
            'steam_created_at' => '2010-05-01 00:00:00',
            'last_login_at' => '2026-09-18 10:00:00',
            'library_synced_at' => '2026-09-18 10:00:00',
            'library_private' => false,
        ]);
        DB::table('owned_games')->insert([
            ['steam_id' => self::STEAM_ID, 'app_id' => 440, 'name' => 'Team Fortress 2', 'playtime_minutes' => 120,
                'playtime_2w_minutes' => null, 'first_seen_at' => now(), 'last_seen_at' => now()],
            ['steam_id' => self::STEAM_ID, 'app_id' => 367520, 'name' => 'Hollow Knight', 'playtime_minutes' => 5400,
                'playtime_2w_minutes' => 30, 'first_seen_at' => now(), 'last_seen_at' => now()],
        ]);
        DB::table('games')->insert([
            'app_id' => 367520, 'name' => 'Hollow Knight', 'tags' => json_encode(['Metroidvania', 'Souls-like']),
            'review_count' => 415946, 'positive_ratio' => 0.97,
        ]);
    }

    /** Without a session the API answers 401 JSON, never a redirect to Steam. */
    public function test_me_without_session_is_401(): void
    {
        $this->getJson('/api/me')->assertStatus(401)->assertExactJson(['error' => 'unauthenticated']);
    }

    /** Also a plain browser-style GET to the API gets JSON, since the proxy may not send Accept. */
    public function test_me_without_accept_header_is_401_json(): void
    {
        $this->get('/api/me')->assertStatus(401)->assertExactJson(['error' => 'unauthenticated']);
    }

    /** With a session, the profile and library come back in the contract shape, sorted by playtime. */
    public function test_me_returns_profile_and_library(): void
    {
        config(['services.steam.capsule_url' => 'https://cdn.test/apps']);
        $this->seedProfile();

        $this->withSession(['steam_id' => self::STEAM_ID])->getJson('/api/me')
            ->assertOk()
            ->assertExactJson([
                'player' => [
                    'steamId' => self::STEAM_ID,
                    'name' => 'Robin',
                    'avatarUrl' => 'https://avatars.test/full.jpg',
                    'profileUrl' => 'https://steamcommunity.com/id/robin/',
                    'steamCreatedAt' => '2010-05-01T00:00:00.000000Z',
                ],
                'library' => [
                    'private' => false,
                    'syncedAt' => '2026-09-18T10:00:00.000000Z',
                    'gameCount' => 2,
                    'totalMinutes' => 5520,
                    'recentMinutes' => 30,
                    'games' => [
                        ['appId' => 367520, 'name' => 'Hollow Knight', 'playtimeMinutes' => 5400, 'recentMinutes' => 30,
                            'capsuleUrl' => 'https://cdn.test/apps/367520/header.jpg', 'tags' => ['Metroidvania', 'Souls-like'],
                            'reviewCount' => 415946, 'positiveRatio' => 0.97],
                        ['appId' => 440, 'name' => 'Team Fortress 2', 'playtimeMinutes' => 120, 'recentMinutes' => 0,
                            'capsuleUrl' => 'https://cdn.test/apps/440/header.jpg', 'tags' => null,
                            'reviewCount' => null, 'positiveRatio' => null],
                    ],
                ],
            ]);
    }

    /** A session pointing at a user that no longer exists is treated as logged out. */
    public function test_me_with_unknown_user_is_401(): void
    {
        $this->withSession(['steam_id' => self::STEAM_ID])->getJson('/api/me')
            ->assertStatus(401)->assertExactJson(['error' => 'unauthenticated']);
    }

    /** Logging out answers 204 and drops the steam id from the session. */
    public function test_logout_clears_session(): void
    {
        $this->withSession(['steam_id' => self::STEAM_ID])->postJson('/api/logout')
            ->assertNoContent()
            ->assertSessionMissing('steam_id');
    }

    /** An unknown API route is a JSON 404, not an HTML page. */
    public function test_unknown_api_route_is_json_404(): void
    {
        $this->get('/api/nope')->assertNotFound()->assertJsonStructure(['message']);
    }
}
