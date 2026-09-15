<?php

namespace App\Services\Steam;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Reads a Steam user's public profile and owned games from the Steam Web API. */
class SteamClient
{
    private const BASE_URL = 'https://api.steampowered.com';

    /** A request pre-configured with the API key, a timeout and a short retry, since Steam is flaky. */
    private function request(): PendingRequest
    {
        return Http::baseUrl(self::BASE_URL)
            ->timeout(10)
            ->retry(2, 200, throw: false)
            ->withQueryParameters(['key' => config('services.steam.key')]);
    }

    /**
     * Returns the games the user owns, or an empty list if the profile is private or Steam is unavailable.
     *
     * ponytail: an outage and a genuinely empty library are indistinguishable here. Harmless while
     * nothing is persisted, but once libraries are cached this MUST NOT overwrite stored data with
     * an empty list -- see docs/internal_documentation.md.
     */
    public function getOwnedGames(string $steamId): array
    {
        $response = $this->get('/IPlayerService/GetOwnedGames/v1/', [
            'steamid' => $steamId,
            'include_appinfo' => 'true',
            'include_played_free_games' => 'true',
        ]);

        return array_map(
            SteamGame::fromApi(...),
            $response['response']['games'] ?? []
        );
    }

    /** Returns the user's public profile, or null if it does not exist or Steam is unavailable. */
    public function getPlayerSummary(string $steamId): ?SteamPlayer
    {
        $response = $this->get('/ISteamUser/GetPlayerSummaries/v2/', ['steamids' => $steamId]);
        $player = $response['response']['players'][0] ?? null;

        return $player === null ? null : SteamPlayer::fromApi($player);
    }

    /** Performs one Steam API call, turning any transport or upstream failure into an empty array. */
    private function get(string $path, array $query): array
    {
        try {
            $response = $this->request()->get($path, $query);
        } catch (Throwable $e) {
            Log::warning('Steam API call failed', ['path' => $path, 'error' => $e->getMessage()]);

            return [];
        }

        if ($response->failed()) {
            Log::warning('Steam API returned an error', ['path' => $path, 'status' => $response->status()]);

            return [];
        }

        return $response->json() ?? [];
    }
}
