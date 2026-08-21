package com.lacerda.indiescovery

import org.junit.jupiter.api.Test
import org.springframework.modulith.core.ApplicationModules

class ModuleStructureTests {

    private val modules = ApplicationModules.of(IndiescoveryApplication::class.java)

    @Test
    fun `modules are structurally valid`() {
        modules.forEach(System.out::println)
        modules.verify()
    }
}
