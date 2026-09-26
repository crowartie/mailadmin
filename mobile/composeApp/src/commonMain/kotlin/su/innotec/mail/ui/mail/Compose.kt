package su.innotec.mail.ui.mail

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
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
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.BasicTextField
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Checkbox
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.MaterialTheme
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
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardCapitalization
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.datetime.TimeZone
import kotlinx.datetime.toInstant
import su.innotec.mail.Nav
import su.innotec.mail.Screen
import su.innotec.mail.api.ApiException
import su.innotec.mail.api.AttachedMessageRef
import su.innotec.mail.api.Attachment
import su.innotec.mail.api.CloudFileRef
import su.innotec.mail.api.ComposeForm
import su.innotec.mail.api.ComposeMeta
import su.innotec.mail.api.Identity
import su.innotec.mail.api.LocalFile
import su.innotec.mail.api.Message
import su.innotec.mail.api.Person
import su.innotec.mail.api.Suggestion
import su.innotec.mail.data.Session
import su.innotec.mail.platform.BackHandler
import su.innotec.mail.platform.HtmlView
import su.innotec.mail.platform.rememberFilePicker
import su.innotec.mail.ui.Divider
import su.innotec.mail.ui.Fmt
import su.innotec.mail.ui.Html
import su.innotec.mail.ui.Ico
import su.innotec.mail.ui.Loading
import su.innotec.mail.ui.P
import su.innotec.mail.ui.Toasts
import su.innotec.mail.ui.Transfers

/** С чего начинается письмо. */
sealed class ComposeStart {
    data class New(val to: String = "", val subject: String = "", val body: String = "", val cloudFiles: List<CloudFileRef> = emptyList()) : ComposeStart()
    data class Reply(val folder: String, val uid: Long, val all: Boolean, val text: String = "") : ComposeStart()
    data class Forward(val folder: String, val uid: Long) : ComposeStart()
    data class ForwardAsAttachment(val items: List<Pair<String, Long>>) : ComposeStart()
    data class Again(val folder: String, val uid: Long) : ComposeStart()
    data class Draft(val uid: Long) : ComposeStart()
    data class Mailto(val url: String) : ComposeStart()
}

/** Состояние окна «Написать»; переживает отмену отправки (окно открывается заново с тем же). */
class ComposeModel(val start: ComposeStart) {
    val to = mutableStateListOf<Person>()
    val cc = mutableStateListOf<Person>()
    val bcc = mutableStateListOf<Person>()
    var showCc by mutableStateOf(false)
    var subject by mutableStateOf("")
    var body by mutableStateOf("")
    /** Подпись, цитата или шапка пересылки — HTML, который дописывается к тексту. */
    var tail by mutableStateOf("")
    var includeTail by mutableStateOf(true)
    var from by mutableStateOf<String?>(null)
    val files = mutableStateListOf<LocalFile>()
    val viaCloud = mutableStateListOf<Int>()
    val existing = mutableStateListOf<Attachment>()
    val keep = mutableStateListOf<Int>()
    val cloudFiles = mutableStateListOf<CloudFileRef>()
    val attachMessages = mutableStateListOf<AttachedMessageRef>()
    var priority by mutableStateOf(false)
    var receipt by mutableStateOf(false)
    var sendAt by mutableStateOf<String?>(null)
    var remindDays by mutableStateOf<Int?>(null)
    var inReplyTo: String? = null
    var references: String? = null
    var answeredFolder: String? = null
    var answeredUid: Long? = null
    var sourceFolder: String? = null
    var sourceUid: Long? = null
    var draftUid by mutableStateOf<Long?>(null)
    var loaded by mutableStateOf(false)
    var loadError by mutableStateOf<String?>(null)
    var dirty by mutableStateOf(false)
    var saving by mutableStateOf(false)
    var meta by mutableStateOf(ComposeMeta())

    val hasContent get() = to.isNotEmpty() || cc.isNotEmpty() || subject.isNotBlank() || body.isNotBlank() || files.isNotEmpty()

    fun identity(): Identity? = meta.identities.firstOrNull { it.mail.equals(from ?: Session.account?.user ?: "", true) }

    private fun signatureHtml(forReply: Boolean): String {
        val id = meta.identities.firstOrNull { it.shared && it.mail.equals(from ?: "", true) }
        val s = id?.signature ?: MailStore.settings.signature
        if (s.isBlank() || (forReply && !MailStore.settings.signatureReply)) return ""
        return "<p><br></p><div class=\"sig\">$s</div>"
    }

    private fun quote(m: Message): String {
        val inner = m.html ?: "<pre style=\"white-space:pre-wrap;font:inherit\">${Html.escape(m.text ?: "")}</pre>"
        return "<p><br></p><div class=\"quote\"><div style=\"color:#6B7787\">${Html.escape(Fmt.full(m.date))}, ${Html.escape(m.from.name)} &lt;${Html.escape(m.from.mail)}&gt; писал(а):</div><blockquote>$inner</blockquote></div>"
    }

    private fun answerSubject(prefix: String, s: String): String {
        val re = if (prefix == "Re") Regex("^re:", RegexOption.IGNORE_CASE) else Regex("^fwd?:", RegexOption.IGNORE_CASE)
        if (re.containsMatchIn(s)) return s
        return "$prefix: ${if (s == "(без темы)") "" else s}".trim()
    }

    private fun isMe(p: Person): Boolean {
        val m = p.mail.lowercase()
        return m == Session.account?.user?.lowercase() || meta.identities.any { it.mail.lowercase() == m }
    }

    /** Письмо из общей папки — от имени её владельца, если можно писать за него. */
    private fun sharedFrom(folder: String): String? {
        val owner = MailStore.folders.firstOrNull { it.path == folder }?.owner ?: return null
        return if (meta.identities.any { it.shared && it.mail == owner }) owner else null
    }

    suspend fun load() {
        val api = Session.api ?: return
        try {
            meta = runCatching { api.composeMeta() }.getOrDefault(ComposeMeta())
            when (val s = start) {
                is ComposeStart.New -> {
                    parseList(s.to).forEach { to.add(it) }
                    subject = s.subject; body = s.body
                    cloudFiles.addAll(s.cloudFiles)
                    tail = signatureHtml(false)
                }
                is ComposeStart.Mailto -> {
                    val (addr, q) = s.url.removePrefix("mailto:").removePrefix("MAILTO:").let { it.substringBefore('?') to it.substringAfter('?', "") }
                    parseList(su.innotec.mail.api.Api.percentDecode(addr)).forEach { to.add(it) }
                    q.split('&').forEach { kv ->
                        val k = kv.substringBefore('=').lowercase(); val v = su.innotec.mail.api.Api.percentDecode(kv.substringAfter('=', "").replace('+', ' '))
                        when (k) { "subject" -> subject = v; "body" -> body = v; "cc" -> { parseList(v).forEach { cc.add(it) }; showCc = true } }
                    }
                    tail = signatureHtml(false)
                }
                is ComposeStart.Reply -> {
                    val m = api.message(s.folder, s.uid, peek = true)
                    from = sharedFrom(s.folder)
                    val role = MailStore.folders.firstOrNull { it.path == s.folder }?.role
                    val targets = if (role == "sent") m.to else m.replyTo.ifEmpty { listOf(m.from) }
                    targets.filter { !isMe(it) || targets.size == 1 }.forEach { to.add(it) }
                    if (s.all) {
                        val seen = to.map { it.mail.lowercase() }.toMutableSet()
                        (m.to + m.cc).forEach { a -> if (!isMe(a) && seen.add(a.mail.lowercase())) cc.add(a) }
                        showCc = cc.isNotEmpty()
                    }
                    subject = answerSubject("Re", m.subject)
                    body = s.text
                    tail = signatureHtml(true) + quote(m)
                    inReplyTo = m.messageId
                    references = listOf(m.references, m.messageId).filter { it.isNotBlank() }.joinToString(" ")
                    answeredFolder = s.folder; answeredUid = m.uid
                    sourceFolder = s.folder; sourceUid = m.uid
                    existing.addAll(m.attachments.filter { !it.inline })
                }
                is ComposeStart.Forward -> {
                    val m = api.message(s.folder, s.uid, peek = true)
                    from = sharedFrom(s.folder)
                    subject = answerSubject("Fwd", m.subject)
                    val hdr = "<div class=\"fwd\" style=\"color:#6B7787\">---------- Пересланное письмо ----------<br>От: ${Html.escape(m.from.name)} &lt;${Html.escape(m.from.mail)}&gt;<br>Дата: ${Html.escape(Fmt.full(m.date))}<br>Тема: ${Html.escape(m.subject)}<br>Кому: ${Html.escape(m.to.joinToString(", ") { it.mail })}</div><br>"
                    tail = signatureHtml(true) + "<p><br></p>" + hdr + (m.html ?: "<pre style=\"white-space:pre-wrap;font:inherit\">${Html.escape(m.text ?: "")}</pre>")
                    references = listOf(m.references, m.messageId).filter { it.isNotBlank() }.joinToString(" ")
                    sourceFolder = s.folder; sourceUid = m.uid
                    existing.addAll(m.attachments)
                    keep.addAll(m.attachments.map { it.index })
                    cloudFiles.addAll(m.cloudFiles)
                }
                is ComposeStart.ForwardAsAttachment -> {
                    val subjects = s.items.map { (f, u) -> MailStore.messages.firstOrNull { it.uid == u }?.subject ?: runCatching { api.message(f, u, peek = true).subject }.getOrDefault("письмо") }
                    subject = if (s.items.size == 1) answerSubject("Fwd", subjects[0]) else "Fwd: ${s.items.size} ${Fmt.plural(s.items.size, "письмо", "письма", "писем")}"
                    tail = signatureHtml(true)
                    s.items.forEachIndexed { i, (f, u) -> attachMessages.add(AttachedMessageRef(f, u, subjects[i].ifBlank { "письмо" })) }
                }
                is ComposeStart.Again -> {
                    val m = api.message(s.folder, s.uid, peek = true)
                    to.addAll(m.to); cc.addAll(m.cc); showCc = cc.isNotEmpty()
                    subject = if (m.subject == "(без темы)") "" else m.subject
                    val (mine, t) = Html.splitTail(m.html ?: Html.fromText(m.text ?: ""))
                    body = Html.toText(mine); tail = t
                    sourceFolder = s.folder; sourceUid = m.uid
                    existing.addAll(m.attachments); keep.addAll(m.attachments.map { it.index })
                    cloudFiles.addAll(m.cloudFiles)
                }
                is ComposeStart.Draft -> {
                    val d = api.openDraft(s.uid)
                    draftUid = d.draftUid.takeIf { it > 0 } ?: s.uid
                    from = d.from.ifBlank { null }
                    parseList(d.to).forEach { to.add(it) }
                    parseList(d.cc).forEach { cc.add(it) }
                    parseList(d.bcc).forEach { bcc.add(it) }
                    showCc = cc.isNotEmpty() || bcc.isNotEmpty()
                    subject = d.subject
                    val (mine, t) = Html.splitTail(d.html)
                    body = Html.toText(mine); tail = t
                    priority = d.priority; receipt = d.receipt
                    inReplyTo = d.inReplyTo.ifBlank { null }; references = d.references.ifBlank { null }
                    existing.addAll(d.attachments); keep.addAll(d.attachments.map { it.index })
                    cloudFiles.addAll(d.cloudFiles)
                    sourceFolder = MailStore.folders.firstOrNull { it.role == "drafts" }?.path; sourceUid = draftUid
                }
            }
            loaded = true
        } catch (e: ApiException) {
            loadError = e.message
        }
    }

    fun parseList(s: String): List<Person> =
        s.split(Regex(",(?![^<]*>)")).map { it.trim() }.filter { it.isNotEmpty() }.map { p ->
            Regex("^\"?([^\"<]*)\"?\\s*<([^>]+)>$").find(p)?.let { Person(it.groupValues[1].trim(), it.groupValues[2].trim()) } ?: Person("", p)
        }

    private fun addr(list: List<Person>) = list.joinToString(", ") { p ->
        if (p.name.isBlank() || p.name == p.mail) p.mail else "\"${p.name.replace("\"", "")}\" <${p.mail}>"
    }

    fun html(): String = Html.fromText(body) + if (includeTail) tail else ""

    fun form(forDraft: Boolean): ComposeForm = ComposeForm(
        from = from, to = addr(to), cc = addr(cc), bcc = addr(bcc), subject = subject, html = html(),
        inReplyTo = inReplyTo, references = references, answeredFolder = answeredFolder, answeredUid = answeredUid,
        sourceFolder = sourceFolder, sourceUid = sourceUid, draftUid = draftUid,
        sendAt = if (forDraft) null else sendAt, remindDays = if (forDraft) null else remindDays,
        keepAttachments = existing.isNotEmpty() && keep.isNotEmpty(), keepIndexes = keep.toList(),
        priority = priority, receipt = receipt, draftKeepFiles = forDraft,
        files = files.toList(), cloud = viaCloud.toList(), cloudFiles = cloudFiles.toList(), attachMessages = attachMessages.toList(),
    )

    /** Сохранить черновик. Свои файлы после первого сохранения живут уже в черновике. */
    suspend fun saveDraft(quiet: Boolean = true): Boolean {
        val api = Session.api ?: return false
        if (saving) return false
        saving = true
        try {
            val r = api.saveDraft(form(forDraft = true))
            val uid = r.draftUid ?: return false
            draftUid = uid
            sourceFolder = r.folder ?: MailStore.folders.firstOrNull { it.role == "drafts" }?.path
            sourceUid = uid
            if (files.isNotEmpty() || existing.isNotEmpty()) {
                val d = api.openDraft(uid)
                existing.clear(); existing.addAll(d.attachments)
                keep.clear(); keep.addAll(d.attachments.map { it.index })
                files.clear(); viaCloud.clear()
            }
            dirty = false
            if (!quiet) Toasts.show("Черновик сохранён")
            return true
        } catch (e: ApiException) {
            if (!quiet) Toasts.error(e)
            return false
        } finally {
            saving = false
        }
    }

    fun problems(): String? {
        if (to.isEmpty() && cc.isEmpty() && bcc.isEmpty()) return "Укажите, кому отправить письмо"
        (to + cc + bcc).firstOrNull { !Regex("^[^@\\s]+@[^@\\s]+\\.[^@\\s]+$").matches(it.mail) }?.let { return "Адрес «${it.mail}» написан с ошибкой" }
        val maxFiles = meta.limits.maxFiles
        if (files.size + keep.size > maxFiles) return "Не больше $maxFiles файлов в одном письме"
        val limit = meta.limits.messageMb.toLong() * 1024 * 1024
        val inMail = files.withIndex().filter { it.index !in viaCloud }.sumOf { it.value.size } + existing.filter { it.index in keep }.sumOf { it.size }
        if (inMail > limit) return "Файлы тяжелее предела почты (${meta.limits.messageMb} МБ)" + if (meta.cloud.enabled) " — отметьте крупные «ссылкой»" else ""
        return null
    }
}

class ComposeScreen(start: ComposeStart, private val existingModel: ComposeModel? = null) : Screen() {
    private val model = existingModel ?: ComposeModel(start)
    override val fullScreen: Boolean get() = true

    @Composable
    override fun Content() = ComposeView(model)
}

private val sendScope = CoroutineScope(SupervisorJob() + Dispatchers.Main)

/** Отправить с окном «Отменить» (настройка undo_seconds); при отмене и ошибке окно открывается снова. */
fun sendWithUndo(m: ComposeModel) {
    val seconds = MailStore.settings.undoSeconds
    fun doSend() {
        sendScope.launch {
            try {
                val r = Session.api!!.send(m.form(forDraft = false))
                if (r.scheduled != null) Toasts.show("Письмо уйдёт ${Fmt.full(r.sendAt)}") else Toasts.show("Письмо отправлено")
                m.answeredUid?.let { u -> MailStore.updateLocal(listOf(u)) { it.copy(answered = true) } }
                MailStore.refreshFolders()
                MailStore.bump()
                if (MailStore.currentFolder?.role in setOf("sent", "drafts")) MailStore.load()
            } catch (e: ApiException) {
                Toasts.error(e)
                Nav.push(ComposeScreen(m.start, m))
            }
        }
    }
    if (seconds <= 0 || m.sendAt != null) { doSend(); return }
    Toasts.action("Отправка через $seconds с", "Отменить", seconds, onTimeout = { doSend() }) {
        Nav.push(ComposeScreen(m.start, m))
    }
}

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun ComposeView(m: ComposeModel) {
    val scope = rememberCoroutineScope()
    var menu by remember { mutableStateOf(false) }
    var dialog by remember { mutableStateOf<String?>(null) }
    var autosave by remember { mutableStateOf<Job?>(null) }
    val pickFiles = rememberFilePicker(multiple = true) { list ->
        val threshold = m.meta.cloud.thresholdMb.toLong() * 1024 * 1024
        list.forEach { f ->
            m.files.add(f)
            if (m.meta.cloud.enabled && f.size >= threshold) m.viaCloud.add(m.files.lastIndex)
        }
        m.dirty = true
    }
    LaunchedEffect(Unit) { if (!m.loaded) m.load() }
    // Автосохранение: через 15 секунд после правки.
    LaunchedEffect(m.dirty, m.body, m.subject, m.to.size) {
        if (m.dirty && m.loaded && m.hasContent) {
            autosave?.cancel()
            autosave = scope.launch { delay(15_000); m.saveDraft() }
        }
    }

    fun close() {
        autosave?.cancel()
        if (m.dirty && m.hasContent) {
            sendScope.launch { if (m.saveDraft()) Toasts.show("Сохранено в черновиках"); MailStore.refreshFolders() }
        }
        Nav.pop()
    }
    BackHandler(true) { close() }

    Column(Modifier.fillMaxSize().background(P.surface).imePadding()) {
        // Верхняя панель
        Row(Modifier.fillMaxWidth().statusBarsPadding().height(56.dp).padding(horizontal = 4.dp), verticalAlignment = Alignment.CenterVertically) {
            IconBtn("x", "Закрыть", Modifier.testTag("compose-close")) { close() }
            Text(
                when (m.start) { is ComposeStart.Reply -> "Ответ"; is ComposeStart.Forward, is ComposeStart.ForwardAsAttachment -> "Пересылка"; is ComposeStart.Draft -> "Черновик"; else -> "Новое письмо" },
                Modifier.weight(1f), style = MaterialTheme.typography.titleMedium,
            )
            if (m.saving) CircularProgressIndicator(Modifier.size(18.dp), strokeWidth = 2.dp)
            IconBtn("clip", "Приложить файл", Modifier.testTag("attach")) { pickFiles() }
            Box {
                IconBtn("dots", "Ещё") { menu = true }
                DropdownMenu(menu, { menu = false }) {
                    DropdownMenuItem({ Text(if (m.priority) "✓ Важное" else "Важное") }, { menu = false; m.priority = !m.priority; m.dirty = true }, leadingIcon = { Ico("flag") })
                    DropdownMenuItem({ Text(if (m.receipt) "✓ Уведомить о прочтении" else "Уведомить о прочтении") }, { menu = false; m.receipt = !m.receipt; m.dirty = true }, leadingIcon = { Ico("check") })
                    DropdownMenuItem({ Text(if (m.sendAt != null) "Отправить позже: ${Fmt.full(m.sendAt)}" else "Отправить позже…") }, { menu = false; dialog = "later" }, leadingIcon = { Ico("clock") })
                    DropdownMenuItem({ Text(m.remindDays?.let { "Напомнить без ответа: $it дн." } ?: "Напомнить, если не ответят…") }, { menu = false; dialog = "remind" }, leadingIcon = { Ico("bell") })
                    if (m.meta.cloud.personal) DropdownMenuItem({ Text("Файл из облака…") }, { menu = false; Nav.push(su.innotec.mail.ui.cloud.CloudPickerScreen { picked -> m.cloudFiles.addAll(picked); m.dirty = true }) }, leadingIcon = { Ico("cloud") })
                    DropdownMenuItem({ Text("Сохранить черновик") }, { menu = false; scope.launch { m.saveDraft(quiet = false) } }, leadingIcon = { Ico("edit") })
                    if (m.draftUid != null) DropdownMenuItem({ Text("Удалить черновик", color = P.no) }, {
                        menu = false
                        val uid = m.draftUid!!
                        val folder = MailStore.folders.firstOrNull { it.role == "drafts" }?.path ?: "Drafts"
                        m.dirty = false
                        Nav.pop()
                        MailStore.act("delete", listOf(uid), folder = folder)
                    }, leadingIcon = { Ico("trash", tint = P.no) })
                }
            }
            Box(
                Modifier.padding(start = 4.dp, end = 8.dp).clip(RoundedCornerShape(10.dp)).background(if (m.loaded) P.accent else P.border2)
                    .clickable(enabled = m.loaded) {
                        val err = m.problems()
                        if (err != null) { Toasts.show(err); return@clickable }
                        autosave?.cancel()
                        Nav.pop()
                        sendWithUndo(m)
                    }.padding(horizontal = 14.dp, vertical = 9.dp).testTag("send"),
            ) { Row(verticalAlignment = Alignment.CenterVertically) { Ico("send", size = 18.dp, tint = P.accentOn); Spacer(Modifier.width(6.dp)); Text("Отправить", color = P.accentOn, fontWeight = FontWeight.SemiBold) } }
        }
        Divider()
        when {
            m.loadError != null -> su.innotec.mail.ui.ErrorBox(m.loadError!!, { m.loadError = null; scope.launch { m.load() } })
            !m.loaded -> Loading()
            // На планшете и ПК строка письма не шире ~900 dp — длинные строки читать тяжело.
            else -> Column(Modifier.weight(1f).fillMaxWidth().verticalScroll(rememberScrollState()), horizontalAlignment = Alignment.CenterHorizontally) { Column(Modifier.widthIn(max = 920.dp).fillMaxWidth()) {
                if (m.meta.identities.size > 1) FromRow(m)
                RecipientsField("Кому", m.to, Modifier.testTag("to"), onChange = { m.dirty = true }, trailing = {
                    if (!m.showCc) Text("Копия", Modifier.clip(RoundedCornerShape(6.dp)).clickable { m.showCc = true }.padding(8.dp), color = P.accentInk, style = MaterialTheme.typography.labelLarge)
                })
                if (m.showCc) {
                    RecipientsField("Копия", m.cc, onChange = { m.dirty = true })
                    RecipientsField("Скрытая", m.bcc, onChange = { m.dirty = true })
                }
                PlainField(m.subject, { m.subject = it; m.dirty = true }, "Тема", Modifier.testTag("subject"), single = true, bold = true)
                Divider()
                Attachments(m)
                PlainField(m.body, { m.body = it; m.dirty = true }, "Текст письма", Modifier.heightIn(min = 220.dp).testTag("body"))
                if (m.tail.isNotBlank()) Tail(m)
                Spacer(Modifier.height(80.dp))
            } }
        }
    }

    when (dialog) {
        "later" -> DateTimeDialog("Отправить", onDismiss = { dialog = null }) { dt ->
            m.sendAt = dt.toInstant(TimeZone.currentSystemDefault()).toString(); m.dirty = true
            Toasts.show("Письмо уйдёт ${Fmt.full(m.sendAt)} — после нажатия «Отправить»")
        }
        "remind" -> su.innotec.mail.ui.ChoiceDialog("Напомнить, если не ответят через…", listOf(0, 1, 2, 3, 5, 7, 14), { if (it == 0) "Не напоминать" else "$it ${Fmt.plural(it, "день", "дня", "дней")}" },
            m.remindDays ?: 0, onDismiss = { dialog = null }) { m.remindDays = it.takeIf { d -> d > 0 }; m.dirty = true }
    }
}

@Composable
private fun FromRow(m: ComposeModel) {
    var open by remember { mutableStateOf(false) }
    Row(Modifier.fillMaxWidth().clickable { open = true }.padding(horizontal = 16.dp, vertical = 12.dp), verticalAlignment = Alignment.CenterVertically) {
        Text("От", Modifier.width(64.dp), color = P.muted)
        Text(m.from ?: Session.account?.user ?: "", Modifier.weight(1f), maxLines = 1, overflow = TextOverflow.Ellipsis)
        Ico("down", size = 16.dp, tint = P.muted)
    }
    Divider()
    if (open) su.innotec.mail.ui.ChoiceDialog("От кого", m.meta.identities, { i -> if (i.shared) "${i.mail} (общий)" else i.mail },
        m.meta.identities.firstOrNull { it.mail.equals(m.from ?: Session.account?.user ?: "", true) }, onDismiss = { open = false }) { i ->
        m.from = if (i.primary) null else i.mail; m.dirty = true
    }
}

@Composable
private fun PlainField(value: String, onChange: (String) -> Unit, placeholder: String, modifier: Modifier = Modifier, single: Boolean = false, bold: Boolean = false) {
    Box(modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 12.dp)) {
        if (value.isEmpty()) Text(placeholder, color = P.faint, style = MaterialTheme.typography.bodyLarge)
        BasicTextField(
            value, onChange, Modifier.fillMaxWidth(), singleLine = single,
            textStyle = MaterialTheme.typography.bodyLarge.copy(color = P.text, fontWeight = if (bold) FontWeight.Medium else FontWeight.Normal),
            cursorBrush = SolidColor(P.accent),
            keyboardOptions = KeyboardOptions(capitalization = KeyboardCapitalization.Sentences, imeAction = if (single) ImeAction.Next else ImeAction.Default),
        )
    }
}

/** Поле адресатов: «пузыри» и подсказки из адресной книги и истории переписки. */
@OptIn(ExperimentalLayoutApi::class)
@Composable
fun RecipientsField(label: String, list: MutableList<Person>, modifier: Modifier = Modifier, onChange: () -> Unit, trailing: @Composable () -> Unit = {}) {
    var text by remember { mutableStateOf("") }
    var hints by remember { mutableStateOf<List<Suggestion>>(emptyList()) }
    val scope = rememberCoroutineScope()
    var job by remember { mutableStateOf<Job?>(null) }
    fun commit(raw: String) {
        raw.split(',', ';', ' ').map { it.trim() }.filter { it.contains('@') }.forEach { a ->
            if (list.none { it.mail.equals(a, true) }) list.add(Person("", a))
        }
        text = ""; hints = emptyList(); onChange()
    }
    Column(modifier) {
        Row(Modifier.fillMaxWidth().padding(start = 16.dp, end = 8.dp, top = 6.dp, bottom = 6.dp), verticalAlignment = Alignment.CenterVertically) {
            Text(label, Modifier.width(64.dp), color = P.muted)
            FlowRow(Modifier.weight(1f), horizontalArrangement = Arrangement.spacedBy(4.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                list.toList().forEach { p ->
                    Row(Modifier.clip(RoundedCornerShape(50)).background(P.chipOff).padding(start = 10.dp, end = 4.dp, top = 4.dp, bottom = 4.dp), verticalAlignment = Alignment.CenterVertically) {
                        Text(p.name.ifBlank { p.mail }, style = MaterialTheme.typography.bodyMedium, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.widthIn(max = 220.dp))
                        Box(Modifier.padding(start = 2.dp).size(20.dp).clip(CircleShape).clickable { list.remove(p); onChange() }, contentAlignment = Alignment.Center) { Ico("x", size = 12.dp, tint = P.muted) }
                    }
                }
                BasicTextField(
                    text,
                    { v ->
                        if (v.endsWith(",") || v.endsWith(";") || (v.endsWith(" ") && v.trim().contains('@'))) { commit(v); return@BasicTextField }
                        text = v
                        job?.cancel()
                        if (v.trim().length >= 2) job = scope.launch {
                            delay(250)
                            hints = runCatching { Session.api!!.suggest(v.trim()) }.getOrDefault(emptyList()).filter { s -> list.none { it.mail.equals(s.mail, true) } }.take(8)
                        } else hints = emptyList()
                    },
                    Modifier.widthIn(min = 120.dp).padding(vertical = 6.dp),
                    singleLine = true,
                    textStyle = MaterialTheme.typography.bodyLarge.copy(color = P.text),
                    cursorBrush = SolidColor(P.accent),
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Email, imeAction = ImeAction.Next),
                    keyboardActions = KeyboardActions(onNext = { commit(text) }),
                )
            }
            trailing()
        }
        hints.forEach { h ->
            Row(Modifier.fillMaxWidth().clickable { list.add(Person(h.name, h.mail)); text = ""; hints = emptyList(); onChange() }.padding(start = 80.dp, end = 16.dp, top = 8.dp, bottom = 8.dp),
                verticalAlignment = Alignment.CenterVertically) {
                su.innotec.mail.ui.Avatar(h.name.ifBlank { h.mail }, h.mail, 28.dp)
                Spacer(Modifier.width(10.dp))
                Column {
                    if (h.name.isNotBlank()) Text(h.name, style = MaterialTheme.typography.bodyMedium)
                    Text(h.mail, style = MaterialTheme.typography.bodySmall, color = P.muted)
                }
            }
        }
        Divider()
    }
}

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun Attachments(m: ComposeModel) {
    if (m.files.isEmpty() && m.existing.isEmpty() && m.cloudFiles.isEmpty() && m.attachMessages.isEmpty()) return
    Column(Modifier.padding(horizontal = 12.dp, vertical = 8.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
        m.attachMessages.toList().forEach { a -> AttChip("mail", a.name ?: "письмо", null, "письмо целиком") { m.attachMessages.remove(a); m.dirty = true } }
        m.existing.toList().forEach { a ->
            val on = a.index in m.keep
            Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(8.dp)).background(P.surface2).clickable { if (on) m.keep.remove(a.index) else m.keep.add(a.index); m.dirty = true }
                .padding(horizontal = 4.dp), verticalAlignment = Alignment.CenterVertically) {
                Checkbox(on, { v -> if (v) m.keep.add(a.index) else m.keep.remove(a.index); m.dirty = true })
                Ico(fileIcon(a.name, a.type), size = 16.dp, tint = P.muted); Spacer(Modifier.width(8.dp))
                Text(a.name, Modifier.weight(1f), maxLines = 1, overflow = TextOverflow.Ellipsis, style = MaterialTheme.typography.bodyMedium)
                Text(Fmt.size(a.size), style = MaterialTheme.typography.bodySmall, color = P.faint, modifier = Modifier.padding(end = 8.dp))
            }
        }
        m.files.toList().forEachIndexed { i, f ->
            val cloud = i in m.viaCloud
            Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(8.dp)).background(P.surface2).padding(start = 12.dp, end = 4.dp, top = 4.dp, bottom = 4.dp), verticalAlignment = Alignment.CenterVertically) {
                Ico(if (cloud) "cloud" else "clip", size = 16.dp, tint = if (cloud) P.accentInk else P.muted); Spacer(Modifier.width(8.dp))
                Column(Modifier.weight(1f)) {
                    Text(f.name, maxLines = 1, overflow = TextOverflow.Ellipsis, style = MaterialTheme.typography.bodyMedium)
                    Text(Fmt.size(f.size) + if (cloud) " · уйдёт ссылкой" else "", style = MaterialTheme.typography.bodySmall, color = P.faint)
                }
                if (m.meta.cloud.enabled) TextButton(onClick = { if (cloud) m.viaCloud.remove(i) else m.viaCloud.add(i); m.dirty = true }) { Text(if (cloud) "Вложением" else "Ссылкой") }
                IconBtn("x", "Убрать", tint = P.muted) {
                    m.files.removeAt(i)
                    val shifted = m.viaCloud.filter { it != i }.map { if (it > i) it - 1 else it }
                    m.viaCloud.clear(); m.viaCloud.addAll(shifted); m.dirty = true
                }
            }
        }
        m.cloudFiles.toList().forEach { c -> AttChip("cloud", c.name.ifBlank { c.path.substringAfterLast('/') }, c.size, "из облака, ссылкой") { m.cloudFiles.remove(c); m.dirty = true } }
    }
    Divider()
}

@Composable
private fun AttChip(icon: String, name: String, size: Long?, note: String, onRemove: () -> Unit) {
    Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(8.dp)).background(P.surface2).padding(start = 12.dp, end = 4.dp, top = 4.dp, bottom = 4.dp), verticalAlignment = Alignment.CenterVertically) {
        Ico(icon, size = 16.dp, tint = P.accentInk); Spacer(Modifier.width(8.dp))
        Column(Modifier.weight(1f)) {
            Text(name, maxLines = 1, overflow = TextOverflow.Ellipsis, style = MaterialTheme.typography.bodyMedium)
            Text(listOfNotNull(size?.let { Fmt.size(it) }, note).joinToString(" · "), style = MaterialTheme.typography.bodySmall, color = P.faint)
        }
        IconBtn("x", "Убрать", tint = P.muted) { onRemove() }
    }
}

/** Подпись и цитата: показываются как есть, можно убрать из письма. */
@Composable
private fun Tail(m: ComposeModel) {
    var open by remember { mutableStateOf(false) }
    val isQuote = m.tail.contains("class=\"quote\"") || m.tail.contains("class=\"fwd\"")
    Column(Modifier.padding(horizontal = 12.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Checkbox(m.includeTail, { m.includeTail = it; m.dirty = true })
            Text(if (isQuote) "Подпись и исходное письмо" else "Подпись", Modifier.weight(1f), style = MaterialTheme.typography.bodyMedium, color = P.muted)
            TextButton(onClick = { open = !open }) { Text(if (open) "Скрыть" else "Показать") }
        }
        if (open) Box(Modifier.fillMaxWidth().clip(RoundedCornerShape(8.dp)).border(1.dp, P.border, RoundedCornerShape(8.dp))) {
            HtmlView(m.tail, P.dark, Modifier.fillMaxWidth(), onLink = {}, loadResource = { p -> Transfers.inlineResource(p) })
        }
    }
}
