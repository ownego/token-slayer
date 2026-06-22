// AuthService.kt
package app.tokenslayer.auth

import app.tokenslayer.api.TokenSlayerClient
import com.google.gson.JsonObject
import java.net.URLEncoder
import java.nio.charset.StandardCharsets
import java.security.SecureRandom

class AuthService(
    private val secrets: SecretStore,
    private val client: TokenSlayerClient,
    private val openBrowser: (String) -> Unit,
    private val serverUrl: () -> String,
    private val loopback: LoopbackServer = JdkLoopbackServer(),
) {
    private var pendingState: String? = null
    private val listeners = mutableSetOf<(Boolean) -> Unit>()
    private val rng = SecureRandom()

    fun onAuthChanged(listener: (Boolean) -> Unit): () -> Unit {
        listeners.add(listener)
        return { listeners.remove(listener) }
    }

    fun token(): String? = secrets.get()
    fun isSignedIn(): Boolean = secrets.get() != null

    fun startSignIn() {
        val state = randomHex(32)
        pendingState = state
        val port = loopback.start { token, callbackState ->
            completeSignIn(token, callbackState)
        }
        val redirect = URLEncoder.encode("http://127.0.0.1:$port/callback", StandardCharsets.UTF_8)
        openBrowser("${serverUrl()}/auth/slack?return=ide&client=jetbrains&state=$state&redirect=$redirect")
    }

    fun completeSignIn(token: String, state: String) {
        val pending = pendingState
        if (pending == null || state != pending) error("sign-in state mismatch")
        pendingState = null
        val body = JsonObject().apply {
            addProperty("token", token)
            addProperty("state", state)
        }
        val result = client.postJson("/api/ide/auth/exchange", body, authenticated = false)
        secrets.set(result.get("bearer").asString)
        fire(true)
    }

    fun signOut() {
        try {
            client.postJson("/api/ide/auth/revoke", JsonObject())
        } catch (_: Exception) {
            // best-effort; client-side clear is what matters
        }
        secrets.clear()
        fire(false)
    }

    fun handleUnauthorized() {
        secrets.clear()
        fire(false)
    }

    fun pendingState(): String? = pendingState

    private fun fire(signedIn: Boolean) {
        for (l in listeners.toList()) {
            try { l(signedIn) } catch (_: Exception) {}
        }
    }

    private fun randomHex(bytes: Int): String {
        val b = ByteArray(bytes); rng.nextBytes(b)
        return b.joinToString("") { "%02x".format(it) }
    }
}
