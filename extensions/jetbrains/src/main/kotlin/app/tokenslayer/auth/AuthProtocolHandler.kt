package app.tokenslayer.auth

import com.intellij.openapi.application.ApplicationManager
import com.intellij.openapi.diagnostic.thisLogger
import com.intellij.openapi.application.JBProtocolCommand
import org.jetbrains.annotations.Nls

data class AuthCallback(val token: String, val state: String)

fun parseAuthCallback(parameters: Map<String, String?>): AuthCallback {
    val token = parameters["token"]
    val state = parameters["state"]
    require(!token.isNullOrBlank()) { "missing token" }
    require(!state.isNullOrBlank()) { "missing state" }
    return AuthCallback(token, state)
}

/** Handles jetbrains://<product>/token-slayer?token=&state= */
class AuthProtocolHandler : JBProtocolCommand("token-slayer") {
    @Suppress("UnstableApiUsage")
    override suspend fun execute(target: String?, parameters: Map<String, String>, fragment: String?): @Nls String? {
        return try {
            val cb = parseAuthCallback(parameters)
            val auth = ApplicationManager.getApplication()
                .getService(app.tokenslayer.TokenSlayerService::class.java).auth
            auth.completeSignIn(cb.token, cb.state)
            null
        } catch (e: Exception) {
            thisLogger().warn("token-slayer sign-in failed", e)
            "token-slayer: sign-in link invalid (${e.message})"
        }
    }
}
