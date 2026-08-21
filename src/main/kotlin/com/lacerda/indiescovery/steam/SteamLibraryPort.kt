package com.lacerda.indiescovery.steam

interface SteamLibraryPort {
    fun getOwnedGames(steamId: String): List<SteamGame>
}
