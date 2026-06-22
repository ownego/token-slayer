package app.tokenslayer.realtime

import app.tokenslayer.api.TokenSlayerClient
import app.tokenslayer.bridge.BridgeMessage
import com.google.gson.JsonObject

class SnapshotPoller(
    private val client: TokenSlayerClient,
    private val schedule: (Long, () -> Unit) -> Unit = { _, _ -> },
    private val cancel: () -> Unit = {},
    private val intervalMs: Long = 5_000,
) {
    private val listeners = mutableSetOf<(BridgeMessage) -> Unit>()
    private var running = false
    private var lastBossId: Int? = null
    private var sawBoss = false
    private var lastCurrentHp: Int? = null
    private var lastYourDamage: Int? = null
    private var lastCharging: String? = null
    private var sawCharging = false
    private var lastConnection: String? = null

    fun onBridgeEvent(listener: (BridgeMessage) -> Unit): () -> Unit {
        listeners.add(listener)
        return { listeners.remove(listener) }
    }

    fun start() {
        if (running) return
        running = true
        emitConnection("connecting")
        tick()
    }

    fun stop() {
        running = false
        cancel()
        lastBossId = null; sawBoss = false; lastCurrentHp = null
        lastYourDamage = null; lastCharging = null; sawCharging = false; lastConnection = null
    }

    fun tick() {
        if (!running) return
        try {
            handleSnapshot(client.getJson("/api/ide/snapshot"))
            emitConnection("connected")
        } catch (_: Exception) {
            emitConnection("disconnected")
        }
        if (running) schedule(intervalMs) { tick() }
    }

    private fun handleSnapshot(snap: JsonObject) {
        val bossEl = snap.get("boss")
        val charging = snap.get("charging")?.takeIf { !it.isJsonNull }?.asString
        val yourDamage = snap.get("yourDamage")?.asInt ?: 0

        if (bossEl == null || bossEl.isJsonNull) {
            if (sawBoss && lastBossId != null) {
                emit(BridgeMessage.BossDefeated(lastBossId!!, 0, null))
            }
            lastBossId = null; lastCurrentHp = null; lastYourDamage = null; sawBoss = true
        } else {
            val boss = bossEl.asJsonObject
            val id = boss.get("id").asInt
            val hp = boss.get("currentHp").asInt
            val changed = id != lastBossId || hp != lastCurrentHp || yourDamage != lastYourDamage
            if (changed) {
                lastBossId = id; lastCurrentHp = hp; lastYourDamage = yourDamage; sawBoss = true
                emit(BridgeMessage.BossSnapshot(id, boss.get("name").asString, boss.get("maxHp").asInt, hp, yourDamage))
            }
        }

        val chargingChanged = if (!sawCharging) charging != null else charging != lastCharging
        if (chargingChanged) {
            lastCharging = charging; sawCharging = true
            emit(BridgeMessage.ChargingUpdated(0, charging, null))
        } else if (!sawCharging) {
            lastCharging = charging; sawCharging = true
        }
    }

    private fun emitConnection(state: String) {
        if (state == lastConnection) return
        lastConnection = state
        emit(BridgeMessage.ConnectionState(state))
    }

    private fun emit(m: BridgeMessage) {
        for (l in listeners.toList()) { try { l(m) } catch (_: Exception) {} }
    }
}
