package app.tokenslayer

import app.tokenslayer.api.JdkHttpTransport
import app.tokenslayer.api.TokenSlayerClient
import app.tokenslayer.auth.AuthService
import app.tokenslayer.auth.PasswordSafeSecretStore
import app.tokenslayer.bridge.BridgeMessage
import app.tokenslayer.hooks.*
import app.tokenslayer.realtime.SnapshotPoller
import app.tokenslayer.settings.TokenSlayerSettings
import app.tokenslayer.ui.*
import com.intellij.ide.BrowserUtil
import com.intellij.openapi.application.ApplicationManager
import com.intellij.openapi.components.Service
import com.intellij.openapi.project.Project
import com.intellij.openapi.ui.Messages
import com.intellij.util.Alarm
import com.google.gson.JsonObject
import java.util.concurrent.CopyOnWriteArraySet

@Service(Service.Level.APP)
class TokenSlayerService {
    val serverUrl: String get() = TokenSlayerSettings.getInstance().serverUrl

    val client: TokenSlayerClient = TokenSlayerClient(
        serverUrl = { TokenSlayerSettings.getInstance().serverUrl },
        getToken = { auth.token() },
        onUnauthorized = { auth.handleUnauthorized() },
        transport = JdkHttpTransport(),
    )

    val auth: AuthService = AuthService(
        secrets = PasswordSafeSecretStore(),
        client = client,
        openBrowser = { BrowserUtil.browse(it) },
        serverUrl = { TokenSlayerSettings.getInstance().serverUrl },
    )

    private val alarm = Alarm(Alarm.ThreadToUse.POOLED_THREAD, ApplicationDisposable)
    private val poller = SnapshotPoller(
        client = client,
        schedule = { ms, cb -> alarm.addRequest(cb, ms.toInt()) },
        cancel = { alarm.cancelAllRequests() },
    )

    private val statusListeners = CopyOnWriteArraySet<(StatusState) -> Unit>()
    @Volatile private var status = StatusState()
    private val hitThrottle = NotificationThrottle()

    init {
        poller.onBridgeEvent { dispatchBridge(it) }
        auth.onAuthChanged { signedIn ->
            if (signedIn) startPolling() else { poller.stop(); updateStatus { StatusState() } }
            updateStatus { it.copy(signedIn = signedIn) }
        }
        if (auth.isSignedIn()) { startPolling(); updateStatus { it.copy(signedIn = true) } }
    }

    /** Kick the poller on the pooled alarm thread so the blocking first tick never runs on the EDT. */
    private fun startPolling() = alarm.addRequest({ poller.start() }, 0)

    fun addStatusListener(listener: (StatusState) -> Unit): () -> Unit {
        statusListeners.add(listener)
        ApplicationManager.getApplication().invokeLater { listener(status) }
        return { statusListeners.remove(listener) }
    }

    fun dispatchBridge(m: BridgeMessage) {
        when (m) {
            is BridgeMessage.ConnectionState -> updateStatus { it.copy(connection = m.state) }
            is BridgeMessage.BossSnapshot -> updateStatus {
                it.copy(bossName = m.name, bossMaxHp = m.maxHp, bossCurrentHp = m.currentHp, yourDamage = m.yourDamage)
            }
            is BridgeMessage.BossSpawned -> {
                updateStatus { it.copy(bossName = m.name, bossMaxHp = m.maxHp, bossCurrentHp = m.maxHp, yourDamage = 0) }
                TokenSlayerNotifications.bossSpawned(null, m.name)
            }
            is BridgeMessage.BossDefeated -> {
                updateStatus { it.copy(bossName = null, bossCurrentHp = null, yourDamage = 0) }
                TokenSlayerNotifications.bossDefeated(null, m.killerHandle)
            }
            is BridgeMessage.HitLanded -> {
                updateStatus { it.copy(bossCurrentHp = m.bossHpAfter, bossMaxHp = m.bossMaxHp, yourDamage = it.yourDamage + m.damage) }
                if (hitThrottle.allow()) TokenSlayerNotifications.hit(null, m.damage, m.bossHpAfter, m.bossMaxHp)
            }
            is BridgeMessage.ChargingUpdated -> updateStatus { it.copy(charging = m.activity) }
            else -> {}
        }
    }

    fun installHooks(project: Project?) {
        val file = SettingsFile()
        try {
            val cfg = parseHookConfig(client.getJson("/api/ide/hook-config"))
            val existing = file.read()
            val next = mergeHooks(existing, cfg)
            if (existing.toString() == next.toString()) {
                Messages.showInfoMessage(project, "Token Slayer hooks already up to date.", "Token Slayer")
                return
            }
            file.write(next)
            Messages.showInfoMessage(project, "Token Slayer hooks installed in ${file.filePath}", "Token Slayer")
        } catch (e: InvalidSettingsError) {
            Messages.showErrorDialog(project, "${e.filePath} is not valid JSON. Fix it and retry.", "Token Slayer")
        } catch (e: Exception) {
            Messages.showErrorDialog(project, "install failed (${e.message})", "Token Slayer")
        }
    }

    fun uninstallHooks(project: Project?) {
        val file = SettingsFile()
        try {
            file.write(removeHooks(file.read(), "token_slayer"))
            Messages.showInfoMessage(project, "Token Slayer hooks removed.", "Token Slayer")
        } catch (e: Exception) {
            Messages.showErrorDialog(project, "uninstall failed (${e.message})", "Token Slayer")
        }
    }

    fun openBattlefield(project: Project?) {
        com.intellij.openapi.wm.ToolWindowManager.getInstance(project ?: return)
            .getToolWindow("Token Slayer")?.activate(null)
    }

    fun openProfile() = BrowserUtil.browse("$serverUrl/profile")

    private fun parseHookConfig(json: JsonObject): HookConfig {
        val events = json.getAsJsonArray("events").map {
            val o = it.asJsonObject
            HookEvent(o.get("name").asString, o.get("command").asString)
        }
        return HookConfig(json.get("namespace").asString, json.get("eventsUrl").asString, events)
    }

    private fun updateStatus(transform: (StatusState) -> StatusState) {
        ApplicationManager.getApplication().invokeLater {
            status = transform(status)
            for (l in statusListeners) { try { l(status) } catch (_: Exception) {} }
        }
    }
}

object ApplicationDisposable : com.intellij.openapi.Disposable {
    override fun dispose() {}
}
