// NotificationThrottle.kt
package app.tokenslayer.ui

class NotificationThrottle(
    private val windowMs: Long = 5_000,
    private val now: () -> Long = System::currentTimeMillis,
) {
    private var last: Long? = null
    fun allow(): Boolean {
        val t = now()
        val l = last
        if (l != null && t - l < windowMs) return false
        last = t
        return true
    }
}
