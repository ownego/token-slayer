package app.tokenslayer.api

data class HttpResult(val status: Int, val body: String)

fun interface HttpTransport {
    fun send(method: String, url: String, headers: Map<String, String>, body: String?): HttpResult
}
