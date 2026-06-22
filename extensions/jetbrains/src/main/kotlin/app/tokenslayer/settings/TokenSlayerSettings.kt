package app.tokenslayer.settings

import com.intellij.openapi.application.ApplicationManager
import com.intellij.openapi.components.*

@State(name = "TokenSlayerSettings", storages = [Storage("token-slayer.xml")])
@Service(Service.Level.APP)
class TokenSlayerSettings : PersistentStateComponent<TokenSlayerSettings.State> {
    data class State(var serverUrl: String = "https://token-slayer.example.com")
    private var state = State()
    override fun getState() = state
    override fun loadState(s: State) { state = s }
    var serverUrl: String
        get() = state.serverUrl.trimEnd('/')
        set(v) { state.serverUrl = v.trim() }
    companion object {
        fun getInstance(): TokenSlayerSettings =
            ApplicationManager.getApplication().getService(TokenSlayerSettings::class.java)
    }
}
