package app.tokenslayer.hooks

import com.google.gson.JsonArray
import com.google.gson.JsonObject

data class HookEvent(val name: String, val command: String)
data class HookConfig(val namespace: String, val eventsUrl: String, val events: List<HookEvent>)

fun mergeHooks(existing: JsonObject, config: HookConfig): JsonObject {
    val next = existing.deepCopy()
    val hooks = next.getAsJsonObject("hooks") ?: JsonObject().also { next.add("hooks", it) }

    for (event in config.events) {
        val kept = JsonArray()
        hooks.getAsJsonArray(event.name)?.forEach { group ->
            if (!groupHasNamespace(group.asJsonObject, config.namespace)) kept.add(group)
        }
        val entry = JsonObject().apply {
            addProperty("type", "command")
            addProperty("command", event.command)
            addProperty("_ns", config.namespace)
        }
        val group = JsonObject().apply {
            addProperty("matcher", "*")
            add("hooks", JsonArray().apply { add(entry) })
        }
        kept.add(group)
        hooks.add(event.name, kept)
    }
    return next
}

fun removeHooks(existing: JsonObject, namespace: String): JsonObject {
    val hooks = existing.getAsJsonObject("hooks") ?: return existing
    val next = existing.deepCopy()
    val nextHooks = next.getAsJsonObject("hooks")
    for (eventName in hooks.keySet().toList()) {
        val filtered = JsonArray()
        nextHooks.getAsJsonArray(eventName).forEach { group ->
            if (!groupHasNamespace(group.asJsonObject, namespace)) filtered.add(group)
        }
        if (filtered.size() == 0) nextHooks.remove(eventName) else nextHooks.add(eventName, filtered)
    }
    return next
}

private fun groupHasNamespace(group: JsonObject, namespace: String): Boolean =
    group.getAsJsonArray("hooks")?.any {
        it.asJsonObject.get("_ns")?.asString == namespace
    } ?: false
