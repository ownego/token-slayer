package app.tokenslayer.ui

import com.intellij.notification.NotificationGroupManager
import com.intellij.notification.NotificationType
import com.intellij.openapi.project.Project

object TokenSlayerNotifications {
    private fun notify(project: Project?, text: String) {
        NotificationGroupManager.getInstance()
            .getNotificationGroup("Token Slayer")
            .createNotification(text, NotificationType.INFORMATION)
            .notify(project)
    }
    fun hit(project: Project?, damage: Int, hpAfter: Int, max: Int) =
        notify(project, "Token Slayer: you hit for $damage (boss $hpAfter/$max)")
    fun bossDefeated(project: Project?, killer: String?) =
        notify(project, "Token Slayer: boss defeated" + (killer?.let { " by @$it" } ?: ""))
    fun bossSpawned(project: Project?, name: String) =
        notify(project, "Token Slayer: new boss spawned — $name")
}
