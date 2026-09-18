<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Drives the Steam login callback end to end and asserts the user and library snapshot it persists. */
class LibrarySyncTest extends TestCase
{
    use RefreshDatabase;

    private const STEAM_ID = '76561197960287930';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.steam.key' => 'test-key']);
    }

    /** Fakes Steam (replacing earlier fakes, since stubs otherwise stack first-match-wins) with the given GetOwnedGames and GetPlayerSummaries responses and a valid OpenID signature. */
    private function fakeSteam(mixed $ownedGames, mixed $summary = null): void
    {
        Http::swap(new Factory);
        Http::fake([
            '*/openid/login' => Http::response("is_valid:true\n"),
            '*/GetPlayerSummaries/*' => $summary ?? Http::response(['response' => ['players' => [[
                'steamid' => self::STEAM_ID,
                'personaname' => 'Robin',
                'profileurl' => 'https://steamcommunity.com/id/robin/',
                'avatarfull' => 'https://avatars.steamstatic.com/full.jpg',
                'timecreated' => 1272672000,
            ]]]]),
            '*/GetOwnedGames/*' => $ownedGames,
        ]);
    }

    /** A GetOwnedGames response listing the given [appid => playtime] games. */
    private function games(array $playtimes): mixed
    {
        $games = [];
        foreach ($playtimes as $appId => $minutes) {
            $games[] = ['appid' => $appId, 'name' => "Game $appId", 'playtime_forever' => $minutes, 'playtime_2weeks' => 5];
        }

        return Http::response(['response' => ['game_count' => count($games), 'games' => $games]]);
    }

    /** Hits the OpenID callback as Steam would after a successful sign-in. */
    private function login(): \Illuminate\Testing\TestResponse
    {
        $claimedId = 'https://steamcommunity.com/openid/id/'.self::STEAM_ID;

        return $this->get('/auth/steam/callback?'.http_build_query([
            'openid.ns' => 'http://specs.openid.net/auth/2.0',
            'openid.mode' => 'id_res',
            'openid.claimed_id' => $claimedId,
            'openid.identity' => $claimedId,
            'openid.sig' => 'deadbeef',
            'openid.signed' => 'claimed_id,identity',
        ]));
    }

    /** Playtime of one owned game row. */
    private function ownedGame(int $appId): object
    {
        return DB::table('owned_games')->where('steam_id', self::STEAM_ID)->where('app_id', $appId)->first();
    }

    /** First login stores the profile, one row per game, and stamps the sync time. */
    public function test_first_login_creates_user_and_library(): void
    {
        $this->fakeSteam($this->games([440 => 120, 570 => 0]));

        $this->login()->assertRedirect(config('app.frontend_url').'/profile');

        $user = User::find(self::STEAM_ID);
        $this->assertSame('Robin', $user->persona_name);
        $this->assertSame('https://avatars.steamstatic.com/full.jpg', $user->avatar_url);
        $this->assertSame(1272672000, $user->steam_created_at->getTimestamp());
        $this->assertNotNull($user->library_synced_at);
        $this->assertFalse($user->library_private);
        $this->assertSame(2, DB::table('owned_games')->count());
        $this->assertSame(120, (int) $this->ownedGame(440)->playtime_minutes);
        $this->assertSame(5, (int) $this->ownedGame(440)->playtime_2w_minutes);
    }

    /** A second login within the resync window skips GetOwnedGames but still records the login. */
    public function test_login_within_resync_window_skips_library_fetch(): void
    {
        $this->fakeSteam($this->games([440 => 120]));
        $this->login();
        $firstLogin = User::find(self::STEAM_ID)->last_login_at;

        $this->travel(2)->days();
        $this->fakeSteam($this->games([440 => 999]));
        $this->login();

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'GetOwnedGames'));
        $this->assertTrue(User::find(self::STEAM_ID)->last_login_at->gt($firstLogin));
        $this->assertSame(120, (int) $this->ownedGame(440)->playtime_minutes);
    }

    /** A stale library is refetched, updating playtime while first_seen_at keeps its original value. */
    public function test_resync_after_window_updates_playtime_and_keeps_first_seen(): void
    {
        $this->fakeSteam($this->games([440 => 120]));
        $this->login();
        $firstSeen = $this->ownedGame(440)->first_seen_at;

        $this->travel(8)->days();
        $this->fakeSteam($this->games([440 => 300, 570 => 10]));
        $this->login();

        $this->assertSame(300, (int) $this->ownedGame(440)->playtime_minutes);
        $this->assertSame($firstSeen, $this->ownedGame(440)->first_seen_at);
        $this->assertNotSame($firstSeen, $this->ownedGame(440)->last_seen_at);
        $this->assertNotNull($this->ownedGame(570));
    }

    /** Steam suddenly reporting zero games for a user who had some is treated as a hiccup, not a wipe. */
    public function test_suspicious_empty_library_is_ignored(): void
    {
        $this->fakeSteam($this->games([440 => 120]));
        $this->login();
        $syncedAt = User::find(self::STEAM_ID)->library_synced_at;

        $this->travel(8)->days();
        $this->fakeSteam(Http::response(['response' => ['game_count' => 0]]));
        $this->login();

        $this->assertSame(1, DB::table('owned_games')->count());
        $this->assertEquals($syncedAt, User::find(self::STEAM_ID)->library_synced_at);
    }

    /** A private profile is flagged and stamped, and the old snapshot is kept. */
    public function test_private_profile_is_flagged_and_keeps_rows(): void
    {
        $this->fakeSteam($this->games([440 => 120]));
        $this->login();

        $this->travel(8)->days();
        $this->fakeSteam(Http::response(['response' => new \stdClass]));
        $this->login();

        $user = User::find(self::STEAM_ID);
        $this->assertTrue($user->library_private);
        $this->assertTrue($user->library_synced_at->isToday());
        $this->assertSame(1, DB::table('owned_games')->count());
    }

    /** GetOwnedGames failing must not block the login, and must leave the library unsynced. */
    public function test_owned_games_outage_still_logs_in(): void
    {
        $this->fakeSteam(Http::response('', 500));

        $this->login()
            ->assertRedirect(config('app.frontend_url').'/profile')
            ->assertSessionHas('steam_id', self::STEAM_ID);

        $this->assertNotNull(User::find(self::STEAM_ID));
        $this->assertNull(User::find(self::STEAM_ID)->library_synced_at);
    }

    /** GetPlayerSummaries failing on first login still creates the user, named after the steam id. */
    public function test_summary_outage_creates_placeholder_user(): void
    {
        $this->fakeSteam($this->games([440 => 120]), Http::response('', 500));

        $this->login()->assertRedirect(config('app.frontend_url').'/profile');

        $this->assertSame(self::STEAM_ID, User::find(self::STEAM_ID)->persona_name);
    }
}
