package app.tokenslayer.api

import java.net.URI
import java.net.http.HttpClient
import java.net.http.HttpRequest
import java.net.http.HttpResponse
import java.time.Duration

class JdkHttpTransport(
    private val client: HttpClient = HttpClient.newBuilder()
        .connectTimeout(Duration.ofSeconds(10))
        .build(),
) : HttpTransport {
    override fun send(method: String, url: String, headers: Map<String, String>, body: String?): HttpResult {
        val builder = HttpRequest.newBuilder(URI.create(url))
            .timeout(Duration.ofSeconds(15))
        val publisher = if (body == null) HttpRequest.BodyPublishers.noBody()
            else HttpRequest.BodyPublishers.ofString(body)
        builder.method(method, publisher)
        headers.forEach { (k, v) -> builder.header(k, v) }
        val resp = client.send(builder.build(), HttpResponse.BodyHandlers.ofString())
        return HttpResult(resp.statusCode(), resp.body() ?: "")
    }
}
