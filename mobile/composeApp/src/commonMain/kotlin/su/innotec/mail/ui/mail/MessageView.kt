package su.innotec.mail.ui.mail

import androidx.compose.foundation.ExperimentalFoundationApi
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.combinedClickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.DatePicker
import androidx.compose.material3.DatePickerDialog
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TimePicker
import androidx.compose.material3.rememberDatePickerState
import androidx.compose.material3.rememberTimePickerState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.launch
import kotlinx.datetime.DatePeriod
import kotlinx.datetime.DayOfWeek
import kotlinx.datetime.LocalDateTime
import kotlinx.datetime.LocalTime
import kotlinx.datetime.TimeZone
import kotlinx.datetime.atTime
import kotlinx.datetime.plus
import kotlinx.datetime.toInstant
import kotlinx.datetime.toLocalDateTime
import kotlin.time.Clock
import kotlin.time.Instant
import su.innotec.mail.Nav
import su.innotec.mail.Screen
import su.innotec.mail.api.ApiException
import su.innotec.mail.api.ApiJson
import su.innotec.mail.api.Attachment
import su.innotec.mail.api.CloudFileRef
import su.innotec.mail.api.Message
import su.innotec.mail.api.Person
import su.innotec.mail.api.Thread
import su.innotec.mail.data.Session
import su.innotec.mail.platform.HtmlView
import su.innotec.mail.platform.Sys
import su.innotec.mail.platform.hasBlockedImages
import su.innotec.mail.platform.textToHtml
import su.innotec.mail.platform.unblockImages
import su.innotec.mail.ui.Avatar
import su.innotec.mail.ui.Divider
import su.innotec.mail.ui.ErrorBox
import su.innotec.mail.ui.Fmt
import su.innotec.mail.ui.Ico
import su.innotec.mail.ui.Loading
import su.innotec.mail.ui.P
import su.innotec.mail.ui.Toasts
import su.innotec.mail.ui.Transfers
import su.innotec.mail.ui.ViewItem
import su.innotec.mail.ui.Viewable
import su.innotec.mail.ui.hexColor
import su.innotec.mail.ui.launchSafe

class MessageScreen(private val folder: String, private val uid: Long) : Screen() {
    override val replacesSame: Boolean get() = true

    @Composable
    override fun Content() = MessageContent(folder, uid, inPane = false, onClose = { Nav.pop() })
}

/** Письмо из приложенного .eml (открывается как отдельное письмо, только чтение). */
class AttachedMessageScreen(private val folder: String, private val uid: Long, private val index: Int) : Screen() {
    @Composable
    override fun Content() {
        var msg by remember { mutableStateOf<Message?>(null) }
        var err by remember { mutableStateOf<String?>(null) }
        LaunchedEffect(Unit) { try { msg = Session.api!!.attachedMessage(folder, uid, index) } catch (e: ApiException) { err = e.message } }
        Column(Modifier.fillMaxSize().background(P.bg)) {
            TopBar(onBack = { Nav.pop() }, title = "Вложенное письмо")
            when {
                err != null -> ErrorBox(err!!, { err = null })
                msg == null -> Loading()
                else -> MessageBody(msg!!, folder, uid, attachedIndex = index)
            }
        }
    }
}

@Composable
private fun TopBar(onBack: () -> Unit, title: String = "", pane: Boolean = false, actions: @Composable () -> Unit = {}) {
    Row(Modifier.fillMaxWidth().background(P.surface).statusBarsPadding().height(56.dp).padding(horizontal = 4.dp), verticalAlignment = Alignment.CenterVertically) {
        // Отдельный экран — стрелка «назад»; панель чтения на ПК — крестик «закрыть».
        IconBtn(if (pane) "x" else "back", if (pane) "Закрыть" else "Назад", Modifier.testTag("msg-back")) { onBack() }
        Text(title, Modifier.weight(1f), style = MaterialTheme.typography.bodyMedium, color = P.muted, maxLines = 1, overflow = TextOverflow.Ellipsis)
        actions()
    }
}

/** «2 из 3 в переписке»: место открытого письма в цепочке (новые сверху, как в списке). */
fun threadPosition(t: Thread?, folder: String, uid: Long, date: String): String {
    if (t == null || t.messages.isEmpty()) return ""
    val all = (t.messages.map { Triple(it.folder, it.uid, it.date) } + Triple(folder, uid, date)).sortedByDescending { Fmt.parse(it.third)?.toEpochMilliseconds() ?: 0L }
    val i = all.indexOfFirst { it.first == folder && it.second == uid }
    return if (i < 0) "" else "${i + 1} из ${all.size} в переписке"
}

@Composable
fun MessageContent(folder: String, uid: Long, inPane: Boolean, onClose: () -> Unit) {
    var msg by remember { mutableStateOf<Message?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var reload by remember { mutableStateOf(0) }
    var more by remember { mutableStateOf(false) }
    var dialog by remember { mutableStateOf<String?>(null) }
    // Цепочка — здесь, а не в теле: шапке нужно «2 из 3 в переписке».
    var thread by remember(folder, uid) { mutableStateOf<Thread?>(null) }
    // Текст быстрого ответа общий для строки внизу и чипов «Ответить/Всем» в карточке письма.
    var quick by remember(folder, uid) { mutableStateOf("") }
    val scope = rememberCoroutineScope()
    val role = MailStore.folders.firstOrNull { it.path == folder }?.role
    val readonly = MailStore.folders.firstOrNull { it.path == folder }?.readonly == true

    LaunchedEffect(folder, uid, reload) {
        error = null
        // Сохранённое письмо — сразу, свежее с сервера — следом (и без сети письмо откроется).
        if (msg == null) MailCache.message(folder, uid)?.let { msg = it }
        try {
            val m = Session.api!!.message(folder, uid)
            msg = m
            MailCache.saveMessage(folder, uid, m)
            MailStore.markOpened(uid)
            if (m.markedSeen) MailStore.refreshFolders()
        } catch (e: ApiException) {
            when {
                e.isAuth -> Toasts.error(e)
                msg != null && e.isNetwork -> Toasts.show("Нет связи — письмо из сохранённых")
                msg == null -> error = e.message
                else -> Toasts.error(e)
            }
        }
    }
    LaunchedEffect(folder, uid) { thread = runCatching { Session.api!!.thread(folder, uid) }.getOrNull() }

    fun leave(op: String, target: String? = null, until: String? = null) {
        MailStore.act(op, listOf(uid), target = target, until = until, folder = folder, senders = listOfNotNull(msg?.from?.mail))
        onClose()
    }

    Column(Modifier.fillMaxSize().background(P.bg)) {
        TopBar(onBack = onClose, pane = inPane, title = msg?.let { threadPosition(thread, folder, uid, it.date) } ?: "") {
            val m = msg
            if (m != null && !readonly) {
                IconBtn("archive", "В архив", Modifier.testTag("msg-archive")) { leave("archive") }
                IconBtn("trash", "Удалить", Modifier.testTag("msg-delete")) { leave("delete") }
            }
            if (m != null) IconBtn("fwd", "Переслать", Modifier.testTag("msg-forward")) { Nav.push(ComposeScreen(ComposeStart.Forward(folder, uid))) }
            Box {
                IconBtn("dots", "Ещё", Modifier.testTag("msg-more")) { more = true }
                DropdownMenu(more, { more = false }) {
                    val m = msg
                    if (m != null && !readonly) {
                        DropdownMenuItem({ Text("Непрочитано") }, { more = false; MailStore.act("unseen", listOf(uid), folder = folder); onClose() }, leadingIcon = { Ico("unread") })
                        DropdownMenuItem({ Text(if (m.flagged) "Снять флажок" else "Флажок") }, {
                            more = false
                            MailStore.act(if (m.flagged) "unflag" else "flag", listOf(uid), folder = folder)
                            msg = m.copy(flagged = !m.flagged)
                        }, leadingIcon = { Ico("flag") })
                        DropdownMenuItem({ Text("Перенести в папку…") }, { more = false; dialog = "move" }, leadingIcon = { Ico("folder") })
                        DropdownMenuItem({ Text("Метка…") }, { more = false; dialog = "label" }, leadingIcon = { Ico("tag") })
                        DropdownMenuItem({ Text("Отложить…") }, { more = false; dialog = "snooze" }, leadingIcon = { Ico("clock") })
                        if (role == "spam") DropdownMenuItem({ Text("Не спам") }, { more = false; leave("notspam") }, leadingIcon = { Ico("inbox") })
                        else DropdownMenuItem({ Text("Это спам") }, { more = false; leave("spam") }, leadingIcon = { Ico("spam") })
                        if (role != "lists") DropdownMenuItem({ Text("Это рассылка") }, { more = false; leave("lists") }, leadingIcon = { Ico("ul") })
                        if (role == "snoozed") DropdownMenuItem({ Text("Вернуть во «Входящие»") }, { more = false; leave("unsnooze") }, leadingIcon = { Ico("inbox") })
                        Divider()
                    }
                    if (m != null) {
                        if (role != "drafts") DropdownMenuItem({ Text(if (Pinned.has(folder, uid)) "Убрать из-под руки" else "Держать под рукой") }, {
                            more = false; pinMessage(PinnedMessage(folder, uid, m.subject, m.from.display))
                        }, leadingIcon = { Ico("pin") })
                        DropdownMenuItem({ Text("Переслать вложением") }, { more = false; Nav.push(ComposeScreen(ComposeStart.ForwardAsAttachment(listOf(folder to uid)))) }, leadingIcon = { Ico("fwd") })
                        DropdownMenuItem({ Text("Изменить как новое") }, { more = false; Nav.push(ComposeScreen(ComposeStart.Again(folder, uid))) }, leadingIcon = { Ico("edit") })
                        DropdownMenuItem({ Text("Вся переписка с отправителем") }, {
                            more = false
                            MailStore.search("переписка:" + m.from.mail, everywhere = true)
                            if (!inPane) Nav.pop()
                        }, leadingIcon = { Ico("users") })
                        DropdownMenuItem({ Text("Письма от отправителя — в папку…") }, { more = false; dialog = "rule" }, leadingIcon = { Ico("filter") })
                        DropdownMenuItem({ Text("Скачать письмо (.eml)") }, { more = false; Transfers.fetch(Session.api!!.rawPath(folder, uid), safeName(m.subject) + ".eml", Transfers.Then.SAVE) }, leadingIcon = { Ico("download") })
                        DropdownMenuItem({ Text("Показать оригинал") }, { more = false; Transfers.fetch(Session.api!!.rawPath(folder, uid), safeName(m.subject) + ".txt", Transfers.Then.OPEN) }, leadingIcon = { Ico("code") })
                        DropdownMenuItem({ Text("Назначить встречу") }, { more = false; Nav.push(su.innotec.mail.ui.calendar.EventEditScreen.fromMessage(m)) }, leadingIcon = { Ico("cal") })
                        if (!readonly) DropdownMenuItem({ Text("Напомнить, если не ответят…") }, { more = false; dialog = "remind" }, leadingIcon = { Ico("bell") })
                        if (su.innotec.mail.platform.Printer.available) DropdownMenuItem({ Text("Печать") }, {
                            more = false
                            su.innotec.mail.platform.Printer.print(m.subject, printDocument(m)) { p -> Transfers.inlineResource(p) }
                        }, leadingIcon = { Ico("print") })
                    }
                }
            }
        }
        Divider()
        Transfers.active?.let {
            LinearProgressIndicator(progress = { Transfers.progress }, modifier = Modifier.fillMaxWidth(), color = P.accent)
        }
        when {
            error != null -> ErrorBox(error!!, { reload++ })
            msg == null -> Loading()
            else -> Box(Modifier.weight(1f)) { MessageBody(msg!!, folder, uid, thread = thread, quick = quick, onCloudFile = { c -> msg = msg?.let { m -> m.copy(cloudFiles = m.cloudFiles.map { if (it.token == c.token) c else it }) } }) }
        }
        val m = msg
        if (m != null && role != "drafts") ReplyBar(m, folder, quick, onQuick = { quick = it })
    }

    when (dialog) {
        "move" -> FolderPicker("Перенести в папку", folder, onDismiss = { dialog = null }) { f -> leave("move", target = f.path) }
        "label" -> LabelDialog(listOf(uid), onDismiss = { dialog = null }, folder = folder, current = msg?.labels ?: emptyList()) { msg = msg?.copy(labels = it) }
        "snooze" -> SnoozeDialog(onDismiss = { dialog = null }) { until -> leave("snooze", until = until) }
        "rule" -> RuleFromSenderDialog(msg?.from?.mail ?: "", onDismiss = { dialog = null })
        "remind" -> RemindDialog(onDismiss = { dialog = null }) { until -> MailStore.act("remind", listOf(uid), until = until, folder = folder) }
    }
}

private fun safeName(s: String) = s.ifBlank { "письмо" }.replace(Regex("[\\\\/:*?\"<>|]"), "_").take(80)

/**
 * Тело письма (макет 27.09): тема, карточка письма на фоне [P.bg] — отправитель, «кому: мне · подробнее»,
 * текст, вложения карточками, чипы «Ответить · Всем · Переслать»; ниже — свёрнутые письма переписки.
 * [onCloudFile] — карточка файла по ссылке обновилась (продлили срок).
 */
@OptIn(ExperimentalLayoutApi::class)
@Composable
fun MessageBody(m: Message, folder: String, uid: Long, attachedIndex: Int? = null, thread: Thread? = null, quick: String = "", onCloudFile: (CloudFileRef) -> Unit = {}) {
    var showImages by remember(m.uid) { mutableStateOf(MailStore.settings.showImages == "always") }
    var card by remember { mutableStateOf<Person?>(null) }
    var unsub by remember { mutableStateOf<String?>(null) }
    card?.let { p -> SenderCard(p, onDismiss = { card = null }) }
    // Переход на экран письма — побочное действие, ему место в эффекте, а не в композиции (иначе повтор при перерисовке).
    LaunchedEffect(unsub) {
        val link = unsub ?: return@LaunchedEffect
        if (link.startsWith("mailto:", true)) { unsub = null; Nav.push(ComposeScreen(ComposeStart.Mailto(link))) }
    }
    unsub?.let { link ->
        // Ссылка ведёт на чужой сайт из письма, которое сочли лишним: показываем адрес и спрашиваем (как в веб-почте).
        if (!link.startsWith("mailto:", true)) su.innotec.mail.ui.ConfirmDialog("Открыть страницу отписки?", "Сайт: " + link.substringAfter("://").substringBefore('/'), "Открыть", onDismiss = { unsub = null }) { Sys.openUrl(link) }
    }
    var showRecipients by remember { mutableStateOf(false) }
    val dark = P.dark
    val role = MailStore.folders.firstOrNull { it.path == folder }?.role
    Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).testTag("msg-body")) {
        // Тема и метки
        Column(Modifier.padding(start = 16.dp, end = 16.dp, top = 14.dp, bottom = 10.dp)) {
            Text(m.subject.ifBlank { "(без темы)" }, style = MaterialTheme.typography.titleLarge, modifier = Modifier.testTag("msg-subject"))
            val labels = m.labels.mapNotNull { id -> MailStore.labels.firstOrNull { it.id == id } }
            if (labels.isNotEmpty() || m.flagged) {
                Spacer(Modifier.height(6.dp))
                FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    if (m.flagged) TagPill("Флажок", P.warn)
                    labels.forEach { TagPill(it.name, hexColor(it.color)) }
                }
            }
        }
        // Карточка письма
        Column(Modifier.padding(horizontal = 10.dp).fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(P.surface).border(1.dp, P.border, RoundedCornerShape(14.dp))) {
            // Отправитель
            Row(Modifier.fillMaxWidth().clickable { showRecipients = !showRecipients }.padding(start = 12.dp, end = 12.dp, top = 12.dp, bottom = 4.dp), verticalAlignment = Alignment.CenterVertically) {
                Box(Modifier.clip(CircleShape).clickable { card = m.from }) { Avatar(m.from.display.ifBlank { "?" }, m.from.mail, 40.dp) }
                Spacer(Modifier.width(10.dp))
                Column(Modifier.weight(1f)) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Text(m.from.display, Modifier.weight(1f), style = MaterialTheme.typography.titleSmall, maxLines = 1, overflow = TextOverflow.Ellipsis)
                        Spacer(Modifier.width(8.dp))
                        Text(Fmt.listDate(m.date), style = MaterialTheme.typography.bodySmall, color = P.faint)
                    }
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Text("кому: " + recipientsShort(m.to + m.cc), Modifier.weight(1f, fill = false), style = MaterialTheme.typography.bodySmall, color = P.muted, maxLines = 1, overflow = TextOverflow.Ellipsis)
                        Text(if (showRecipients) " · свернуть" else " · подробнее", style = MaterialTheme.typography.bodySmall, color = P.link, maxLines = 1)
                    }
                }
            }
            if (showRecipients) {
                Column(Modifier.padding(start = 12.dp, end = 12.dp, top = 4.dp, bottom = 6.dp)) {
                    RecipientLine("От", listOf(m.from)) { card = it }
                    RecipientLine("Кому", m.to) { card = it }
                    if (m.cc.isNotEmpty()) RecipientLine("Копия", m.cc) { card = it }
                    if (m.bcc.isNotEmpty()) RecipientLine("Скрытая", m.bcc) { card = it }
                    if (m.replyTo.isNotEmpty()) RecipientLine("Ответ на", m.replyTo) { card = it }
                    Row(Modifier.padding(vertical = 2.dp)) {
                        Text("Дата:", Modifier.width(70.dp), style = MaterialTheme.typography.bodySmall, color = P.faint)
                        Text(Fmt.full(m.date), style = MaterialTheme.typography.bodySmall)
                    }
                }
            }
            // Отписаться от рассылки
            if (m.listUnsubscribe.isNotBlank()) {
                val link = Regex("<(https?://[^>]+)>").find(m.listUnsubscribe)?.groupValues?.get(1)
                    ?: Regex("<(mailto:[^>]+)>").find(m.listUnsubscribe)?.groupValues?.get(1)
                if (link != null) Row(Modifier.padding(horizontal = 12.dp, vertical = 4.dp)) {
                    OutlinedButton(onClick = { unsub = link }) { Ico("unsub", size = 16.dp); Spacer(Modifier.width(6.dp)); Text("Отписаться от рассылки") }
                }
            }
            // Внешние картинки
            if (!showImages && hasBlockedImages(m.html)) {
                Row(
                    Modifier.padding(horizontal = 12.dp, vertical = 6.dp).fillMaxWidth().clip(RoundedCornerShape(8.dp)).background(P.surface2).border(1.dp, P.border, RoundedCornerShape(8.dp))
                        .padding(horizontal = 12.dp, vertical = 8.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Ico("img", size = 18.dp, tint = P.muted); Spacer(Modifier.width(8.dp))
                    Text("Картинки из интернета скрыты", Modifier.weight(1f), style = MaterialTheme.typography.bodySmall, color = P.muted)
                    TextButton(onClick = { showImages = true }, modifier = Modifier.testTag("show-images")) { Text("Показать") }
                }
            }
            // Текст письма
            val html = remember(m.html, m.text, showImages) {
                val h = m.html?.takeIf { it.isNotBlank() } ?: textToHtml(m.text ?: "")
                if (showImages) unblockImages(h) else h
            }
            Box(Modifier.fillMaxWidth().padding(horizontal = 4.dp, vertical = 6.dp).clip(RoundedCornerShape(10.dp)).heightIn(min = 60.dp)) {
                HtmlView(html, dark, Modifier.fillMaxWidth(), onLink = { url -> openLink(url) }, loadResource = { p -> Transfers.inlineResource(p) })
            }
            // Вложения — карточками в строку
            val files = m.attachments.filter { !it.inline || m.html == null || !m.html.contains("attachment/${it.index}?inline") }
            if (files.isNotEmpty()) {
                Row(Modifier.padding(start = 12.dp, end = 4.dp, top = 4.dp), verticalAlignment = Alignment.CenterVertically) {
                    Text("${files.size} ${Fmt.plural(files.size, "вложение", "вложения", "вложений")} · ${Fmt.size(files.sumOf { it.size })}", Modifier.weight(1f),
                        style = MaterialTheme.typography.labelLarge, color = P.muted)
                    if (files.size > 1 && attachedIndex == null) TextButton(onClick = {
                        Transfers.fetch(Session.api!!.attachmentsZipPath(folder, uid), safeName(m.subject) + ".zip", Transfers.Then.SAVE)
                    }) { Text("Скачать все") }
                }
                // Файл нулевого размера приложен с ошибкой: лучше сказать сразу, чем ждать, пока он не откроется.
                if (files.any { it.size == 0L }) Row(Modifier.padding(horizontal = 12.dp, vertical = 4.dp), verticalAlignment = Alignment.CenterVertically) {
                    Ico("warn", size = 16.dp, tint = P.warn); Spacer(Modifier.width(6.dp))
                    Text("Пустое вложение: файл, скорее всего, приложен с ошибкой — попросите прислать его заново.", style = MaterialTheme.typography.bodySmall, color = P.muted)
                }
                val views = files.mapNotNull { a -> viewItem(a, folder, uid, attachedIndex) }
                Row(Modifier.fillMaxWidth().horizontalScroll(rememberScrollState()).padding(horizontal = 12.dp, vertical = 6.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    files.forEach { a -> AttachmentCard(a, folder, uid, attachedIndex, views) }
                }
            }
            if (m.cloudFiles.isNotEmpty()) {
                Row(Modifier.padding(start = 12.dp, end = 4.dp, top = 4.dp), verticalAlignment = Alignment.CenterVertically) {
                    Text("Файлы по ссылке", Modifier.weight(1f), style = MaterialTheme.typography.labelLarge, color = P.muted)
                    if (m.cloudFiles.size > 1) TextButton(onClick = { Transfers.fetch(Session.api!!.cloudZipPath(folder, uid), safeName(m.subject) + " (облако).zip", Transfers.Then.SAVE) }) { Text("Скачать все") }
                }
                val views = m.cloudFiles.mapNotNull { cloudViewItem(it) }
                Row(Modifier.fillMaxWidth().horizontalScroll(rememberScrollState()).padding(horizontal = 12.dp, vertical = 6.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    m.cloudFiles.forEach { c -> CloudFileCard(c, views, onChanged = onCloudFile) }
                }
            }
            // Ответить · Всем · Переслать — внутри карточки (макет); в черновике и вложенном письме их нет.
            if (attachedIndex == null && role != "drafts") {
                val many = (m.to + m.cc).count { it.mail.lowercase() != Session.account?.user?.lowercase() } > 1
                Row(Modifier.padding(start = 12.dp, end = 12.dp, top = 8.dp, bottom = 12.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    ReplyButton("reply", "Ответить", Modifier.testTag("reply"), accent = true) { Nav.push(ComposeScreen(ComposeStart.Reply(folder, m.uid, all = MailStore.settings.replyAll && many, text = quick))) }
                    if (many) ReplyButton("replyall", "Всем", Modifier.testTag("reply-all")) { Nav.push(ComposeScreen(ComposeStart.Reply(folder, m.uid, all = true, text = quick))) }
                    ReplyButton("fwd", "Переслать", Modifier.testTag("forward")) { Nav.push(ComposeScreen(ComposeStart.Forward(folder, m.uid))) }
                }
            } else Spacer(Modifier.height(8.dp))
        }
        // Цепочка: /thread отдаёт остальные письма переписки, открытое добавляем сами по дате.
        // Письмо из цепочки раскрывается здесь же (как в веб-почте), а не новым экраном со своей цепочкой.
        val t = thread
        if (t != null && t.messages.isNotEmpty()) {
            val self = su.innotec.mail.api.ThreadMessage(uid = uid, subject = m.subject, from = m.from, date = m.date, seen = true, folder = folder)
            // Новые сверху, открытое письмо — на своём месте по дате (как в веб-почте).
            val all = (t.messages + self).sortedByDescending { Fmt.parse(it.date)?.toEpochMilliseconds() ?: 0L }
            Text("В переписке ${all.size} ${Fmt.plural(all.size, "письмо", "письма", "писем")}" +
                (if (t.hidden > 0) " · показаны не все, ещё ${t.hidden} — найдёт «Вся переписка»" else ""),
                Modifier.padding(start = 16.dp, top = 16.dp, bottom = 4.dp, end = 16.dp), style = MaterialTheme.typography.labelLarge, color = P.muted)
            Column(Modifier.padding(horizontal = 10.dp).testTag("thread")) {
                all.forEach { tm -> ThreadItem(tm, current = tm.uid == uid && tm.folder == folder, openFolder = folder, dark = dark) }
            }
        }
        Spacer(Modifier.height(24.dp))
    }
}

/** Письмо цепочки: свёрнуто — карточка (аватар 26, имя · превью, время), развёрнуто — шапка, текст, вложения, ответ. */
@Composable
private fun ThreadItem(tm: su.innotec.mail.api.ThreadMessage, current: Boolean, openFolder: String, dark: Boolean) {
    var open by remember(tm.folder, tm.uid) { mutableStateOf(false) }
    var full by remember(tm.folder, tm.uid) { mutableStateOf<Message?>(null) }
    var error by remember(tm.folder, tm.uid) { mutableStateOf<String?>(null) }
    LaunchedEffect(open) {
        if (open && full == null) {
            val api = Session.api ?: return@LaunchedEffect
            // Папка только для чтения или чужая (без «отмечать прочитанным в общих»): смотрим, не трогая флаг.
            val src = MailStore.folders.firstOrNull { it.path == tm.folder }
            val peek = src?.readonly == true || (src?.isShared == true && !MailStore.settings.sharedMarkSeen)
            try {
                full = api.message(tm.folder, tm.uid, peek = peek)
                // Строка списка — только в открытой папке: в другой папке тот же uid — другое письмо.
                if (!peek && tm.folder == MailStore.query.folder) MailStore.markOpened(tm.uid)
            } catch (e: ApiException) { error = e.message }
        }
    }
    Column(
        Modifier.fillMaxWidth().padding(vertical = 3.dp).clip(RoundedCornerShape(12.dp)).background(if (current) P.accentSoft else P.surface)
            .border(1.dp, if (current) P.accentSoft else P.border, RoundedCornerShape(12.dp)),
    ) {
        Row(
            Modifier.fillMaxWidth().clickable(enabled = !current) { open = !open }.padding(10.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Avatar(tm.from.display.ifBlank { "?" }, tm.from.mail, 26.dp)
            Spacer(Modifier.width(10.dp))
            Column(Modifier.weight(1f)) {
                Text(tm.from.display + if (current) " · это письмо" else "", style = MaterialTheme.typography.bodyMedium,
                    fontWeight = if (tm.seen || open) FontWeight.Normal else FontWeight.SemiBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
                if (!current && !open) Text((tm.preview ?: tm.text ?: "").trim().take(160), style = MaterialTheme.typography.bodySmall, color = P.muted, maxLines = 1, overflow = TextOverflow.Ellipsis)
            }
            Column(horizontalAlignment = Alignment.End) {
                Text(if (open) Fmt.full(tm.date) else Fmt.listDate(tm.date), style = MaterialTheme.typography.bodySmall, color = P.faint)
                if (tm.folderName.isNotBlank() && tm.folder != openFolder) Text(tm.folderName, style = MaterialTheme.typography.labelSmall, color = P.faint)
            }
            if (!current) { Spacer(Modifier.width(6.dp)); Ico(if (open) "up" else "down", size = 16.dp, tint = P.faint) }
        }
        if (open) {
            val f = full
            when {
                error != null -> Text(error!!, Modifier.padding(12.dp), color = P.no)
                f == null -> Loading(Modifier.fillMaxWidth().height(80.dp))
                else -> Column(Modifier.padding(start = 8.dp, end = 8.dp, bottom = 10.dp)) {
                    Text("кому: " + recipientsShort(f.to + f.cc), Modifier.padding(start = 4.dp, bottom = 6.dp), style = MaterialTheme.typography.bodySmall, color = P.muted)
                    val html = remember(f.html, f.text) { unblockImagesIf(f.html?.takeIf { it.isNotBlank() } ?: textToHtml(f.text ?: "")) }
                    Box(Modifier.fillMaxWidth().clip(RoundedCornerShape(8.dp))) {
                        HtmlView(html, dark, Modifier.fillMaxWidth(), onLink = { url -> openLink(url) }, loadResource = { p -> Transfers.inlineResource(p) })
                    }
                    val files = f.attachments.filter { !it.inline }
                    if (files.isNotEmpty()) {
                        val views = files.mapNotNull { a -> viewItem(a, tm.folder, tm.uid, null) }
                        Row(Modifier.fillMaxWidth().horizontalScroll(rememberScrollState()).padding(vertical = 6.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            files.forEach { a -> AttachmentCard(a, tm.folder, tm.uid, null, views) }
                        }
                    }
                    Row(Modifier.padding(top = 8.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        ReplyButton("reply", "Ответить", accent = true) { Nav.push(ComposeScreen(ComposeStart.Reply(tm.folder, tm.uid, all = false))) }
                        ReplyButton("fwd", "Переслать") { Nav.push(ComposeScreen(ComposeStart.Forward(tm.folder, tm.uid))) }
                    }
                }
            }
        }
    }
}

/** Внешние картинки в развёрнутом письме цепочки — по той же настройке, что и в открытом. */
private fun unblockImagesIf(h: String) = if (MailStore.settings.showImages == "always") unblockImages(h) else h

private fun recipientsShort(list: List<Person>): String {
    val me = Session.account?.user?.lowercase()
    val names = list.map { if (it.mail.lowercase() == me) "мне" else it.display }
    return if (names.size <= 2) names.joinToString(", ") else names.take(2).joinToString(", ") + " и ещё ${names.size - 2}"
}

@Composable
private fun RecipientLine(title: String, list: List<Person>, onPerson: (Person) -> Unit) {
    Row(Modifier.padding(vertical = 2.dp)) {
        Text("$title:", Modifier.width(70.dp), style = MaterialTheme.typography.bodySmall, color = P.faint)
        Column {
            list.forEach { p ->
                Text(if (p.name.isNotBlank() && p.name != p.mail) "${p.name} <${p.mail}>" else p.mail, style = MaterialTheme.typography.bodySmall,
                    modifier = Modifier.clickable { onPerson(p) })
            }
        }
    }
}

/** Карточка человека из письма: написать, скопировать адрес, вся переписка, в контакты, правило для его писем. */
@Composable
private fun SenderCard(p: Person, onDismiss: () -> Unit) {
    val scope = rememberCoroutineScope()
    var rule by remember { mutableStateOf(false) }
    if (rule) { RuleFromSenderDialog(p.mail, onDismiss = { rule = false; onDismiss() }); return }
    androidx.compose.material3.AlertDialog(
        onDismissRequest = onDismiss,
        title = {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Avatar(p.name.ifBlank { p.mail }, p.mail, 44.dp); Spacer(Modifier.width(12.dp))
                Column {
                    if (p.name.isNotBlank() && p.name != p.mail) Text(p.name, style = MaterialTheme.typography.titleMedium)
                    Text(p.mail, style = MaterialTheme.typography.bodyMedium, color = P.muted)
                }
            }
        },
        text = {
            Column {
                CardItem("edit", "Написать") { onDismiss(); Nav.push(ComposeScreen(ComposeStart.New(to = p.mail))) }
                CardItem("copy", "Скопировать адрес") { onDismiss(); Sys.copy(p.mail); Toasts.show("Адрес скопирован") }
                CardItem("users", "Вся переписка") { onDismiss(); MailStore.search("переписка:" + p.mail, everywhere = true); while (Nav.stack.isNotEmpty()) Nav.pop() }
                CardItem("user", "В контакты") {
                    onDismiss()
                    scope.launchSafe {
                        val api = Session.api!!
                        if (api.contacts(q = p.mail).any { c -> c.emails.any { it.value.equals(p.mail, true) } }) Toasts.show("${p.mail} уже есть в контактах")
                        else {
                            val parts = p.name.trim().split(' ', limit = 2)
                            api.createContact(su.innotec.mail.api.ContactInput(book = "personal", first = parts.getOrElse(0) { "" }.ifBlank { p.mail.substringBefore('@') },
                                last = parts.getOrElse(1) { "" }.trim(), emails = listOf(su.innotec.mail.api.TypedValue(p.mail, "work"))))
                            Toasts.show("Добавлено в личные контакты")
                        }
                    }
                }
                CardItem("filter", "Его письма — в папку…") { rule = true }
            }
        },
        confirmButton = { TextButton(onClick = onDismiss) { Text("Закрыть") } },
    )
}

@Composable
private fun CardItem(icon: String, text: String, action: () -> Unit) {
    Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(8.dp)).clickable { action() }.padding(vertical = 12.dp, horizontal = 4.dp), verticalAlignment = Alignment.CenterVertically) {
        Ico(icon, tint = P.muted); Spacer(Modifier.width(14.dp)); Text(text)
    }
}

private fun openLink(url: String) {
    when {
        url.startsWith("mailto:", ignoreCase = true) -> Nav.push(ComposeScreen(ComposeStart.Mailto(url)))
        url.startsWith("http", ignoreCase = true) || url.startsWith("tel:", ignoreCase = true) -> Sys.openUrl(url)
    }
}

fun fileIcon(name: String, type: String = ""): String {
    val ext = name.substringAfterLast('.', "").lowercase()
    return when {
        type.startsWith("image/") || ext in setOf("jpg", "jpeg", "png", "gif", "webp", "heic", "bmp") -> "img"
        type.startsWith("video/") || ext in setOf("mp4", "mov", "avi", "mkv") -> "video"
        ext == "eml" || type == "message/rfc822" -> "mail"
        ext == "pdf" || type == "application/pdf" -> "file"
        else -> "file"
    }
}

/** Подпись типа файла на карточке вложения: расширение заглавными («PDF», «XLSX»), у картинок — «Фото». */
fun fileKindLabel(name: String, type: String = ""): String {
    val ext = name.substringAfterLast('.', "").lowercase()
    return when {
        type.startsWith("image/") || ext in setOf("jpg", "jpeg", "png", "gif", "webp", "heic", "bmp") -> "Фото"
        ext == "eml" || type == "message/rfc822" -> "Письмо"
        ext.isNotEmpty() && ext.length <= 5 -> ext.uppercase()
        else -> "Файл"
    }
}

@Composable
/** Вложение, которое можно посмотреть прямо в приложении (картинка, PDF, документ Office через PDF сервера). */
private fun viewItem(a: Attachment, folder: String, uid: Long, attachedIndex: Int?): ViewItem? {
    val api = Session.api ?: return null
    val kind = Viewable.kind(a.name, a.type) ?: return null
    if (kind == "office" && attachedIndex != null) return null   // предпросмотра вложений из .eml сервер не делает
    val path = if (attachedIndex == null) api.attachmentPath(folder, uid, a.index) else api.attachedPartPath(folder, uid, attachedIndex, a.index)
    return ViewItem(a.name, path, kind, if (kind == "office") api.attachmentPreviewPath(folder, uid, a.index) else null)
}

/**
 * Файл по ссылке, который откроется внутри приложения: картинка и PDF — по `files/{token}/content`,
 * документ Office — через `preview.pdf`, если сервер умеет его собрать. Просроченный или чужой без токена — нет.
 */
private fun cloudViewItem(c: CloudFileRef): ViewItem? {
    val api = Session.api ?: return null
    if (c.token.isBlank() || c.expired) return null
    val kind = Viewable.kind(c.name, c.type) ?: return null
    if (kind == "office" && !c.preview) return null
    return ViewItem(c.name, api.fileContentPath(c.token), kind, if (kind == "office") api.filePreviewPath(c.token) else null)
}

@Composable
private fun AttachmentCard(a: Attachment, folder: String, uid: Long, attachedIndex: Int?, views: List<ViewItem> = emptyList()) {
    val api = Session.api!!
    val path = if (attachedIndex == null) api.attachmentPath(folder, uid, a.index) else api.attachedPartPath(folder, uid, attachedIndex, a.index)
    val isEml = a.type == "message/rfc822" || a.name.endsWith(".eml", true)
    FileCard(a.name, a.size, fileIcon(a.name, a.type), fileKindLabel(a.name, a.type),
        onOpen = {
            val at = views.indexOfFirst { it.path == path }
            when {
                isEml && attachedIndex == null -> Nav.push(AttachedMessageScreen(folder, uid, a.index))
                at >= 0 -> Nav.push(su.innotec.mail.ui.ViewerScreen(views, at))
                else -> Transfers.fetch(path, a.name, Transfers.Then.OPEN)
            }
        },
        onSave = { Transfers.fetch(path, a.name, Transfers.Then.SAVE) },
        onShare = { Transfers.fetch(path, a.name, Transfers.Then.SHARE) },
    )
}

/** Файл по ссылке: просмотр внутри приложения, сохранение, ссылка «поделиться»; свой — «Продлить». */
@Composable
private fun CloudFileCard(c: CloudFileRef, views: List<ViewItem>, onChanged: (CloudFileRef) -> Unit) {
    val api = Session.api!!
    val scope = rememberCoroutineScope()
    val name = c.name.ifBlank { c.path.substringAfterLast('/') }
    val content = c.token.takeIf { it.isNotBlank() }?.let { api.fileContentPath(it) }
    fun renew() {
        scope.launchSafe {
            val r = api.fileRenew(c.token)
            // Сервер отвечает обновлённой карточкой (CloudFile::toCard) — подставляем её в письмо.
            val fresh = runCatching { ApiJson.decodeFromJsonElement(CloudFileRef.serializer(), r) }.getOrNull()
            if (fresh != null) onChanged(fresh.copy(path = c.path))
            val until = fresh?.expires?.let { Fmt.dateOnly(it) }?.let { " до " + Fmt.dateShort(it) } ?: ""
            Toasts.show("Срок хранения продлён$until")
        }
    }
    FileCard(name, c.size, if (c.expired) "warn" else "cloud", when {
        c.expired -> "Срок истёк"
        c.expires != null -> "До " + (Fmt.dateOnly(c.expires)?.let { Fmt.dateShort(it) } ?: c.expires)
        else -> "По ссылке"
    },
        onOpen = {
            val at = views.indexOfFirst { content != null && it.path == content }
            when {
                c.expired -> Toasts.show("Срок хранения файла истёк — попросите прислать заново")
                at >= 0 -> Nav.push(su.innotec.mail.ui.ViewerScreen(views, at))
                content != null -> Transfers.fetch(content, name, Transfers.Then.OPEN)
                c.url.isNotBlank() -> Sys.openUrl(c.url)
            }
        },
        onSave = { if (content != null && !c.expired) Transfers.fetch(content, name, Transfers.Then.SAVE) else if (c.url.isNotBlank()) Sys.openUrl(c.url) },
        onShare = { if (c.url.isNotBlank()) Sys.shareText(c.url) },
        extra = if (c.mine && c.token.isNotBlank() && !c.cloud) ("Продлить" to { renew() }) else null,
        danger = c.expired,
    )
}

/**
 * Карточка файла 150 dp: значок по типу, имя, «размер · Открыть». Касание — открыть, долгое — меню
 * (открыть, сохранить, поделиться, [extra] — своё действие, например «Продлить»).
 */
@OptIn(ExperimentalFoundationApi::class)
@Composable
fun FileCard(name: String, size: Long?, icon: String, kind: String, onOpen: () -> Unit, onSave: () -> Unit, onShare: () -> Unit, extra: Pair<String, () -> Unit>? = null, danger: Boolean = false) {
    var menu by remember { mutableStateOf(false) }
    Box {
        Column(
            Modifier.width(150.dp).clip(RoundedCornerShape(12.dp)).background(P.surface2).border(1.dp, P.border, RoundedCornerShape(12.dp))
                .combinedClickable(onClick = onOpen, onLongClick = { menu = true }),
        ) {
            Box(Modifier.fillMaxWidth().height(64.dp).background(if (danger) P.noSoft else P.accentSoft), contentAlignment = Alignment.Center) {
                Ico(icon, size = 28.dp, tint = if (danger) P.no else P.accentInk)
                Text(kind, Modifier.align(Alignment.BottomEnd).padding(end = 8.dp, bottom = 4.dp), style = MaterialTheme.typography.labelSmall, color = if (danger) P.no else P.accentInk)
            }
            Column(Modifier.padding(horizontal = 10.dp, vertical = 8.dp)) {
                Text(name, style = MaterialTheme.typography.bodyMedium, maxLines = 1, overflow = TextOverflow.Ellipsis)
                Row(verticalAlignment = Alignment.CenterVertically) {
                    if (size != null && size > 0) Text(Fmt.size(size) + " · ", style = MaterialTheme.typography.bodySmall, color = P.faint, maxLines = 1)
                    Text("Открыть", style = MaterialTheme.typography.bodySmall, color = P.link, maxLines = 1)
                    if (extra != null) {
                        Spacer(Modifier.weight(1f))
                        Text(extra.first, Modifier.clip(RoundedCornerShape(4.dp)).clickable { extra.second() }.padding(horizontal = 2.dp), style = MaterialTheme.typography.bodySmall, color = P.accentInk, maxLines = 1)
                    }
                }
            }
        }
        DropdownMenu(menu, { menu = false }) {
            DropdownMenuItem({ Text("Открыть") }, { menu = false; onOpen() }, leadingIcon = { Ico("eye") })
            DropdownMenuItem({ Text("Сохранить в «Загрузки»") }, { menu = false; onSave() }, leadingIcon = { Ico("download") })
            DropdownMenuItem({ Text("Поделиться") }, { menu = false; onShare() }, leadingIcon = { Ico("share") })
            if (extra != null) DropdownMenuItem({ Text(extra.first) }, { menu = false; extra.second() }, leadingIcon = { Ico("refresh") })
        }
    }
}

/** Имя для «Быстрый ответ Марии…»: первое слово имени в дательном падеже там, где это простое женское/мужское имя. */
fun quickReplyHint(p: Person): String {
    val first = p.name.trim().split(Regex("\\s+")).firstOrNull { it.isNotBlank() && !it.contains('@') } ?: return "Быстрый ответ…"
    val dat = when {
        first.endsWith("ия") -> first.dropLast(1) + "и"
        first.endsWith("а") -> first.dropLast(1) + "е"
        first.endsWith("я") -> first.dropLast(1) + "е"
        first.endsWith("й") -> first.dropLast(1) + "ю"
        first.endsWith("ь") -> first.dropLast(1) + "ю"
        first.last().isLetter() && first.last().lowercaseChar() in "бвгджзклмнпрстфхцчшщ" -> first + "у"
        else -> return "Быстрый ответ: $first…"
    }
    return "Быстрый ответ $dat…"
}

/** Одна строка быстрого ответа с круглой кнопкой (кнопки «Ответить/Всем/Переслать» — в карточке письма). */
@Composable
private fun ReplyBar(m: Message, folder: String, quick: String, onQuick: (String) -> Unit) {
    var sending by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    val role = MailStore.folders.firstOrNull { it.path == folder }?.role
    val target = m.replyTo.firstOrNull() ?: m.from
    // Автоматический адрес: ответ никто не прочитает, а быстрый ответ выглядит как разговор (как в веб-почте).
    val noReply = NOREPLY.matches(target.mail.substringBefore('@'))
    if (role == "spam") return

    /** Быстрый ответ — тот же ответ, что и полный (тема, подпись, цитата, «от имени» общего ящика), с отменой отправки. */
    fun sendQuick() {
        val text = quick.trim()
        if (text.isEmpty() || sending) return
        sending = true
        scope.launch {
            val cm = ComposeModel(ComposeStart.Reply(folder, m.uid, all = false, text = text))
            cm.load()
            sending = false
            if (cm.loadError != null) { Toasts.show(cm.loadError!!); return@launch }
            onQuick("")
            sendWithUndo(cm)
        }
    }

    Divider()
    Column(Modifier.fillMaxWidth().background(P.surface).imePadding()) {
        if (noReply) {
            Row(Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 10.dp), verticalAlignment = Alignment.CenterVertically) {
                Ico("warn", size = 16.dp, tint = P.warn); Spacer(Modifier.width(8.dp))
                Text("Письмо с автоматического адреса ${target.mail} — ответ, скорее всего, никто не прочитает.", style = MaterialTheme.typography.bodySmall, color = P.muted)
            }
            return@Column
        }
        val phrases = MailStore.settings.quickReplies.filter { it.isNotBlank() }
        if (phrases.isNotEmpty()) {
            Row(Modifier.fillMaxWidth().horizontalScroll(rememberScrollState()).padding(start = 12.dp, end = 12.dp, top = 8.dp), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                phrases.forEach { q ->
                    Text(q, Modifier.clip(RoundedCornerShape(50)).border(1.dp, P.border2, RoundedCornerShape(50))
                        .clickable { onQuick(q) }.padding(horizontal = 12.dp, vertical = 6.dp),
                        style = MaterialTheme.typography.bodySmall, maxLines = 1)
                }
            }
        }
        Row(Modifier.fillMaxWidth().padding(start = 12.dp, end = 10.dp, top = 8.dp, bottom = 8.dp), verticalAlignment = Alignment.CenterVertically) {
            Box(Modifier.weight(1f).clip(RoundedCornerShape(22.dp)).background(P.surface2).border(1.dp, P.border, RoundedCornerShape(22.dp)).padding(horizontal = 14.dp, vertical = 10.dp)) {
                if (quick.isEmpty()) Text(quickReplyHint(target), color = P.faint, maxLines = 1)
                androidx.compose.foundation.text.BasicTextField(
                    quick, onQuick, Modifier.fillMaxWidth().heightIn(max = 120.dp).testTag("quick-reply"),
                    textStyle = MaterialTheme.typography.bodyLarge.copy(color = P.text),
                    cursorBrush = androidx.compose.ui.graphics.SolidColor(P.accent),
                    keyboardOptions = androidx.compose.foundation.text.KeyboardOptions(capitalization = androidx.compose.ui.text.input.KeyboardCapitalization.Sentences),
                )
            }
            Spacer(Modifier.width(8.dp))
            val ready = quick.isNotBlank() && !sending
            Box(
                Modifier.size(42.dp).clip(CircleShape).background(if (ready) P.accent else P.chipOff).clickable(enabled = ready) { sendQuick() }.testTag("quick-send"),
                contentAlignment = Alignment.Center,
            ) {
                if (sending) androidx.compose.material3.CircularProgressIndicator(Modifier.size(20.dp), strokeWidth = 2.dp, color = P.accentInk)
                else Ico("send", size = 20.dp, tint = if (ready) P.accentOn else P.faint, contentDescription = "Отправить ответ")
            }
        }
    }
}

/** «Напомнить, если не ответят»: письмо вернётся во «Входящие», если за это время ответа не будет (как в веб-почте). */
@Composable
fun RemindDialog(onDismiss: () -> Unit, onPick: (String) -> Unit) {
    su.innotec.mail.ui.ChoiceDialog("Напомнить, если не ответят", listOf(1, 2, 3, 5, 7), { "через $it ${Fmt.plural(it, "день", "дня", "дней")}" }, null, onDismiss = onDismiss) { d ->
        onPick((kotlin.time.Clock.System.now() + kotlin.time.Duration.parse("${d}d")).toString())
    }
}

/** Письмо для печати: шапка (тема, от кого, кому, когда, вложения) и текст — как PrintPreview веб-почты. */
fun printDocument(m: Message): String {
    fun esc(s: String) = su.innotec.mail.ui.Html.escape(s)
    fun who(p: su.innotec.mail.api.Person) = esc(if (p.name.isBlank() || p.name == p.mail) p.mail else "${p.name} <${p.mail}>")
    val rows = buildList {
        add("От" to who(m.from))
        if (m.to.isNotEmpty()) add("Кому" to m.to.joinToString(", ") { who(it) })
        if (m.cc.isNotEmpty()) add("Копия" to m.cc.joinToString(", ") { who(it) })
        add("Дата" to esc(Fmt.full(m.date)))
        val files = m.attachments.filter { !it.inline }
        if (files.isNotEmpty()) add("Вложения" to files.joinToString(", ") { esc(it.name) + " (" + Fmt.size(it.size) + ")" })
    }.joinToString("") { (k, v) -> "<tr><td style=\"color:#6B7280;padding:2px 12px 2px 0;vertical-align:top;white-space:nowrap\">$k</td><td style=\"padding:2px 0\">$v</td></tr>" }
    val body = m.html?.let { su.innotec.mail.platform.unblockImages(it) } ?: su.innotec.mail.platform.textToHtml(m.text ?: "")
    return su.innotec.mail.platform.wrapHtml(
        "<h2 style=\"font-size:20px;margin:0 0 10px\">${esc(m.subject)}</h2><table style=\"font-size:13px;border-collapse:collapse;margin-bottom:14px\">$rows</table><hr style=\"border:0;border-top:1px solid #CFCBC4;margin:0 0 14px\">$body",
        dark = false,
    )
}

private val NOREPLY = Regex("^(no[-_.]?reply|do[-_.]?not[-_.]?reply|mailer[-_.]?daemon|bounce[sd]?|postmaster|nobody)$", RegexOption.IGNORE_CASE)

/** Чип действия под письмом: «Ответить» — акцентный, остальные — на подложке. */
@Composable
private fun ReplyButton(icon: String, text: String, modifier: Modifier = Modifier, accent: Boolean = false, onClick: () -> Unit) {
    Row(
        modifier.height(38.dp).clip(RoundedCornerShape(50)).background(if (accent) P.accent else P.chipOff).clickable(onClick = onClick).padding(horizontal = 14.dp),
        horizontalArrangement = Arrangement.Center, verticalAlignment = Alignment.CenterVertically,
    ) {
        Ico(icon, size = 18.dp, tint = if (accent) P.accentOn else P.text); Spacer(Modifier.width(6.dp))
        Text(text, style = MaterialTheme.typography.labelLarge, color = if (accent) P.accentOn else P.text)
    }
}

// ---------- диалоги ----------

/** Палитра новой метки — 8 цветов гаммы (как выбор цвета в «Ещё → Метки»). */
val LABEL_PALETTE = listOf("#E85D04", "#1F7A4D", "#1D5FD1", "#6B3FA0", "#0F766E", "#9A6700", "#C62828", "#0E8A9E")

@Composable
fun LabelDialog(uids: List<Long>, onDismiss: () -> Unit, folder: String? = null, current: List<Long> = emptyList(), onChanged: (List<Long>) -> Unit = {}) {
    var have by remember { mutableStateOf(current.ifEmpty { MailStore.messages.filter { it.uid in uids }.flatMap { it.labels }.distinct() }) }
    var creating by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text("Метки") },
        text = {
            Column {
                if (MailStore.labels.isEmpty()) Text("Меток пока нет.", color = P.muted)
                MailStore.labels.forEach { l ->
                    val on = l.id in have
                    Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(8.dp)).clickable {
                        MailStore.act(if (on) "unlabel" else "label", uids, label = l.id, folder = folder)
                        have = if (on) have - l.id else have + l.id
                        onChanged(have)
                    }.padding(vertical = 10.dp, horizontal = 4.dp), verticalAlignment = Alignment.CenterVertically) {
                        Box(Modifier.size(12.dp).clip(CircleShape).background(hexColor(l.color)))
                        Spacer(Modifier.width(14.dp))
                        Text(l.name, Modifier.weight(1f))
                        if (on) Ico("check", tint = P.accent)
                    }
                }
                TextButton(onClick = { creating = true }) { Ico("plus", size = 16.dp); Spacer(Modifier.width(6.dp)); Text("Новая метка") }
            }
        },
        confirmButton = { TextButton(onClick = onDismiss) { Text("Готово") } },
    )
    if (creating) NewLabelDialog(onDismiss = { creating = false }) { name, color ->
        scope.launchSafe {
            Session.api!!.createLabel(name, color)
            MailStore.reloadLabels()
        }
    }
}

/** Новая метка из письма: название и цвет из палитры — как в «Ещё → Метки». */
@OptIn(ExperimentalLayoutApi::class)
@Composable
fun NewLabelDialog(onDismiss: () -> Unit, onCreate: (String, String) -> Unit) {
    var name by remember { mutableStateOf("") }
    var color by remember { mutableStateOf(LABEL_PALETTE.random()) }
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text("Новая метка") },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
                OutlinedTextField(name, { name = it }, Modifier.fillMaxWidth(), label = { Text("Название") }, singleLine = true)
                FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    LABEL_PALETTE.forEach { h ->
                        Box(Modifier.size(32.dp).clip(CircleShape).background(hexColor(h)).clickable { color = h }, contentAlignment = Alignment.Center) {
                            if (h.equals(color, true)) Ico("check", size = 16.dp, tint = Color.White)
                        }
                    }
                }
            }
        },
        confirmButton = { TextButton(enabled = name.isNotBlank(), onClick = { onDismiss(); onCreate(name.trim(), color) }) { Text("Создать") } },
        dismissButton = { TextButton(onClick = onDismiss) { Text("Отмена") } },
    )
}

/** Варианты «Отложить» — как в веб-почте: вечер, завтра, выходные, понедельник, своё время. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun SnoozeDialog(onDismiss: () -> Unit, title: String = "Отложить до…", weekend: Boolean = true, onPick: (String) -> Unit) {
    val tz = TimeZone.currentSystemDefault()
    val now = Clock.System.now().toLocalDateTime(tz)
    fun at(d: kotlinx.datetime.LocalDate, h: Int) = d.atTime(LocalTime(h, 0))
    val today = now.date
    val options = buildList {
        if (now.hour < 17) add("Сегодня вечером" to at(today, 18))
        add("Завтра утром" to at(today.plus(DatePeriod(days = 1)), 9))
        val sat = (1..7).map { today.plus(DatePeriod(days = it)) }.first { it.dayOfWeek == DayOfWeek.SATURDAY }
        if (weekend) add("В выходные" to at(sat, 9))
        val mon = (1..7).map { today.plus(DatePeriod(days = it)) }.first { it.dayOfWeek == DayOfWeek.MONDAY }
        add("В понедельник" to at(mon, 9))
        add("Через неделю" to at(today.plus(DatePeriod(days = 7)), 9))
    }
    var custom by remember { mutableStateOf(false) }
    if (!custom) AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(title) },
        text = {
            Column {
                options.forEach { (t, dt) ->
                    Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(8.dp)).clickable { onDismiss(); onPick(dt.toInstant(tz).toString()) }.padding(vertical = 12.dp, horizontal = 4.dp)) {
                        Text(t, Modifier.weight(1f))
                        Text("${Fmt.weekdaysShort[dt.date.dayOfWeek.ordinal]}, ${Fmt.dateShort(dt.date)}, ${Fmt.time(dt)}", color = P.muted, style = MaterialTheme.typography.bodySmall)
                    }
                }
                Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(8.dp)).clickable { custom = true }.padding(vertical = 12.dp, horizontal = 4.dp)) {
                    Ico("cal", size = 18.dp, tint = P.muted); Spacer(Modifier.width(10.dp)); Text("Выбрать дату и время…")
                }
            }
        },
        confirmButton = {},
        dismissButton = { TextButton(onClick = onDismiss) { Text("Отмена") } },
    ) else DateTimeDialog(title.removeSuffix("…"), onDismiss) { dt -> onPick(dt.toInstant(tz).toString()) }
}

/** Выбор даты, потом времени. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun DateTimeDialog(title: String, onDismiss: () -> Unit, initial: LocalDateTime? = null, onPick: (LocalDateTime) -> Unit) {
    var date by remember { mutableStateOf<kotlinx.datetime.LocalDate?>(null) }
    val tz = TimeZone.currentSystemDefault()
    if (date == null) {
        val st = rememberDatePickerState(initialSelectedDateMillis = (initial?.date ?: Clock.System.now().toLocalDateTime(tz).date).atTime(LocalTime(12, 0)).toInstant(TimeZone.UTC).toEpochMilliseconds())
        DatePickerDialog(
            onDismissRequest = onDismiss,
            confirmButton = { TextButton(onClick = { st.selectedDateMillis?.let { date = Instant.fromEpochMilliseconds(it).toLocalDateTime(TimeZone.UTC).date } }) { Text("Далее") } },
            dismissButton = { TextButton(onClick = onDismiss) { Text("Отмена") } },
        ) { DatePicker(st, title = { Text(title, Modifier.padding(start = 24.dp, top = 16.dp)) }) }
    } else {
        val tp = rememberTimePickerState(initialHour = initial?.hour ?: 9, initialMinute = initial?.minute ?: 0, is24Hour = true)
        AlertDialog(
            onDismissRequest = onDismiss,
            title = { Text(Fmt.dateShort(date!!)) },
            text = { TimePicker(tp) },
            confirmButton = { TextButton(onClick = { onDismiss(); onPick(date!!.atTime(LocalTime(tp.hour, tp.minute))) }) { Text("Готово") } },
            dismissButton = { TextButton(onClick = { date = null }) { Text("Назад") } },
        )
    }
}

/** «Письма от отправителя — в папку»: правило через /sender/mark (как «Это рассылка» и спам). */
@Composable
private fun RuleFromSenderDialog(sender: String, onDismiss: () -> Unit) {
    val scope = rememberCoroutineScope()
    FolderPicker("Письма от $sender — в папку", null, onDismiss = onDismiss) { f ->
        scope.launchSafe {
            Session.api!!.markSender("folder", "address", sender, true, f.path)
            Toasts.show("Письма от $sender будут попадать в «${f.name}»")
            MailStore.load(); MailStore.reloadRules()
        }
    }
}
