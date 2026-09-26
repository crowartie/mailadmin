package su.innotec.mail.api

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonObject

// Модели ответов /api/v1. Сервер обещает только добавлять поля (docs/mobile-api.md, «Версия заморожена»),
// поэтому все поля со значениями по умолчанию, а незнакомые ключи игнорируются (ApiJson).

// ---------- Вход и сервер ----------

@Serializable
data class Discovery(
    val api: String = "",
    val name: String = "",
    val domain: String = "",
    val version: String = "",
    val minApp: String = "0",
    val features: List<String> = emptyList(),
)

@Serializable
data class DeviceInfo(val name: String, val platform: String, @SerialName("app_version") val appVersion: String)

@Serializable
data class LoginRequest(val login: String, val password: String, val device: DeviceInfo, val remember: Boolean = true)

@Serializable
data class LoginCodeRequest(val challenge: String, val code: String, val device: DeviceInfo? = null)

@Serializable
data class ServerInfo(val version: String = "", val minApp: String = "0", val features: List<String> = emptyList())

@Serializable
data class DeviceRef(val id: Long = 0, val name: String = "", val platform: String = "")

@Serializable
data class LoginResponse(
    val token: String? = null,
    val user: String = "",
    val name: String = "",
    val device: DeviceRef = DeviceRef(),
    val tokenDays: Int = 90,
    val server: ServerInfo = ServerInfo(),
    // 202: нужна вторая ступень
    val challenge: String? = null,
    val twofa: Boolean = false,
    val message: String? = null,
)

@Serializable
data class Me(
    val user: String = "",
    val name: String = "",
    val device: DeviceRef = DeviceRef(),
    val server: ServerInfo = ServerInfo(),
    val tokenDays: Int = 90,
    /** Адреса и порты для почтовых программ (как в «Настройках» веб-почты). */
    val hosts: Hosts? = null,
)

@Serializable
data class Hosts(val imap: String = "", val imapPort: Int = 993, val smtp: String = "", val smtpPort: Int = 465, val dav: String = "", val mobileconfig: String = "")

@Serializable
data class Device(
    val id: Long = 0,
    val name: String = "",
    val platform: String = "",
    val appVersion: String = "",
    val ip: String = "",
    val created: String = "",
    val seen: String = "",
    val me: Boolean = false,
)

// ---------- Почта ----------

@Serializable
data class Folder(
    val path: String = "",
    val name: String = "",
    val role: String = "",
    val depth: Int = 0,
    val parent: String? = null,
    val unread: Int = 0,
    val total: Int = 0,
    val srole: String = "",
    val owner: String = "",
    val inbox: Boolean = false,
    val ownerName: String = "",
    val readonly: Boolean = false,
    val rights: String = "",
    val shared: Boolean = false,
) {
    /** Папка из чужого ящика (общий доступ). */
    val isShared: Boolean get() = owner.isNotEmpty() || shared
}

@Serializable
data class Person(val name: String = "", val mail: String = "") {
    val display: String get() = name.ifBlank { mail }
}

@Serializable
data class Label(val id: Long = 0, val name: String = "", val color: String = "")

@Serializable
data class MessageSummary(
    val uid: Long = 0,
    val subject: String = "",
    val from: Person = Person(),
    val toName: String = "",
    val toMail: String = "",
    val date: String = "",
    val seen: Boolean = false,
    val flagged: Boolean = false,
    val answered: Boolean = false,
    val hasAttachments: Boolean = false,
    val size: Long = 0,
    val labels: List<Long> = emptyList(),
    val messageId: String = "",
    val preview: String? = null,
    val folder: String? = null,
    val folderRole: String? = null,
    val folderName: String? = null,
    val snoozed: String? = null,
    val thread: Int? = null,
)

@Serializable
data class MessageList(
    val messages: List<MessageSummary> = emptyList(),
    val total: Int = 0,
    val offset: Int = 0,
    val limit: Int = 0,
    val folders: List<Folder>? = null,
)

@Serializable
data class Attachment(
    val index: Int = 0,
    val name: String = "",
    val size: Long = 0,
    val type: String = "",
    val inline: Boolean = false,
)

@Serializable
data class CloudFileRef(val path: String = "", val name: String = "", val size: Long = 0, val url: String = "")

@Serializable
data class Message(
    val uid: Long = 0,
    val folder: String = "",
    val subject: String = "",
    val from: Person = Person(),
    val to: List<Person> = emptyList(),
    val cc: List<Person> = emptyList(),
    val bcc: List<Person> = emptyList(),
    val replyTo: List<Person> = emptyList(),
    val date: String = "",
    val seen: Boolean = false,
    val flagged: Boolean = false,
    val answered: Boolean = false,
    val hasAttachments: Boolean = false,
    val size: Long = 0,
    val labels: List<Long> = emptyList(),
    val messageId: String = "",
    val inReplyTo: String = "",
    val references: String = "",
    val html: String? = null,
    val text: String? = null,
    val attachments: List<Attachment> = emptyList(),
    val listUnsubscribe: String = "",
    val cloudFiles: List<CloudFileRef> = emptyList(),
    val markedSeen: Boolean = false,
)

@Serializable
data class ThreadMessage(
    val uid: Long = 0,
    val subject: String = "",
    val from: Person = Person(),
    val to: List<Person> = emptyList(),
    val cc: List<Person> = emptyList(),
    val date: String = "",
    val seen: Boolean = false,
    val flagged: Boolean = false,
    val folder: String = "",
    val folderRole: String = "",
    val folderName: String = "",
    val text: String? = null,
    val html: String? = null,
    val preview: String? = null,
    val hasAttachments: Boolean = false,
    val attachments: List<Attachment> = emptyList(),
    val light: Boolean = false,
)

@Serializable
data class Thread(val messages: List<ThreadMessage> = emptyList(), val hidden: Int = 0)

@Serializable
data class FolderStatus(val messages: Int = 0, val unseen: Int = 0, val uidnext: Long = 0)

@Serializable
data class Status(val folder: FolderStatus = FolderStatus(), val inboxUnseen: Int = 0, val at: String = "")

@Serializable
data class ActionRequest(
    val folder: String,
    val uids: List<Long>? = null,
    val op: String,
    val target: String? = null,
    val label: Long? = null,
    val until: String? = null,
    val all: AllSelector? = null,
)

@Serializable
data class AllSelector(val filter: String? = null, val q: String? = null)

@Serializable
data class ActionResult(val ok: Boolean = false, val done: Int = 0, val folders: List<Folder>? = null, val message: String? = null)

@Serializable
data class Draft(
    val draftUid: Long = 0,
    val from: String = "",
    val to: String = "",
    val cc: String = "",
    val bcc: String = "",
    val priority: Boolean = false,
    val receipt: Boolean = false,
    val subject: String = "",
    val html: String = "",
    val inReplyTo: String = "",
    val references: String = "",
    val attachments: List<Attachment> = emptyList(),
    val cloudFiles: List<CloudFileRef> = emptyList(),
)

@Serializable
data class SendResult(
    val sent: Boolean = false,
    val messageId: String? = null,
    val scheduled: Long? = null,
    val sendAt: String? = null,
    val draftUid: Long? = null,
    val folder: String? = null,
    val outbox: Long? = null,
    val undoUntil: String? = null,
)

@Serializable
data class OutboxItem(
    val id: Long = 0,
    val subject: String = "",
    val recipients: String = "",
    @SerialName("send_at") val sendAt: String = "",
    val status: String = "",
    val error: String? = null,
)

@Serializable
data class Suggestion(val mail: String = "", val name: String = "", val kind: String = "")

/** От чьего имени можно писать: свой адрес, псевдонимы, общие ящики (со своей подписью). */
@Serializable
data class Identity(
    val mail: String = "",
    val primary: Boolean = false,
    val shared: Boolean = false,
    val name: String = "",
    val signature: String? = null,
)

@Serializable
data class ComposeLimits(val messageMb: Int = 25, val maxFiles: Int = 20)

@Serializable
data class CloudConfig(val enabled: Boolean = false, val thresholdMb: Int = 25, val maxMb: Int = 50, val personal: Boolean = false)

@Serializable
data class Quota(val usedKb: Long = 0, val limitKb: Long = 0, val percent: Int = 0)

@Serializable
data class ComposeMeta(
    val identities: List<Identity> = emptyList(),
    val limits: ComposeLimits = ComposeLimits(),
    val cloud: CloudConfig = CloudConfig(),
    val quota: Quota? = null,
    val outbox: Int = 0,
    val quarantine: Int = 0,
)

@Serializable
data class Settings(
    @SerialName("display_name") val displayName: String = "",
    val signature: String = "",
    @SerialName("signature_reply") val signatureReply: Boolean = true,
    val theme: String = "light",
    val density: String = "",
    @SerialName("reply_all") val replyAll: Boolean = false,
    @SerialName("notify_browser") val notifyBrowser: Boolean = false,
    // В веб-почте «не задано» значит «спрашивать» (ask_rule_on_move !== false).
    @SerialName("ask_rule_on_move") val askRuleOnMove: Boolean = true,
    @SerialName("shared_mark_seen") val sharedMarkSeen: Boolean = true,
    @SerialName("undo_seconds") val undoSeconds: Int = 0,
    @SerialName("quick_replies") val quickReplies: List<String> = emptyList(),
    val shortcuts: Boolean = true,
    val preview: Boolean = true,
    @SerialName("unread_highlight") val unreadHighlight: Boolean = false,
    @SerialName("unread_color") val unreadColor: String = "",
    @SerialName("show_images") val showImages: String = "ask",
    @SerialName("totp_enabled") val totpEnabled: Boolean = false,
    @SerialName("ui_simple") val uiSimple: Boolean = false,
)

// ---------- Безопасность ----------

@Serializable
data class SessionRow(
    val id: String = "",
    val user: String = "",
    val device: String = "",
    val ip: String = "",
    val seen: String = "",
    val kind: String = "",
    val me: Boolean = false,
    val count: Int = 1,
    val remembered: Boolean = false,
)

@Serializable
data class LoginRow(val at: String = "", val ip: String = "", val result: String = "", val device: String = "")

@Serializable
data class AppPassword(
    val id: Long = 0,
    val name: String = "",
    val created: String? = null,
    val lastUsed: String? = null,
    /** Только в ответе на создание: показывается один раз. */
    val plain: String? = null,
)

@Serializable
data class SessionsResult(val sessions: List<SessionRow> = emptyList(), val remember: Boolean? = null)

@Serializable
data class Security(
    val totp: Boolean = false,
    val required: Boolean = false,
    val appPasswordsAllowed: Boolean = false,
    val appPasswords: List<AppPassword> = emptyList(),
    val sessions: List<SessionRow> = emptyList(),
    val logins: List<LoginRow> = emptyList(),
    val minPassword: Int = 8,
    val remember: Boolean = false,
    val rememberDays: Int = 0,
)

// ---------- Правила ----------

@Serializable
data class RuleCondition(val field: String = "from", val op: String = "contains", val value: String = "", val header: String? = null)

@Serializable
data class RuleAction(val type: String = "move", val value: String = "")

@Serializable
data class Rule(
    val id: String = "",
    val name: String = "",
    val enabled: Boolean = true,
    val match: String = "all",
    val stop: Boolean = false,
    val conditions: List<RuleCondition> = emptyList(),
    val actions: List<RuleAction> = emptyList(),
)

/** Автоответ: from/to — период (ГГГГ-ММ-ДД), days — не чаще раза в N дней одному адресату. */
@Serializable
data class AutoReply(
    val enabled: Boolean = false,
    val subject: String = "",
    val body: String = "",
    val from: String? = null,
    val to: String? = null,
    val days: Int? = null,
)

@Serializable
data class Rules(val rules: List<Rule> = emptyList(), val autoreply: AutoReply? = null, val script: String = "")

// ---------- Карантин, обращения ----------

@Serializable
data class QuarantineItem(
    val id: String = "",
    val time: String = "",
    val from: String = "",
    val to: String = "",
    val subject: String = "",
    val score: Double? = null,
    val kind: String = "",
    val size: Long = 0,
    val released: Boolean = false,
)

@Serializable
data class QuarantineResult(val ok: Boolean = false, val items: List<QuarantineItem> = emptyList())

@Serializable
data class TicketMessage(
    val id: Long = 0,
    val author: String = "",
    val role: String = "",
    val text: String = "",
    val file: Boolean = false,
    val at: String = "",
)

@Serializable
data class Ticket(
    val id: Long = 0,
    val user: String = "",
    val kind: String = "",
    val kindLabel: String = "",
    val subject: String = "",
    val status: String = "",
    val statusLabel: String = "",
    val resolution: String? = null,
    val newForUser: Boolean = false,
    val createdAt: String? = null,
    val lastReplyAt: String? = null,
    val closedAt: String? = null,
    val last: TicketMessage? = null,
)

@Serializable
data class Tickets(val tickets: List<Ticket> = emptyList(), val unread: Int = 0)

@Serializable
data class TicketPoll(val messages: List<TicketMessage> = emptyList(), val ticket: Ticket = Ticket())

@Serializable
data class TicketReply(val message: TicketMessage = TicketMessage(), val ticket: Ticket = Ticket())

@Serializable
data class Created(val id: Long = 0)

// ---------- Контакты ----------

@Serializable
data class AddressBook(
    val id: Long = 0,
    val uri: String = "",
    val name: String = "",
    val description: String = "",
    val kind: String = "",
    val readonly: Boolean = false,
    val count: Int = 0,
)

@Serializable
data class TypedValue(val value: String = "", val type: String = "")

@Serializable
data class PostalAddress(
    val type: String = "work",
    val street: String = "",
    val city: String = "",
    val region: String = "",
    val postal: String = "",
    val country: String = "",
) {
    val oneLine: String get() = listOf(postal, country, region, city, street).filter { it.isNotBlank() }.joinToString(", ")
}

@Serializable
data class Contact(
    val uri: String = "",
    val etag: String = "",
    val uid: String = "",
    val fn: String = "",
    val last: String = "",
    val first: String = "",
    val middle: String = "",
    val nick: String = "",
    val org: String = "",
    val department: String = "",
    val title: String = "",
    val emails: List<TypedValue> = emptyList(),
    val phones: List<TypedValue> = emptyList(),
    val addresses: List<PostalAddress> = emptyList(),
    val birthday: String = "",
    val url: String = "",
    val note: String = "",
    val groups: List<String> = emptyList(),
    val favorite: Boolean = false,
    val employee: Boolean = false,
    val email: String = "",
    val book: String = "",
    val bookName: String = "",
    val readonly: Boolean = false,
    val photo: String? = null,
)

/** Тело POST contacts / PUT contacts/{book}/{uri}. */
@Serializable
data class ContactInput(
    val book: String? = null,
    val fn: String = "",
    val first: String = "",
    val last: String = "",
    val middle: String = "",
    val nick: String = "",
    val org: String = "",
    val department: String = "",
    val title: String = "",
    val emails: List<TypedValue> = emptyList(),
    val phones: List<TypedValue> = emptyList(),
    val addresses: List<PostalAddress> = emptyList(),
    val birthday: String = "",
    val url: String = "",
    val note: String = "",
    val groups: List<String> = emptyList(),
    val favorite: Boolean = false,
)

fun Contact.toInput() = ContactInput(
    book = book, fn = fn, first = first, last = last, middle = middle, nick = nick, org = org,
    department = department, title = title, emails = emails, phones = phones, addresses = addresses,
    birthday = birthday, url = url, note = note, groups = groups, favorite = favorite,
)

@Serializable
data class ContactGroup(val name: String = "", val count: Int = 0)

@Serializable
data class HistoryEntry(val email: String = "", val name: String = "", val uses: Int = 0, @SerialName("last_at") val lastAt: String = "")

// ---------- Календарь и задачи ----------

@Serializable
data class CalendarOwner(val mail: String? = null, val name: String = "", val unit: Boolean = false)

@Serializable
data class Calendar(
    val id: Long = 0,
    val instance: Long = 0,
    val uri: String = "",
    val name: String = "",
    val color: String = "",
    val kind: String = "",
    val readonly: Boolean = false,
    val owner: CalendarOwner = CalendarOwner(),
    val order: Int = 0,
)

@Serializable
data class Attendee(
    val mail: String = "",
    val name: String = "",
    val status: String = "NEEDS-ACTION",
    val role: String = "REQ-PARTICIPANT",
)

@Serializable
data class RRule(
    val freq: String = "WEEKLY",
    val interval: Int = 1,
    val until: String? = null,
    val count: Int? = null,
    val byday: List<String> = emptyList(),
)

/** Событие: id — имя объекта в календаре, calendar — uri календаря (так их ждут PUT/DELETE events/{calendar}/{id}). */
@Serializable
data class CalEvent(
    val id: String = "",
    val calendar: String = "",
    val calendarName: String = "",
    val color: String = "",
    val readonly: Boolean = false,
    val etag: String? = null,
    val uid: String = "",
    val title: String = "",
    val start: String = "",
    val end: String = "",
    val allDay: Boolean = false,
    val location: String = "",
    val description: String = "",
    val url: String = "",
    val status: String = "CONFIRMED",
    val transparent: Boolean = false,
    val organizer: Attendee? = null,
    val attendees: List<Attendee> = emptyList(),
    val alarm: Int? = null,
    val rrule: RRule? = null,
    val recurrenceId: String? = null,
    val sequence: Int? = null,
)

/** Тело POST events / PUT events/{calendar}/{id}. */
@Serializable
data class EventInput(
    val calendar: String? = null,
    val title: String = "",
    val start: String,
    val end: String? = null,
    val allDay: Boolean = false,
    val location: String = "",
    val description: String = "",
    val url: String = "",
    val status: String? = null,
    val transparent: Boolean = false,
    val rrule: RRule? = null,
    val attendees: List<Attendee> = emptyList(),
    val alarm: Int? = null,
)

@Serializable
data class TaskItem(
    val id: String = "",
    val calendar: String = "",
    val calendarName: String = "",
    val color: String = "",
    val uid: String = "",
    val title: String = "",
    val description: String = "",
    val due: String? = null,
    val allDay: Boolean = false,
    val done: Boolean = false,
    val priority: Int = 0,
    val created: String? = null,
)

@Serializable
data class TaskInput(
    val calendar: String? = null,
    val title: String? = null,
    val due: String? = null,
    val done: Boolean? = null,
    val priority: Int? = null,
    val description: String? = null,
)

@Serializable
data class FreeBusySlot(val start: String = "", val end: String = "")

// ---------- Облако ----------

@Serializable
data class CloudLife(
    val expires: String? = null,
    val days: Int? = null,
    val pinned: Boolean = false,
    val pinnedBy: String? = null,
    val reason: String = "",
)

@Serializable
data class CloudLink(
    val path: String = "",
    @SerialName("share_id") val shareId: String = "",
    val url: String = "",
    @SerialName("expires_at") val expiresAt: String? = null,
    @SerialName("has_password") val hasPassword: Boolean = false,
    val password: String? = null,
)

@Serializable
data class CloudItem(
    val name: String = "",
    val path: String = "",
    val dir: Boolean = false,
    val size: Long? = null,
    val modified: String? = null,
    val type: String = "",
    val fileid: String? = null,
    val life: CloudLife? = null,
    val link: CloudLink? = null,
    val id: Long? = null,
    val deleted: String? = null,
)

@Serializable
data class CloudListing(
    val path: String = "",
    val items: List<CloudItem> = emptyList(),
    val used: Long = 0,
    val quota: Long = 0,
    val fileDays: Int = 0,
    val pinCap: Long = 0,
    val pinned: Long = 0,
)

/** Ответ POST cloud/uploads и GET cloud/uploads/{id}: have — номера уже принятых частей (с 1). */
@Serializable
data class UploadState(
    val id: String = "",
    val path: String = "",
    val name: String = "",
    val chunkSize: Long = 0,
    val chunks: Int = 0,
    val have: List<Int> = emptyList(),
)

@Serializable
data class UploadFinished(val item: CloudItem = CloudItem())

@Serializable
data class PathResult(val path: String = "")

@Serializable
data class LinkResult(val link: CloudLink = CloudLink())

@Serializable
data class PinResult(val life: CloudLife? = null, val pinned: Long = 0, val pinCap: Long = 0)

// ---------- Большие вложения (files.innotec.su) ----------

@Serializable
data class SharedFile(
    val token: String = "",
    val name: String = "",
    val size: Long = 0,
    val type: String = "",
    val url: String = "",
    val expires: String? = null,
    val expired: Boolean = false,
    val mine: Boolean = true,
    val downloads: Int = 0,
    val subject: String = "",
    val preview: Boolean = false,
    val cloud: Boolean = false,
    val created: String = "",
)

@Serializable
data class SharedFiles(
    val files: List<SharedFile> = emptyList(),
    val used: Long = 0,
    val quota: Long = 0,
    val expireDays: Int = 0,
    val enabled: Boolean = false,
)

// ---------- Общий доступ к папкам ----------

@Serializable
data class FolderShare(val mail: String = "", val level: String = "")

@Serializable
data class Candidate(val mail: String = "", val name: String = "")

@Serializable
data class FolderShares(val shares: List<FolderShare> = emptyList(), val candidates: List<Candidate> = emptyList(), val folders: List<Folder>? = null)

@Serializable
data class CalendarShare(val mail: String = "", val name: String = "", val level: String = "read")

/** Ответ, который приложение не разбирает по полям (например, эхо сохранения). */
typealias RawJson = JsonElement

@Serializable
data class ErrorBody(val message: String? = null, val code: String? = null, val errors: JsonObject? = null)
