<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Exercises the real routes, middleware and session to prove the Steam login flow works end to end. */
class SteamAuthFlowTest extends TestCase
{
    private const STEAM_ID = '76561197960287930';

    /** A minimal but structurally valid id_res callback query string. */
    private function callbackQuery(string $claimedId): array
    {
        return [
            'openid.ns' => 'http://specs.openid.net/auth/2.0',
            'openid.mode' => 'id_res',
            'openid.claimed_id' => $claimedId,
            'openid.identity' => $claimedId,
            'openid.sig' => 'deadbeef',
            'openid.signed' => 'claimed_id,identity',
        ];
    }

    /** Hitting the login route sends the browser to Steam's OpenID endpoint. */
    public function test_login_redirects_to_steam(): void
    {
        $this->get('/auth/steam')
            ->assertRedirectContains('https://steamcommunity.com/openid/login');
    }

    /** A callback Steam confirms stores the steam id in the session and lands the user on the home page. */
    public function test_successful_callback_stores_steam_id_in_session(): void
    {
        Http::fake(['*/openid/login' => Http::response("is_valid:true\n")]);

        $this->get('/auth/steam/callback?'.http_build_query(
            $this->callbackQuery('https://steamcommunity.com/openid/id/'.self::STEAM_ID)
        ))
            ->assertRedirect('/')
            ->assertSessionHas('steam_id', self::STEAM_ID);
    }

    /** A rejected callback must leave the session untouched and bounce the user back to the login. */
    public function test_rejected_callback_creates_no_session(): void
    {
        Http::fake(['*/openid/login' => Http::response("is_valid:false\n")]);

        $this->get('/auth/steam/callback?'.http_build_query(
            $this->callbackQuery('https://steamcommunity.com/openid/id/'.self::STEAM_ID)
        ))
            ->assertRedirect('/auth/steam')
            ->assertSessionMissing('steam_id');
    }

    /** A callback with no openid parameters at all must redirect, not blow up with a 500 (CLAUDE.md Rule #8). */
    public function test_empty_callback_does_not_error(): void
    {
        Http::fake(['*/openid/login' => Http::response("is_valid:false\n")]);

        $this->get('/auth/steam/callback')
            ->assertRedirect('/auth/steam')
            ->assertSessionMissing('steam_id');
    }

    /** An anonymous visitor to the home page is sent to the Steam login. */
    public function test_home_requires_authentication(): void
    {
        $this->get('/')->assertRedirect('/auth/steam');
    }

    /** With a steam id in session the home page resolves and reports the player's library. */
    public function test_home_renders_for_an_authenticated_visitor(): void
    {
        config(['services.steam.key' => 'test-key']);
        Http::fake([
            '*/GetPlayerSummaries/*' => Http::response(['response' => ['players' => [[
                'steamid' => self::STEAM_ID,
                'personaname' => 'Robin',
                'profileurl' => 'https://steamcommunity.com/id/robinwalker/',
                'avatarfull' => 'https://avatars.steamstatic.com/full.jpg',
            ]]]]),
            '*/GetOwnedGames/*' => Http::response(['response' => ['game_count' => 1, 'games' => [
                ['appid' => 440, 'name' => 'Team Fortress 2', 'playtime_forever' => 120],
            ]]]),
        ]);

        $this->withSession(['steam_id' => self::STEAM_ID])
            ->get('/')
            ->assertOk()
            ->assertSee('Robin')
            ->assertSee('1');
    }

    /** The home page must still render when Steam is down, rather than returning a 500. */
    public function test_home_survives_a_steam_outage(): void
    {
        config(['services.steam.key' => 'test-key']);
        Http::fake(['*' => Http::response('', 500)]);

        $this->withSession(['steam_id' => self::STEAM_ID])
            ->get('/')
            ->assertOk();
    }
}
