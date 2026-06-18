package app.tokenslayer.settings

import com.intellij.openapi.options.Configurable
import javax.swing.JComponent
import javax.swing.JLabel
import javax.swing.JPanel
import javax.swing.JTextField
import java.awt.BorderLayout

class TokenSlayerConfigurable : Configurable {
    private val field = JTextField(40)
    override fun getDisplayName() = "token-slayer"
    override fun createComponent(): JComponent {
        val panel = JPanel(BorderLayout(8, 8))
        panel.add(JLabel("Server URL:"), BorderLayout.WEST)
        panel.add(field, BorderLayout.CENTER)
        field.text = TokenSlayerSettings.getInstance().serverUrl
        return panel
    }
    override fun isModified() = field.text.trimEnd('/') != TokenSlayerSettings.getInstance().serverUrl
    override fun apply() { TokenSlayerSettings.getInstance().serverUrl = field.text }
    override fun reset() { field.text = TokenSlayerSettings.getInstance().serverUrl }
}
