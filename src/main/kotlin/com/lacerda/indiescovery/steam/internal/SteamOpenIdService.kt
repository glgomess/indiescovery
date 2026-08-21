package com.lacerda.indiescovery.steam.internal

import com.lacerda.indiescovery.steam.SteamOpenIdPort
import org.springframework.http.MediaType
import org.springframework.stereotype.Service
import org.springframework.web.client.RestClient
import org.springframework.web.util.UriComponentsBuilder
import java.net.URLEncoder
import java.nio.charset.StandardCharsets

@Service
internal class SteamOpenIdService(restClientBuilder: RestClient.Builder) : SteamOpenIdPort {

    private val restClient = restClientBuilder.build()

    companion object {
        private const val STEAM_OPENID_URL = "https://steamcommunity.com/openid/login"
        private val STEAM_ID_REGEX = Regex("""https://steamcommunity\.com/openid/id/(\d+)""")
    }

    override fun buildLoginRedirectUrl(realm: String, returnTo: String): String =
        UriComponentsBuilder.fromUriString(STEAM_OPENID_URL)
            .queryParam("openid.ns", "http://specs.openid.net/auth/2.0")
            .queryParam("openid.mode", "checkid_setup")
            .queryParam("openid.return_to", returnTo)
            .queryParam("openid.realm", realm)
            .queryParam("openid.identity", "http://specs.openid.net/auth/2.0/identifier_select")
            .queryParam("openid.claimed_id", "http://specs.openid.net/auth/2.0/identifier_select")
            .build()
            .encode()
            .toUriString()

    override fun verifyAndExtractSteamId(params: Map<String, String>): String? {
        val verifyParams = params + ("openid.mode" to "check_authentication")

        val formBody = verifyParams.entries.joinToString("&") { (k, v) ->
            "${encode(k)}=${encode(v)}"
        }

        val response = restClient.post()
            .uri(STEAM_OPENID_URL)
            .contentType(MediaType.APPLICATION_FORM_URLENCODED)
            .body(formBody)
            .retrieve()
            .body(String::class.java) ?: return null

        if (!response.contains("is_valid:true")) return null

        return params["openid.claimed_id"]
            ?.let { STEAM_ID_REGEX.find(it)?.groupValues?.get(1) }
    }

    private fun encode(value: String) = URLEncoder.encode(value, StandardCharsets.UTF_8)
}
