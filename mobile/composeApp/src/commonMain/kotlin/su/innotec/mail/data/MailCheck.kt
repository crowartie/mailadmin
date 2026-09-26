package su.innotec.mail.data

import kotlinx.coroutines.sync.withLock
import su.innotec.mail.api.ApiException
import su.innotec.mail.api.Folder
import su.innotec.mail.api.Reminder
import su.innotec.mail.platform.KeyValueStore
import su.innotec.mail.platform.Notifier
import su.innotec.mail.ui.Fmt

/** Проверка новых писем во «Входящих» для уведомлений (фоновая задача и опрос на ПК). */
object MailCheck {
    /** Проверку делают и служба (раз в минуту), и WorkManager (раз в 15 минут): одновременно — дважды одно уведомление. */
    private val lock = kotlinx.coroutines.sync.Mutex()

    /**
     * Список папок запрашиваем не каждый раз: раз в минуту достаточно статуса «Входящих» (сотни байт),
     * а папки — когда появились новые письма (для общих ящиков) или раз в 10 минут (переименовали, добавили общий).
     */
    private var folders: List<Folder> = emptyList()
    private var inbox: Folder? = null
    private var foldersAt = 0L
    private var foldersFor: String? = null
    private const val FOLDERS_TTL_MS = 10 * 60_000L

    /** Возвращает, сколько уведомлений показано. */
    suspend fun run(): Int = lock.withLock { check() }

    private suspend fun check(): Int {
        val api = Session.api ?: return 0
        val account = Session.account ?: return 0
        val prefs = Session.prefs
        val now = kotlin.time.Clock.System.now().toEpochMilliseconds()
        val key = account.origin + " " + account.user
        if (inbox == null || foldersFor != key || now - foldersAt > FOLDERS_TTL_MS) refreshFolders(api, key, now)
        val ib = inbox ?: return 0
        val st = try {
            api.status(ib.path)
        } catch (e: ApiException) {
            // Папку могли переименовать или отозвать доступ — в следующий раз перечитать список.
            if (!e.isAuth && !e.isNetwork) inbox = null
            throw e
        }
        // Напоминания о встречах приходят в том же ответе — показываем до проверки новых писем,
        // иначе в тихий день (uidnext не меняется) до них не дошло бы.
        Reminders.handle(st.reminders)
        if (st.folder.uidnext <= prefs.lastNotifiedUidNext) return 0
        // Новые письма есть — заодно освежить папки: список общих ящиков и их непрочитанное.
        if (now - foldersAt > 60_000L) runCatching { refreshFolders(api, key, now) }
        val first = prefs.lastNotifiedUid == 0L
        val fresh = api.list(ib.path, 0, 20, "unread").messages.filter { it.uid > prefs.lastNotifiedUid }
        val maxUid = maxOf(prefs.lastNotifiedUid, fresh.maxOfOrNull { it.uid } ?: 0)
        Session.updatePrefs { it.copy(lastNotifiedUid = maxUid, lastNotifiedUidNext = st.folder.uidnext) }
        // Первый запуск после входа: старые непрочитанные — не повод для уведомлений.
        if (first) return 0
        var shown = 0
        fresh.sortedBy { it.uid }.takeLast(5).forEach { m ->
            Notifier.show((m.uid % Int.MAX_VALUE).toInt(), m.from.display.ifBlank { "Новое письмо" }, m.subject.ifBlank { "(без темы)" }, ib.path, m.uid)
            shown++
        }
        if (Session.prefs.notifyShared) {
            folders.filter { it.isShared && it.inbox }.forEach { f ->
                runCatching {
                    api.list(f.path, 0, 5, "unread").messages.filter { Fmt2.recent(it.date) }.take(2).forEach { m ->
                        Notifier.show(((m.uid + f.path.hashCode()) % Int.MAX_VALUE).toInt(), "${f.ownerName.ifBlank { f.owner }}: ${m.from.display}", m.subject.ifBlank { "(без темы)" }, f.path, m.uid)
                    }
                }
            }
        }
        return shown
    }

    private suspend fun refreshFolders(api: su.innotec.mail.api.Api, key: String, now: Long) {
        val list = api.folders()
        folders = list
        inbox = list.firstOrNull { it.role == "inbox" && !it.isShared }
        foldersAt = now
        foldersFor = key
    }
}

/**
 * Напоминания о встречах (поле reminders в GET status, как useLiveUpdates.js в веб-почте): системное
 * уведомление на каждое, показанные помним по ключу «uid@начало» — сервер отдаёт то же напоминание
 * ещё пару минут, а фон и открытое приложение опрашивают его независимо.
 */
object Reminders {
    private val store by lazy { KeyValueStore("reminders") }
    /** Сколько ключей помнить: напоминаний несколько в день, 200 хватает на месяцы. */
    const val KEEP = 200
    private val lock = kotlinx.coroutines.sync.Mutex()

    private fun storeKey() = "shown:" + (Session.account?.let { it.origin + " " + it.user } ?: "")

    fun shown(): Set<String> = store.get(storeKey())?.split('\n')?.filter { it.isNotBlank() }?.toSet() ?: emptySet()

    /** Какие из пришедших ещё не показывали (порядок сервера сохраняется). */
    fun newOnes(list: List<Reminder>, shown: Set<String>): List<Reminder> = list.filter { it.key.isNotBlank() && it.key !in shown }.distinctBy { it.key }

    /** Ключи для хранения: старые вытесняются, остаются последние [KEEP]. */
    fun trim(keys: List<String>, keep: Int = KEEP): List<String> = keys.distinct().takeLast(keep)

    /** Заголовок и текст уведомления: «Напоминание: Планёрка» / «В 10:00 · переговорная». */
    fun describe(r: Reminder): Pair<String, String> {
        val time = if (r.allDay) "Весь день" else Fmt.local(r.start)?.let { "В " + Fmt.time(it) } ?: "Скоро"
        return ("Напоминание: " + r.title.ifBlank { "(без названия)" }) to (time + if (r.location.isNotBlank()) " · " + r.location else "")
    }

    /** Показать непоказанные и запомнить их. Возвращает, сколько показано. */
    fun handle(list: List<Reminder>): Int {
        if (list.isEmpty()) return 0
        val fresh = newOnes(list, shown())
        if (fresh.isEmpty()) return 0
        store.put(storeKey(), trim(shown().toList() + fresh.map { it.key }).joinToString("\n"))
        fresh.forEach { r ->
            val (title, text) = describe(r)
            Notifier.event(title, text, r.key.hashCode() and 0x7fffffff)
        }
        return fresh.size
    }

    /** Опрос из открытого приложения (раз в минуту, App.kt): статус «Входящих» — сотни байт. */
    suspend fun poll(): Int = lock.withLock {
        val api = Session.api ?: return 0
        val st = api.status("INBOX")
        handle(st.reminders)
    }
}

private object Fmt2 {
    /** Письмо за последние 20 минут (для общих ящиков, где нет своей отметки «последнее показанное»). */
    fun recent(iso: String): Boolean {
        val t = su.innotec.mail.ui.Fmt.parse(iso) ?: return false
        return kotlin.time.Clock.System.now() - t < kotlin.time.Duration.parse("20m")
    }
}
