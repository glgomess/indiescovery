<?php

namespace App\Services\Steam;

use Illuminate\Container\Attributes\Config;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Reads a Steam user's public profile and owned games from the Steam Web API. */
class SteamClient
{
    /** Receives the Steam Web API base URL and key from config, injected by the container. */
    public function __construct(
        #[Config('services.steam.api_url')] private readonly string $apiUrl,
        #[Config('services.steam.key')] private readonly ?string $apiKey,
    ) {}

    /** A request pre-configured with the API key, a timeout and a short retry, since Steam is flaky. */
    private function request(): PendingRequest
    {
        return Http::baseUrl($this->apiUrl)
            ->timeout(10)
            ->retry(2, 200, throw: false)
            ->withQueryParameters(['key' => $this->apiKey]);
    }

    /** Returned by getOwnedGames when the user's game details are not public. */
    public const PRIVATE = 'private';

    /** Returns the user's games, PRIVATE when Steam hides them, or null when Steam failed (never confuse these). */
    public function getOwnedGames(string $steamId): array|string|null
    {
        $response = $this->get('/IPlayerService/GetOwnedGames/v1/', [
            'steamid' => $steamId,
            'include_appinfo' => 'true',
            'include_played_free_games' => 'true',
        ]);

        if ($response === []) {
            return null;
        }

        if (! isset($response['response']['game_count'])) {
            return self::PRIVATE;
        }

        return array_map(SteamGame::fromApi(...), $response['response']['games'] ?? []);
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
