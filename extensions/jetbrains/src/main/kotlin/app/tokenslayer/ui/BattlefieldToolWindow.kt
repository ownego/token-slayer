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
        // inject window.__tokenSlayerRelay on every load
        browser.jbCefClient.addLoadHandler(object : org.cef.handler.CefLoadHandlerAdapter() {
            override fun onLoadEnd(b: org.cef.browser.CefBrowser?, f: org.cef.browser.CefFrame?, code: Int) {
                browser.cefBrowser.executeJavaScript(
                    "window.__tokenSlayerRelay = function(m){ ${relay.inject("m")} };",
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
        if (!svc.auth.isSignedIn()) {
            browser.loadHTML(signedOutHtml())
            return
        }
        try {
            val body = com.google.gson.JsonObject().apply { addProperty("path", "/battlefield?embed=ide") }
            val url = svc.client.postJson("/api/ide/auth/session-url", body).get("url").asString
            val origin = URI.create(svc.serverUrl).let { "${it.scheme}://${it.authority}" }
            browser.loadHTML(iframeWrapperHtml(url, origin))
        } catch (e: Exception) {
            browser.loadHTML(errorHtml(e.message ?: "error"))
        }
    }
}
