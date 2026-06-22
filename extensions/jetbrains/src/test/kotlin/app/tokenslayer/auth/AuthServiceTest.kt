package app.tokenslayer.auth

import app.tokenslayer.api.HttpResult
import app.tokenslayer.api.HttpTransport
import app.tokenslayer.api.TokenSlayerClient
import com.google.gson.JsonObject
import org.junit.jupiter.api.Test
import kotlin.test.*

class AuthServiceTest {
    private class MemStore(var value: String? = null) : SecretStore {
        override fun get() = value
        override fun set(v: String) { value = v }
        override fun clear() { value = null }
    }

    private class FakeLoopback : LoopbackServer {
        override fun start(onCallback: (String, String) -> Unit): Int = 12345
        override fun stop() {}
    }

    private fun service(
        store: MemStore = MemStore(),
        opened: MutableList<String> = mutableListOf(),
        respond: (String, String, Map<String, String>, String?) -> HttpResult = { _, _, _, _ -> HttpResult(200, "{}") },
    ): AuthService {
        val client = TokenSlayerClient({ "https://srv" }, { store.get() }, {}, HttpTransport(respond))
        return AuthService(store, client, { opened.add(it) }, { "https://srv" }, FakeLoopback())
    }

    @Test fun startSignInOpensBrowserWithClientJetbrains() {
        val opened = mutableListOf<String>()
        val s = service(opened = opened)
        s.startSignIn()
        val url = opened.single()
        assertTrue(url.startsWith("https://srv/auth/slack?return=ide&client=jetbrains&state="))
        assertNotNull(s.pendingState())
    }

    @Test fun completeSignInRejectsStateMismatch() {
        val s = service()
        s.startSignIn()
        assertFailsWith<IllegalStateException> { s.completeSignIn("tok", "wrong-state") }
    }

    @Test fun completeSignInStoresBearerAndFires() {
        val store = MemStore()
        var fired: Boolean? = null
        val s = service(store = store) { _, _, _, _ -> HttpResult(200, """{"bearer":"BEAR"}""") }
        s.onAuthChanged { fired = it }
        s.startSignIn()
        s.completeSignIn("tok", s.pendingState()!!)
        assertEquals("BEAR", store.get())
        assertEquals(true, fired)
        assertNull(s.pendingState())
    }

    @Test fun signOutClearsEvenIfRevokeFails() {
        val store = MemStore("BEAR")
        var fired: Boolean? = null
        val s = service(store = store) { _, _, _, _ -> HttpResult(500, "boom") }
        s.onAuthChanged { fired = it }
        s.signOut()
        assertNull(store.get())
        assertEquals(false, fired)
    }

    @Test fun handleUnauthorizedClears() {
        val store = MemStore("BEAR")
        val s = service(store = store)
        s.handleUnauthorized()
        assertFalse(s.isSignedIn())
    }
}
