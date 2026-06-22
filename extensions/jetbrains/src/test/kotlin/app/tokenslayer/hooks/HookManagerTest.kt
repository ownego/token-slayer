package app.tokenslayer.hooks

import com.google.gson.JsonParser
import org.junit.jupiter.api.Test
import kotlin.test.assertEquals
import kotlin.test.assertFalse
import kotlin.test.assertTrue

class HookManagerTest {
    private fun obj(json: String) = JsonParser.parseString(json).asJsonObject
    private val config = HookConfig(
        namespace = "token_slayer",
        eventsUrl = "https://x/api/events",
        events = listOf(HookEvent("Stop", "curl x"), HookEvent("Notification", "curl y")),
    )

    @Test fun mergeAddsNamespacedGroups() {
        val out = mergeHooks(obj("{}"), config)
        val stop = out.getAsJsonObject("hooks").getAsJsonArray("Stop")
        assertEquals(1, stop.size())
        val entry = stop[0].asJsonObject.getAsJsonArray("hooks")[0].asJsonObject
        assertEquals("token_slayer", entry.get("_ns").asString)
        assertEquals("curl x", entry.get("command").asString)
    }

    @Test fun mergePreservesForeignKeysAndHooks() {
        val existing = obj("""{"model":"opus","hooks":{"Stop":[{"matcher":"*","hooks":[{"type":"command","command":"other"}]}]}}""")
        val out = mergeHooks(existing, config)
        assertEquals("opus", out.get("model").asString)
        val stop = out.getAsJsonObject("hooks").getAsJsonArray("Stop")
        assertEquals(2, stop.size()) // foreign group kept + ours appended
    }

    @Test fun mergeIsIdempotent() {
        val once = mergeHooks(obj("{}"), config)
        val twice = mergeHooks(once.deepCopy(), config)
        assertEquals(once.toString(), twice.toString())
    }

    @Test fun removeDropsOnlyOurEntries() {
        val merged = mergeHooks(obj("""{"hooks":{"Stop":[{"matcher":"*","hooks":[{"type":"command","command":"other"}]}]}}"""), config)
        val out = removeHooks(merged, "token_slayer")
        val stop = out.getAsJsonObject("hooks").getAsJsonArray("Stop")
        assertEquals(1, stop.size())
        assertEquals("other", stop[0].asJsonObject.getAsJsonArray("hooks")[0].asJsonObject.get("command").asString)
        // Notification event had only our entry -> array removed entirely
        assertFalse(out.getAsJsonObject("hooks").has("Notification"))
    }

    @Test fun removeNoHooksIsNoop() {
        val out = removeHooks(obj("""{"model":"x"}"""), "token_slayer")
        assertTrue(out.has("model"))
    }
}
