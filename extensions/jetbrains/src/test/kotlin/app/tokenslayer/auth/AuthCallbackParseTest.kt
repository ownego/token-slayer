package app.tokenslayer.auth

import org.junit.jupiter.api.Test
import kotlin.test.assertEquals
import kotlin.test.assertFailsWith

class AuthCallbackParseTest {
    @Test fun parsesTokenAndState() {
        val c = parseAuthCallback(mapOf("token" to "T", "state" to "S"))
        assertEquals(AuthCallback("T", "S"), c)
    }

    @Test fun rejectsMissingToken() {
        assertFailsWith<IllegalArgumentException> { parseAuthCallback(mapOf("state" to "S")) }
    }

    @Test fun rejectsBlankState() {
        assertFailsWith<IllegalArgumentException> { parseAuthCallback(mapOf("token" to "T", "state" to "")) }
    }
}
