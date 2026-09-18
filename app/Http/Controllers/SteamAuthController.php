<?php

namespace App\Http\Controllers;

use App\Services\Steam\SteamOpenId;
use Illuminate\Container\Attributes\Config;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Handles the two legs of the Steam OpenID login: the redirect out and the callback back. */
class SteamAuthController
{
    /** Receives the OpenID helper and the frontend URL that users land on after login. */
    public function __construct(
        private readonly SteamOpenId $openId,
        #[Config('app.frontend_url')] private readonly string $frontendUrl,
    ) {}

    /** Sends the visitor to Steam to sign in. */
    public function login(): RedirectResponse
    {
        return redirect()->away($this->openId->loginUrl(route('steam.callback')));
    }

    /** Verifies the callback with Steam and, on success, records the steam id in the session. */
    public function callback(Request $request): RedirectResponse
    {
        $steamId = $this->openId->verify($this->openIdParams($request));

        if ($steamId === null) {
            return redirect()->away($this->frontendUrl.'/?login=failed');
        }

        // Prevents session fixation: the pre-login session id must not survive authentication.
        $request->session()->regenerate();
        $request->session()->put('steam_id', $steamId);

        return redirect()->away($this->frontendUrl.'/profile');
    }

    /**
     * Parses the raw query string, because PHP rewrites dots in parameter names to underscores.
     *
     * Steam's OpenID parameters are all named "openid.something", so $request->query() would hand
     * back "openid_claimed_id" -- and the signature we echo back to Steam for verification has to
     * carry the original names or Steam always answers is_valid:false. Reading QUERY_STRING keeps
     * the names intact. Related: App\Services\Steam\SteamOpenId::verify().
     */
    private function openIdParams(Request $request): array
    {
        $params = [];

        foreach (explode('&', (string) $request->server('QUERY_STRING')) as $pair) {
            if ($pair === '') {
                continue;
            }

            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $params[urldecode($name)] = urldecode($value);
        }

        return $params;
    }
}
