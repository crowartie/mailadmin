package su.innotec.mail.data

import kotlinx.coroutines.sync.withLock
import su.innotec.mail.api.ApiException
import su.innotec.mail.api.Folder
import su.innotec.mail.platform.Notifier

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

private object Fmt2 {
    /** Письмо за последние 20 минут (для общих ящиков, где нет своей отметки «последнее показанное»). */
    fun recent(iso: String): Boolean {
        val t = su.innotec.mail.ui.Fmt.parse(iso) ?: return false
        return kotlin.time.Clock.System.now() - t < kotlin.time.Duration.parse("20m")
    }
}
