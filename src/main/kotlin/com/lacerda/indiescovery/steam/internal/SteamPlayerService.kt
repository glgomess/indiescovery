package com.lacerda.indiescovery.steam.internal

import com.fasterxml.jackson.annotation.JsonIgnoreProperties
import com.fasterxml.jackson.annotation.JsonProperty
import com.lacerda.indiescovery.steam.SteamPlayer
import com.lacerda.indiescovery.steam.SteamPlayerPort
import org.springframework.beans.factory.annotation.Value
import org.springframework.stereotype.Service
import org.springframework.web.client.RestClient

@Service
internal class SteamPlayerService(restClientBuilder: RestClient.Builder) : SteamPlayerPort {

    private val restClient = restClientBuilder.build()

    @Value("\${steam.api-key}")
    private lateinit var apiKey: String

    override fun getPlayerSummary(steamId: String): SteamPlayer? {
        val response = restClient.get()
            .uri(
                "https://api.steampowered.com/ISteamUser/GetPlayerSummaries/v2/?key={key}&steamids={steamId}",
                apiKey,
                steamId
            )
            .retrieve()
            .body(SteamApiResponse::class.java) ?: return null

        val player = response.response.players.firstOrNull() ?: return null

        return SteamPlayer(
            steamId = player.steamId,
            personaName = player.personaName,
            profileUrl = player.profileUrl,
            avatarFull = player.avatarFull,
            countryCode = player.countryCode,
            lastLogoff = player.lastLogoff,
            timeCreated = player.timeCreated
        )
    }

    @JsonIgnoreProperties(ignoreUnknown = true)
    private data class SteamApiResponse(val response: SteamApiPlayers)

    @JsonIgnoreProperties(ignoreUnknown = true)
    private data class SteamApiPlayers(val players: List<SteamApiPlayer>)

    @JsonIgnoreProperties(ignoreUnknown = true)
    private data class SteamApiPlayer(
        @JsonProperty("steamid") val steamId: String,
        @JsonProperty("personaname") val personaName: String,
        @JsonProperty("profileurl") val profileUrl: String,
        @JsonProperty("avatarfull") val avatarFull: String,
        @JsonProperty("loccountrycode") val countryCode: String?,
        @JsonProperty("lastlogoff") val lastLogoff: Long?,
        @JsonProperty("timecreated") val timeCreated: Long?
    )
}
