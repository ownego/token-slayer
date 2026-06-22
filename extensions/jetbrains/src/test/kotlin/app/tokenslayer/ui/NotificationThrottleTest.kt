// NotificationThrottleTest.kt
package app.tokenslayer.ui

import org.junit.jupiter.api.Test
import kotlin.test.assertFalse
import kotlin.test.assertTrue

class NotificationThrottleTest {
    @Test fun allowsFirstThenThrottlesWithinWindow() {
        var t = 0L
        val th = NotificationThrottle(5000) { t }
        assertTrue(th.allow())
        t = 1000
        assertFalse(th.allow())
        t = 6000
        assertTrue(th.allow())
    }
}
