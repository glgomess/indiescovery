<?php

namespace App\Services\Library;

use App\Models\User;
use App\Services\Steam\SteamClient;
use Illuminate\Container\Attributes\Config;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Stores a Steam user's profile at login and refreshes their owned games snapshot when it is stale. */
class LibrarySync
{
    /** Receives the Steam client and the resync window from config. */
    public function __construct(
        private readonly SteamClient $steam,
        #[Config('library.resync_days')] private readonly int $resyncDays,
    ) {}

    /** Upserts the user and, if stale, their library; never throws, so a Steam problem never blocks a login. */
    public function run(string $steamId): void
    {
        try {
            $user = $this->upsertUser($steamId);

            if ($user->library_synced_at?->gt(now()->subDays($this->resyncDays))) {
                return;
            }

            $this->syncLibrary($user);
        } catch (Throwable $e) {
            Log::warning('Library sync failed', ['steam_id' => $steamId, 'error' => $e->getMessage()]);
        }
    }

    /** Creates or refreshes the user row, keeping the old profile (or the steam id as a name) if Steam fails. */
    private function upsertUser(string $steamId): User
    {
        $user = User::find($steamId) ?? new User(['steam_id' => $steamId, 'persona_name' => $steamId]);
        $player = $this->steam->getPlayerSummary($steamId);

        if ($player !== null) {
            $user->fill([
                'persona_name' => $player->personaName,
                'avatar_url' => $player->avatarFull ?: null,
                'profile_url' => $player->profileUrl ?: null,
                'steam_created_at' => $player->timeCreated ? Carbon::createFromTimestamp($player->timeCreated) : null,
            ]);
        }

        $user->last_login_at = now();
        $user->save();

        return $user;
    }

    /** Fetches owned games and upserts them with the sync stamp in one transaction; failures keep the old snapshot. */
    private function syncLibrary(User $user): void
    {
        $games = $this->steam->getOwnedGames($user->steam_id);
        $hasGames = DB::table('owned_games')->where('steam_id', $user->steam_id)->exists();

        // An empty list for a user who had games is almost always a Steam hiccup, not a real change.
        if ($games === null || ($games === [] && $hasGames)) {
            Log::warning('Skipping library sync', ['steam_id' => $user->steam_id, 'failed' => $games === null]);

            return;
        }

        $now = now();
        $rows = is_array($games) ? array_map(fn ($game) => [
            'steam_id' => $user->steam_id,
            'app_id' => $game->appId,
            'name' => $game->name,
            'playtime_minutes' => $game->playtimeForeverMinutes,
            'playtime_2w_minutes' => $game->playtimeTwoWeeksMinutes,
            'first_seen_at' => $now, // insert-only: excluded from the update columns below
            'last_seen_at' => $now,
        ], $games) : [];

        DB::transaction(function () use ($user, $rows, $games, $now) {
            foreach (array_chunk($rows, 1000) as $chunk) {
                DB::table('owned_games')->upsert(
                    $chunk,
                    ['steam_id', 'app_id'],
                    ['name', 'playtime_minutes', 'playtime_2w_minutes', 'last_seen_at'],
                );
            }

            $user->update(['library_synced_at' => $now, 'library_private' => $games === SteamClient::PRIVATE]);
        });
    }
}
