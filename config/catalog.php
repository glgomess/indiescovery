<?php

/*
 * Steam catalog crawl settings. Batch sizes are per scheduler run (every minute) and are sized to
 * the observed upstream limits: Steam store ~200 requests / 5 min per IP, SteamSpy ~1 request / s.
 */
return [
    // 'steamspy' (real user tags) or 'steam' (fallback: coarse genres + appreviews). See docs/internal_documentation.md.
    'stats_source' => env('CATALOG_STATS_SOURCE', 'steamspy'),

    'store_url' => env('STEAM_STORE_URL', 'https://store.steampowered.com'),
    'steamspy_url' => env('STEAMSPY_URL', 'https://steamspy.com/api.php'),

    'batch' => [
        'discover_page' => 10000,
        'details' => 40,
        // The Steam fallback shares the store limit with fetch-details (2 calls per app), so both shrink.
        'details_with_steam_stats' => 20,
        'stats_steamspy' => 50,
        'stats_steam' => 10,
    ],

    'steamspy_delay_ms' => 1000,
    'refresh_days' => 30,
    'max_attempts' => 3,
];
