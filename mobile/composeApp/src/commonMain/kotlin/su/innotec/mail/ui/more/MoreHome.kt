package su.innotec.mail.ui.more

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.launch
import su.innotec.mail.AppInfo
import su.innotec.mail.Nav
import su.innotec.mail.data.Session
import su.innotec.mail.platform.Sys
import su.innotec.mail.ui.Avatar
import su.innotec.mail.ui.Badge
import su.innotec.mail.ui.ConfirmDialog
import su.innotec.mail.ui.Divider
import su.innotec.mail.ui.ListRow
import su.innotec.mail.ui.P
import su.innotec.mail.ui.SectionTitle
import su.innotec.mail.ui.calendar.CalStore
import su.innotec.mail.ui.cloud.CloudStore
import su.innotec.mail.ui.contacts.ContactsStore
import su.innotec.mail.ui.mail.MailStore
import su.innotec.mail.ui.mail.OutboxScreen
import su.innotec.mail.ui.mail.QuarantineScreen

/** Выйти на этом устройстве: токен отзывается на сервере, локальное всё стирается. */
fun signOut() {
    val api = Session.api
    CoroutineScope(SupervisorJob() + Dispatchers.Main).launch {
        runCatching { api?.logout() }
    }
    su.innotec.mail.platform.Notifier.schedule(false)
    MailStore.reset(); ContactsStore.reset(); CalStore.reset(); CloudStore.reset()
    Nav.reset()
    Session.signOut()
}

@Composable
fun MoreHome() {
    val acc = Session.account ?: return
    var confirm by remember { mutableStateOf(false) }
    var tickets by remember { mutableStateOf(0) }
    LaunchedEffect(Unit) { tickets = runCatching { Session.api!!.ticketsUnread() }.getOrDefault(0); Updates.checkQuietly() }
    Column(Modifier.fillMaxSize().background(P.bg).verticalScroll(rememberScrollState())) {
        Row(Modifier.fillMaxWidth().background(P.surface).statusBarsPadding().padding(16.dp), verticalAlignment = Alignment.CenterVertically) {
            Avatar(acc.name.ifBlank { acc.user }, acc.user, 52.dp)
            Spacer(Modifier.width(14.dp))
            Column(Modifier.weight(1f)) {
                Text(acc.name.ifBlank { acc.user }, style = MaterialTheme.typography.titleMedium, maxLines = 1, overflow = TextOverflow.Ellipsis)
                Text(acc.user, style = MaterialTheme.typography.bodySmall, color = P.muted, maxLines = 1)
                Text(acc.serverName.ifBlank { acc.origin.removePrefix("https://") }, style = MaterialTheme.typography.bodySmall, color = P.faint, maxLines = 1)
            }
        }
        Divider()
        if (Updates.available != null) {
            ListRow("Доступна версия ${Updates.available!!.version}", "Нажмите, чтобы обновить", icon = "download", iconTint = P.accent) { Nav.push(AboutScreen()) }
            Divider()
        }
        SectionTitle("Почта")
        Column(Modifier.background(P.surface)) {
            ListRow("Настройки почты", "Имя, подпись, ответы, отмена отправки, картинки", icon = "sliders") { Nav.push(MailSettingsScreen()) }
            ListRow("Метки", icon = "tag") { Nav.push(LabelsScreen()) }
            ListRow("Правила и автоответ", icon = "filter") { Nav.push(RulesScreen()) }
            ListRow("Файлы по ссылке", "Большие вложения на files", icon = "link") { Nav.push(FilesScreen()) }
            ListRow("Карантин", icon = "shield", trailing = { Badge(MailStore.quarantineCount, strong = false) }) { Nav.push(QuarantineScreen()) }
            ListRow("Ждут отправки", icon = "clock", trailing = { Badge(MailStore.outboxCount, strong = false) }) { Nav.push(OutboxScreen()) }
        }
        SectionTitle("Приложение")
        Column(Modifier.background(P.surface)) {
            ListRow("Оформление и уведомления", "Тема, уведомления, жесты в списке писем", icon = "bell") { Nav.push(AppSettingsScreen()) }
            ListRow("Безопасность", "Устройства, сеансы, пароли приложений, 2FA", icon = "lock") { Nav.push(SecurityScreen()) }
            ListRow("Сообщить о проблеме", "Обращения к администратору", icon = "info", trailing = { Badge(tickets) }) { Nav.push(TicketsScreen()) }
            ListRow("Справка", "Откроется в браузере", icon = "book") { Sys.openUrl(acc.origin + "/mail/help") }
            ListRow("О приложении", "Версия ${AppInfo.VERSION}", icon = "mobile") { Nav.push(AboutScreen()) }
        }
        Spacer(Modifier.height(12.dp))
        Box(Modifier.background(P.surface)) {
            ListRow("Выйти на этом устройстве", icon = "logout", danger = true) { confirm = true }
        }
        Spacer(Modifier.height(40.dp))
    }
    if (confirm) ConfirmDialog("Выйти?", "Вход на этом устройстве будет отозван. Письма останутся на сервере.", "Выйти", danger = true, onDismiss = { confirm = false }) { signOut() }
}
