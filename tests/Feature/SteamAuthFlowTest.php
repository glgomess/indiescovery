<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Exercises the real routes, middleware and session to prove the Steam login flow works end to end. */
class SteamAuthFlowTest extends TestCase
{
    use RefreshDatabase;

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

    /** A callback Steam confirms stores the steam id in the session and lands the user on the frontend profile. */
    public function test_successful_callback_stores_steam_id_in_session(): void
    {
        Http::fake(['*/openid/login' => Http::response("is_valid:true\n"), '*' => Http::response('', 500)]);

        $this->get('/auth/steam/callback?'.http_build_query(
            $this->callbackQuery('https://steamcommunity.com/openid/id/'.self::STEAM_ID)
        ))
            ->assertRedirect(config('app.frontend_url').'/profile')
            ->assertSessionHas('steam_id', self::STEAM_ID);
    }

    /** A rejected callback must leave the session untouched and send the user to the frontend with a failure flag. */
    public function test_rejected_callback_creates_no_session(): void
    {
        Http::fake(['*/openid/login' => Http::response("is_valid:false\n")]);

        $this->get('/auth/steam/callback?'.http_build_query(
            $this->callbackQuery('https://steamcommunity.com/openid/id/'.self::STEAM_ID)
        ))
            ->assertRedirect(config('app.frontend_url').'/?login=failed')
            ->assertSessionMissing('steam_id');
    }

    /** A callback with no openid parameters at all must redirect, not blow up with a 500 (CLAUDE.md Rule #8). */
    public function test_empty_callback_does_not_error(): void
    {
        Http::fake(['*/openid/login' => Http::response("is_valid:false\n")]);

        $this->get('/auth/steam/callback')
            ->assertRedirect(config('app.frontend_url').'/?login=failed')
            ->assertSessionMissing('steam_id');
    }
}
