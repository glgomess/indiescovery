<?php

namespace App\Services\SteamSpy;

use App\Services\UpstreamUnavailable;
use Illuminate\Container\Attributes\Config;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Reads user tags and review counts from SteamSpy, a third-party Steam statistics API.
 *
 * ponytail: single third-party dependency with no uptime guarantee. If it dies, set
 * CATALOG_STATS_SOURCE=steam; FetchStats resumes on the same rows (see docs/internal_documentation.md).
 */
class SteamSpyClient
{
    /** Receives the SteamSpy API URL from config, injected by the container. */
    public function __construct(
        #[Config('catalog.steamspy_url')] private readonly string $url,
    ) {}

    /** Returns ['tags' => names ordered by votes, 'positive' => int, 'negative' => int], or null when SteamSpy has no data. */
    public function appDetails(int $appId): ?array
    {
        try {
            $response = Http::timeout(10)->get($this->url, ['request' => 'appdetails', 'appid' => $appId]);
        } catch (Throwable $e) {
            throw new UpstreamUnavailable("SteamSpy unreachable: {$e->getMessage()}", previous: $e);
        }

        if ($response->status() === 429 || $response->serverError()) {
            throw new UpstreamUnavailable("SteamSpy returned {$response->status()}");
        }

        $body = $response->json();
        // Unknown apps come back with zeroed counts and a null name, not an error.
        if (! isset($body['name'], $body['positive'], $body['negative'])) {
            return null;
        }

        // SteamSpy returns [] instead of {} when an app has no tags.
        $tags = is_array($body['tags'] ?? null) ? $body['tags'] : [];
        arsort($tags);

        return ['tags' => array_keys($tags), 'positive' => (int) $body['positive'], 'negative' => (int) $body['negative']];
    }
}
