package com.lacerda.indiescovery.steam

interface SteamOpenIdPort {
    fun buildLoginRedirectUrl(realm: String, returnTo: String): String
    fun verifyAndExtractSteamId(params: Map<String, String>): String?
}
