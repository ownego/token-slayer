import org.jetbrains.intellij.platform.gradle.TestFrameworkType

plugins {
    id("java")
    id("org.jetbrains.kotlin.jvm") version "1.9.24"
    id("org.jetbrains.intellij.platform") version "2.1.0"
}

group = "app.tokenslayer"
version = "0.1.0"

repositories {
    mavenCentral()
    intellijPlatform { defaultRepositories() }
}

dependencies {
    intellijPlatform {
        phpstorm("2024.1.7")
        instrumentationTools()
        testFramework(TestFrameworkType.Platform)
    }
    implementation("com.google.code.gson:gson:2.11.0")
    testImplementation("org.junit.jupiter:junit-jupiter:5.10.2")
    testImplementation(kotlin("test"))
    testRuntimeOnly("junit:junit:4.13.2")
}

intellijPlatform {
    pluginConfiguration {
        ideaVersion {
            sinceBuild = "241"
            untilBuild = provider { null }
        }
    }
}

kotlin { jvmToolchain(17) }

tasks.test { useJUnitPlatform() }

// Dev-only (does not affect the published plugin): disable plugin auto-reload under runIde.
// This plugin isn't dynamic-unload-safe, so hot-reload can drop its UI extensions mid-session.
tasks {
    runIde {
        jvmArgumentProviders += CommandLineArgumentProvider {
            listOf("-Didea.auto.reload.plugins=false")
        }
    }
}
