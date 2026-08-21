package com.lacerda.indiescovery.steam

import jakarta.servlet.http.HttpServletRequest
import jakarta.servlet.http.HttpServletResponse
import org.springframework.beans.factory.annotation.Qualifier
import org.springframework.beans.factory.annotation.Value
import org.springframework.security.core.context.SecurityContextHolder
import org.springframework.security.web.context.SecurityContextRepository
import org.springframework.stereotype.Controller
import org.springframework.web.bind.annotation.GetMapping
import org.springframework.web.bind.annotation.RequestMapping
import org.springframework.web.bind.annotation.RequestParam
import org.springframework.web.servlet.view.RedirectView

@Controller
@RequestMapping("/auth/steam")
class SteamAuthController(
    @Qualifier("steamOpenIdService") private val steamOpenIdService: SteamOpenIdPort,
    private val securityContextRepository: SecurityContextRepository
) {
    @Value("\${app.base-url:http://localhost:8080}")
    private lateinit var baseUrl: String

    @GetMapping
    fun login(): RedirectView {
        val redirectUrl = steamOpenIdService.buildLoginRedirectUrl(
            realm = baseUrl,
            returnTo = "$baseUrl/auth/steam/callback"
        )

        return RedirectView(redirectUrl)
    }

    @GetMapping("/callback")
    fun callback(
        @RequestParam params: Map<String, String>,
        request: HttpServletRequest,
        response: HttpServletResponse
    ): RedirectView {
        val steamId = steamOpenIdService.verifyAndExtractSteamId(params)
            ?: return RedirectView("/auth/steam?error=true")

        val context = SecurityContextHolder.createEmptyContext()
        context.authentication = SteamAuthentication(steamId)
        SecurityContextHolder.setContext(context)
        securityContextRepository.saveContext(context, request, response)

        return RedirectView("/")
    }
}
