<?php

namespace App\Services\Steam;

/** A Steam user's public profile, as returned by ISteamUser/GetPlayerSummaries. */
readonly class SteamPlayer
{
    public function __construct(
        public string $steamId,
        public string $personaName,
        public string $profileUrl,
        public string $avatarFull,
        public ?string $countryCode,
        public ?int $lastLogoff,
        public ?int $timeCreated,
    ) {}

    /** Builds a SteamPlayer from one raw entry of the GetPlayerSummaries response. */
    public static function fromApi(array $player): self
    {
        return new self(
            steamId: (string) ($player['steamid'] ?? ''),
            personaName: (string) ($player['personaname'] ?? ''),
            profileUrl: (string) ($player['profileurl'] ?? ''),
            avatarFull: (string) ($player['avatarfull'] ?? ''),
            countryCode: $player['loccountrycode'] ?? null,
            lastLogoff: isset($player['lastlogoff']) ? (int) $player['lastlogoff'] : null,
            timeCreated: isset($player['timecreated']) ? (int) $player['timecreated'] : null,
        );
    }
}
