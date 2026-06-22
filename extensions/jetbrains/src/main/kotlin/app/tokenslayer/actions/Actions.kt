package app.tokenslayer.actions

import app.tokenslayer.TokenSlayerService
import com.intellij.openapi.actionSystem.AnAction
import com.intellij.openapi.actionSystem.AnActionEvent
import com.intellij.openapi.application.ApplicationManager

private val svc get() = ApplicationManager.getApplication().getService(TokenSlayerService::class.java)

class SignInAction : AnAction() { override fun actionPerformed(e: AnActionEvent) = svc.auth.startSignIn() }
class SignOutAction : AnAction() { override fun actionPerformed(e: AnActionEvent) = svc.auth.signOut() }
class InstallHooksAction : AnAction() { override fun actionPerformed(e: AnActionEvent) = svc.installHooks(e.project) }
class UninstallHooksAction : AnAction() { override fun actionPerformed(e: AnActionEvent) = svc.uninstallHooks(e.project) }
class OpenBattlefieldAction : AnAction() { override fun actionPerformed(e: AnActionEvent) = svc.openBattlefield(e.project) }
class OpenProfileAction : AnAction() { override fun actionPerformed(e: AnActionEvent) = svc.openProfile() }
