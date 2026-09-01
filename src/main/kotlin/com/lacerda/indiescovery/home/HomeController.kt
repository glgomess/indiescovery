package com.lacerda.indiescovery.home

import com.lacerda.indiescovery.steam.SteamAuthentication
import com.lacerda.indiescovery.steam.SteamLibraryPort
import com.lacerda.indiescovery.steam.SteamPlayerPort
import org.slf4j.LoggerFactory
import org.springframework.security.core.context.SecurityContextHolder
import org.springframework.web.bind.annotation.GetMapping
import org.springframework.web.bind.annotation.RestController

@RestController
class HomeController(
    private val steamPlayerPort: SteamPlayerPort,
    private val steamLibraryPort: SteamLibraryPort
) {
    private val log = LoggerFactory.getLogger(HomeController::class.java)

    @GetMapping("/")
    fun home(): String {
        val steamId = (SecurityContextHolder.getContext().authentication as SteamAuthentication).steamId
        val player = steamPlayerPort.getPlayerSummary(steamId)
        val games = steamLibraryPort.getOwnedGames(steamId)

        log.info("Player info: $player")
        log.info("Owned games (${games.size}): $games")
        return "OK"
    }
}
