package app.tokenslayer.ui

import org.junit.jupiter.api.Test
import kotlin.test.assertTrue

class StatusTooltipTest {
    @Test fun signedOutPromptsSignIn() {
        val s = StatusState(signedIn = false)
        assertTrue(statusText(s).contains("Token Slayer", ignoreCase = true))
        assertTrue(statusTooltip(s).contains("Sign in", ignoreCase = true))
    }

    @Test fun showsBossHpAndYourDamage() {
        val s = StatusState(signedIn = true, connection = "connected",
            bossName = "Hydra", bossMaxHp = 100, bossCurrentHp = 40, yourDamage = 24)
        val tip = statusTooltip(s)
        assertTrue(tip.contains("Hydra"))
        assertTrue(tip.contains("40"))
        assertTrue(tip.contains("24"))
    }

    @Test fun disconnectedShownInText() {
        val s = StatusState(signedIn = true, connection = "disconnected")
        assertTrue(statusText(s).contains("Token Slayer", ignoreCase = true))
        assertTrue(statusTooltip(s).contains("Disconnected", ignoreCase = true))
    }
}
