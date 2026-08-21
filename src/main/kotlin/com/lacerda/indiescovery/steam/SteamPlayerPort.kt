package com.lacerda.indiescovery.steam

interface SteamPlayerPort {
    fun getPlayerSummary(steamId: String): SteamPlayer?
}
