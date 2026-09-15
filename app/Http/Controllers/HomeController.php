<?php

namespace App\Http\Controllers;

use App\Services\Steam\SteamClient;
use Illuminate\Http\Request;

/** Temporary smoke endpoint that proves the Steam integration works for the signed-in user. */
class HomeController
{
    public function __invoke(Request $request, SteamClient $steam): string
    {
        $steamId = $request->session()->get('steam_id');
        $player = $steam->getPlayerSummary($steamId);
        $games = $steam->getOwnedGames($steamId);

        return sprintf('%s owns %d games', $player?->personaName ?? 'Unknown player', count($games));
    }
}
