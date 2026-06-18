package app.tokenslayer.auth

import com.intellij.credentialStore.CredentialAttributes
import com.intellij.credentialStore.Credentials
import com.intellij.credentialStore.generateServiceName
import com.intellij.ide.passwordSafe.PasswordSafe

class PasswordSafeSecretStore : SecretStore {
    private val attrs = CredentialAttributes(generateServiceName("token-slayer", "bearer"))
    override fun get(): String? = PasswordSafe.instance.getPassword(attrs)
    override fun set(value: String) = PasswordSafe.instance.set(attrs, Credentials("token-slayer", value))
    override fun clear() = PasswordSafe.instance.set(attrs, null)
}
