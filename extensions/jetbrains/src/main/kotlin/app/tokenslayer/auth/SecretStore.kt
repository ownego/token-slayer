// SecretStore.kt
package app.tokenslayer.auth

interface SecretStore {
    fun get(): String?
    fun set(value: String)
    fun clear()
}
