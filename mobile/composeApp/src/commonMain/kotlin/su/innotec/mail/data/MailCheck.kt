package su.innotec.mail.data

import kotlinx.coroutines.sync.withLock
import su.innotec.mail.api.Api
import su.innotec.mail.api.ApiException
import su.innotec.mail.api.Folder
import su.innotec.mail.api.Reminder
import su.innotec.mail.platform.KeyValueStore
import su.innotec.mail.platform.Notifier
import su.innotec.mail.ui.Fmt

/** Проверка новых писем во «Входящих» каждого ящика для уведомлений (фоновая задача и опрос на ПК). */
object MailCheck {
    /** Проверку делают и служба (раз в минуту), и WorkManager (раз в 15 минут): одновременно — дважды одно уведомление. */
    private val lock = kotlinx.coroutines.sync.Mutex()

    /**
     * Список папок запрашиваем не каждый раз: раз в минуту достаточно статуса «Входящих» (сотни байт),
     * а папки — когда появились новые письма (для общих ящиков) или раз в 10 минут (переименовали, добавили общий).
     * У каждого ящика свой набор.
     */
    private class Folders(var list: List<Folder> = emptyList(), var inbox: Folder? = null, var at: Long = 0L)
    private var folders: Map<String, Folders> = emptyMap()
    private const val FOLDERS_TTL_MS = 10 * 60_000L

    /**
     * Обойти все ящики. Возвращает, сколько уведомлений показано. Отозванный вход одного ящика убирает только его
     * и не мешает остальным; наружу ошибка уходит, лишь если не ответил ни один (нет сети) — служба тогда ждёт дольше.
     */
    suspend fun run(): Int = lock.withLock {
        var shown = 0
        var answered = false
        var failure: ApiException? = null
        for (a in Session.accounts) {
            try {
                shown += check(a)
                answered = true
            } catch (e: ApiException) {
                if (e.isAuth) Session.signOut("Вход устарел или отозван — войдите заново.", a.key)
                else if (failure == null) failure = e
            }
        }
        if (!answered && failure != null) throw failure
        shown
    }

    private suspend fun check(start: Account): Int {
        val api = Session.apiFor(start)
        val now = kotlin.time.Clock.System.now().toEpochMilliseconds()
        val fs = folders[start.key]?.takeIf { it.inbox != null && now - it.at <= FOLDERS_TTL_MS } ?: refreshFolders(api, start.key, now)
        val ib = fs.inbox ?: return 0
        val st = try {
            api.status(ib.path)
        } catch (e: ApiException) {
            // Папку могли переименовать или отозвать доступ — в следующий раз перечитать список.
            if (!e.isAuth && !e.isNetwork) fs.inbox = null
            throw e
        }
        // Напоминания о встречах приходят в том же ответе — показываем до проверки новых писем,
        // иначе в тихий день (uidnext не меняется) до них не дошло бы.
        Reminders.handle(st.reminders, start.key)
        // Счётчики берём свежие: пока шёл запрос, открытый список писем мог их сдвинуть (MailStore.load).
        val a = Session.accounts.firstOrNull { it.key == start.key } ?: return 0
        if (st.folder.uidnext <= a.lastNotifiedUidNext) return 0
        // Новые письма есть — заодно освежить папки: список общих ящиков и их непрочитанное.
        if (now - fs.at > 60_000L) runCatching { refreshFolders(api, a.key, now) }
        val first = a.lastNotifiedUid == 0L
        val fresh = api.list(ib.path, 0, 20, "unread").messages.filter { it.uid > a.lastNotifiedUid }
        val maxUid = maxOf(a.lastNotifiedUid, fresh.maxOfOrNull { it.uid } ?: 0)
        Session.updateAccount(a.key) { it.copy(lastNotifiedUid = maxUid, lastNotifiedUidNext = st.folder.uidnext) }
        // Первый запуск после входа: старые непрочитанные — не повод для уведомлений.
        if (first) return 0
        val prefix = AccountList.notifyPrefix(Session.accounts, a)
        var shown = 0
        fresh.sortedBy { it.uid }.takeLast(5).forEach { m ->
            Notifier.show(AccountList.notifyId(a.key, m.uid), AccountList.notifyTitle(prefix, m.from.display.ifBlank { "Новое письмо" }), m.subject.ifBlank { "(без темы)" }, ib.path, m.uid, a.key)
            shown++
        }
        if (Session.prefs.notifyShared) {
            fs.list.filter { it.isShared && it.inbox }.forEach { f ->
                runCatching {
                    api.list(f.path, 0, 5, "unread").messages.filter { Fmt2.recent(it.date) }.take(2).forEach { m ->
                        Notifier.show(AccountList.notifyId(a.key + f.path, m.uid), AccountList.notifyTitle(prefix, "${f.ownerName.ifBlank { f.owner }}: ${m.from.display}"), m.subject.ifBlank { "(без темы)" }, f.path, m.uid, a.key)
                    }
                }
            }
        }
        return shown
    }

    private suspend fun refreshFolders(api: Api, key: String, now: Long): Folders {
        val list = api.folders()
        val fs = Folders(list, list.firstOrNull { it.role == "inbox" && !it.isShared }, now)
        folders = folders + (key to fs)
        return fs
    }
}

/**
 * Напоминания о встречах (поле reminders в GET status, как useLiveUpdates.js в веб-почте): системное
 * уведомление на каждое, показанные помним по ключу «uid@начало» отдельно для каждого ящика — сервер отдаёт
 * то же напоминание ещё пару минут, а фон и открытое приложение опрашивают его независимо.
 */
object Reminders {
    private val store by lazy { KeyValueStore("reminders") }
    /** Сколько ключей помнить: напоминаний несколько в день, 200 хватает на месяцы. */
    const val KEEP = 200
    private val lock = kotlinx.coroutines.sync.Mutex()

    private fun storeKey(account: String) = "shown:$account"

    fun shown(account: String): Set<String> = store.get(storeKey(account))?.split('\n')?.filter { it.isNotBlank() }?.toSet() ?: emptySet()

    /** Выход из ящика: его показанные напоминания больше не нужны. */
    fun forget(account: String) = store.put(storeKey(account), null)

    /** Какие из пришедших ещё не показывали (порядок сервера сохраняется). */
    fun newOnes(list: List<Reminder>, shown: Set<String>): List<Reminder> = list.filter { it.key.isNotBlank() && it.key !in shown }.distinctBy { it.key }

    /** Ключи для хранения: старые вытесняются, остаются последние [KEEP]. */
    fun trim(keys: List<String>, keep: Int = KEEP): List<String> = keys.distinct().takeLast(keep)

    /** Заголовок и текст уведомления: «Напоминание: Планёрка» / «В 10:00 · переговорная». */
    fun describe(r: Reminder): Pair<String, String> {
        val time = if (r.allDay) "Весь день" else Fmt.local(r.start)?.let { "В " + Fmt.time(it) } ?: "Скоро"
        return ("Напоминание: " + r.title.ifBlank { "(без названия)" }) to (time + if (r.location.isNotBlank()) " · " + r.location else "")
    }

    /** Показать непоказанные напоминания ящика [account] (Account.key) и запомнить их. Возвращает, сколько показано. */
    fun handle(list: List<Reminder>, account: String): Int {
        if (list.isEmpty()) return 0
        val fresh = newOnes(list, shown(account))
        if (fresh.isEmpty()) return 0
        store.put(storeKey(account), trim(shown(account).toList() + fresh.map { it.key }).joinToString("\n"))
        fresh.forEach { r ->
            val (title, text) = describe(r)
            Notifier.event(title, text, r.key.hashCode() and 0x7fffffff)
        }
        return fresh.size
    }

    /** Опрос из открытого приложения (раз в минуту, App.kt): статус «Входящих» активного ящика — сотни байт. */
    suspend fun poll(): Int = lock.withLock {
        val a = Session.account ?: return 0
        val st = Session.apiFor(a).status("INBOX")
        handle(st.reminders, a.key)
    }
}

private object Fmt2 {
    /** Письмо за последние 20 минут (для общих ящиков, где нет своей отметки «последнее показанное»). */
    fun recent(iso: String): Boolean {
        val t = su.innotec.mail.ui.Fmt.parse(iso) ?: return false
        return kotlin.time.Clock.System.now() - t < kotlin.time.Duration.parse("20m")
    }
}
