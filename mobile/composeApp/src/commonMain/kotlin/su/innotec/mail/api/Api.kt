package su.innotec.mail.api

import io.ktor.client.plugins.onUpload
import io.ktor.client.HttpClient
import io.ktor.client.call.body
import io.ktor.client.plugins.timeout
import io.ktor.client.request.HttpRequestBuilder
import io.ktor.client.request.forms.InputProvider
import io.ktor.client.request.forms.MultiPartFormDataContent
import io.ktor.client.request.forms.formData
import io.ktor.client.request.header
import io.ktor.client.request.prepareRequest
import io.ktor.client.request.request
import io.ktor.client.request.setBody
import io.ktor.client.request.url
import io.ktor.client.statement.HttpResponse
import io.ktor.client.statement.bodyAsChannel
import io.ktor.client.statement.bodyAsText
import io.ktor.http.ContentType
import io.ktor.http.Headers
import io.ktor.http.HttpHeaders
import io.ktor.http.HttpMethod
import io.ktor.http.contentType
import io.ktor.http.isSuccess
import io.ktor.utils.io.ByteReadChannel
import kotlinx.io.Source
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.put
import kotlinx.serialization.json.putJsonArray

val ApiJson = Json {
    ignoreUnknownKeys = true
    isLenient = true
    coerceInputValues = true
    explicitNulls = false
    encodeDefaults = true
}

/** Ошибка сервера: текст для человека (как в веб-почте) и код из MailException. */
class ApiException(val status: Int, val code: String?, override val message: String) : Exception(message) {
    /** Токен больше не годится — приложение уходит на вход. */
    val isAuth: Boolean get() = status == 401
    val isNetwork: Boolean get() = status == 0
}

/** Как encodeURIComponent: папки с «/» и кириллицей идут одним сегментом пути. */
fun enc(s: String): String {
    val sb = StringBuilder()
    for (b in s.encodeToByteArray()) {
        val c = b.toInt() and 0xff
        val ch = c.toChar()
        if (ch in 'A'..'Z' || ch in 'a'..'z' || ch in '0'..'9' || ch in "-_.!~*'()") sb.append(ch)
        else {
            sb.append('%'); sb.append("0123456789ABCDEF"[c shr 4]); sb.append("0123456789ABCDEF"[c and 15])
        }
    }
    return sb.toString()
}

/** Файл с устройства для загрузки: открывается заново на каждую попытку. */
class LocalFile(
    val name: String,
    val size: Long,
    val mime: String = "application/octet-stream",
    val open: () -> Source,
)

/** Поля окна «Написать» — как composeForm() веб-почты. */
data class ComposeForm(
    val from: String? = null,
    val to: String = "",
    val cc: String = "",
    val bcc: String = "",
    val subject: String = "",
    val html: String = "",
    val inReplyTo: String? = null,
    val references: String? = null,
    val answeredFolder: String? = null,
    val answeredUid: Long? = null,
    val sourceFolder: String? = null,
    val sourceUid: Long? = null,
    val draftUid: Long? = null,
    val sendAt: String? = null,
    val remindDays: Int? = null,
    val keepAttachments: Boolean = false,
    val keepIndexes: List<Int> = emptyList(),
    val priority: Boolean = false,
    val receipt: Boolean = false,
    val draftKeepFiles: Boolean = false,
    val files: List<LocalFile> = emptyList(),
    /** Номера файлов из files, которые уйдут ссылкой (большие вложения). */
    val cloud: List<Int> = emptyList(),
    val cloudFiles: List<CloudFileRef> = emptyList(),
    val attachMessages: List<AttachedMessageRef> = emptyList(),
)

data class AttachedMessageRef(val folder: String, val uid: Long, val name: String? = null)

class Api(
    private val http: HttpClient,
    /** https://mail.example.ru — без завершающей косой. */
    val origin: String,
    private val token: () -> String?,
) {
    private val v1 = "$origin/api/v1"

    // ---------- низкий уровень ----------

    private suspend fun raw(method: HttpMethod, path: String, body: Any? = null, timeoutMs: Long? = null, block: HttpRequestBuilder.() -> Unit = {}): HttpResponse {
        val resp = try {
            http.request {
                this.method = method
                url(if (path.startsWith("http")) path else v1 + path)
                header(HttpHeaders.Accept, "application/json")
                token()?.let { header(HttpHeaders.Authorization, "Bearer $it") }
                when (body) {
                    null -> {}
                    is MultiPartFormDataContent -> setBody(body)
                    is JsonElement -> { contentType(ContentType.Application.Json); setBody(body.toString()) }
                    is String -> { contentType(ContentType.Application.Json); setBody(body) }
                    else -> error("unsupported body")
                }
                if (timeoutMs != null) timeout { requestTimeoutMillis = timeoutMs; socketTimeoutMillis = timeoutMs }
                block()
            }
        } catch (e: ApiException) {
            throw e
        } catch (e: Exception) {
            throw ApiException(0, "network", "Нет связи с сервером. Проверьте интернет.")
        }
        if (!resp.status.isSuccess()) throw errorOf(resp)
        return resp
    }

    private suspend fun errorOf(resp: HttpResponse): ApiException {
        val text = runCatching { resp.bodyAsText() }.getOrDefault("")
        val body = runCatching { ApiJson.decodeFromString(ErrorBody.serializer(), text) }.getOrNull()
        val all = body?.errors?.values?.flatMap { (it as? JsonArray)?.mapNotNull { e -> (e as? JsonPrimitive)?.content } ?: emptyList() } ?: emptyList()
        val msg = body?.message?.takeIf { it.isNotBlank() }
            ?: all.joinToString(" ").takeIf { it.isNotBlank() }
            ?: when (resp.status.value) {
                401 -> "Вход устарел — войдите заново."
                403 -> "Нет доступа."
                404 -> "Не найдено."
                413 -> "Слишком большой файл."
                429 -> "Слишком часто. Подождите минуту."
                in 500..599 -> "Сервер не ответил. Попробуйте позже."
                else -> "Ошибка ${resp.status.value}"
            }
        return ApiException(resp.status.value, body?.code, msg)
    }

    private suspend inline fun <reified T> get(path: String): T = raw(HttpMethod.Get, path).body()
    private suspend inline fun <reified T> send(method: HttpMethod, path: String, body: JsonElement? = null, timeoutMs: Long? = null): T =
        raw(method, path, body ?: JsonObject(emptyMap()), timeoutMs).body()
    private suspend inline fun <reified T> post(path: String, body: JsonElement? = null, timeoutMs: Long? = null): T = send(HttpMethod.Post, path, body, timeoutMs)
    private suspend fun postOk(path: String, body: JsonElement? = null) { raw(HttpMethod.Post, path, body ?: JsonObject(emptyMap())) }
    private suspend fun deleteOk(path: String, body: JsonElement? = null) { raw(HttpMethod.Delete, path, body) }

    private inline fun <reified T> json(v: T): JsonElement = ApiJson.encodeToJsonElement(kotlinx.serialization.serializer<T>(), v)

    /** Абсолютный адрес ресурса API (вложение, файл облака) — для загрузчика. */
    fun url(path: String): String = v1 + path

    /** Скачать в поток: ответ читается по мере поступления (большие вложения, видео). */
    suspend fun <R> download(path: String, onResponse: suspend (contentLength: Long?, contentType: String?, fileName: String?, channel: ByteReadChannel) -> R): R {
        val stmt = http.prepareRequest {
            method = HttpMethod.Get
            url(if (path.startsWith("http")) path else v1 + path)
            token()?.let { header(HttpHeaders.Authorization, "Bearer $it") }
            timeout { requestTimeoutMillis = 3_600_000; socketTimeoutMillis = 120_000 }
        }
        return try {
            stmt.execute { resp ->
                if (!resp.status.isSuccess()) throw errorOf(resp)
                val cd = resp.headers[HttpHeaders.ContentDisposition]
                onResponse(resp.headers[HttpHeaders.ContentLength]?.toLongOrNull(), resp.headers[HttpHeaders.ContentType], fileNameOf(cd), resp.bodyAsChannel())
            }
        } catch (e: ApiException) {
            throw e
        } catch (e: Exception) {
            throw ApiException(0, "network", "Нет связи с сервером. Проверьте интернет.")
        }
    }

    // ---------- вход ----------

    suspend fun discover(): Discovery = raw(HttpMethod.Get, "$origin/.well-known/mailadmin").body()
    suspend fun login(req: LoginRequest): LoginResponse = post("/login", json(req))
    suspend fun loginCode(req: LoginCodeRequest): LoginResponse = post("/login/code", json(req))
    suspend fun me(): Me = get("/me")
    suspend fun composeMeta(): ComposeMeta = get("/compose-meta")
    suspend fun logout() = deleteOk("/session")
    suspend fun devices(): List<Device> = get("/devices")
    suspend fun revokeDevice(id: Long) = deleteOk("/devices/$id")
    suspend fun registerPush(kind: String, token: String) = postOk("/devices/push", buildJsonObject { put("kind", kind); put("token", token) })

    // ---------- папки и письма ----------

    suspend fun folders(): List<Folder> = get("/folders")
    suspend fun status(folder: String): Status = get("/status?folder=${enc(folder)}")
    suspend fun createFolder(name: String, parent: String?) = postOk("/folders", buildJsonObject { put("name", name); parent?.let { put("parent", it) } })
    suspend fun renameFolder(path: String, name: String) { raw(HttpMethod.Patch, "/folders/${enc(path)}", buildJsonObject { put("name", name) }) }
    suspend fun deleteFolder(path: String) = deleteOk("/folders/${enc(path)}")
    suspend fun emptyFolder(path: String) = postOk("/folders/${enc(path)}/empty")
    suspend fun folderShares(path: String): FolderShares = get("/folders/${enc(path)}/shares")
    suspend fun shareFolder(path: String, with: String, level: String): FolderShares = post("/folders/${enc(path)}/shares", buildJsonObject { put("with", with); put("level", level) })
    suspend fun unshareFolder(path: String, with: String): FolderShares = send(HttpMethod.Delete, "/folders/${enc(path)}/shares", buildJsonObject { put("with", with) })

    suspend fun list(
        folder: String,
        offset: Int = 0,
        limit: Int = 50,
        filter: String = "all",
        q: String = "",
        scope: String = "folder",
        sort: String = "date",
        withFolders: Boolean = false,
    ): MessageList {
        val p = buildString {
            append("?offset=$offset&limit=$limit&filter=${enc(filter)}")
            if (q.isNotBlank()) append("&q=${enc(q)}")
            if (scope == "all") append("&scope=all")
            if (sort != "date") append("&sort=${enc(sort)}")
            if (!withFolders) append("&folders=0")
        }
        return get("/list/${enc(folder)}$p")
    }

    suspend fun listAt(folder: String, date: String, filter: String = "all", q: String = ""): Int {
        val o: JsonObject = get("/list-at/${enc(folder)}?date=${enc(date)}&filter=${enc(filter)}" + (if (q.isNotBlank()) "&q=${enc(q)}" else ""))
        return (o["offset"] as? JsonPrimitive)?.content?.toIntOrNull() ?: 0
    }

    suspend fun message(folder: String, uid: Long, peek: Boolean = false): Message = get("/message/${enc(folder)}/$uid" + if (peek) "?peek=1" else "")
    suspend fun thread(folder: String, uid: Long): Thread = get("/message/${enc(folder)}/$uid/thread")
    suspend fun attachedMessage(folder: String, uid: Long, index: Int): Message = get("/message/${enc(folder)}/$uid/attachment/$index/message")
    fun attachmentPath(folder: String, uid: Long, index: Int, inline: Boolean = false) = "/message/${enc(folder)}/$uid/attachment/$index" + if (inline) "?inline=1" else ""
    fun attachmentPreviewPath(folder: String, uid: Long, index: Int) = "/message/${enc(folder)}/$uid/attachment/$index/preview.pdf"
    fun attachedPartPath(folder: String, uid: Long, index: Int, sub: Int) = "/message/${enc(folder)}/$uid/attachment/$index/message/$sub"
    fun attachmentsZipPath(folder: String, uid: Long) = "/message/${enc(folder)}/$uid/attachments.zip"
    fun cloudZipPath(folder: String, uid: Long) = "/message/${enc(folder)}/$uid/cloud.zip"
    fun rawPath(folder: String, uid: Long) = "/message/${enc(folder)}/$uid/raw"

    suspend fun action(req: ActionRequest): ActionResult = post("/action", json(req), timeoutMs = 180_000)

    suspend fun markSender(kind: String, match: String, value: String, resort: Boolean = true, folder: String? = null): JsonElement =
        post("/sender/mark", buildJsonObject { put("kind", kind); put("match", match); put("value", value); put("resort", resort); folder?.let { put("folder", it) } })

    // ---------- написать ----------

    private fun composeBody(f: ComposeForm): MultiPartFormDataContent = MultiPartFormDataContent(formData {
        fun opt(k: String, v: Any?) { if (v != null && v.toString().isNotEmpty()) append(k, v.toString()) }
        opt("from", f.from); opt("to", f.to); opt("cc", f.cc); opt("bcc", f.bcc); opt("subject", f.subject); opt("html", f.html)
        opt("inReplyTo", f.inReplyTo); opt("references", f.references); opt("answeredFolder", f.answeredFolder); opt("answeredUid", f.answeredUid)
        opt("sourceFolder", f.sourceFolder); opt("sourceUid", f.sourceUid); opt("draftUid", f.draftUid); opt("sendAt", f.sendAt); opt("remindDays", f.remindDays)
        if (f.keepAttachments) append("keepAttachments", "1")
        if (f.priority) append("priority", "1")
        if (f.receipt) append("receipt", "1")
        if (f.draftKeepFiles) append("draftKeepFiles", "1")
        f.files.forEach { file ->
            append("files[]", InputProvider(file.size) { file.open() }, Headers.build {
                append(HttpHeaders.ContentType, file.mime)
                append(HttpHeaders.ContentDisposition, "filename=\"${file.name.replace("\"", "")}\"")
            })
        }
        f.cloud.forEach { append("cloud[]", it.toString()) }
        f.keepIndexes.forEach { append("keepIndexes[]", it.toString()) }
        f.cloudFiles.forEachIndexed { i, c ->
            append("cloudFiles[$i][path]", c.path)
            if (c.name.isNotEmpty()) append("cloudFiles[$i][name]", c.name)
            append("cloudFiles[$i][size]", c.size.toString())
        }
        f.attachMessages.forEachIndexed { i, m ->
            append("attachMessages[$i][folder]", m.folder)
            append("attachMessages[$i][uid]", m.uid.toString())
            m.name?.let { append("attachMessages[$i][name]", it) }
        }
    })

    /** [progress] — сколько байт письма ушло на сервер и сколько всего (для плашки «Отправляется…»). */
    suspend fun send(f: ComposeForm, progress: ((Long, Long?) -> Unit)? = null): SendResult =
        raw(HttpMethod.Post, "/send", composeBody(f), timeoutMs = 1_800_000) { progress?.let { p -> onUpload { sent, total -> p(sent, total) } } }.body()
    suspend fun saveDraft(f: ComposeForm, progress: ((Long, Long?) -> Unit)? = null): SendResult =
        raw(HttpMethod.Post, "/draft", composeBody(f), timeoutMs = 1_800_000) { progress?.let { p -> onUpload { sent, total -> p(sent, total) } } }.body()
    suspend fun openDraft(uid: Long): Draft = get("/draft/$uid")
    suspend fun outbox(): List<OutboxItem> = get("/outbox")
    suspend fun cancelOutbox(id: Long) = deleteOk("/outbox/$id")
    suspend fun suggest(q: String): List<Suggestion> = get("/suggest?q=${enc(q)}")
    suspend fun checkDomain(domain: String): JsonElement = get("/check-domain?domain=${enc(domain)}")

    // ---------- настройки, метки, правила ----------

    suspend fun settings(): Settings = get("/settings")
    suspend fun saveSettings(patch: JsonObject): JsonElement = send(HttpMethod.Put, "/settings", patch)
    suspend fun labels(): List<Label> = get("/labels")
    suspend fun createLabel(name: String, color: String): JsonElement = post("/labels", buildJsonObject { put("name", name); put("color", color) })
    suspend fun updateLabel(id: Long, name: String, color: String) { raw(HttpMethod.Patch, "/labels/$id", buildJsonObject { put("name", name); put("color", color) }) }
    suspend fun deleteLabel(id: Long) = deleteOk("/labels/$id")
    suspend fun rules(): Rules = get("/rules")
    suspend fun saveRules(rules: List<Rule>, autoreply: AutoReply?): JsonElement =
        send(HttpMethod.Put, "/rules", buildJsonObject { put("rules", json(rules)); put("autoreply", autoreply?.let { json(it) } ?: JsonPrimitive(null as String?)) })
    suspend fun applyRules(): JsonElement = post("/rules/apply", timeoutMs = 300_000)

    // ---------- безопасность ----------

    suspend fun security(): Security = get("/security")
    suspend fun twofaSetup(): JsonObject = post("/security/2fa/setup")
    suspend fun twofaEnable(code: String) = postOk("/security/2fa/enable", buildJsonObject { put("code", code) })
    suspend fun twofaDisable(password: String) = postOk("/security/2fa/disable", buildJsonObject { put("password", password) })
    suspend fun createAppPassword(name: String, password: String): AppPassword = post("/security/app-passwords", buildJsonObject { put("name", name); put("password", password) })
    suspend fun deleteAppPassword(id: Long) = deleteOk("/security/app-passwords/$id")
    suspend fun kickSession(id: String): SessionsResult = post("/security/sessions/kick", buildJsonObject { put("id", id) })
    suspend fun kickOthers(): SessionsResult = post("/security/sessions/kick-others")

    // ---------- карантин, обращения ----------

    suspend fun quarantine(): List<QuarantineItem> = get("/quarantine")
    suspend fun quarantineRelease(id: String): QuarantineResult = post("/quarantine/${enc(id)}/release")
    suspend fun quarantineDelete(id: String): QuarantineResult = send(HttpMethod.Delete, "/quarantine/${enc(id)}")

    suspend fun tickets(): Tickets = get("/feedback")
    suspend fun ticketsUnread(): Int = (get<JsonObject>("/feedback/unread")["unread"] as? JsonPrimitive)?.content?.toIntOrNull() ?: 0
    suspend fun ticketPoll(id: Long, after: Long = 0): TicketPoll = get("/feedback/$id?after=$after")
    fun ticketFilePath(id: Long, message: Long) = "/feedback/$id/file/$message"

    suspend fun createTicket(kind: String, text: String, subject: String?, context: String?, file: LocalFile?): Created =
        raw(HttpMethod.Post, "/feedback", MultiPartFormDataContent(formData {
            append("kind", kind); append("text", text)
            subject?.takeIf { it.isNotBlank() }?.let { append("subject", it) }
            context?.let { append("context", it) }
            file?.let { f -> append("file", InputProvider(f.size) { f.open() }, Headers.build { append(HttpHeaders.ContentType, f.mime); append(HttpHeaders.ContentDisposition, "filename=\"${f.name}\"") }) }
        }), timeoutMs = 300_000).body()

    suspend fun replyTicket(id: Long, text: String, file: LocalFile?): TicketReply =
        raw(HttpMethod.Post, "/feedback/$id/reply", MultiPartFormDataContent(formData {
            append("text", text)
            file?.let { f -> append("file", InputProvider(f.size) { f.open() }, Headers.build { append(HttpHeaders.ContentType, f.mime); append(HttpHeaders.ContentDisposition, "filename=\"${f.name}\"") }) }
        }), timeoutMs = 300_000).body()

    // ---------- контакты ----------

    suspend fun books(): List<AddressBook> = get("/contacts/books")
    suspend fun contacts(book: String? = null, q: String = ""): List<Contact> {
        val p = listOfNotNull(book?.takeIf { it.isNotEmpty() }?.let { "book=${enc(it)}" }, q.takeIf { it.isNotBlank() }?.let { "q=${enc(it)}" })
        return get("/contacts" + if (p.isEmpty()) "" else "?" + p.joinToString("&"))
    }
    suspend fun contact(book: String, uri: String): Contact = get("/contacts/${enc(book)}/${enc(uri)}")
    suspend fun createContact(c: ContactInput): Contact = post("/contacts", json(c))
    suspend fun updateContact(book: String, uri: String, c: ContactInput): Contact = send(HttpMethod.Put, "/contacts/${enc(book)}/${enc(uri)}", json(c))
    suspend fun deleteContact(book: String, uri: String) = deleteOk("/contacts/${enc(book)}/${enc(uri)}")
    suspend fun copyContact(book: String, uri: String, to: String = "personal"): JsonElement = post("/contacts/${enc(book)}/${enc(uri)}/copy", buildJsonObject { put("to", to) })
    suspend fun suggestContact(book: String, uri: String, note: String): JsonElement = post("/contacts/${enc(book)}/${enc(uri)}/suggest", buildJsonObject { put("note", note) })
    suspend fun contactGroups(): List<ContactGroup> = get("/contacts/groups")
    suspend fun contactHistory(): List<HistoryEntry> = get("/contacts/history")
    suspend fun forgetHistory(email: String) = deleteOk("/contacts/history/${enc(email)}")
    suspend fun importContacts(file: LocalFile, book: String = "personal"): JsonElement =
        raw(HttpMethod.Post, "/contacts/import", MultiPartFormDataContent(formData {
            append("book", book)
            append("file", InputProvider(file.size) { file.open() }, Headers.build { append(HttpHeaders.ContentType, file.mime); append(HttpHeaders.ContentDisposition, "filename=\"${file.name}\"") })
        })).body()
    fun contactsExportPath(book: String? = null) = "/contacts/export" + (book?.let { "?book=${enc(it)}" } ?: "")

    // ---------- календарь, задачи ----------

    suspend fun calendars(): List<Calendar> = get("/calendars")
    suspend fun createCalendar(name: String, color: String): JsonElement = post("/calendars", buildJsonObject { put("name", name); put("color", color) })
    suspend fun updateCalendar(uri: String, name: String?, color: String?) { raw(HttpMethod.Patch, "/calendars/${enc(uri)}", buildJsonObject { name?.let { put("name", it) }; color?.let { put("color", it) } }) }
    suspend fun deleteCalendar(uri: String) = deleteOk("/calendars/${enc(uri)}")
    suspend fun calendarShares(uri: String): List<CalendarShare> = get("/calendars/${enc(uri)}/shares")
    /** Календарь файлом .ics (для «Скачать .ics»). */
    fun calendarExportPath(uri: String) = "/calendars/${enc(uri)}/export"
    suspend fun shareCalendar(uri: String, with: String, level: String) = postOk("/calendars/${enc(uri)}/shares", buildJsonObject { put("with", with); put("level", level) })
    suspend fun unshareCalendar(uri: String, with: String) = deleteOk("/calendars/${enc(uri)}/shares", buildJsonObject { put("with", with) })
    suspend fun events(from: String, to: String, calendars: List<String> = emptyList()): List<CalEvent> =
        get("/events?from=${enc(from)}&to=${enc(to)}" + if (calendars.isEmpty()) "" else "&calendars=${enc(calendars.joinToString(","))}")
    suspend fun event(calendar: String, id: String): CalEvent = get("/events/${enc(calendar)}/${enc(id)}")
    suspend fun createEvent(e: EventInput): JsonElement = post("/events", json(e))
    suspend fun updateEvent(calendar: String, id: String, e: EventInput): JsonElement = send(HttpMethod.Put, "/events/${enc(calendar)}/${enc(id)}", json(e))
    suspend fun deleteEvent(calendar: String, id: String, occurrence: String? = null) =
        deleteOk("/events/${enc(calendar)}/${enc(id)}", occurrence?.let { buildJsonObject { put("occurrence", it) } })
    suspend fun respond(calendar: String, id: String, status: String) = postOk("/events/${enc(calendar)}/${enc(id)}/respond", buildJsonObject { put("status", status) })
    suspend fun freebusy(users: List<String>, from: String, to: String): JsonElement = get("/freebusy?users=${enc(users.joinToString(","))}&from=${enc(from)}&to=${enc(to)}")

    suspend fun tasks(): List<TaskItem> = get("/tasks")
    suspend fun createTask(t: TaskInput): TaskItem = post("/tasks", json(t))
    suspend fun updateTask(calendar: String, id: String, t: TaskInput): TaskItem = send(HttpMethod.Patch, "/tasks/${enc(calendar)}/${enc(id)}", json(t))
    suspend fun deleteTask(calendar: String, id: String) = deleteOk("/tasks/${enc(calendar)}/${enc(id)}")

    // ---------- облако ----------

    suspend fun cloudList(path: String = ""): CloudListing = get("/cloud/list?path=${enc(path)}")
    suspend fun cloudFolders(path: String = ""): CloudListing = get("/cloud/folders?path=${enc(path)}")
    suspend fun cloudRecent(): CloudListing = get("/cloud/recent")
    suspend fun cloudLinks(): CloudListing = get("/cloud/links")
    suspend fun cloudTrash(): CloudListing = get("/cloud/trash")
    suspend fun cloudMkdir(path: String, name: String): PathResult = post("/cloud/folder", buildJsonObject { put("path", path); put("name", name) })
    suspend fun cloudRename(path: String, name: String): PathResult = post("/cloud/rename", buildJsonObject { put("path", path); put("name", name) })
    suspend fun cloudMove(paths: List<String>, to: String): JsonElement = post("/cloud/move", buildJsonObject { putJsonArray("paths") { paths.forEach { add(JsonPrimitive(it)) } }; put("to", to) })
    suspend fun cloudDelete(paths: List<String>): JsonElement = post("/cloud/delete", buildJsonObject { putJsonArray("paths") { paths.forEach { add(JsonPrimitive(it)) } } })
    suspend fun cloudRestore(id: Long): PathResult = post("/cloud/trash/$id/restore")
    suspend fun cloudPurge(id: Long) = deleteOk("/cloud/trash/$id")
    suspend fun cloudEmptyTrash() = deleteOk("/cloud/trash")
    suspend fun cloudUploadStart(path: String, name: String, size: Long): UploadState = post("/cloud/uploads", buildJsonObject { put("path", path); put("name", name); put("size", size) })
    suspend fun cloudUploadStatus(id: String): UploadState = get("/cloud/uploads/${enc(id)}")
    /** [progress] — сколько байт части уже ушло: ход загрузки плавный, а не скачками по размеру части. */
    suspend fun cloudUploadChunk(id: String, n: Int, bytes: ByteArray, progress: ((Long) -> Unit)? = null) {
        raw(HttpMethod.Put, "/cloud/uploads/${enc(id)}/$n", null, timeoutMs = 600_000) {
            contentType(ContentType.Application.OctetStream); setBody(bytes)
            progress?.let { p -> onUpload { sent, _ -> p(sent) } }
        }
    }
    suspend fun cloudUploadFinish(id: String): UploadFinished = post("/cloud/uploads/${enc(id)}/finish", timeoutMs = 600_000)
    suspend fun cloudUploadAbort(id: String) = deleteOk("/cloud/uploads/${enc(id)}")
    suspend fun cloudLink(path: String, days: Int, password: Boolean? = null): LinkResult =
        post("/cloud/link", buildJsonObject { put("path", path); put("days", days); password?.let { put("password", it) } })
    suspend fun cloudUnlink(path: String) = postOk("/cloud/unlink", buildJsonObject { put("path", path) })
    suspend fun cloudAttach(paths: List<String>): JsonElement = post("/cloud/attach", buildJsonObject { putJsonArray("paths") { paths.forEach { add(JsonPrimitive(it)) } } })
    suspend fun cloudPin(path: String, on: Boolean): PinResult = post("/cloud/pin", buildJsonObject { put("path", path); put("on", on) })
    fun cloudFilePath(path: String, inline: Boolean = false) = "/cloud/file?path=${enc(path)}" + if (inline) "&inline=1" else ""

    // ---------- большие вложения ----------

    suspend fun files(): SharedFiles = get("/files")
    suspend fun fileRenew(token: String): JsonElement = post("/files/$token/renew")
    suspend fun fileDelete(token: String) = deleteOk("/files/$token")
    fun fileContentPath(token: String) = "/files/$token/content"
    fun filePreviewPath(token: String) = "/files/$token/preview.pdf"

    // ---------- журнал ----------

    suspend fun activity(kind: String, detail: String) { runCatching { postOk("/activity", buildJsonObject { put("kind", kind); put("detail", detail) }) } }

    companion object {
        /** filename*=UTF-8''… или filename="…" из Content-Disposition. */
        fun fileNameOf(cd: String?): String? {
            if (cd == null) return null
            Regex("filename\\*=UTF-8''([^;]+)", RegexOption.IGNORE_CASE).find(cd)?.let { return percentDecode(it.groupValues[1].trim()) }
            Regex("filename=\"([^\"]*)\"", RegexOption.IGNORE_CASE).find(cd)?.let { return it.groupValues[1] }
            Regex("filename=([^;]+)", RegexOption.IGNORE_CASE).find(cd)?.let { return it.groupValues[1].trim() }
            return null
        }

        fun percentDecode(s: String): String {
            val out = ArrayList<Byte>()
            var i = 0
            while (i < s.length) {
                val c = s[i]
                if (c == '%' && i + 2 < s.length + 0 && i + 2 <= s.length - 1) {
                    val h = s.substring(i + 1, i + 3).toIntOrNull(16)
                    if (h != null) { out.add(h.toByte()); i += 3; continue }
                }
                c.toString().encodeToByteArray().forEach { out.add(it) }
                i++
            }
            return out.toByteArray().decodeToString()
        }
    }
}
