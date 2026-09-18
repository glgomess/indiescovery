<?php

namespace App\Services\Steam;

/** One game in a Steam user's owned library, as returned by IPlayerService/GetOwnedGames. */
readonly class SteamGame
{
    public function __construct(
        public int $appId,
        public string $name,
        public int $playtimeForeverMinutes,
        public ?int $playtimeTwoWeeksMinutes,
        public ?string $iconUrl,
    ) {}

    /** Builds a SteamGame from one raw entry of the GetOwnedGames response. */
    public static function fromApi(array $game): self
    {
        $appId = (int) ($game['appid'] ?? 0);
        $icon = $game['img_icon_url'] ?? '';

        return new self(
            appId: $appId,
            name: (string) ($game['name'] ?? ''),
            playtimeForeverMinutes: (int) ($game['playtime_forever'] ?? 0),
            playtimeTwoWeeksMinutes: isset($game['playtime_2weeks']) ? (int) $game['playtime_2weeks'] : null,
            iconUrl: $icon === ''
                ? null
                : config('services.steam.media_url')."/steamcommunity/public/images/apps/{$appId}/{$icon}.jpg",
        );
    }
}
