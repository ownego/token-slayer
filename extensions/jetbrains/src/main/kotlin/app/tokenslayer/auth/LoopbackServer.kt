package app.tokenslayer.auth

import com.intellij.openapi.diagnostic.thisLogger
import com.sun.net.httpserver.HttpServer
import java.net.InetAddress
import java.net.InetSocketAddress
import java.net.URLDecoder
import java.nio.charset.StandardCharsets

/**
 * Receives the OAuth callback on a loopback HTTP server instead of an OS `jetbrains://`
 * deep link. The browser is redirected to `http://127.0.0.1:<port>/callback?token=&state=`,
 * which is delivered straight to this process — no OS scheme registration required.
 */
interface LoopbackServer {
    /** Bind 127.0.0.1 on an ephemeral port and return it. [onCallback] fires with (token, state). */
    fun start(onCallback: (token: String, state: String) -> Unit): Int

    fun stop()
}

/** Parse a raw URL query string into decoded key/value pairs. Pure; unit-tested. */
fun parseQueryParams(raw: String?): Map<String, String> {
    if (raw.isNullOrBlank()) {
        return emptyMap()
    }
    val out = LinkedHashMap<String, String>()
    for (pair in raw.split('&')) {
        if (pair.isEmpty()) {
            continue
        }
        val idx = pair.indexOf('=')
        val key = if (idx < 0) pair else pair.substring(0, idx)
        val value = if (idx < 0) "" else pair.substring(idx + 1)
        out[decode(key)] = decode(value)
    }
    return out
}

private fun decode(s: String): String = URLDecoder.decode(s, StandardCharsets.UTF_8)

private const val SUCCESS_HTML =
    "<!DOCTYPE html><html><body style=\"font-family:sans-serif;padding:2rem;color:#e2e8f0;background:#0f172a\">" +
        "<h2>token-slayer</h2><p>Signed in. You can close this tab and return to your IDE.</p></body></html>"

private const val ERROR_HTML =
    "<!DOCTYPE html><html><body style=\"font-family:sans-serif;padding:2rem;color:#e2e8f0;background:#0f172a\">" +
        "<h2>token-slayer</h2><p>Sign-in link was invalid. Please try again from the IDE.</p></body></html>"

class JdkLoopbackServer : LoopbackServer {
    @Volatile private var server: HttpServer? = null

    override fun start(onCallback: (String, String) -> Unit): Int {
        stop()
        val httpServer = HttpServer.create(InetSocketAddress(InetAddress.getLoopbackAddress(), 0), 0)
        httpServer.createContext("/callback") { exchange ->
            val params = parseQueryParams(exchange.requestURI.rawQuery)
            val token = params["token"]
            val state = params["state"]
            val ok = !token.isNullOrBlank() && !state.isNullOrBlank()
            val body = (if (ok) SUCCESS_HTML else ERROR_HTML).toByteArray(StandardCharsets.UTF_8)
            exchange.responseHeaders.add("Content-Type", "text/html; charset=utf-8")
            exchange.sendResponseHeaders(200, body.size.toLong())
            exchange.responseBody.use { it.write(body) }
            if (ok) {
                try {
                    onCallback(token!!, state!!)
                } catch (e: Exception) {
                    thisLogger().warn("token-slayer: loopback sign-in failed after callback", e)
                }
            }
            stop()
        }
        httpServer.executor = null
        httpServer.start()
        server = httpServer
        return httpServer.address.port
    }

    override fun stop() {
        server?.stop(0)
        server = null
    }
}
