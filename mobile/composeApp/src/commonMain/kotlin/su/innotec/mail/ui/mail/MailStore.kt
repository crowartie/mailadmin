package su.innotec.mail.ui.mail

import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.delay
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch
import su.innotec.mail.api.ActionRequest
import su.innotec.mail.api.AllSelector
import su.innotec.mail.api.ApiException
import su.innotec.mail.api.Folder
import su.innotec.mail.api.Label
import su.innotec.mail.api.MessageSummary
import su.innotec.mail.api.Settings
import su.innotec.mail.data.Session
import su.innotec.mail.ui.Fmt
import su.innotec.mail.ui.Toasts

/** Что показывает список: папка, фильтр, поиск. */
data class ListQuery(
    val folder: String = "INBOX",
    val filter: String = "all",
    val q: String = "",
    val everywhere: Boolean = false,
    val sort: String = "date",
)

/**
 * Состояние раздела «Почта» — одно на приложение, как Inbox.vue в веб-почте.
 * Действия с письмами работают «с отменой»: строки пропадают сразу, запрос уходит,
 * когда истекло время «Отменить» (настройка undo_seconds, не меньше 4 секунд).
 */
object MailStore {
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.Main)

    var folders by mutableStateOf<List<Folder>>(emptyList()); private set
    var labels by mutableStateOf<List<Label>>(emptyList()); private set
    var settings by mutableStateOf(Settings()); private set
    var inboxUnread by mutableIntStateOf(0); private set
    var outboxCount by mutableIntStateOf(0); private set
    var quarantineCount by mutableIntStateOf(0); private set

    var query by mutableStateOf(ListQuery()); private set
    val messages = mutableStateListOf<MessageSummary>()
    var total by mutableIntStateOf(0); private set
    var loading by mutableStateOf(false); private set
    var loadingMore by mutableStateOf(false); private set
    var error by mutableStateOf<String?>(null); private set
    val selected = mutableStateListOf<Long>()
    /** Открытое письмо (планшет: правая панель). */
    var openUid by mutableStateOf<Long?>(null)
    var scrollTopSignal by mutableIntStateOf(0)
    /** Меняется после каждого действия — открытые экраны перечитывают своё. */
    var version by mutableIntStateOf(0); private set

    private var loadJob: Job? = null
    private var pollJob: Job? = null
    private var started = false

    private val api get() = Session.api ?: throw ApiException(401, "unauthorized", "Не выполнен вход")

    val pageSize = 50

    fun reset() {
        loadJob?.cancel(); pollJob?.cancel()
        started = false
        folders = emptyList(); labels = emptyList(); settings = Settings()
        messages.clear(); selected.clear(); total = 0; openUid = null; query = ListQuery(); error = null
        inboxUnread = 0; outboxCount = 0; quarantineCount = 0
    }

    /** Первый показ раздела: настройки, метки, папки, список; затем опрос раз в минуту. */
    fun start() {
        if (started) return
        started = true
        scope.launch {
            runCatching { settings = api.settings() }
            runCatching { labels = api.labels() }
        }
        refreshFolders()
        load()
        pollJob = scope.launch {
            while (isActive) {
                delay(60_000)
                poll()
            }
        }
    }

    fun refreshFolders() {
        scope.launch {
            try {
                applyFolders(api.folders())
                runCatching { outboxCount = api.outbox().count { it.status == "scheduled" || it.status.isEmpty() } }
                runCatching { quarantineCount = api.quarantine().size }
            } catch (e: ApiException) {
                if (e.isAuth) Toasts.error(e)
            }
        }
    }

    fun applyFolders(list: List<Folder>) {
        folders = list
        inboxUnread = list.firstOrNull { it.role == "inbox" }?.unread ?: inboxUnread
    }

    val currentFolder: Folder? get() = folders.firstOrNull { it.path == query.folder }

    /** Проверка новых писем, пока приложение открыто. */
    private suspend fun poll() {
        if (Session.account == null) return
        runCatching {
            val st = api.status(query.folder)
            inboxUnread = st.inboxUnseen
            val topUid = messages.firstOrNull()?.uid ?: 0
            if (query.q.isBlank() && st.folder.uidnext > topUid + 1 && !loading && selected.isEmpty()) {
                // Новые письма сверху: тихо перечитать первую страницу.
                val r = api.list(query.folder, 0, maxOf(pageSize, messages.size.coerceAtMost(200)), query.filter, sort = query.sort)
                replaceTop(r.messages)
                total = r.total
                refreshFolders()
            }
        }
    }

    private fun replaceTop(fresh: List<MessageSummary>) {
        val have = messages.map { it.uid }.toSet()
        val add = fresh.filter { it.uid !in have && !pendingHidden(it.uid) }
        if (add.isNotEmpty()) messages.addAll(0, add)
        // обновить флаги уже показанных
        val byUid = fresh.associateBy { it.uid }
        for (i in messages.indices) byUid[messages[i].uid]?.let { if (it != messages[i]) messages[i] = it }
    }

    fun go(folder: String, filter: String = "all") {
        selected.clear()
        openUid = null
        query = ListQuery(folder = folder, filter = filter)
        load()
    }

    fun setFilter(filter: String) { if (query.filter != filter) { query = query.copy(filter = filter); load() } }
    fun setSort(sort: String) { if (query.sort != sort) { query = query.copy(sort = sort); load() } }
    fun search(q: String, everywhere: Boolean) { query = query.copy(q = q.trim(), everywhere = everywhere); load() }
    fun clearSearch() { if (query.q.isNotEmpty()) { query = query.copy(q = "", everywhere = false); load() } }

    fun load() {
        loadJob?.cancel()
        loading = true
        error = null
        loadJob = scope.launch {
            try {
                val q = query
                val r = api.list(q.folder, 0, pageSize, q.filter, q.q, if (q.everywhere) "all" else "folder", q.sort, withFolders = folders.isEmpty())
                if (q != query) return@launch
                messages.clear()
                messages.addAll(r.messages.filterNot { pendingHidden(it.uid) })
                total = r.total
                r.folders?.let { applyFolders(it) }
                if (q.folder == folders.firstOrNull { it.role == "inbox" }?.path && q.filter == "all" && q.q.isEmpty()) {
                    r.messages.firstOrNull()?.let { top -> Session.updatePrefs { p -> if (top.uid > p.lastNotifiedUid) p.copy(lastNotifiedUid = top.uid) else p } }
                }
            } catch (e: ApiException) {
                if (e.isAuth) Toasts.error(e) else error = e.message
            } finally {
                loading = false
            }
        }
    }

    fun loadMore() {
        if (loadingMore || loading || messages.size >= total) return
        loadingMore = true
        scope.launch {
            try {
                val q = query
                val r = api.list(q.folder, messages.size, pageSize, q.filter, q.q, if (q.everywhere) "all" else "folder", q.sort)
                if (q == query) {
                    val have = messages.map { it.uid to it.folder }.toSet()
                    messages.addAll(r.messages.filter { (it.uid to it.folder) !in have })
                    total = r.total
                }
            } catch (e: ApiException) {
                Toasts.error(e)
            } finally {
                loadingMore = false
            }
        }
    }

    /** Перейти к дате (как «К дате» в веб-почте). */
    fun jumpToDate(date: String, onOffset: (Int) -> Unit) {
        scope.launch {
            try {
                val q = query
                val off = api.listAt(q.folder, date, q.filter, q.q)
                if (off >= messages.size) {
                    val r = api.list(q.folder, messages.size, (off - messages.size + pageSize).coerceAtMost(400), q.filter, q.q, sort = q.sort)
                    messages.addAll(r.messages)
                    total = r.total
                }
                onOffset(off.coerceAtMost(messages.lastIndex.coerceAtLeast(0)))
            } catch (e: ApiException) { Toasts.error(e) }
        }
    }

    // ---------- действия ----------

    /** Письма, которые сейчас «ждут отмены» — их не показываем при перечитывании. */
    private val pending = mutableMapOf<Long, Int>()
    private fun pendingHidden(uid: Long) = pending.containsKey(uid)

    fun folderOf(uid: Long): String = messages.firstOrNull { it.uid == uid }?.folder ?: query.folder

    fun updateLocal(uids: Collection<Long>, f: (MessageSummary) -> MessageSummary) {
        for (i in messages.indices) if (messages[i].uid in uids) messages[i] = f(messages[i])
    }

    private fun opText(op: String, n: Int, target: String?): String {
        val w = "$n ${Fmt.plural(n, "письмо", "письма", "писем")}"
        val one = n == 1
        return when (op) {
            "delete" -> if (query.folder.let { f -> folders.firstOrNull { it.path == f }?.role } == "trash") (if (one) "Удалено навсегда" else "$w удалено навсегда") else if (one) "Удалено" else "$w в корзине"
            "archive" -> if (one) "В архиве" else "$w в архиве"
            "move" -> (if (one) "Перенесено" else "$w перенесено") + (target?.let { t -> " в «" + (folders.firstOrNull { it.path == t }?.name ?: t) + "»" } ?: "")
            "spam" -> if (one) "В спаме" else "$w в спаме"
            "notspam" -> if (one) "Не спам — во «Входящих»" else "$w во «Входящих»"
            "snooze" -> if (one) "Отложено" else "$w отложено"
            "unsnooze" -> "Возвращено во «Входящие»"
            "lists" -> if (one) "В «Рассылках»" else "$w в «Рассылках»"
            else -> "Готово"
        }
    }

    /**
     * Действие над письмами. Уходящие из папки (удалить, архив, перенести, спам, отложить)
     * пропадают из списка сразу и отправляются после окна «Отменить».
     */
    fun act(op: String, uids: List<Long>, target: String? = null, label: Long? = null, until: String? = null, folder: String? = null, onDone: () -> Unit = {}) {
        if (uids.isEmpty()) return
        val f = folder ?: folderOf(uids.first())
        val leaves = op in setOf("delete", "archive", "move", "spam", "notspam", "snooze", "unsnooze", "lists")
        selected.removeAll(uids)
        if (!leaves) {
            // флажки, прочитанность, метки — сразу, без отмены
            when (op) {
                "seen" -> updateLocal(uids) { it.copy(seen = true) }
                "unseen" -> updateLocal(uids) { it.copy(seen = false) }
                "flag" -> updateLocal(uids) { it.copy(flagged = true) }
                "unflag" -> updateLocal(uids) { it.copy(flagged = false) }
                "label" -> updateLocal(uids) { if (label != null && label !in it.labels) it.copy(labels = it.labels + label) else it }
                "unlabel" -> updateLocal(uids) { it.copy(labels = it.labels - (label ?: -1)) }
            }
            scope.launch {
                try {
                    val r = api.action(ActionRequest(folder = f, uids = uids, op = op, target = target, label = label, until = until))
                    r.folders?.let { applyFolders(it) }
                    version++
                    onDone()
                } catch (e: ApiException) {
                    Toasts.error(e); load()
                }
            }
            return
        }
        val removed = messages.withIndex().filter { it.value.uid in uids }.map { it.index to it.value }
        removed.asReversed().forEach { messages.removeAt(it.first) }
        uids.forEach { pending[it] = (pending[it] ?: 0) + 1 }
        total = (total - removed.size).coerceAtLeast(0)
        if (openUid in uids) openUid = null
        val seconds = settings.undoSeconds.coerceAtLeast(4)
        var undone = false
        Toasts.action(opText(op, uids.size, target), "Отменить", seconds, onTimeout = {
            if (undone) return@action
            scope.launch {
                try {
                    val r = api.action(ActionRequest(folder = f, uids = uids, op = op, target = target, label = label, until = until))
                    r.folders?.let { applyFolders(it) }
                    version++
                    onDone()
                } catch (e: ApiException) {
                    Toasts.error(e); restore(removed)
                } finally {
                    uids.forEach { pending.remove(it) }
                }
            }
        }) {
            undone = true
            uids.forEach { pending.remove(it) }
            restore(removed)
        }
    }

    private fun restore(removed: List<Pair<Int, MessageSummary>>) {
        for ((i, m) in removed) {
            if (messages.none { it.uid == m.uid }) messages.add(i.coerceAtMost(messages.size), m)
        }
        total += removed.size
    }

    /** «Выбрать все письма папки» — действие на сервере над всем списком (all:{filter,q}). */
    fun actAll(op: String, target: String? = null, label: Long? = null) {
        val q = query
        scope.launch {
            try {
                val r = api.action(ActionRequest(folder = q.folder, op = op, target = target, label = label, all = AllSelector(q.filter, q.q.ifBlank { null })))
                r.folders?.let { applyFolders(it) }
                Toasts.show("Готово: ${r.done} ${Fmt.plural(r.done, "письмо", "письма", "писем")}")
                selected.clear()
                load()
            } catch (e: ApiException) { Toasts.error(e) }
        }
    }

    /** Письмо открыли: отметить прочитанным локально и поправить счётчики. */
    fun markOpened(uid: Long) {
        val m = messages.firstOrNull { it.uid == uid } ?: return
        if (!m.seen) {
            updateLocal(listOf(uid)) { it.copy(seen = true) }
            folders = folders.map { if (it.path == (m.folder ?: query.folder) && it.unread > 0) it.copy(unread = it.unread - 1) else it }
            if (currentFolder?.role == "inbox") inboxUnread = (inboxUnread - 1).coerceAtLeast(0)
        }
    }

    fun reloadSettings() { scope.launch { runCatching { settings = api.settings() } } }
    fun setSettingsLocal(s: Settings) { settings = s }
    fun reloadLabels() { scope.launch { runCatching { labels = api.labels() } } }
    fun bump() { version++ }
}
