<?php

namespace Tests\Feature;

use App\Services\Steam\SteamOpenId;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Covers building the Steam OpenID 2.0 redirect and verifying the callback that comes back from it. */
class SteamOpenIdTest extends TestCase
{
    private const CALLBACK = 'http://localhost:8000/auth/steam/callback';

    /** A valid id_res callback as Steam sends it, parameterised by claimed_id. */
    private function callbackParams(string $claimedId): array
    {
        return [
            'openid.ns' => 'http://specs.openid.net/auth/2.0',
            'openid.mode' => 'id_res',
            'openid.op_endpoint' => 'https://steamcommunity.com/openid/login',
            'openid.claimed_id' => $claimedId,
            'openid.identity' => $claimedId,
            'openid.return_to' => self::CALLBACK,
            'openid.response_nonce' => '2026-09-15T00:00:00Zabc',
            'openid.assoc_handle' => '1234567890',
            'openid.signed' => 'signed,op_endpoint,claimed_id,identity,return_to,response_nonce,assoc_handle',
            'openid.sig' => 'deadbeef',
        ];
    }

    /** The Steam login endpoint comes from config, so it can change without a code change. */
    public function test_login_url_comes_from_config(): void
    {
        config(['services.steam.login_url' => 'https://steam.test/openid/login']);

        $url = app(SteamOpenId::class)->loginUrl(self::CALLBACK);

        $this->assertStringStartsWith('https://steam.test/openid/login?', $url);
    }

    /** The redirect URL must carry the six openid.* parameters Steam requires, properly encoded. */
    public function test_it_builds_the_login_redirect_url(): void
    {
        $url = app(SteamOpenId::class)->loginUrl(self::CALLBACK);

        $this->assertStringStartsWith('https://steamcommunity.com/openid/login?', $url);

        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('http://specs.openid.net/auth/2.0', $query['openid_ns'] ?? $query['openid.ns']);
        $this->assertSame('checkid_setup', $query['openid_mode'] ?? $query['openid.mode']);
        $this->assertSame(self::CALLBACK, $query['openid_return_to'] ?? $query['openid.return_to']);
        $this->assertSame(config('app.url'), $query['openid_realm'] ?? $query['openid.realm']);
        $this->assertSame('http://specs.openid.net/auth/2.0/identifier_select', $query['openid_identity'] ?? $query['openid.identity']);
        $this->assertSame('http://specs.openid.net/auth/2.0/identifier_select', $query['openid_claimed_id'] ?? $query['openid.claimed_id']);
    }

    /** A signature Steam confirms with is_valid:true yields the numeric steam id from claimed_id. */
    public function test_it_extracts_the_steam_id_when_steam_confirms_the_signature(): void
    {
        Http::fake(['*/openid/login' => Http::response("ns:http://specs.openid.net/auth/2.0\nis_valid:true\n")]);

        $steamId = app(SteamOpenId::class)->verify(
            $this->callbackParams('https://steamcommunity.com/openid/id/76561197960287930')
        );

        $this->assertSame('76561197960287930', $steamId);
    }

    /** Steam must be asked to verify with mode=check_authentication, never the mode the browser supplied. */
    public function test_it_asks_steam_to_verify_with_check_authentication(): void
    {
        Http::fake(['*/openid/login' => Http::response("is_valid:true\n")]);

        app(SteamOpenId::class)->verify($this->callbackParams('https://steamcommunity.com/openid/id/76561197960287930'));

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request['openid.mode'] === 'check_authentication'
            && $request['openid.sig'] === 'deadbeef');
    }

    /** A forged signature that Steam rejects must not authenticate anyone. */
    public function test_it_rejects_an_invalid_signature(): void
    {
        Http::fake(['*/openid/login' => Http::response("is_valid:false\n")]);

        $this->assertNull(app(SteamOpenId::class)->verify(
            $this->callbackParams('https://steamcommunity.com/openid/id/76561197960287930')
        ));
    }

    /** A claimed_id on an attacker-controlled host must be rejected even if Steam says the signature is valid. */
    public function test_it_rejects_a_claimed_id_from_another_host(): void
    {
        Http::fake(['*/openid/login' => Http::response("is_valid:true\n")]);

        $this->assertNull(app(SteamOpenId::class)->verify(
            $this->callbackParams('https://evil.example.com/https://steamcommunity.com/openid/id/76561197960287930')
        ));
    }

    /** If the verification call to Steam fails, the login must fail closed rather than open (CLAUDE.md Rule #9). */
    public function test_it_fails_closed_when_steam_is_unreachable(): void
    {
        Http::fake(['*/openid/login' => Http::response('', 500)]);

        $this->assertNull(app(SteamOpenId::class)->verify(
            $this->callbackParams('https://steamcommunity.com/openid/id/76561197960287930')
        ));
    }
}
