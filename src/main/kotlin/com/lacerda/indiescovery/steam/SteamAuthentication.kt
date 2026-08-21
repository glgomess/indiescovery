package com.lacerda.indiescovery.steam

import org.springframework.security.authentication.AbstractAuthenticationToken
import org.springframework.security.core.authority.SimpleGrantedAuthority

class SteamAuthentication(val steamId: String) : AbstractAuthenticationToken(
    listOf(SimpleGrantedAuthority("ROLE_USER"))
) {
    init { isAuthenticated = true }
    override fun getCredentials(): Any? = null
    override fun getPrincipal(): Any = steamId
}
