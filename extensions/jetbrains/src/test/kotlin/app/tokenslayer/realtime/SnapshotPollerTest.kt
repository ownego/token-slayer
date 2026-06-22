package app.tokenslayer.realtime

import app.tokenslayer.api.HttpResult
import app.tokenslayer.api.HttpTransport
import app.tokenslayer.api.TokenSlayerClient
import app.tokenslayer.bridge.BridgeMessage
import org.junit.jupiter.api.Test
import kotlin.test.assertEquals
import kotlin.test.assertTrue

class SnapshotPollerTest {
    private fun poller(body: () -> HttpResult): Pair<SnapshotPoller, MutableList<BridgeMessage>> {
        val client = TokenSlayerClient({ "https://srv" }, { "t" }, {}, HttpTransport { _, _, _, _ -> body() })
        val events = mutableListOf<BridgeMessage>()
        val p = SnapshotPoller(client, schedule = { _, _ -> }, cancel = {})
        p.onBridgeEvent { events.add(it) }
        return p to events
    }

    @Test fun startEmitsConnecting() {
        val (p, events) = poller { HttpResult(200, """{"boss":null,"yourDamage":0,"charging":null}""") }
        p.start()
        assertEquals(BridgeMessage.ConnectionState("connecting"), events.first())
    }

    @Test fun emitsBossSnapshotOnChangeOnly() {
        val (p, events) = poller { HttpResult(200, """{"boss":{"id":1,"name":"H","maxHp":100,"currentHp":60},"yourDamage":5,"charging":null}""") }
        p.start(); p.tick()
        val snaps = events.filterIsInstance<BridgeMessage.BossSnapshot>()
        assertEquals(1, snaps.size) // first tick (in start) + identical tick => only one
        assertEquals(BridgeMessage.BossSnapshot(1, "H", 100, 60, 5), snaps.first())
    }

    @Test fun emitsDisconnectedOnError() {
        val (p, events) = poller { HttpResult(500, "x") }
        p.start()
        assertTrue(events.any { it == BridgeMessage.ConnectionState("disconnected") })
    }
}
