package app.tokenslayer.ui

data class StatusState(
    val signedIn: Boolean = false,
    val connection: String = "connecting",
    val bossName: String? = null,
    val bossMaxHp: Int = 0,
    val bossCurrentHp: Int? = null,
    val yourDamage: Int = 0,
    val charging: String? = null,
)

fun statusText(s: StatusState): String = when {
    !s.signedIn -> "⚡ Token Slayer: sign in"
    s.connection == "disconnected" -> "⚠ Token Slayer"
    s.connection == "connecting" || s.connection == "reconnecting" -> "↻ Token Slayer…"
    s.bossName != null -> "⚡ ${s.bossName}"
    else -> "⚡ Token Slayer"
}

fun statusTooltip(s: StatusState): String {
    if (!s.signedIn) return "Token Slayer — Signed out\nSign in with Slack to see live boss and fighter data."
    val sb = StringBuilder("Token Slayer — ${connectionLabel(s.connection)}\n")
    if (s.bossName != null && s.bossCurrentHp != null) {
        val pct = if (s.bossMaxHp > 0) (s.bossCurrentHp * 100 / s.bossMaxHp) else 0
        sb.append("${s.bossName}: $pct% HP (${s.bossCurrentHp} / ${s.bossMaxHp})\n")
        sb.append("Your damage: ${s.yourDamage}\n")
    } else {
        sb.append("No boss is currently spawned.\n")
    }
    s.charging?.let { sb.append("Charging: $it\n") }
    return sb.toString().trimEnd()
}

private fun connectionLabel(c: String): String = when (c) {
    "connected" -> "Connected"
    "connecting" -> "Connecting…"
    "reconnecting" -> "Reconnecting…"
    else -> "Disconnected"
}
