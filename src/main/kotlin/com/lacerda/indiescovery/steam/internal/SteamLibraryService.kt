package com.lacerda.indiescovery.steam.internal

import com.fasterxml.jackson.annotation.JsonIgnoreProperties
import com.fasterxml.jackson.annotation.JsonProperty
import com.lacerda.indiescovery.steam.SteamGame
import com.lacerda.indiescovery.steam.SteamLibraryPort
import org.springframework.beans.factory.annotation.Value
import org.springframework.stereotype.Service
import org.springframework.web.client.RestClient

@Service
internal class SteamLibraryService(restClientBuilder: RestClient.Builder) : SteamLibraryPort {

    private val restClient = restClientBuilder.build()

    @Value("\${steam.api-key}")
    private lateinit var apiKey: String

    override fun getOwnedGames(steamId: String): List<SteamGame> {
        val response = restClient.get()
            .uri(
                "https://api.steampowered.com/IPlayerService/GetOwnedGames/v1/?key={key}&steamid={steamId}&include_appinfo=true&include_played_free_games=true",
                apiKey,
                steamId
            )
            .retrieve()
            .body(SteamApiResponse::class.java) ?: return emptyList()

        return response.response.games?.map { game ->
            SteamGame(
                appId = game.appId,
                name = game.name,
                playtimeForeverMinutes = game.playtimeForever,
                playtimeTwoWeeksMinutes = game.playtimeTwoWeeks,
                iconUrl = game.imgIconUrl
                    ?.takeIf { it.isNotBlank() }
                    ?.let { "https://media.steampowered.com/steamcommunity/public/images/apps/${game.appId}/$it.jpg" }
            )
        } ?: emptyList()
    }

    @JsonIgnoreProperties(ignoreUnknown = true)
    private data class SteamApiResponse(val response: SteamApiLibrary)

    @JsonIgnoreProperties(ignoreUnknown = true)
    private data class SteamApiLibrary(
        @JsonProperty("game_count") val gameCount: Int = 0,
        val games: List<SteamApiGame>?
    )

    @JsonIgnoreProperties(ignoreUnknown = true)
    private data class SteamApiGame(
        @JsonProperty("appid") val appId: Int,
        val name: String = "",
        @JsonProperty("playtime_forever") val playtimeForever: Int = 0,
        @JsonProperty("playtime_2weeks") val playtimeTwoWeeks: Int?,
        @JsonProperty("img_icon_url") val imgIconUrl: String?
    )
}
