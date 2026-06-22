package app.tokenslayer.ui

import app.tokenslayer.TokenSlayerService
import app.tokenslayer.bridge.parseBridgeMessage
import com.intellij.openapi.application.ApplicationManager
import com.intellij.openapi.project.Project
import com.intellij.openapi.wm.ToolWindow
import com.intellij.openapi.wm.ToolWindowFactory
import com.intellij.ui.content.ContentFactory
import com.intellij.ui.jcef.JBCefBrowser
import com.intellij.ui.jcef.JBCefJSQuery
import com.intellij.ui.jcef.JBCefApp
import com.intellij.openapi.Disposable
import com.intellij.openapi.util.Disposer
import javax.swing.JLabel
import javax.swing.JPanel
import java.net.URI

class BattlefieldToolWindowFactory : ToolWindowFactory {
    override fun createToolWindowContent(project: Project, toolWindow: ToolWindow) {
        val svc = ApplicationManager.getApplication().getService(TokenSlayerService::class.java)
        val content = ContentFactory.getInstance()

        if (!JBCefApp.isSupported()) {
            val panel = JPanel().apply { add(JLabel("JCEF unavailable — open the battlefield in your browser.")) }
            toolWindow.contentManager.addContent(content.createContent(panel, "", false))
            return
        }

        val uiContent = content.createContent(JPanel(), "", false)
        val browser = JBCefBrowser()
        Disposer.register(uiContent, browser)
        val relay = JBCefJSQuery.create(browser as com.intellij.ui.jcef.JBCefBrowserBase)
        Disposer.register(browser, relay)
        relay.addHandler { raw ->
            handleRelay(svc, raw) { reload(svc, browser) }
            null
        }
        // On every load, expose window.__tokenSlayerRelay and bridge any window 'message'
        // events to it. The battlefield page is loaded top-level (not in an iframe), so its
        // postMessage-to-parent lands on this same window — the listener relays it to the plugin.
        browser.jbCefClient.addLoadHandler(object : org.cef.handler.CefLoadHandlerAdapter() {
            override fun onLoadEnd(b: org.cef.browser.CefBrowser?, f: org.cef.browser.CefFrame?, code: Int) {
                browser.cefBrowser.executeJavaScript(
                    "window.__tokenSlayerRelay = function(m){ ${relay.inject("m")} };" +
                        "if(!window.__tsRelayBound){window.__tsRelayBound=true;" +
                        "window.addEventListener('message',function(e){try{window.__tokenSlayerRelay(JSON.stringify(e.data));}catch(_){}})}",
                    browser.cefBrowser.url, 0,
                )
            }
        }, browser.cefBrowser)

        val unsubscribe = svc.auth.onAuthChanged { reload(svc, browser) }
        Disposer.register(uiContent, Disposable { unsubscribe() })
        reload(svc, browser)

        uiContent.component = browser.component
        toolWindow.contentManager.addContent(uiContent)
    }

    private fun handleRelay(svc: TokenSlayerService, raw: String, reload: () -> Unit) {
        try {
            val type = com.google.gson.JsonParser.parseString(raw).asJsonObject.get("type")?.asString
            when (type) {
                "sign-in-requested" -> svc.auth.startSignIn()
                "retry-requested" -> reload()
                else -> parseBridgeMessage(raw)?.let { svc.dispatchBridge(it) }
            }
        } catch (_: Exception) {}
    }

    private fun reload(svc: TokenSlayerService, browser: JBCefBrowser) {
        // Load the wrapper with the server origin as its base URL so the JCEF document has a
        // real (non-opaque) origin. Otherwise the embedded battlefield iframe is blocked by the
        // page's `frame-ancestors` CSP, which never matches a null/opaque ancestor origin.
        val origin = URI.create(svc.serverUrl).let { "${it.scheme}://${it.authority}" }
        val baseUrl = "$origin/__ide_embed"
        if (!svc.auth.isSignedIn()) {
            browser.loadHTML(signedOutHtml(), baseUrl)
            return
        }
        try {
            val body = com.google.gson.JsonObject().apply { addProperty("path", "/battlefield?embed=ide") }
            val url = svc.client.postJson("/api/ide/auth/session-url", body).get("url").asString
            // JCEF can host the signed URL directly as the top document — no iframe needed, which
            // avoids the page's frame-ancestors CSP entirely. The injected message bridge relays
            // the page's postMessage calls back to the plugin.
            browser.loadURL(url)
        } catch (e: Exception) {
            browser.loadHTML(errorHtml(e.message ?: "error"), baseUrl)
        }
    }
}
