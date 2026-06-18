package app.tokenslayer.bridge

import org.junit.jupiter.api.Test
import kotlin.test.assertEquals
import kotlin.test.assertNull

class BridgeMessageTest {
    @Test fun parsesHitLanded() {
        val m = parseBridgeMessage("""{"type":"hit-landed","userId":7,"damage":12,"bossId":3,"bossHpAfter":80,"bossMaxHp":100}""")
        assertEquals(BridgeMessage.HitLanded(7, 12, 3, 80, 100), m)
    }

    @Test fun parsesBossSnapshot() {
        val m = parseBridgeMessage("""{"type":"boss-snapshot","bossId":3,"name":"Hydra","maxHp":100,"currentHp":40,"yourDamage":15}""")
        assertEquals(BridgeMessage.BossSnapshot(3, "Hydra", 100, 40, 15), m)
    }

    @Test fun parsesConnectionState() {
        assertEquals(BridgeMessage.ConnectionState("connected"),
            parseBridgeMessage("""{"type":"connection-state","state":"connected"}"""))
    }

    @Test fun rejectsBadConnectionState() {
        assertNull(parseBridgeMessage("""{"type":"connection-state","state":"weird"}"""))
    }

    @Test fun rejectsUnknownType() {
        assertNull(parseBridgeMessage("""{"type":"nope"}"""))
    }

    @Test fun rejectsMissingFields() {
        assertNull(parseBridgeMessage("""{"type":"hit-landed","userId":7}"""))
    }

    @Test fun rejectsNonObject() {
        assertNull(parseBridgeMessage("""123"""))
        assertNull(parseBridgeMessage("""not json"""))
    }
}
