package com.lacerda.indiescovery.steam

data class SteamPlayer(
    val steamId: String,
    val personaName: String,
    val profileUrl: String,
    val avatarFull: String,
    val countryCode: String?,
    val lastLogoff: Long?,
    val timeCreated: Long?
)
