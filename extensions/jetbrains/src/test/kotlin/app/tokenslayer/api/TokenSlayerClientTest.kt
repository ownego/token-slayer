package app.tokenslayer.api

import com.google.gson.JsonObject
import org.junit.jupiter.api.Test
import kotlin.test.assertEquals
import kotlin.test.assertFailsWith
import kotlin.test.assertTrue

class TokenSlayerClientTest {
    private fun client(
        token: String? = "tok",
        onUnauthorized: () -> Unit = {},
        respond: (String, String, Map<String, String>, String?) -> HttpResult,
    ) = TokenSlayerClient({ "https://srv" }, { token }, onUnauthorized, HttpTransport(respond))

    @Test fun getSendsBearerAndParsesJson() {
        var seen: Map<String, String> = emptyMap()
        val c = client { _, url, headers, _ ->
            seen = headers
            assertEquals("https://srv/api/ide/snapshot", url)
            HttpResult(200, """{"ok":true}""")
        }
        val out = c.getJson("/api/ide/snapshot")
        assertEquals("Bearer tok", seen["Authorization"])
        assertTrue(out.get("ok").asBoolean)
    }

    @Test fun unauthenticatedOmitsBearer() {
        var seen: Map<String, String> = emptyMap()
        val c = client { _, _, headers, _ -> seen = headers; HttpResult(200, "{}") }
        c.postJson("/api/ide/auth/exchange", JsonObject(), authenticated = false)
        assertTrue(!seen.containsKey("Authorization"))
    }

    @Test fun status401CallsOnUnauthorizedAndThrows() {
        var called = false
        val c = client(onUnauthorized = { called = true }) { _, _, _, _ -> HttpResult(401, "{}") }
        val err = assertFailsWith<HttpError> { c.getJson("/api/ide/me") }
        assertEquals(401, err.status)
        assertTrue(called)
    }

    @Test fun status500Throws() {
        val c = client { _, _, _, _ -> HttpResult(500, "boom") }
        assertEquals(500, assertFailsWith<HttpError> { c.getJson("/x") }.status)
    }

    @Test fun status204ReturnsEmpty() {
        val c = client { _, _, _, _ -> HttpResult(204, "") }
        assertEquals(0, c.postJson("/api/ide/auth/revoke", JsonObject()).size())
    }
}
