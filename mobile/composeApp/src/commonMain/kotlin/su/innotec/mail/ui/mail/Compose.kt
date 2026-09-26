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
import kotlinx.coroutines.currentCoroutineContext
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
import su.innotec.mail.api.Staged
import su.innotec.mail.api.Suggestion
import su.innotec.mail.data.Session
import su.innotec.mail.platform.BackHandler
import su.innotec.mail.platform.HtmlView
import su.innotec.mail.platform.RichEditor
import su.innotec.mail.platform.RichEditorState
import androidx.compose.foundation.horizontalScroll
import androidx.compose.runtime.mutableStateMapOf
import androidx.compose.ui.layout.onGloballyPositioned
import androidx.compose.ui.layout.onSizeChanged
import androidx.compose.ui.layout.positionInParent
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import kotlinx.io.readByteArray
import kotlinx.serialization.json.contentOrNull
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
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
    /** Текст письма с оформлением; создаётся, когда письмо загружено. */
    var editor by mutableStateOf(RichEditorState(""))
    /** Подпись — отдельно: меняется вместе с «От кого» (письмо от общего ящика — с его подписью). */
    var sig by mutableStateOf("")
    /** Цитата или шапка пересылки (у черновика — всё после текста человека). */
    var rest by mutableStateOf("")
    /** Подпись свёрнутой цитаты: «Иванова Мария, 12:17» (у черновика исходное письмо неизвестно — null). */
    var quoteTitle by mutableStateOf<String?>(null)
    /** Подпись, цитата или шапка пересылки — HTML, который дописывается к тексту. */
    val tail get() = sig + rest
    private var replyKind = false
    /** Предупреждения о доменах получателей: адрес → текст и подсказка исправления. */
    val domainWarn = mutableStateMapOf<String, DomainWarn>()
    private val checked = mutableSetOf<String>()
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
    /** «Отправить» нажали, пока файлы грузились: письмо уйдёт само, когда загрузка закончится — даже если окно закрыли. */
    var waitUpload by mutableStateOf(false)
    /** «Отправить позже» ждёт подтверждений (домены, пустая тема) и загрузки файлов. */
    var pendingAt by mutableStateOf<String?>(null)

    val bodyHasContent get() = editor.html.contains("<img", true) || Html.toText(editor.html).isNotBlank()
    val hasContent get() = to.isNotEmpty() || cc.isNotEmpty() || subject.isNotBlank() || bodyHasContent || files.isNotEmpty() || cloudFiles.isNotEmpty() || staged.isNotEmpty()

    fun identity(): Identity? = meta.identities.firstOrNull { it.mail.equals(from ?: Session.account?.user ?: "", true) }

    /** Сменили «От кого» — подпись меняется, текст и цитата остаются. */
    fun fromChanged() { if (sigManaged) sig = signatureHtml(replyKind) }
    private var sigManaged = false

    /** Проверить домены новых адресатов (есть ли такой, принимает ли почту) — как в веб-почте. */
    suspend fun verifyDomains() {
        val api = Session.api ?: return
        (to + cc + bcc).map { it.mail.lowercase() }.filter { it.contains('@') && checked.add(it) }.forEach { mail ->
            val r = runCatching { api.checkDomain(mail.substringAfter('@')).jsonObject }.getOrNull() ?: return@forEach
            val status = r["status"]?.jsonPrimitive?.contentOrNull ?: "ok"
            if (status != "ok") domainWarn[mail] = DomainWarn(r["text"]?.jsonPrimitive?.contentOrNull ?: "Домен не принимает почту", r["suggestion"]?.jsonPrimitive?.contentOrNull)
        }
    }

    fun warningsFor(list: List<Person>) = list.mapNotNull { domainWarn[it.mail.lowercase()] }

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
            var body = ""
            when (val s = start) {
                is ComposeStart.New -> {
                    parseList(s.to).forEach { to.add(it) }
                    subject = s.subject; body = if (s.body.isBlank()) "" else Html.fromText(s.body)
                    cloudFiles.addAll(s.cloudFiles)
                    sigManaged = true; sig = signatureHtml(false)
                }
                is ComposeStart.Mailto -> {
                    val (addr, q) = s.url.removePrefix("mailto:").removePrefix("MAILTO:").let { it.substringBefore('?') to it.substringAfter('?', "") }
                    parseList(su.innotec.mail.api.Api.percentDecode(addr)).forEach { to.add(it) }
                    q.split('&').forEach { kv ->
                        val k = kv.substringBefore('=').lowercase(); val v = su.innotec.mail.api.Api.percentDecode(kv.substringAfter('=', "").replace('+', ' '))
                        when (k) { "subject" -> subject = v; "body" -> body = Html.fromText(v); "cc" -> { parseList(v).forEach { cc.add(it) }; showCc = true } }
                    }
                    sigManaged = true; sig = signatureHtml(false)
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
                    body = if (s.text.isBlank()) "" else Html.fromText(s.text)
                    replyKind = true; sigManaged = true; sig = signatureHtml(true); rest = quote(m)
                    quoteTitle = quoteTitleOf(m.from.display, m.date)
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
                    replyKind = true; sigManaged = true; sig = signatureHtml(true)
                    rest = "<p><br></p>" + hdr + (m.html ?: "<pre style=\"white-space:pre-wrap;font:inherit\">${Html.escape(m.text ?: "")}</pre>")
                    quoteTitle = quoteTitleOf(m.from.display, m.date)
                    references = listOf(m.references, m.messageId).filter { it.isNotBlank() }.joinToString(" ")
                    sourceFolder = s.folder; sourceUid = m.uid
                    existing.addAll(m.attachments)
                    keep.addAll(m.attachments.map { it.index })
                    cloudFiles.addAll(m.cloudFiles)
                }
                is ComposeStart.ForwardAsAttachment -> {
                    val subjects = s.items.map { (f, u) -> MailStore.messages.firstOrNull { it.uid == u }?.subject ?: runCatching { api.message(f, u, peek = true).subject }.getOrDefault("письмо") }
                    subject = if (s.items.size == 1) answerSubject("Fwd", subjects[0]) else "Fwd: ${s.items.size} ${Fmt.plural(s.items.size, "письмо", "письма", "писем")}"
                    replyKind = true; sigManaged = true; sig = signatureHtml(true)
                    s.items.forEachIndexed { i, (f, u) -> attachMessages.add(AttachedMessageRef(f, u, subjects[i].ifBlank { "письмо" })) }
                }
                is ComposeStart.Again -> {
                    val m = api.message(s.folder, s.uid, peek = true)
                    to.addAll(m.to); cc.addAll(m.cc); showCc = cc.isNotEmpty()
                    subject = if (m.subject == "(без темы)") "" else m.subject
                    val (mine, t) = Html.splitTail(m.html ?: Html.fromText(m.text ?: ""))
                    body = mine; rest = t
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
                    body = mine; rest = t
                    priority = d.priority; receipt = d.receipt
                    inReplyTo = d.inReplyTo.ifBlank { null }; references = d.references.ifBlank { null }
                    existing.addAll(d.attachments); keep.addAll(d.attachments.map { it.index })
                    cloudFiles.addAll(d.cloudFiles)
                    d.staged.forEach { st -> staged.add(StagedFile(st.name, st.size, st.token).apply { fromDraft = true }) }
                    sourceFolder = MailStore.folders.firstOrNull { it.role == "drafts" }?.path; sourceUid = draftUid
                    sourceIsDraft = true
                }
            }
            editor = RichEditorState(body)
            loaded = true
            verifyDomains()
        } catch (e: ApiException) {
            loadError = e.message
        } catch (e: kotlinx.coroutines.CancellationException) {
            throw e
        } catch (e: Throwable) {
            // Неожиданное (битый адрес mailto:, сбой разбора) — экран «Повторить», а не падение приложения.
            loadError = e.message ?: "Не удалось открыть письмо"
        }
    }

    fun parseList(s: String): List<Person> =
        s.split(Regex(",(?![^<]*>)")).map { it.trim() }.filter { it.isNotEmpty() }.map { p ->
            Regex("^\"?([^\"<]*)\"?\\s*<([^>]+)>$").find(p)?.let { Person(it.groupValues[1].trim(), it.groupValues[2].trim()) } ?: Person("", p)
        }

    private fun addr(list: List<Person>) = list.joinToString(", ") { p ->
        if (p.name.isBlank() || p.name == p.mail) p.mail else "\"${p.name.replace("\"", "")}\" <${p.mail}>"
    }

    fun html(): String = (if (bodyHasContent) editor.html else "<p><br></p>") + if (includeTail) tail else ""

    /**
     * Источник вложений — уже сам черновик (а не исходное письмо ответа или пересылки). Свой флаг, а не сравнение
     * с путём папки «Черновики»: список папок мог ещё не загрузиться (открыли черновик по ссылке из уведомления).
     */
    var sourceIsDraft: Boolean = false; private set

    /** Файлы, которые уйдут ссылкой: в черновик их не кладём (см. [saveOnce]), они ждут отправки на устройстве. */
    private fun draftFiles(): List<LocalFile> = files.filterIndexed { i, _ -> i !in viaCloud }

    fun form(forDraft: Boolean): ComposeForm = ComposeForm(
        from = from, to = addr(to), cc = addr(cc), bcc = addr(bcc), subject = subject, html = html(),
        inReplyTo = inReplyTo, references = references, answeredFolder = answeredFolder, answeredUid = answeredUid,
        sourceFolder = sourceFolder, sourceUid = sourceUid, draftUid = draftUid,
        sendAt = if (forDraft) null else sendAt, remindDays = if (forDraft) null else remindDays,
        keepAttachments = existing.isNotEmpty() && keep.isNotEmpty(), keepIndexes = keep.toList(),
        priority = priority, receipt = receipt,
        // draftKeepFiles заставляет сервер брать вложения из прошлого черновика вместо sourceFolder. Пока источник —
        // исходное письмо (ответ без файлов), этого нельзя: отмеченные вложения исходного не попали бы в черновик.
        draftKeepFiles = forDraft && sourceIsDraft,
        files = if (forDraft) draftFiles() else files.toList(), cloud = if (forDraft) emptyList() else viaCloud.toList(),
        cloudFiles = cloudFiles.toList(), attachMessages = attachMessages.toList(),
        staged = staged.filter { it.state == "ready" && it.token != null }.map { Staged(it.token!!, it.name, it.size) },
    )

    /**
     * Крупный файл, заранее положенный в хранилище (compose/stage) — как в веб-почте, когда личного облака нет:
     * при отправке сервер вставит ссылку, а не будет перекладывать файл из черновика. Этапы — словами у файла.
     */
    class StagedFile(val name: String, val size: Long, token: String? = null) {
        var token by mutableStateOf(token)
        /** upload — идёт загрузка; check — файл дошёл, сервер проверяет антивирусом; ready; error. */
        var state by mutableStateOf(if (token != null) "ready" else "upload")
        var sent by mutableStateOf(0L)
        var error by mutableStateOf("")
        var job: Job? = null
        /** Пришёл с черновиком: при удалении из письма в хранилище не трогаем — уборка сервера сама удалит, если письмо не уйдёт. */
        var fromDraft = false
        val pct: Int get() = if (size > 0) (sent * 100 / size).toInt().coerceIn(0, 99) else 0
    }
    val staged = mutableStateListOf<StagedFile>()
    val stageBusy get() = staged.any { it.state == "upload" || it.state == "check" }

    fun stageFile(f: LocalFile, scope: CoroutineScope) {
        val api = Session.api ?: return
        val s = StagedFile(f.name, f.size)
        staged.add(s); dirty = true
        s.job = scope.launch {
            try {
                val r = api.stageFile(f) { sent, _ -> s.sent = sent; if (sent >= f.size) s.state = "check" }
                // Убрали, пока грузился, — файл в хранилище не нужен.
                if (s !in staged) { runCatching { api.unstage(r.token) }; return@launch }
                s.token = r.token; s.state = "ready"; s.error = ""
                dirty = true
                saveDraft()   // черновик должен запомнить файл
            } catch (e: ApiException) {
                if (e.isAuth) { Toasts.error(e); staged.remove(s); return@launch }
                // Хранилище не принимает заранее (409: не своё) — прежний путь: файл в черновик, ссылкой при отправке.
                if (e.status == 409 && s in staged) {
                    staged.remove(s); files.add(f); if (meta.cloud.enabled) viaCloud.add(files.lastIndex); dirty = true
                    saveDraft(); return@launch
                }
                s.state = "error"; s.error = e.message
            } catch (e: kotlinx.coroutines.CancellationException) {
                throw e
            } catch (e: Throwable) {
                // Файл не открылся (удалили или отозвали доступ, пока он ждал очереди).
                s.state = "error"; s.error = "не удалось прочитать файл"
            }
        }
    }

    /** Убрать файл из письма; из хранилища — только свой, ещё не отправленный. */
    fun dropStaged(s: StagedFile) {
        staged.remove(s); s.job?.cancel()
        val t = s.token
        if (t != null && !s.fromDraft) sendScope.launch { runCatching { Session.api?.unstage(t) } }
        dirty = true
    }

    /** Черновик удалили — файлам в хранилище делать нечего. */
    fun unstageAll() {
        staged.toList().forEach { s -> s.job?.cancel(); s.token?.let { t -> sendScope.launch { runCatching { Session.api?.unstage(t) } } } }
        staged.clear()
    }

    /** Сохранить черновик. Свои файлы после первого сохранения живут уже в черновике. */
    /** Крупный файл, который сейчас грузится в облако, чтобы уйти ссылкой (этап — словами в списке вложений). */
    class CloudJob(val file: LocalFile) {
        var started by mutableStateOf(false)
        var done by mutableStateOf(0L)
        var linking by mutableStateOf(false)
    }
    val cloudJobs = mutableStateListOf<CloudJob>()
    val busy get() = saving || cloudJobs.isNotEmpty() || stageBusy

    /**
     * Крупный файл — сразу в облако («Вложения из почты»), в письмо — ссылка на него. Тогда при отправке серверу
     * не надо перекладывать сотни мегабайт из черновика в облако (это и было «повторной загрузкой» при отправке),
     * а черновик не разрастается. Не вышло (нет места в облаке) — прежний путь: файл в черновик, ссылкой при отправке.
     */
    fun uploadToCloud(f: LocalFile, scope: CoroutineScope) {
        val api = Session.api ?: return
        val job = CloudJob(f)
        cloudJobs.add(job)
        scope.launch {
            try {
                runCatching { api.cloudMkdir("", CLOUD_DIR) }   // уже есть — не страшно
                val path = su.innotec.mail.ui.cloud.CloudUploads.uploadFile(CLOUD_DIR, f, onStarted = { job.started = true }) { job.done = it; if (it >= f.size) job.linking = true }
                cloudFiles.add(CloudFileRef(path, path.substringAfterLast('/'), f.size))
                dirty = true
            } catch (e: ApiException) {
                if (e.isAuth) { Toasts.error(e); return@launch }
                Toasts.show("«${f.name}»: ${e.message} — файл уйдёт через черновик")
                files.add(f); viaCloud.add(files.lastIndex); dirty = true
            } catch (e: kotlinx.coroutines.CancellationException) {
                throw e
            } catch (e: Throwable) {
                // Файл не открылся (удалили или отозвали доступ к нему, пока он ждал очереди) — говорим, а не падаем.
                Toasts.show("«${f.name}»: не удалось прочитать файл — приложите его заново")
                return@launch
            } finally {
                cloudJobs.remove(job)
            }
            // Окно могли закрыть, пока файл грузился: ссылку всё равно записываем в черновик.
            saveDraft()
        }
    }

    /** Файлы, которые сейчас уходят на сервер, и сколько байт уже ушло (ход — у каждого файла в списке). */
    var uploadingFiles by mutableStateOf<List<LocalFile>>(emptyList()); private set
    var uploadSent by mutableStateOf(0L); private set
    /** Сервер начал принимать данные (до этого — «Связь с сервером…»). */
    var uploadStarted by mutableStateOf(false); private set
    /** Во время загрузки успели ещё что-то изменить — после неё сохранить снова. */
    private var again = false
    /** Идущее сохранение и его итог — чтобы закрытие окна дождалось его, а не сохраняло вторым заходом. */
    private var saveJob: Job? = null
    private var lastSaveOk = false

    /** Сколько уже загружено из файла [f] (null — файл сейчас не грузится). */
    fun uploadedOf(f: LocalFile): Long? {
        val i = uploadingFiles.indexOf(f)
        if (i < 0) return null
        val before = uploadingFiles.take(i).sumOf { it.size }
        return (uploadSent - before).coerceIn(0, f.size)
    }

    // ---------- автосохранение ----------

    private var autosave: Job? = null

    /**
     * Автосохранение через 15 секунд после правки — в [sendScope], а не в области окна: закрытое окно
     * не должно обрывать заливку файлов. Таймер отменяем, идущее сохранение — никогда.
     */
    fun scheduleAutosave() {
        cancelAutosave()
        autosave = sendScope.launch {
            delay(15_000)
            autosave = null   // дальше отменять нечего: сохранение должно дойти до конца
            if (dirty) saveDraft()
        }
    }

    /** Снять таймер (окно закрыли, письмо отправляют, черновик удалили); заливку, которая уже идёт, не трогает. */
    fun cancelAutosave() { autosave?.cancel(); autosave = null }

    /**
     * Сохранить черновик. Свои файлы при этом загружаются на сервер и дальше живут в черновике —
     * поэтому загрузка идёт сразу после выбора файла (как в Gmail), а не при отправке.
     * Если сохранение уже идёт, ждём его: оно само сделает ещё заход (again), раз что-то изменилось.
     */
    suspend fun saveDraft(quiet: Boolean = true): Boolean {
        val api = Session.api ?: return false
        if (saving) { again = true; saveJob?.join(); return lastSaveOk }
        saving = true
        saveJob = currentCoroutineContext()[Job]
        try {
            var ok: Boolean
            do {
                again = false
                ok = saveOnce(api, quiet)
            } while (ok && again)
            lastSaveOk = ok
            return ok
        } finally {
            saving = false
            saveJob = null
            uploadingFiles = emptyList(); uploadSent = 0
            // Плашка внизу — только своя: идущую отправку письма гасить нельзя.
            if (SendProgress.isDraft) SendProgress.done()
        }
    }

    private suspend fun saveOnce(api: su.innotec.mail.api.Api, quiet: Boolean): Boolean {
        // Файлы «ссылкой» в черновик не кладём: в черновике отметка «ссылкой» теряется, и при отправке
        // они пошли бы вложением поверх предела письма. Они ждут отправки на устройстве (cloud[] уходит с /send).
        val batch = draftFiles()
        // Черновик пока без вложений, источник — исходное письмо ответа или пересылки: его вложения можно
        // отметить и позже. Как только в черновике что-то есть, источник — он сам.
        val switchToDraft = sourceIsDraft || batch.isNotEmpty() || keep.isNotEmpty()
        uploadingFiles = batch; uploadSent = 0; uploadStarted = false
        // Плашка внизу — для случая, когда окно уже закрыли, а файлы ещё грузятся (в окне ход виден у каждого файла).
        // Идёт отправка письма — её плашку не подменяем.
        val ownBar = SendProgress.title == null || SendProgress.isDraft
        if (ownBar) SendProgress.start(subject, isDraft = true, heavy = batch.isNotEmpty())
        try {
            val r = api.saveDraft(form(forDraft = true)) { a, b -> uploadStarted = true; uploadSent = a; if (ownBar && SendProgress.isDraft) SendProgress.upload(a, b) }
            val uid = r.draftUid ?: return false
            draftUid = uid
            if (switchToDraft) {
                sourceFolder = r.folder ?: MailStore.folders.firstOrNull { it.role == "drafts" }?.path
                sourceUid = uid
                sourceIsDraft = true
                val d = api.openDraft(uid)
                existing.clear(); existing.addAll(d.attachments)
                keep.clear(); keep.addAll(d.attachments.map { it.index })
                // Убираем только загруженные: файл, добавленный во время загрузки, остаётся и уйдёт следующим заходом.
                val rest = files.filter { it !in batch }
                val restCloud = rest.withIndex().filter { (_, f) -> viaCloud.any { files.getOrNull(it) == f } }.map { it.index }
                files.clear(); files.addAll(rest)
                viaCloud.clear(); viaCloud.addAll(restCloud)
                // Остались файлы не «ссылкой» (добавили во время заливки) — ещё заход; одни «ссылкой» — нет, они ждут отправки.
                if (rest.indices.any { it !in restCloud }) again = true
            }
            dirty = again
            if (!quiet) Toasts.show("Черновик сохранён")
            return true
        } catch (e: ApiException) {
            if (!quiet || batch.isNotEmpty()) Toasts.error(e)
            return false
        } catch (e: kotlinx.coroutines.CancellationException) {
            throw e
        } catch (e: Throwable) {
            // Файл с устройства не открылся (удалён, доступ отозван): черновик без него, человеку — почему.
            Toasts.show("Черновик не сохранён: не удалось прочитать файл — приложите его заново")
            return false
        } finally {
            uploadingFiles = emptyList()
        }
    }

    /**
     * Вложение исходного письма крупнее порога облака сервер при отправке сам кладёт в облако и шлёт ссылкой
     * (MailBuilder::keptAttachments) — как в веб-почте (keptCloud). В размер письма оно не входит.
     */
    fun keptViaCloud(a: Attachment): Boolean = meta.cloud.enabled && a.size >= meta.cloud.thresholdMb.toLong() * 1024 * 1024

    /**
     * Сколько места займут вложения внутри письма (без ушедших ссылкой). Сервер сравнивает с пределом
     * закодированный размер (3 байта → 4 знака) — считаем так же, см. [encoded].
     */
    fun inMailSize(): Long = files.withIndex().filter { it.index !in viaCloud }.sumOf { it.value.size } +
        existing.filter { it.index in keep && !keptViaCloud(it) }.sumOf { it.size }

    /**
     * Вес текста с цепочкой RE и картинками в нём — в пределе письма, как в веб-почте (Compose.vue, measureBody):
     * кириллица кодируется втрое, картинки data: уже в base64.
     */
    fun bodySize(): Long {
        // Полный текст письма — с подписью и цитатой: они уходят вместе с ним и тоже считаются в пределе.
        val bytes = html().encodeToByteArray()
        var n = 0L
        for (b in bytes) n += if (b >= 0) 1 else 3
        return n * 105 / 100
    }

    fun problems(): String? {
        if (to.isEmpty() && cc.isEmpty() && bcc.isEmpty()) return "Укажите, кому отправить письмо"
        (to + cc + bcc).firstOrNull { !Regex("^[^@\\s]+@[^@\\s]+\\.[^@\\s]+$").matches(it.mail) }?.let { return "Адрес «${it.mail}» написан с ошибкой" }
        val maxFiles = meta.limits.maxFiles
        if (files.size + keep.size + staged.size > maxFiles) return "Не больше $maxFiles файлов в одном письме"
        staged.firstOrNull { it.state == "error" }?.let { return "«${it.name}» не загрузился — уберите его или приложите заново" }
        val limit = meta.limits.messageMb.toLong() * 1024 * 1024
        if (encoded(inMailSize()) + bodySize() > limit) return "Файлы тяжелее предела почты (${meta.limits.messageMb} МБ)" + if (meta.cloud.enabled) " — отметьте крупные «ссылкой»" else ""
        return null
    }
}

data class DomainWarn(val text: String, val suggestion: String?)

/** «Иванова Мария, 12:17» — подпись свёрнутой цитаты (сегодня — время, раньше — дата, как в списке). */
fun quoteTitleOf(who: String, date: String): String = listOf(who.trim(), Fmt.listDate(date)).filter { it.isNotBlank() }.joinToString(", ")

/** Подпись у файла в хранилище: «48 МБ · ссылкой · 72%», «… · проверка антивирусом…», «… · не загрузился: …». */
fun stageLabel(state: String, size: Long, pct: Int, error: String = ""): String {
    val sz = Fmt.size(size)
    return when (state) {
        "upload" -> "$sz · ссылкой · $pct%"
        "check" -> "$sz · ссылкой · проверка антивирусом…"
        "error" -> "$sz · не загрузился" + if (error.isNotBlank()) ": $error" else ""
        else -> "$sz · ссылкой"
    }
}

/** Размер вложения внутри письма после кодирования (base64: 3 байта → 4 знака) — так считает сервер. */
fun encoded(bytes: Long): Long = (bytes * 4 + 2) / 3

/** Папка облака для крупных вложений писем. */
const val CLOUD_DIR = "Вложения из почты"

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
            val api = Session.api ?: return@launch
            // Письмо с файлами или с крупными вложениями исходного — показать ход отправки (иначе молчание на минуты).
            val bigKept = m.existing.any { it.index in m.keep && m.keptViaCloud(it) }
            SendProgress.start(m.subject, isDraft = false, heavy = m.files.isNotEmpty() || bigKept, viaCloud = bigKept || m.viaCloud.isNotEmpty())
            try {
                val r = api.send(m.form(forDraft = false)) { a, b -> if (!SendProgress.isDraft) SendProgress.upload(a, b) }
                if (r.scheduled != null) Toasts.show("Письмо уйдёт ${Fmt.full(r.sendAt)}") else Toasts.show("Письмо отправлено")
                m.answeredUid?.let { u -> MailStore.updateLocal(listOf(u)) { it.copy(answered = true) } }
                MailStore.refreshFolders()
                MailStore.bump()
                if (MailStore.currentFolder?.role in setOf("sent", "drafts")) MailStore.load()
            } catch (e: ApiException) {
                Toasts.error(e)
                m.dirty = true   // письмо снова только в окне — закрытие должно сохранить его в черновики
                Nav.push(ComposeScreen(m.start, m))
            } catch (e: kotlinx.coroutines.CancellationException) {
                throw e
            } catch (e: Throwable) {
                // Файл с устройства не прочитался — письмо не ушло, окно возвращаем с текстом, а не роняем приложение.
                Toasts.show("Письмо не отправлено: не удалось прочитать вложение — приложите файл заново")
                m.dirty = true
                Nav.push(ComposeScreen(m.start, m))
            } finally {
                // Только свою плашку: черновик другого окна мог начать показывать свою.
                if (!SendProgress.isDraft) SendProgress.done()
            }
        }
    }
    if (seconds <= 0 || m.sendAt != null) { doSend(); return }
    Toasts.action("Отправка через $seconds с", "Отменить", seconds, onTimeout = { doSend() }) {
        m.dirty = true
        Nav.push(ComposeScreen(m.start, m))
    }
}

/**
 * «Отправить» нажали, пока грузились файлы, а окно закрыли: обещание в силе — ждём конца загрузки
 * (в [sendScope], он окно переживает) и отправляем.
 */
private fun sendWhenIdle(m: ComposeModel) {
    sendScope.launch {
        while (m.busy) delay(300)
        if (!m.waitUpload) return@launch   // передумали (открыли окно заново и отправили или закрыли иначе)
        m.waitUpload = false
        m.cancelAutosave()
        m.pendingAt?.let { m.sendAt = it }
        m.dirty = false
        sendWithUndo(m)
    }
}

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun ComposeView(m: ComposeModel) {
    val scope = rememberCoroutineScope()
    var menu by remember { mutableStateOf(false) }
    var dialog by remember { mutableStateOf<String?>(null) }
    val scroll = rememberScrollState()
    val density = androidx.compose.ui.platform.LocalDensity.current
    var editorTop by remember { mutableStateOf(0f) }       // px от верха прокручиваемой области
    var viewport by remember { mutableStateOf(0) }         // px видимой части
    val pickFiles = rememberFilePicker(multiple = true) { list ->
        val threshold = m.meta.cloud.thresholdMb.toLong() * 1024 * 1024
        // Больше предела облака файл не уйдёт даже ссылкой — говорим сразу, а не при отправке (как в веб-почте).
        val cap = (if (m.meta.cloud.enabled) m.meta.cloud.maxMb else m.meta.limits.messageMb).toLong() * 1024 * 1024
        list.filter { it.size > cap }.forEach { Toasts.show("«${it.name}» больше ${cap / 1048576} МБ — такой файл не отправить, даже ссылкой") }
        var toDraft = false
        // Порог облака — на файл, предел письма — на все вместе (как в веб-почте): новые файлы идут в письмо
        // от мелких к крупным, пока влезают; что не влезло — уходит ссылкой само, а не тупиком при отправке.
        var room = m.meta.limits.messageMb.toLong() * 1024 * 1024 - encoded(m.inMailSize()) - m.bodySize()
        var rerouted = 0
        list.filter { it.size <= cap }.sortedBy { it.size }.forEach { f ->
            val tooMuch = m.meta.cloud.enabled && f.size < threshold && encoded(f.size) > room
            if (tooMuch) rerouted++
            val link = f.size >= threshold || tooMuch
            when {
                m.meta.cloud.personal && link -> m.uploadToCloud(f, sendScope)
                // Личного облака нет: крупный файл — сразу в хранилище (compose/stage), как в веб-почте.
                m.meta.cloud.canStage && link -> m.stageFile(f, sendScope)
                else -> {
                    m.files.add(f)
                    if (m.meta.cloud.enabled && link) m.viaCloud.add(m.files.lastIndex) else room -= encoded(f.size)
                    toDraft = true
                }
            }
        }
        if (rerouted > 0) Toasts.show("Вместе файлы не помещаются в письмо (предел ${m.meta.limits.messageMb} МБ) — $rerouted " +
            (when { rerouted % 10 == 1 && rerouted % 100 != 11 -> "файл уйдёт"; rerouted % 10 in 2..4 && rerouted % 100 !in 12..14 -> "файла уйдут"; else -> "файлов уйдут" }) + " ссылкой")
        m.dirty = true
        // Загрузка — сразу и не в окне: закроют окно — файл всё равно догрузится в черновик.
        if (toDraft) sendScope.launch { m.saveDraft() }
    }
    // Картинка в текст — как в веб-почте, до 400 КБ (встраивается в письмо); больше — уходит обычным вложением.
    val pickImage = rememberFilePicker(multiple = false, mimes = listOf("image/*")) { list ->
        val f = list.firstOrNull() ?: return@rememberFilePicker
        if (f.size > 400 * 1024) {
            m.files.add(f); m.dirty = true
            Toasts.show("Картинка больше 400 КБ — приложена файлом")
            return@rememberFilePicker
        }
        scope.launch {
            try {
                val bytes = kotlinx.coroutines.withContext(Dispatchers.Default) { f.open().use { it.readByteArray() } }
                m.editor.image("data:${f.mime};base64," + b64(bytes))
            } catch (e: kotlinx.coroutines.CancellationException) {
                throw e
            } catch (e: Throwable) {
                // Картинку не удалось прочитать (доступ к файлу отозван, файл удалён) — сказать, а не упасть.
                Toasts.show("Не удалось открыть «${f.name}»")
            }
        }
    }
    LaunchedEffect(Unit) { if (!m.loaded) m.load() }
    LaunchedEffect(m.editor) {
        m.editor.onEdit = { m.dirty = true }
        m.editor.onNote = { Toasts.show(it) }
        // Курсор всегда в видимой части: поле растёт вместе с текстом, прокручивается весь экран письма.
        m.editor.onCaret = { top, bottom ->
            val t = editorTop + top * density.density
            val b = editorTop + bottom * density.density
            val margin = 24 * density.density
            val target = when {
                b + margin > scroll.value + viewport -> (b + margin - viewport).toInt()
                t - margin < scroll.value -> (t - margin).toInt().coerceAtLeast(0)
                else -> null
            }
            if (target != null) scope.launch { scroll.animateScrollTo(target) }
        }
    }
    // Клавиатура открылась (видимая часть уменьшилась) — вернуть курсор в поле зрения.
    LaunchedEffect(viewport) { if (m.editor.focused) m.editor.reportCaret() }
    // Автосохранение: через 15 секунд после правки (таймер и заливка живут в модели, а не в окне).
    LaunchedEffect(m.dirty, m.editor.html, m.subject, m.to.size) {
        if (m.dirty && m.loaded && m.hasContent) m.scheduleAutosave()
    }
    // Новые адресаты — проверить их домены.
    LaunchedEffect(m.to.size, m.cc.size, m.bcc.size) { if (m.loaded) m.verifyDomains() }

    fun close() {
        m.cancelAutosave()
        // «Отправить» уже нажали, файлы догружаются: обещание держим и без окна.
        if (m.waitUpload) { Nav.pop(); sendWhenIdle(m); return }
        if (m.dirty && m.hasContent) {
            // saveDraft дождётся идущей заливки (и её повторного захода), а не начнёт вторую.
            sendScope.launch { if (m.saveDraft()) Toasts.show("Сохранено в черновиках"); MailStore.refreshFolders() }
        }
        Nav.pop()
    }
    BackHandler(true) { close() }

    /** Отправка с проверками веб-почты: ошибки → предупреждения о доменах → пустая тема. */
    fun send(at: String?, step: Int = 0) {
        if (step == 0) {
            m.problems()?.let { Toasts.show(it); return }
            m.pendingAt = at
        }
        if (step <= 1 && m.warningsFor(m.to + m.cc + m.bcc).isNotEmpty()) { dialog = "confirm-domain"; return }
        if (step <= 2 && m.subject.isBlank()) { dialog = "confirm-subject"; return }
        // Файлы ещё грузятся — письмо уйдёт само, как только они загрузятся (иначе загрузились бы дважды).
        if (m.busy) { m.waitUpload = true; if (m.pendingAt == null) m.pendingAt = at; Toasts.show("Файлы загружаются — письмо уйдёт, как только они загрузятся"); return }
        m.cancelAutosave()
        m.pendingAt?.let { m.sendAt = it }
        m.dirty = false   // иначе таймер автосохранения, взведённый позже, создал бы копию уже отправленного
        Nav.pop()
        sendWithUndo(m)
    }

    // Загрузка закончилась, а «Отправить» уже нажимали — отправляем.
    LaunchedEffect(m.busy) { if (!m.busy && m.waitUpload) { m.waitUpload = false; send(m.pendingAt, 3) } }

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
                    DropdownMenuItem({ Text("Отправить позже…") }, { menu = false; dialog = "later" }, leadingIcon = { Ico("clock") })
                    DropdownMenuItem({ Text(if (m.priority) "✓ Важное" else "Важное") }, { menu = false; m.priority = !m.priority; m.dirty = true }, leadingIcon = { Ico("flag") })
                    DropdownMenuItem({ Text(if (m.receipt) "✓ Уведомить о прочтении" else "Уведомить о прочтении") }, { menu = false; m.receipt = !m.receipt; m.dirty = true }, leadingIcon = { Ico("check") })
                    DropdownMenuItem({ Text(m.remindDays?.let { "Напомнить без ответа: $it дн." } ?: "Напомнить, если не ответят…") }, { menu = false; dialog = "remind" }, leadingIcon = { Ico("bell") })
                    if (m.editor.rich) DropdownMenuItem({ Text("Картинка в текст…") }, { menu = false; pickImage() }, leadingIcon = { Ico("img") })
                    if (m.meta.cloud.personal) DropdownMenuItem({ Text("Файл из облака…") }, { menu = false; Nav.push(su.innotec.mail.ui.cloud.CloudPickerScreen { picked -> m.cloudFiles.addAll(picked); m.dirty = true }) }, leadingIcon = { Ico("cloud") })
                    // В sendScope: закрытое окно не должно обрывать заливку.
                    DropdownMenuItem({ Text("Сохранить черновик") }, { menu = false; sendScope.launch { m.saveDraft(quiet = false) } }, leadingIcon = { Ico("edit") })
                    if (m.draftUid != null) DropdownMenuItem({ Text("Удалить черновик", color = P.no) }, {
                        menu = false
                        val uid = m.draftUid!!
                        val folder = MailStore.folders.firstOrNull { it.role == "drafts" }?.path ?: "Drafts"
                        m.cancelAutosave()
                        m.dirty = false
                        m.unstageAll()
                        Nav.pop()
                        MailStore.act("delete", listOf(uid), folder = folder)
                    }, leadingIcon = { Ico("trash", tint = P.no) })
                }
            }
            // Только самолётик в акцентном квадрате (макет); что это «Отправить» — подсказка для читалки экрана.
            Box(
                Modifier.padding(start = 4.dp, end = 8.dp).size(40.dp).clip(RoundedCornerShape(10.dp)).background(if (m.loaded) P.accent else P.border2)
                    .clickable(enabled = m.loaded) { send(null) }.semantics { contentDescription = "Отправить" }.testTag("send"),
                contentAlignment = Alignment.Center,
            ) { Ico("send", size = 20.dp, tint = P.accentOn) }
        }
        Divider()
        when {
            m.loadError != null -> su.innotec.mail.ui.ErrorBox(m.loadError!!, { m.loadError = null; scope.launch { m.load() } })
            !m.loaded -> Loading()
            // На планшете и ПК строка письма не шире ~900 dp — длинные строки читать тяжело.
            else -> Column(
                Modifier.weight(1f).fillMaxWidth().onSizeChanged { viewport = it.height }.verticalScroll(scroll),
                horizontalAlignment = Alignment.CenterHorizontally,
            ) { Column(Modifier.widthIn(max = 920.dp).fillMaxWidth()) {
                if (m.meta.identities.size > 1) FromRow(m)
                RecipientsField("Кому", m.to, Modifier.testTag("to"), onChange = { m.dirty = true }, others = { m.cc + m.bcc }, warns = m.domainWarn, trailing = {
                    if (!m.showCc) Text("Копия", Modifier.clip(RoundedCornerShape(6.dp)).clickable { m.showCc = true }.padding(8.dp), color = P.accentInk, style = MaterialTheme.typography.labelLarge)
                })
                if (m.showCc) {
                    RecipientsField("Копия", m.cc, onChange = { m.dirty = true }, others = { m.to + m.bcc }, warns = m.domainWarn)
                    RecipientsField("Скрытая", m.bcc, onChange = { m.dirty = true }, others = { m.to + m.cc }, warns = m.domainWarn)
                }
                if (m.warningsFor(m.to + m.cc + m.bcc).isNotEmpty()) DomainNotice(m)
                SubjectRow(m)
                Divider()
                Attachments(m)
                RichEditor(
                    m.editor, P.dark, "Текст письма",
                    Modifier.fillMaxWidth().height(maxOf(220f, m.editor.contentHeight + 8f).dp)
                        .onGloballyPositioned { editorTop = it.positionInParent().y }.testTag("body"),
                    loadResource = { p -> Transfers.inlineResource(p) },
                )
                if (m.tail.isNotBlank()) Tail(m)
                Spacer(Modifier.height(80.dp))
            } }
        }
        // Панель оформления — над клавиатурой, пока пишут текст (как в Gmail и Outlook).
        if (m.loaded && m.editor.rich && m.editor.focused) FormatBar(m.editor, onImage = { pickImage() })
    }

    when (dialog) {
        "later" -> SnoozeDialog(onDismiss = { dialog = null }, title = "Отправить…", weekend = false) { at -> send(at) }
        "remind" -> su.innotec.mail.ui.ChoiceDialog("Напомнить, если не ответят через…", listOf(0, 1, 2, 3, 5, 7, 14), { if (it == 0) "Не напоминать" else "$it ${Fmt.plural(it, "день", "дня", "дней")}" },
            m.remindDays ?: 0, onDismiss = { dialog = null }) { m.remindDays = it.takeIf { d -> d > 0 }; m.dirty = true }
        "confirm-domain" -> su.innotec.mail.ui.ConfirmDialog(
            "Письмо, скорее всего, не дойдёт",
            m.warningsFor(m.to + m.cc + m.bcc).joinToString("\n") { it.text } + "\n\nОтправить всё равно?",
            confirm = "Отправить всё равно", danger = true, onDismiss = { dialog = null },
        ) { send(m.pendingAt, 2) }
        "confirm-subject" -> su.innotec.mail.ui.ConfirmDialog("Отправить письмо без темы?", confirm = "Отправить", onDismiss = { dialog = null }) { send(m.pendingAt, 3) }
    }
}

@OptIn(kotlin.io.encoding.ExperimentalEncodingApi::class)
private fun b64(bytes: ByteArray): String = kotlin.io.encoding.Base64.encode(bytes)

/** Предупреждение о домене адресата и «Исправить на …», как в веб-почте. */
@Composable
private fun DomainNotice(m: ComposeModel) {
    val bad = (m.to + m.cc + m.bcc).mapNotNull { p -> m.domainWarn[p.mail.lowercase()]?.let { p to it } }
    Column(Modifier.fillMaxWidth().background(P.no.copy(alpha = .08f)).padding(horizontal = 16.dp, vertical = 8.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
        bad.forEach { (p, w) ->
            Row(verticalAlignment = Alignment.CenterVertically) {
                Ico("warn", size = 16.dp, tint = P.no); Spacer(Modifier.width(8.dp))
                Text("${p.mail}: ${w.text}", Modifier.weight(1f), style = MaterialTheme.typography.bodySmall, color = P.text)
                if (w.suggestion != null) TextButton(onClick = {
                    val fixed = p.copy(mail = p.mail.substringBefore('@') + "@" + w.suggestion)
                    listOf(m.to, m.cc, m.bcc).forEach { l -> val i = l.indexOf(p); if (i >= 0) l[i] = fixed }
                    m.domainWarn.remove(p.mail.lowercase()); m.dirty = true
                }) { Text("На @${w.suggestion}") }
            }
        }
    }
    Divider()
}

/** Панель оформления для других экранов с редактором (подпись). */
@Composable
fun FormatBarPublic(e: RichEditorState, onImage: () -> Unit) = FormatBar(e, onImage)

/** Кнопки оформления над клавиатурой. Нажатие не уводит курсор из поля. */
@Composable
private fun FormatBar(e: RichEditorState, onImage: () -> Unit) {
    var linkAsk by remember { mutableStateOf(false) }
    val keyboard = androidx.compose.ui.platform.LocalSoftwareKeyboardController.current
    Divider()
    Row(Modifier.fillMaxWidth().background(P.surface2), verticalAlignment = Alignment.CenterVertically) {
        Row(
            Modifier.weight(1f).horizontalScroll(rememberScrollState()).padding(horizontal = 4.dp, vertical = 2.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            FmtBtn("bold", "Жирный", e.bold) { e.cmd("bold") }
            FmtBtn("italic", "Курсив", e.italic) { e.cmd("italic") }
            FmtBtn("underline", "Подчёркнутый", e.underline) { e.cmd("underline") }
            FmtBtn("link", "Ссылка", false) { linkAsk = true }
            FmtBtn("ul", "Список", e.bullets) { e.cmd("insertUnorderedList") }
            FmtBtn("ol", "Нумерованный список", e.numbers) { e.cmd("insertOrderedList") }
            FmtBtn("quote", "Цитата", e.quote) { e.cmd("formatBlock", "blockquote") }
            FmtBtn("img", "Картинка", false) { onImage() }
            FmtBtn("eraser", "Убрать оформление", false) { e.cmd("removeFormat") }
        }
        // Закреплена справа, вне прокрутки: на узком телефоне уезжала за край. На планшете без кнопок «Назад»
        // жест закрывал окно письма — эта кнопка убирает только клавиатуру.
        Box(Modifier.width(1.dp).height(28.dp).background(P.border))
        FmtBtn("keyboard-down", "Скрыть клавиатуру", false) { e.blur(); keyboard?.hide() }
    }
    if (linkAsk) su.innotec.mail.ui.InputDialog("Ссылка", "Адрес", initial = "https://", confirm = "Вставить", keyboard = KeyboardType.Uri, onDismiss = { linkAsk = false }) { raw ->
        var url = raw.trim()
        if (url.isNotEmpty() && url != "https://") {
            // «www.site.ru» без схемы стал бы ссылкой внутрь письма — дописываем https://.
            if (!Regex("^[a-z][a-z0-9+.-]*:", RegexOption.IGNORE_CASE).containsMatchIn(url)) url = "https://" + url.trimStart('/')
            e.link(url)
        }
    }
}

@Composable
private fun FmtBtn(icon: String, label: String, on: Boolean, onClick: () -> Unit) {
    Box(
        Modifier.padding(2.dp).size(44.dp).clip(RoundedCornerShape(8.dp)).background(if (on) P.accent.copy(alpha = .14f) else androidx.compose.ui.graphics.Color.Transparent)
            .clickable(onClickLabel = label) { onClick() }.semantics { contentDescription = label },
        contentAlignment = Alignment.Center,
    ) { Ico(icon, size = 20.dp, tint = if (on) P.accentInk else P.text) }
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
        m.from = if (i.primary) null else i.mail; m.fromChanged(); m.dirty = true
    }
}

/** Строка «Тема» — с подписью слева, как «От» и «Кому». */
@Composable
private fun SubjectRow(m: ComposeModel) {
    Row(Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 12.dp), verticalAlignment = Alignment.CenterVertically) {
        Text("Тема", Modifier.width(64.dp), color = P.muted)
        Box(Modifier.weight(1f)) {
            if (m.subject.isEmpty()) Text("Без темы", color = P.faint, style = MaterialTheme.typography.bodyLarge)
            BasicTextField(
                m.subject, { m.subject = it; m.dirty = true }, Modifier.fillMaxWidth().testTag("subject"), singleLine = true,
                textStyle = MaterialTheme.typography.bodyLarge.copy(color = P.text, fontWeight = FontWeight.Medium),
                cursorBrush = SolidColor(P.accent),
                keyboardOptions = KeyboardOptions(capitalization = KeyboardCapitalization.Sentences, imeAction = ImeAction.Next),
            )
        }
    }
}

/** Поле адресатов: «пузыри» и подсказки из адресной книги и истории переписки. */
@OptIn(ExperimentalLayoutApi::class)
@Composable
fun RecipientsField(
    label: String, list: MutableList<Person>, modifier: Modifier = Modifier, onChange: () -> Unit,
    others: () -> List<Person> = { emptyList() }, warns: Map<String, DomainWarn> = emptyMap(), trailing: @Composable () -> Unit = {},
) {
    var text by remember { mutableStateOf("") }
    var hints by remember { mutableStateOf<List<Suggestion>>(emptyList()) }
    val scope = rememberCoroutineScope()
    var job by remember { mutableStateOf<Job?>(null) }
    /** Повтор не добавляем, но и не молчим: иначе кажется, что вставка не сработала (как в веб-почте). */
    fun add(p: Person) {
        when {
            list.any { it.mail.equals(p.mail, true) } -> Toasts.show("${p.mail} уже в этом поле")
            others().any { it.mail.equals(p.mail, true) } -> Toasts.show("${p.mail} уже указан в другом поле — второй раз письмо не нужно")
            else -> list.add(p)
        }
    }
    fun commit(raw: String) {
        raw.split(',', ';', ' ').map { it.trim().trim('<', '>') }.filter { it.contains('@') }.forEach { a -> add(Person("", a)) }
        text = ""; hints = emptyList(); onChange()
    }
    Column(modifier) {
        Row(Modifier.fillMaxWidth().padding(start = 16.dp, end = 8.dp, top = 6.dp, bottom = 6.dp), verticalAlignment = Alignment.CenterVertically) {
            Text(label, Modifier.width(64.dp), color = P.muted)
            FlowRow(Modifier.weight(1f), horizontalArrangement = Arrangement.spacedBy(4.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                list.toList().forEach { p ->
                    val bad = warns[p.mail.lowercase()] != null
                    Row(Modifier.clip(RoundedCornerShape(50)).background(if (bad) P.no.copy(alpha = .14f) else P.chipOff).padding(start = 10.dp, end = 4.dp, top = 4.dp, bottom = 4.dp), verticalAlignment = Alignment.CenterVertically) {
                        if (bad) { Ico("warn", size = 14.dp, tint = P.no); Spacer(Modifier.width(4.dp)) }
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
        // Подсказки — выпадающим списком поверх страницы (раньше строками в самой форме: всё ниже съезжало).
        // focusable = false: поле ввода не теряет фокус, клавиатура остаётся.
        Box(Modifier.padding(start = 72.dp)) {
            DropdownMenu(
                expanded = hints.isNotEmpty(), onDismissRequest = { hints = emptyList() },
                properties = androidx.compose.ui.window.PopupProperties(focusable = false),
                modifier = Modifier.widthIn(min = 280.dp, max = 520.dp).testTag("recipient-hints"),
            ) {
                hints.forEach { h ->
                    DropdownMenuItem(
                        text = {
                            Column {
                                if (h.name.isNotBlank()) Text(h.name, style = MaterialTheme.typography.bodyMedium, maxLines = 1, overflow = TextOverflow.Ellipsis)
                                Text(h.mail, style = MaterialTheme.typography.bodySmall, color = P.muted, maxLines = 1, overflow = TextOverflow.Ellipsis)
                            }
                        },
                        onClick = { add(Person(h.name, h.mail)); text = ""; hints = emptyList(); onChange() },
                        leadingIcon = { su.innotec.mail.ui.Avatar(h.name.ifBlank { h.mail }, h.mail, 28.dp) },
                    )
                }
            }
        }
        Divider()
    }
}


/**
 * Вложения — чипами (макет): имя, размер, крестик; у крупных «ссылкой» — ход загрузки. Касание чипа своего
 * файла — меню «Ссылкой / Вложением»; вложение исходного письма — включить/выключить.
 */
@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun Attachments(m: ComposeModel) {
    if (m.files.isEmpty() && m.existing.isEmpty() && m.cloudFiles.isEmpty() && m.attachMessages.isEmpty() && m.cloudJobs.isEmpty() && m.staged.isEmpty()) return
    Column(Modifier.padding(horizontal = 12.dp, vertical = 8.dp)) {
        FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
            m.attachMessages.toList().forEach { a -> AttChip("mail", a.name ?: "письмо", "письмо целиком", onRemove = { m.attachMessages.remove(a); m.dirty = true }) }
            m.existing.toList().forEach { a ->
                val on = a.index in m.keep
                val cloud = m.keptViaCloud(a)
                val fromDraft = m.sourceFolder != null && m.sourceFolder == MailStore.folders.firstOrNull { it.role == "drafts" }?.path
                AttChip(
                    if (!on) "plus" else if (cloud) "cloud" else fileIcon(a.name, a.type), a.name,
                    Fmt.size(a.size) + when {
                        !on -> " · не приложено"
                        cloud -> " · ссылкой (крупнее ${m.meta.cloud.thresholdMb} МБ)"
                        fromDraft -> ""
                        else -> " · из исходного письма"
                    },
                    dim = !on,
                    onClick = { if (on) m.keep.remove(a.index) else m.keep.add(a.index); m.dirty = true },
                    onRemove = if (on) ({ m.keep.remove(a.index); m.dirty = true }) else null,
                )
            }
            m.files.toList().forEachIndexed { i, f ->
                val cloud = i in m.viaCloud
                val done = m.uploadedOf(f)
                var menu by remember(f) { mutableStateOf(false) }
                Box {
                    AttChip(
                        if (cloud) "cloud" else fileIcon(f.name, f.mime), f.name,
                        // Каждый этап — словами: пользователь видит, что происходит, а не гадает.
                        when {
                            done == null -> Fmt.size(f.size) + (if (cloud) " · ссылкой" else "") + " · ждёт"
                            !m.uploadStarted -> Fmt.size(f.size) + " · связь с сервером…"
                            done >= f.size -> Fmt.size(f.size) + " · сервер сохраняет…"
                            else -> Fmt.size(f.size) + (if (cloud) " · ссылкой" else "") + " · ${(done * 100 / f.size.coerceAtLeast(1)).toInt()}%"
                        },
                        progress = if (done != null && f.size > 0) (done.toFloat() / f.size).coerceIn(0f, 1f) else null,
                        onClick = if (m.meta.cloud.enabled) ({ menu = true }) else null,
                        onRemove = {
                            m.files.removeAt(i)
                            val shifted = m.viaCloud.filter { it != i }.map { if (it > i) it - 1 else it }
                            m.viaCloud.clear(); m.viaCloud.addAll(shifted); m.dirty = true
                        },
                    )
                    DropdownMenu(menu, { menu = false }) {
                        DropdownMenuItem({ Text(if (cloud) "Вложением в письме" else "Ссылкой (через хранилище)") }, { menu = false; if (cloud) m.viaCloud.remove(i) else m.viaCloud.add(i); m.dirty = true },
                            leadingIcon = { Ico(if (cloud) "clip" else "cloud") })
                    }
                }
            }
            m.cloudJobs.toList().forEach { j ->
                val f = j.file
                AttChip("cloud", f.name, when {
                    !j.started -> "${Fmt.size(f.size)} · связь с сервером…"
                    j.linking -> "${Fmt.size(f.size)} · в облаке, готовлю ссылку…"
                    else -> "${Fmt.size(f.size)} · в облако · ${(j.done * 100 / f.size.coerceAtLeast(1)).toInt()}%"
                }, progress = if (f.size > 0) (j.done.toFloat() / f.size).coerceIn(0f, 1f) else null)
            }
            m.staged.toList().forEach { s ->
                AttChip(
                    if (s.state == "error") "warn" else "cloud", s.name, stageLabel(s.state, s.size, s.pct, s.error),
                    bad = s.state == "error",
                    progress = if (s.state == "upload" && s.size > 0) (s.sent.toFloat() / s.size).coerceIn(0f, 1f) else null,
                    onRemove = { m.dropStaged(s) },
                )
            }
            m.cloudFiles.toList().forEach { c -> AttChip("cloud", c.name.ifBlank { c.path.substringAfterLast('/') }, Fmt.size(c.size) + " · из облака, ссылкой", onRemove = { m.cloudFiles.remove(c); m.dirty = true }) }
        }
        // Заранее, а не только при отправке: 60 % предела — письмо может не пройти у получателя.
        val limit = m.meta.limits.messageMb.toLong() * 1024 * 1024
        val used = m.inMailSize()
        if (limit > 0 && used > limit * 6 / 10) Text(
            if (encoded(used) > limit) "Вложения ${Fmt.size(used)} — больше предела ${m.meta.limits.messageMb} МБ" + (if (m.meta.cloud.enabled) ": отметьте крупные «Ссылкой»" else "")
            else "Вложения ${Fmt.size(used)} из ${m.meta.limits.messageMb} МБ — у некоторых получателей предел меньше" + (if (m.meta.cloud.enabled) ", крупные лучше «Ссылкой»" else ""),
            Modifier.padding(horizontal = 4.dp, vertical = 6.dp), style = MaterialTheme.typography.bodySmall, color = if (used > limit) P.no else P.muted,
        )
    }
    Divider()
}

/** Чип вложения: значок (или кольцо хода загрузки), имя, подпись, крестик. */
@Composable
private fun AttChip(
    icon: String, name: String, note: String, onRemove: (() -> Unit)? = null, onClick: (() -> Unit)? = null,
    progress: Float? = null, bad: Boolean = false, dim: Boolean = false,
) {
    Row(
        Modifier.widthIn(max = 280.dp).clip(RoundedCornerShape(10.dp)).background(if (bad) P.noSoft else P.surface2).border(1.dp, if (bad) P.noSoft else P.border, RoundedCornerShape(10.dp))
            .let { if (onClick != null) it.clickable(onClick = onClick) else it }
            .padding(start = 10.dp, end = if (onRemove != null) 2.dp else 10.dp, top = 6.dp, bottom = 6.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        if (progress != null) CircularProgressIndicator(progress = { progress }, modifier = Modifier.size(18.dp), strokeWidth = 2.dp, color = P.accent, trackColor = P.border)
        else Ico(icon, size = 18.dp, tint = when { bad -> P.no; dim -> P.faint; else -> P.accentInk })
        Spacer(Modifier.width(8.dp))
        Column(Modifier.weight(1f, fill = false)) {
            Text(name, maxLines = 1, overflow = TextOverflow.Ellipsis, style = MaterialTheme.typography.bodyMedium, color = if (dim) P.muted else P.text)
            Text(note, maxLines = 1, overflow = TextOverflow.Ellipsis, style = MaterialTheme.typography.bodySmall, color = when { bad -> P.no; progress != null -> P.accentInk; else -> P.faint })
        }
        if (onRemove != null) {
            Box(Modifier.padding(start = 4.dp).size(28.dp).clip(CircleShape).clickable(onClick = onRemove), contentAlignment = Alignment.Center) { Ico("x", size = 14.dp, tint = P.muted, contentDescription = "Убрать") }
        }
    }
}

/** Подпись и цитата — свёрнутой плашкой «Иванова Мария, 12:17 · Развернуть»; галочкой можно не отправлять. */
@Composable
private fun Tail(m: ComposeModel) {
    var open by remember { mutableStateOf(false) }
    val isQuote = m.tail.contains("class=\"quote\"") || m.tail.contains("class=\"fwd\"")
    Column(Modifier.padding(horizontal = 12.dp, vertical = 4.dp)) {
        Row(
            Modifier.fillMaxWidth().clip(RoundedCornerShape(10.dp)).background(P.surface2).border(1.dp, P.border, RoundedCornerShape(10.dp))
                .clickable { open = !open }.padding(start = 4.dp, end = 4.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Checkbox(m.includeTail, { m.includeTail = it; m.dirty = true })
            Ico("quote", size = 16.dp, tint = P.muted); Spacer(Modifier.width(8.dp))
            Column(Modifier.weight(1f)) {
                Text(if (isQuote) m.quoteTitle ?: "Исходное письмо" else "Подпись", style = MaterialTheme.typography.bodyMedium, color = if (m.includeTail) P.text else P.muted, maxLines = 1, overflow = TextOverflow.Ellipsis)
                if (isQuote && m.sig.isNotBlank()) Text("и подпись", style = MaterialTheme.typography.bodySmall, color = P.faint)
            }
            TextButton(onClick = { open = !open }) { Text(if (open) "Свернуть" else "Развернуть", color = P.link) }
        }
        if (open) Box(Modifier.fillMaxWidth().padding(top = 4.dp).clip(RoundedCornerShape(8.dp)).border(1.dp, P.border, RoundedCornerShape(8.dp))) {
            HtmlView(m.tail, P.dark, Modifier.fillMaxWidth(), onLink = {}, loadResource = { p -> Transfers.inlineResource(p) })
        }
    }
}
