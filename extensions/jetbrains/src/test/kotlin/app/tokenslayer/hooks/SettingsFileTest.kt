package app.tokenslayer.hooks

import com.google.gson.JsonParser
import org.junit.jupiter.api.Test
import org.junit.jupiter.api.io.TempDir
import java.nio.file.Files
import java.nio.file.Path
import kotlin.test.assertEquals
import kotlin.test.assertFailsWith
import kotlin.test.assertTrue

class SettingsFileTest {
    @Test fun readMissingReturnsEmpty(@TempDir dir: Path) {
        val f = SettingsFile(dir.resolve("nope/settings.json"))
        assertEquals(0, f.read().size())
    }

    @Test fun readInvalidThrows(@TempDir dir: Path) {
        val p = dir.resolve("settings.json")
        Files.writeString(p, "{ not json")
        assertFailsWith<InvalidSettingsError> { SettingsFile(p).read() }
    }

    @Test fun writeThenReadRoundTrips(@TempDir dir: Path) {
        val p = dir.resolve("deep/settings.json")
        val obj = JsonParser.parseString("""{"model":"opus"}""").asJsonObject
        val f = SettingsFile(p)
        f.write(obj)
        assertTrue(Files.exists(p))
        assertEquals("opus", f.read().get("model").asString)
        assertTrue(Files.readString(p).endsWith("\n"))
    }
}
