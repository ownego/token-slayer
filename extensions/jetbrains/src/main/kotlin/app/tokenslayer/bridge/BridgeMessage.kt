package app.tokenslayer.bridge

import com.google.gson.JsonParser
import com.google.gson.JsonObject

sealed interface BridgeMessage {
    data class ConnectionState(val state: String) : BridgeMessage
    data object AuthNeeded : BridgeMessage
    data class ChargingUpdated(val userId: Int, val activity: String?, val startedAt: String?) : BridgeMessage
    data class HitLanded(val userId: Int, val damage: Int, val bossId: Int, val bossHpAfter: Int, val bossMaxHp: Int) : BridgeMessage
    data class BossDefeated(val bossId: Int, val killerUserId: Int, val killerHandle: String?) : BridgeMessage
    data class BossSpawned(val bossId: Int, val name: String, val maxHp: Int) : BridgeMessage
    data class BossSnapshot(val bossId: Int, val name: String, val maxHp: Int, val currentHp: Int, val yourDamage: Int) : BridgeMessage
    data object InstallHooksRequested : BridgeMessage
}

private val CONNECTION_STATES = setOf("connecting", "connected", "reconnecting", "disconnected")

fun parseBridgeMessage(json: String): BridgeMessage? {
    val obj = try {
        JsonParser.parseString(json).let { if (it.isJsonObject) it.asJsonObject else return null }
    } catch (_: Exception) {
        return null
    }
    return when (obj.string("type")) {
        "auth-needed" -> BridgeMessage.AuthNeeded
        "install-hooks-requested" -> BridgeMessage.InstallHooksRequested
        "connection-state" -> obj.string("state")?.takeIf { it in CONNECTION_STATES }
            ?.let { BridgeMessage.ConnectionState(it) }
        "charging-updated" -> {
            val userId = obj.int("userId") ?: return null
            BridgeMessage.ChargingUpdated(userId, obj.stringOrNull("activity"), obj.stringOrNull("startedAt"))
        }
        "hit-landed" -> {
            val u = obj.int("userId"); val d = obj.int("damage"); val b = obj.int("bossId")
            val a = obj.int("bossHpAfter"); val m = obj.int("bossMaxHp")
            if (u == null || d == null || b == null || a == null || m == null) null
            else BridgeMessage.HitLanded(u, d, b, a, m)
        }
        "boss-defeated" -> {
            val b = obj.int("bossId"); val k = obj.int("killerUserId")
            if (b == null || k == null) null
            else BridgeMessage.BossDefeated(b, k, obj.stringOrNull("killerHandle"))
        }
        "boss-spawned" -> {
            val b = obj.int("bossId"); val n = obj.string("name"); val m = obj.int("maxHp")
            if (b == null || n == null || m == null) null else BridgeMessage.BossSpawned(b, n, m)
        }
        "boss-snapshot" -> {
            val b = obj.int("bossId"); val n = obj.string("name"); val mx = obj.int("maxHp")
            val cur = obj.int("currentHp"); val yd = obj.int("yourDamage")
            if (b == null || n == null || mx == null || cur == null || yd == null) null
            else BridgeMessage.BossSnapshot(b, n, mx, cur, yd)
        }
        else -> null
    }
}

private fun JsonObject.string(key: String): String? =
    get(key)?.takeIf { it.isJsonPrimitive && it.asJsonPrimitive.isString }?.asString

private fun JsonObject.stringOrNull(key: String): String? {
    val e = get(key) ?: return null
    if (e.isJsonNull) return null
    return string(key)
}

private fun JsonObject.int(key: String): Int? =
    get(key)?.takeIf { it.isJsonPrimitive && it.asJsonPrimitive.isNumber }?.asInt
