package com.lacerda.indiescovery.steam

data class SteamGame(
    val appId: Int,
    val name: String,
    val playtimeForeverMinutes: Int,
    val playtimeTwoWeeksMinutes: Int?,
    val iconUrl: String?
)
