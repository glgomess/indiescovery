package com.lacerda.indiescovery.security

import jakarta.servlet.http.HttpServletResponse
import org.springframework.context.annotation.Bean
import org.springframework.context.annotation.Configuration
import org.springframework.security.config.annotation.web.builders.HttpSecurity
import org.springframework.security.web.SecurityFilterChain
import org.springframework.security.web.context.HttpSessionSecurityContextRepository
import org.springframework.security.web.context.SecurityContextRepository

@Configuration
class SecurityConfig {

    @Bean
    fun securityContextRepository(): SecurityContextRepository =
        HttpSessionSecurityContextRepository()

    @Bean
    fun securityFilterChain(
        http: HttpSecurity,
        securityContextRepository: SecurityContextRepository
    ): SecurityFilterChain {
        http
            .securityContext { it.securityContextRepository(securityContextRepository) }
            .authorizeHttpRequests { auth ->
                auth
                    .requestMatchers("/ping", "/auth/steam", "/auth/steam/callback").permitAll()
                    .anyRequest().authenticated()
            }
            .exceptionHandling { ex ->
                ex.authenticationEntryPoint { _, response, _ ->
                    response.sendRedirect("/auth/steam")
//                    response.sendError(HttpServletResponse.SC_FORBIDDEN)
                }
            }
            .csrf { it.disable() }

        return http.build()
    }
}
