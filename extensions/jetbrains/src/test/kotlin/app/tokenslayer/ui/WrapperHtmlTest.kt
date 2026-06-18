// WrapperHtmlTest.kt
package app.tokenslayer.ui

import org.junit.jupiter.api.Test
import kotlin.test.assertTrue

class WrapperHtmlTest {
    @Test fun embedsIframeWithSignedUrl() {
        val html = iframeWrapperHtml("https://srv/battlefield?embed=ide&_t=abc", "https://srv")
        assertTrue(html.contains("<iframe"))
        assertTrue(html.contains("https://srv/battlefield?embed=ide&amp;_t=abc"))
        assertTrue(html.contains("__tokenSlayerRelay"))
    }

    @Test fun escapesUrlQuotes() {
        val html = iframeWrapperHtml("https://srv/x?a=\"b\"", "https://srv")
        assertTrue(!html.contains("a=\"b\""))
    }

    @Test fun signedOutHasSignInTrigger() {
        assertTrue(signedOutHtml().contains("sign-in-requested"))
    }
}
