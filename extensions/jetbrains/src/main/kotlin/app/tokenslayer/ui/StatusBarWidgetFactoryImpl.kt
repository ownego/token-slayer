package app.tokenslayer.ui

import app.tokenslayer.TokenSlayerService
import com.intellij.openapi.application.ApplicationManager
import com.intellij.openapi.project.Project
import com.intellij.openapi.wm.StatusBar
import com.intellij.openapi.wm.StatusBarWidget
import com.intellij.openapi.wm.StatusBarWidgetFactory
import com.intellij.util.Consumer
import java.awt.event.MouseEvent

class TokenSlayerStatusWidgetFactory : StatusBarWidgetFactory {
    override fun getId() = "token-slayer.status"
    override fun getDisplayName() = "Token Slayer"
    override fun createWidget(project: Project): StatusBarWidget = TokenSlayerStatusWidget(project)
}

class TokenSlayerStatusWidget(private val project: Project) :
    StatusBarWidget, StatusBarWidget.TextPresentation {
    @Volatile private var state = StatusState()
    private var bar: StatusBar? = null
    private var unregister: (() -> Unit)? = null
    private val svc get() = ApplicationManager.getApplication().getService(TokenSlayerService::class.java)

    override fun ID() = "token-slayer.status"
    override fun getPresentation() = this
    override fun install(statusBar: StatusBar) {
        bar = statusBar
        unregister = svc.addStatusListener { state = it; bar?.updateWidget(ID()) }
    }
    override fun dispose() {
        unregister?.invoke()
        unregister = null
        bar = null
    }
    override fun getText() = statusText(state)
    override fun getTooltipText() = statusTooltip(state)
    override fun getAlignment() = java.awt.Component.RIGHT_ALIGNMENT
    override fun getClickConsumer() = Consumer<MouseEvent> {
        if (svc.auth.isSignedIn()) svc.openBattlefield(project) else svc.auth.startSignIn()
    }
}
