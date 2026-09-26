package su.innotec.mail.ui.more

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.delay
import su.innotec.mail.AppInfo
import su.innotec.mail.Nav
import su.innotec.mail.Screen
import su.innotec.mail.api.LocalFile
import su.innotec.mail.api.Ticket
import su.innotec.mail.api.TicketMessage
import su.innotec.mail.data.Session
import su.innotec.mail.platform.PlatformInfo
import su.innotec.mail.platform.rememberFilePicker
import su.innotec.mail.ui.Badge
import su.innotec.mail.ui.Chip
import su.innotec.mail.ui.Divider
import su.innotec.mail.ui.Empty
import su.innotec.mail.ui.Fmt
import su.innotec.mail.ui.Ico
import su.innotec.mail.ui.P
import su.innotec.mail.ui.Toasts
import su.innotec.mail.ui.Transfers
import su.innotec.mail.ui.launchSafe
import su.innotec.mail.ui.mail.IconBtn
import su.innotec.mail.ui.mail.Loader
import su.innotec.mail.ui.mail.SubBar

class TicketsScreen : Screen() {
    @Composable
    override fun Content() {
        var key by remember { mutableStateOf(0) }
        Column(Modifier.fillMaxSize().background(P.bg)) {
            SubBar("Обращения") { IconBtn("plus", "Новое обращение") { Nav.push(NewTicketScreen { key++ }) } }
            Loader(key, { Session.api!!.tickets() }) { t, _ ->
                if (t.tickets.isEmpty()) Empty("info", "Обращений нет", "Что-то не работает или есть идея — напишите администратору") {
                    androidx.compose.material3.Button(onClick = { Nav.push(NewTicketScreen { key++ }) }) { Text("Написать") }
                } else LazyColumn {
                    items(t.tickets.size) { i ->
                        val x = t.tickets[i]
                        Row(Modifier.fillMaxWidth().background(P.surface).clickable { Nav.push(TicketScreen(x)) }.padding(16.dp), verticalAlignment = Alignment.CenterVertically) {
                            Column(Modifier.weight(1f)) {
                                Text("№${x.id} · ${x.subject}", style = MaterialTheme.typography.bodyLarge, maxLines = 1, overflow = TextOverflow.Ellipsis,
                                    fontWeight = if (x.newForUser) FontWeight.SemiBold else FontWeight.Normal)
                                Text("${x.kindLabel} · ${x.statusLabel} · ${Fmt.listDate(x.lastReplyAt ?: x.createdAt)}", style = MaterialTheme.typography.bodySmall, color = P.muted)
                                x.last?.let { Text(it.text, style = MaterialTheme.typography.bodySmall, color = P.faint, maxLines = 1, overflow = TextOverflow.Ellipsis) }
                            }
                            if (x.newForUser) Badge(1)
                        }
                        Divider()
                    }
                }
            }
        }
    }
}

private fun context(): String = """{"client":"${PlatformInfo.kind} · приложение ${AppInfo.VERSION}","screen":"${PlatformInfo.model}","lang":"ru"}"""

class NewTicketScreen(private val onDone: () -> Unit) : Screen() {
    override val fullScreen: Boolean get() = true

    @Composable
    override fun Content() {
        var kind by remember { mutableStateOf("bug") }
        var subject by remember { mutableStateOf("") }
        var text by remember { mutableStateOf("") }
        var file by remember { mutableStateOf<LocalFile?>(null) }
        var sending by remember { mutableStateOf(false) }
        val scope = rememberCoroutineScope()
        val pick = rememberFilePicker(false, listOf("image/*")) { file = it.firstOrNull() }
        Column(Modifier.fillMaxSize().background(P.surface).imePadding()) {
            SubBar("Новое обращение") {
                TextButton(enabled = !sending && text.trim().length >= 3, onClick = {
                    sending = true
                    scope.launchSafe {
                        try {
                            val r = Session.api!!.createTicket(kind, text.trim(), subject, context(), file)
                            Toasts.show("Обращение №${r.id} отправлено"); onDone(); Nav.pop()
                        } finally { sending = false }
                    }
                }) { Text("Отправить", fontWeight = FontWeight.SemiBold) }
            }
            Column(Modifier.verticalScroll(rememberScrollState()).padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    listOf("bug" to "Не работает", "idea" to "Идея", "question" to "Вопрос").forEach { (k, t) -> Chip(t, kind == k, { kind = k }) }
                }
                OutlinedTextField(subject, { subject = it }, Modifier.fillMaxWidth(), label = { Text("Тема (необязательно)") }, singleLine = true)
                OutlinedTextField(text, { text = it }, Modifier.fillMaxWidth(), label = { Text("Что случилось") }, minLines = 6)
                Row(verticalAlignment = Alignment.CenterVertically) {
                    TextButton(onClick = { pick() }) { Ico("img", size = 18.dp); Spacer(Modifier.padding(3.dp)); Text(file?.name ?: "Приложить снимок экрана") }
                    if (file != null) IconBtn("x", "Убрать", tint = P.muted) { file = null }
                }
                Text("К обращению приложится версия приложения и модель устройства — так администратору проще разобраться.", style = MaterialTheme.typography.bodySmall, color = P.faint)
            }
        }
    }
}

class TicketScreen(private val start: Ticket) : Screen() {
    @Composable
    override fun Content() {
        var ticket by remember { mutableStateOf(start) }
        val messages = remember { mutableStateListOf<TicketMessage>() }
        var text by remember { mutableStateOf("") }
        var file by remember { mutableStateOf<LocalFile?>(null) }
        val scope = rememberCoroutineScope()
        val list = rememberLazyListState()
        val pick = rememberFilePicker(false, listOf("image/*")) { file = it.firstOrNull() }
        // Живое обновление, пока экран открыт: новые ответы администратора подтягиваются сами.
        LaunchedEffect(start.id) {
            while (true) {
                runCatching { Session.api!!.ticketPoll(start.id, messages.lastOrNull()?.id ?: 0) }.onSuccess { r ->
                    if (r.messages.isNotEmpty()) { messages.addAll(r.messages); list.animateScrollToItem(messages.lastIndex.coerceAtLeast(0)) }
                    ticket = r.ticket
                }
                delay(15_000)
            }
        }
        val me = Session.account?.user
        Column(Modifier.fillMaxSize().background(P.bg).imePadding()) {
            SubBar("№${ticket.id} · ${ticket.subject}", "${ticket.kindLabel} · ${ticket.statusLabel}")
            LazyColumn(Modifier.weight(1f), state = list) {
                items(messages.size) { i ->
                    val m = messages[i]
                    val mine = m.author == me
                    Row(Modifier.fillMaxWidth().padding(horizontal = 12.dp, vertical = 4.dp), horizontalArrangement = if (mine) Arrangement.End else Arrangement.Start) {
                        Column(Modifier.widthIn(max = 320.dp).clip(RoundedCornerShape(12.dp)).background(if (mine) P.accentSoft else P.surface).padding(10.dp)) {
                            if (!mine) Text(if (m.role == "admin") "Администратор" else m.author, style = MaterialTheme.typography.labelMedium, color = P.accentInk)
                            Text(m.text, style = MaterialTheme.typography.bodyMedium)
                            if (m.file) TextButton(onClick = { Transfers.fetch(Session.api!!.ticketFilePath(ticket.id, m.id), "снимок-${m.id}.png", Transfers.Then.OPEN) }) { Text("Вложение") }
                            Text(Fmt.full(m.at), style = MaterialTheme.typography.labelSmall, color = P.faint)
                        }
                    }
                }
            }
            Divider()
            Row(Modifier.fillMaxWidth().background(P.surface).padding(8.dp), verticalAlignment = Alignment.CenterVertically) {
                IconBtn("img", "Приложить снимок", tint = if (file != null) P.accent else P.muted) { pick() }
                OutlinedTextField(text, { text = it }, Modifier.weight(1f), placeholder = { Text("Ответ") }, maxLines = 4)
                IconBtn("send", "Отправить", tint = if (text.isNotBlank()) P.accent else P.faint) {
                    if (text.isBlank()) return@IconBtn
                    val t = text.trim(); val f = file
                    text = ""; file = null
                    scope.launchSafe {
                        val r = Session.api!!.replyTicket(ticket.id, t, f)
                        messages.add(r.message); ticket = r.ticket
                        list.animateScrollToItem(messages.lastIndex)
                    }
                }
            }
        }
    }
}
