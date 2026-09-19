<?php

namespace App\Services\Steam;

use App\Services\UpstreamUnavailable;
use Illuminate\Container\Attributes\Config;
use Illuminate\Support\Facades\Http;
use Throwable;

/** Reads per-app data from Steam's keyless store API (appdetails, appreviews), pinned to US/English. */
class SteamStore
{
    /** Receives the store base URL from config, injected by the container. */
    public function __construct(
        #[Config('catalog.store_url')] private readonly string $storeUrl,
    ) {}

    /** Returns the appdetails `data` object, or null when Steam has no data for the app (delisted, region-locked). */
    public function appDetails(int $appId): ?array
    {
        $body = $this->get('/api/appdetails', ['appids' => $appId, 'cc' => 'us', 'l' => 'english']);
        $entry = $body[(string) $appId] ?? null;

        return ($entry['success'] ?? false) ? ($entry['data'] ?? null) : null;
    }

    /** Returns [positive, negative] review totals across all languages, or null when Steam has none. */
    public function appReviews(int $appId): ?array
    {
        $body = $this->get("/appreviews/$appId", ['json' => 1, 'num_per_page' => 0, 'language' => 'all', 'purchase_type' => 'all']);
        $summary = $body['query_summary'] ?? null;

        return $summary === null ? null : [(int) $summary['total_positive'], (int) $summary['total_negative']];
    }

    /** Performs one store call; throws UpstreamUnavailable on transport errors, 429 or 5xx. */
    private function get(string $path, array $query): array
    {
        try {
            $response = Http::baseUrl($this->storeUrl)->timeout(10)->get($path, $query);
        } catch (Throwable $e) {
            throw new UpstreamUnavailable("Steam store unreachable: {$e->getMessage()}", previous: $e);
        }

        if ($response->status() === 429 || $response->serverError()) {
            throw new UpstreamUnavailable("Steam store returned {$response->status()}");
        }

        return $response->json() ?? [];
    }
}
