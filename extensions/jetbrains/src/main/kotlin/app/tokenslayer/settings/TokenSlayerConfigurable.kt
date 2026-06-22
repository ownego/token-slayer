package app.tokenslayer.settings

import com.intellij.openapi.options.Configurable
import javax.swing.JComponent
import javax.swing.JLabel
import javax.swing.JPanel
import javax.swing.JTextField
import java.awt.BorderLayout

class TokenSlayerConfigurable : Configurable {
    private val field = JTextField(40)
    override fun getDisplayName() = "Token Slayer"
    override fun createComponent(): JComponent {
        val row = JPanel(BorderLayout(8, 0))
        row.add(JLabel("Server URL:"), BorderLayout.WEST)
        row.add(field, BorderLayout.CENTER)
        field.text = TokenSlayerSettings.getInstance().serverUrl
        // Pin the row to the top so the text field keeps its natural (single-line) height
        // instead of stretching to fill the whole settings panel.
        val panel = JPanel(BorderLayout())
        panel.add(row, BorderLayout.NORTH)
        return panel
    }
    override fun isModified() = field.text.trimEnd('/') != TokenSlayerSettings.getInstance().serverUrl
    override fun apply() { TokenSlayerSettings.getInstance().serverUrl = field.text }
    override fun reset() { field.text = TokenSlayerSettings.getInstance().serverUrl }
}
