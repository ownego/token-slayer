package app.tokenslayer.hooks

import com.google.gson.GsonBuilder
import com.google.gson.JsonObject
import com.google.gson.JsonParser
import com.google.gson.JsonSyntaxException
import java.nio.file.Files
import java.nio.file.Path

class InvalidSettingsError(val filePath: String) : Exception("Invalid JSON at $filePath")

class SettingsFile(val filePath: Path = defaultPath()) {
    private val gson = GsonBuilder().setPrettyPrinting().create()

    fun read(): JsonObject {
        if (!Files.exists(filePath)) return JsonObject()
        val raw = Files.readString(filePath)
        return try {
            JsonParser.parseString(raw).asJsonObject
        } catch (_: JsonSyntaxException) {
            throw InvalidSettingsError(filePath.toString())
        } catch (_: IllegalStateException) {
            throw InvalidSettingsError(filePath.toString())
        }
    }

    fun write(settings: JsonObject) {
        filePath.parent?.let { Files.createDirectories(it) }
        Files.writeString(filePath, gson.toJson(settings) + "\n")
    }

    companion object {
        fun defaultPath(): Path =
            Path.of(System.getProperty("user.home"), ".claude", "settings.json")
    }
}
