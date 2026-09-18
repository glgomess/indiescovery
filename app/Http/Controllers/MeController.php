<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Container\Attributes\Config;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Serves the signed-in Steam user's profile and library to the frontend, and logs them out. */
class MeController
{
    /** Receives the Steam CDN base URL used to build capsule image links. */
    public function __construct(
        #[Config('services.steam.capsule_url')] private readonly string $capsuleUrl,
    ) {}

    /** Returns the player and their library, games sorted by playtime; 401 if the session's user is gone. */
    public function show(Request $request): JsonResponse
    {
        $user = User::find($request->session()->get('steam_id'));

        if ($user === null) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }

        $games = $this->games($user->steam_id);

        return response()->json([
            'player' => [
                'steamId' => $user->steam_id,
                'name' => $user->persona_name,
                'avatarUrl' => $user->avatar_url,
                'profileUrl' => $user->profile_url,
                'steamCreatedAt' => $user->steam_created_at?->toJSON(),
            ],
            'library' => [
                'private' => $user->library_private,
                'syncedAt' => $user->library_synced_at?->toJSON(),
                'gameCount' => count($games),
                'totalMinutes' => array_sum(array_column($games, 'playtimeMinutes')),
                'recentMinutes' => array_sum(array_column($games, 'recentMinutes')),
                'games' => $games,
            ],
        ]);
    }

    /** Ends the session and rotates the CSRF token. */
    public function logout(Request $request): Response
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    /** Loads the user's owned games, enriched with catalog tags and review stats when the catalog has them. */
    private function games(string $steamId): array
    {
        $query = DB::table('owned_games as o')->where('o.steam_id', $steamId)->orderByDesc('o.playtime_minutes');

        // ponytail: games ships with feat/catalog-crawl; drop this guard once that branch merges.
        $hasCatalog = Schema::hasTable('games');
        $hasCatalog
            ? $query->leftJoin('games as g', 'g.app_id', '=', 'o.app_id')
                ->select('o.*', 'g.name as catalog_name', 'g.tags', 'g.review_count', 'g.positive_ratio')
            : $query->select('o.*');

        return $query->get()->map(fn ($row) => [
            'appId' => (int) $row->app_id,
            'name' => $row->catalog_name ?? $row->name,
            'playtimeMinutes' => (int) $row->playtime_minutes,
            'recentMinutes' => (int) $row->playtime_2w_minutes,
            'capsuleUrl' => "{$this->capsuleUrl}/{$row->app_id}/header.jpg",
            'tags' => isset($row->tags) ? json_decode($row->tags) : null,
            'reviewCount' => isset($row->review_count) ? (int) $row->review_count : null,
            'positiveRatio' => isset($row->positive_ratio) ? (float) $row->positive_ratio : null,
        ])->all();
    }
}
