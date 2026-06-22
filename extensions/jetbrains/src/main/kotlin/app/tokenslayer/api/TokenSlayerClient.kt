package app.tokenslayer.api

import com.google.gson.JsonObject
import com.google.gson.JsonParser

class HttpError(val status: Int, val body: String) : Exception("HTTP $status")

class TokenSlayerClient(
    private val serverUrl: () -> String,
    private val getToken: () -> String?,
    private val onUnauthorized: () -> Unit,
    private val transport: HttpTransport,
) {
    fun getJson(path: String, authenticated: Boolean = true): JsonObject =
        request("GET", path, null, authenticated)

    fun postJson(path: String, body: JsonObject?, authenticated: Boolean = true): JsonObject =
        request("POST", path, body, authenticated)

    private fun request(method: String, path: String, body: JsonObject?, authenticated: Boolean): JsonObject {
        val headers = linkedMapOf("Accept" to "application/json")
        if (body != null) headers["Content-Type"] = "application/json"
        if (authenticated) getToken()?.let { headers["Authorization"] = "Bearer $it" }

        val result = transport.send(method, "${serverUrl()}$path", headers, body?.toString())

        if (result.status == 401) {
            onUnauthorized()
            throw HttpError(401, result.body)
        }
        if (result.status >= 400) throw HttpError(result.status, result.body)
        if (result.status == 204 || result.body.isBlank()) return JsonObject()
        return JsonParser.parseString(result.body).asJsonObject
    }
}
