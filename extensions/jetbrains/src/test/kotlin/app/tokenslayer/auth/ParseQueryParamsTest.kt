package app.tokenslayer.auth

import org.junit.jupiter.api.Test
import kotlin.test.assertEquals
import kotlin.test.assertTrue

class ParseQueryParamsTest {
    @Test fun parsesTokenAndState() {
        val p = parseQueryParams("token=abc&state=xyz")
        assertEquals("abc", p["token"])
        assertEquals("xyz", p["state"])
    }

    @Test fun urlDecodesValues() {
        val p = parseQueryParams("token=a%20b&state=x%2By")
        assertEquals("a b", p["token"])
        assertEquals("x+y", p["state"])
    }

    @Test fun emptyOrNullYieldsEmptyMap() {
        assertTrue(parseQueryParams(null).isEmpty())
        assertTrue(parseQueryParams("").isEmpty())
    }
}
