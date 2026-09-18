<?php

namespace App\Services\Steam;

use Illuminate\Container\Attributes\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Implements Steam's OpenID 2.0 login: builds the redirect and verifies the signed callback. */
class SteamOpenId
{
    /** Receives the Steam OpenID endpoint from config, injected by the container. */
    public function __construct(
        #[Config('services.steam.login_url')] private readonly string $loginUrl,
    ) {}

    /** Only a claimed_id anchored at Steam's own identity namespace is acceptable. */
    private const CLAIMED_ID_PATTERN = '#^https://steamcommunity\.com/openid/id/(\d+)$#';

    /** Builds the URL that sends the browser to Steam to authenticate and come back to $returnTo. */
    public function loginUrl(string $returnTo): string
    {
        return $this->loginUrl.'?'.http_build_query([
            'openid.ns' => 'http://specs.openid.net/auth/2.0',
            'openid.mode' => 'checkid_setup',
            'openid.return_to' => $returnTo,
            'openid.realm' => config('app.url'),
            'openid.identity' => 'http://specs.openid.net/auth/2.0/identifier_select',
            'openid.claimed_id' => 'http://specs.openid.net/auth/2.0/identifier_select',
        ]);
    }

    /**
     * Returns the verified steam id from a callback, or null if Steam rejects it or cannot be reached.
     *
     * Fails closed on purpose: the browser controls every parameter here, so the signature must be
     * confirmed by Steam itself before the id is trusted.
     */
    public function verify(array $params): ?string
    {
        $claimedId = $params['openid.claimed_id'] ?? '';

        if (! preg_match(self::CLAIMED_ID_PATTERN, $claimedId, $matches)) {
            return null;
        }

        if (! $this->steamConfirmsSignature($params)) {
            return null;
        }

        return $matches[1];
    }

    /** Asks Steam to re-check the signature it issued, replacing the browser-supplied mode. */
    private function steamConfirmsSignature(array $params): bool
    {
        try {
            $response = Http::timeout(10)->asForm()->post(
                $this->loginUrl,
                [...$params, 'openid.mode' => 'check_authentication']
            );
        } catch (Throwable $e) {
            Log::warning('Steam OpenID verification failed', ['error' => $e->getMessage()]);

            return false;
        }

        return $response->successful() && str_contains($response->body(), 'is_valid:true');
    }
}
