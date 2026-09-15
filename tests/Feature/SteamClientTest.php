<?php

namespace Tests\Feature;

use App\Services\Steam\SteamClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Covers the Steam Web API client against faked upstream responses, including the failure modes Steam actually produces. */
class SteamClientTest extends TestCase
{
    private const STEAM_ID = '76561197960287930';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.steam.key' => 'test-key']);
    }

    /** Maps a normal GetOwnedGames payload into SteamGame objects, including the derived icon URL. */
    public function test_it_maps_owned_games(): void
    {
        Http::fake(['*/GetOwnedGames/*' => Http::response([
            'response' => [
                'game_count' => 2,
                'games' => [
                    ['appid' => 440, 'name' => 'Team Fortress 2', 'playtime_forever' => 120, 'playtime_2weeks' => 30, 'img_icon_url' => 'e3f595a92552da3d664ad00277fad2107345f743'],
                    ['appid' => 570, 'name' => 'Dota 2', 'playtime_forever' => 0, 'img_icon_url' => ''],
                ],
            ],
        ])]);

        $games = app(SteamClient::class)->getOwnedGames(self::STEAM_ID);

        $this->assertCount(2, $games);
        $this->assertSame(440, $games[0]->appId);
        $this->assertSame('Team Fortress 2', $games[0]->name);
        $this->assertSame(120, $games[0]->playtimeForeverMinutes);
        $this->assertSame(30, $games[0]->playtimeTwoWeeksMinutes);
        $this->assertSame(
            'https://media.steampowered.com/steamcommunity/public/images/apps/440/e3f595a92552da3d664ad00277fad2107345f743.jpg',
            $games[0]->iconUrl
        );

        $this->assertNull($games[1]->playtimeTwoWeeksMinutes);
        $this->assertNull($games[1]->iconUrl, 'blank img_icon_url must not produce a broken URL');
    }

    /** A private profile returns an empty response object, which must degrade to an empty list rather than an error. */
    public function test_private_profile_returns_empty_list(): void
    {
        Http::fake(['*/GetOwnedGames/*' => Http::response(['response' => []])]);

        $this->assertSame([], app(SteamClient::class)->getOwnedGames(self::STEAM_ID));
    }

    /** An upstream Steam outage must not bubble a raw exception up to the caller (CLAUDE.md Rule #8). */
    public function test_steam_outage_returns_empty_list(): void
    {
        Http::fake(['*/GetOwnedGames/*' => Http::response('gateway down', 500)]);

        $this->assertSame([], app(SteamClient::class)->getOwnedGames(self::STEAM_ID));
    }

    /** Maps a GetPlayerSummaries payload into a SteamPlayer, keeping optional fields nullable. */
    public function test_it_maps_player_summary(): void
    {
        Http::fake(['*/GetPlayerSummaries/*' => Http::response([
            'response' => ['players' => [[
                'steamid' => self::STEAM_ID,
                'personaname' => 'Robin',
                'profileurl' => 'https://steamcommunity.com/id/robinwalker/',
                'avatarfull' => 'https://avatars.steamstatic.com/full.jpg',
                'loccountrycode' => 'US',
                'lastlogoff' => 1234567890,
            ]]],
        ])]);

        $player = app(SteamClient::class)->getPlayerSummary(self::STEAM_ID);

        $this->assertNotNull($player);
        $this->assertSame('Robin', $player->personaName);
        $this->assertSame('US', $player->countryCode);
        $this->assertSame(1234567890, $player->lastLogoff);
        $this->assertNull($player->timeCreated, 'absent timecreated must be null, not 0');
    }

    /** An unknown or delisted steam id yields an empty players array, which must map to null. */
    public function test_unknown_player_returns_null(): void
    {
        Http::fake(['*/GetPlayerSummaries/*' => Http::response(['response' => ['players' => []]])]);

        $this->assertNull(app(SteamClient::class)->getPlayerSummary(self::STEAM_ID));
    }

    /** A Steam outage on the summary endpoint must also degrade to null instead of throwing. */
    public function test_player_summary_survives_steam_outage(): void
    {
        Http::fake(['*/GetPlayerSummaries/*' => Http::response('', 503)]);

        $this->assertNull(app(SteamClient::class)->getPlayerSummary(self::STEAM_ID));
    }
}
