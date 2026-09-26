package su.innotec.mail.data

import su.innotec.mail.platform.Notifier

/** Проверка новых писем во «Входящих» для уведомлений (фоновая задача и опрос на ПК). */
object MailCheck {
    /** Возвращает, сколько уведомлений показано. */
    suspend fun run(): Int {
        val api = Session.api ?: return 0
        val prefs = Session.prefs
        val folders = api.folders()
        val inbox = folders.firstOrNull { it.role == "inbox" && !it.isShared } ?: return 0
        val st = api.status(inbox.path)
        if (st.folder.uidnext <= prefs.lastNotifiedUidNext) return 0
        val first = prefs.lastNotifiedUid == 0L
        val fresh = api.list(inbox.path, 0, 20, "unread").messages.filter { it.uid > prefs.lastNotifiedUid }
        val maxUid = maxOf(prefs.lastNotifiedUid, fresh.maxOfOrNull { it.uid } ?: 0)
        Session.updatePrefs { it.copy(lastNotifiedUid = maxUid, lastNotifiedUidNext = st.folder.uidnext) }
        // Первый запуск после входа: старые непрочитанные — не повод для уведомлений.
        if (first) return 0
        var shown = 0
        fresh.sortedBy { it.uid }.takeLast(5).forEach { m ->
            Notifier.show((m.uid % Int.MAX_VALUE).toInt(), m.from.display.ifBlank { "Новое письмо" }, m.subject.ifBlank { "(без темы)" }, inbox.path, m.uid)
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
}

private object Fmt2 {
    /** Письмо за последние 20 минут (для общих ящиков, где нет своей отметки «последнее показанное»). */
    fun recent(iso: String): Boolean {
        val t = su.innotec.mail.ui.Fmt.parse(iso) ?: return false
        return kotlin.time.Clock.System.now() - t < kotlin.time.Duration.parse("20m")
    }
}
