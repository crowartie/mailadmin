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
import kotlinx.coroutines.cancelChildren
import kotlinx.coroutines.delay
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch
import su.innotec.mail.api.ActionRequest
import su.innotec.mail.api.AllSelector
import su.innotec.mail.api.Api
import su.innotec.mail.api.ApiException
import su.innotec.mail.api.Folder
import su.innotec.mail.api.Label
import su.innotec.mail.api.MessageSummary
import su.innotec.mail.api.Rule
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
    /** Правила ящика — чтобы не спрашивать «класть сюда всегда?», когда правило для отправителя уже есть. */
    var rules by mutableStateOf<List<Rule>>(emptyList()); private set
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
    /** Выбраны все письма папки (с учётом фильтра и поиска), а не только загруженные — действия идут на сервере. */
    var allFolder by mutableStateOf(false)
    /** Занятое место в ящике — внизу панели папок, как в веб-почте. */
    var quota by mutableStateOf<su.innotec.mail.api.Quota?>(null)
    /** Открытое письмо (планшет: правая панель). */
    var openUid by mutableStateOf<Long?>(null)
    var scrollTopSignal by mutableIntStateOf(0)
    /** Открыть поиск (Ctrl+F на ПК). */
    var searchSignal by mutableIntStateOf(0)
    /** Меняется после каждого действия — открытые экраны перечитывают своё. */
    var version by mutableIntStateOf(0); private set

    /** Какой список сейчас на экране: из кэша показываем, только если открыли другой (иначе список мигнул бы старым). */
    private var shownQuery: ListQuery? = null
    /** Сеть недоступна — на экране сохранённые письма (полоса «Нет связи» над списком). */
    var offline by mutableStateOf(false); private set

    private var loadJob: Job? = null
    private var pollJob: Job? = null
    private var started = false
    /** uidnext папки на прошлом опросе: перечитываем список только когда он сдвинулся. */
    private var lastUidNext = 0L

    private val api get() = Session.api ?: throw ApiException(401, "unauthorized", "Не выполнен вход")

    val pageSize = 50

    /**
     * Сброс раздела. [switching] — переход на другой ящик, а не выход: окна «Отменить» не снимаем молча, а
     * досрочно завершаем — действие уйдёт на сервер прежнего ящика (его API запомнен в самом действии).
     */
    fun reset(switching: Boolean = false) {
        loadJob?.cancel(); pollJob?.cancel()
        // И остальные корутины раздела (настройки, метки, правила, папки): ответ прежнего ящика иначе доезжал в новый.
        scope.coroutineContext.cancelChildren()
        started = false
        lastUidNext = 0
        // При выходе окна «Отменить» снимаем молча: вход уже отозван, запрос всё равно не пройдёт.
        pendingToasts.toList().forEach { if (switching) it.expire() else it.dismiss() }; pendingToasts.clear(); pending.clear()
        folders = emptyList(); labels = emptyList(); settings = Settings(); rules = emptyList()
        messages.clear(); selected.clear(); total = 0; openUid = null; query = ListQuery(); error = null; shownQuery = null; offline = false
        inboxUnread = 0; outboxCount = 0; quarantineCount = 0; loading = false; loadingMore = false; quota = null
    }

    /**
     * Все отложенные действия (ждут окна «Отменить») — на сервер сейчас. Зовётся, когда приложение уходит
     * в фон: систему не спросишь, убьёт ли она процесс через секунду, а удаление уже показано сделанным.
     */
    fun flushPending() { pendingToasts.toList().forEach { it.expire() } }

    /** Первый показ раздела: настройки, метки, папки, список; затем опрос раз в минуту. */
    fun start() {
        if (started) return
        started = true
        scope.launch {
            runCatching { settings = api.settings() }
            runCatching { labels = api.labels() }
            runCatching { rules = api.rules().rules }
        }
        Pinned.load()
        if (folders.isEmpty()) MailCache.folders()?.let { applyFolders(it) }
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
                val list = api.folders()
                applyFolders(list)
                MailCache.saveFolders(list)
                runCatching { outboxCount = api.outbox().count { it.status == "scheduled" || it.status.isEmpty() } }
                runCatching { quarantineCount = api.quarantine().size }
                runCatching { quota = api.composeMeta().quota }
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
            // Раньше сравнение было только с верхним uid: при сортировке не по дате, фильтре или после удаления
            // верхнего письма оно выполнялось всегда, и каждую минуту перечитывалось до 200 писем.
            val moved = lastUidNext != 0L && st.folder.uidnext != lastUidNext
            lastUidNext = st.folder.uidnext
            if (moved && query.q.isBlank() && query.sort == "date" && st.folder.uidnext > topUid + 1 && !loading && selected.isEmpty()) {
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
        lastUidNext = 0
        loading = true
        error = null
        val q0 = query
        // Другой список — сразу показать сохранённый, пока сервер отвечает (мгновенный запуск и переход по папкам).
        if (shownQuery != q0 && q0.q.isEmpty()) {
            val cached = MailCache.list(q0.folder, q0.filter, q0.sort)
            messages.clear()
            if (cached != null) { messages.addAll(cached.first.filterNot { pendingHidden(it.uid) }); total = cached.second }
            shownQuery = q0
        }
        // Запуск отложенный (LAZY): задача узнаёт себя в loadJob ещё до первого шага, независимо от диспетчера.
        val job = scope.launch(start = kotlinx.coroutines.CoroutineStart.LAZY) {
            // Своя задача или уже устаревшая: устаревшая (её отменил новый load) не должна гасить «loading»
            // и писать ошибку поверх нового списка.
            val me = coroutineContext[Job]
            try {
                val q = query
                val r = api.list(q.folder, 0, pageSize, q.filter, q.q, if (q.everywhere) "all" else "folder", q.sort, withFolders = folders.isEmpty())
                if (q != query || loadJob !== me) return@launch
                messages.clear()
                messages.addAll(r.messages.filterNot { pendingHidden(it.uid) })
                total = r.total
                shownQuery = q
                offline = false
                error = null
                if (q.q.isEmpty()) MailCache.saveList(q.folder, q.filter, q.sort, r.messages, r.total)
                r.folders?.let { applyFolders(it) }
                if (q.folder == folders.firstOrNull { it.role == "inbox" }?.path && q.filter == "all" && q.q.isEmpty()) {
                    // Письмо уже увидели в списке — уведомлять о нём фоновой проверке не нужно.
                    r.messages.firstOrNull()?.let { top -> Session.account?.let { a -> Session.updateAccount(a.key) { p -> if (top.uid > p.lastNotifiedUid) p.copy(lastNotifiedUid = top.uid) else p } } }
                }
            } catch (e: ApiException) {
                if (loadJob !== me) return@launch
                if (e.isAuth) Toasts.error(e) else error = e.message
                offline = e.isNetwork && messages.isNotEmpty()
            } finally {
                if (loadJob === me) loading = false
            }
        }
        loadJob = job
        job.start()
    }

    fun loadMore() {
        // Без связи показан сохранённый список: дальше него на сервере всё равно не спросить,
        // а каждая прокрутка к концу давала бы новое сообщение об ошибке.
        if (offline || loadingMore || loading || messages.size >= total) return
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
    /** Открытые окна «Отменить»: чтобы выполнить всё сразу при уходе в фон или снять при выходе. */
    private val pendingToasts = mutableListOf<Toasts.ActionToast>()

    fun folderOf(uid: Long): String = messages.firstOrNull { it.uid == uid }?.folder ?: query.folder

    /**
     * В поиске «везде» выбранные письма лежат в разных папках, а /action работает с одной:
     * uid группируются по папке письма и уходят отдельным запросом на каждую.
     */
    private fun groupByFolder(uids: List<Long>, folder: String?): Map<String, List<Long>> =
        if (folder != null) mapOf(folder to uids) else uids.groupBy { folderOf(it) }

    /** [via] — API ящика, в котором действие начали: отложенное «удалить» после смены ящика должно уйти туда же. */
    private suspend fun actGroups(groups: Map<String, List<Long>>, op: String, target: String?, label: Long?, until: String?, via: Api = api) {
        for ((f, ids) in groups) {
            val r = via.action(ActionRequest(folder = f, uids = ids, op = op, target = target, label = label, until = until))
            if (via === Session.api) r.folders?.let { applyFolders(it) }
        }
    }

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
            "remind" -> "Напомню, если не ответят"
            else -> "Готово"
        }
    }

    /**
     * Действие над письмами. Уходящие из папки (удалить, архив, перенести, спам, отложить)
     * пропадают из списка сразу и отправляются после окна «Отменить».
     */
    fun act(op: String, uids: List<Long>, target: String? = null, label: Long? = null, until: String? = null, folder: String? = null, senders: List<String>? = null, onDone: () -> Unit = {}) {
        if (uids.isEmpty()) return
        val groups = groupByFolder(uids, folder)
        // Спам, рассылка, «не спам» и перенос в свою папку — с вопросом о правиле для отправителя (как в веб-почте).
        // Тогда действие идёт сразу, без окна «Отменить»: правило может тут же разложить письма, и отложенный
        // запрос пришёл бы уже к переехавшим письмам. В чужом общем ящике правил не предлагаем.
        val srcs = groups.keys.map { f -> folders.firstOrNull { it.path == f } }
        val targetFolder = target?.let { t -> folders.firstOrNull { it.path == t } }
        var askKind = when {
            // owner — строка, не null: сравнение с null было всегда истинным, и вопрос о правиле не задавался никогда.
            srcs.any { it?.role == "shared" || it?.isShared == true } -> null
            op == "spam" -> "spam"
            op == "lists" -> "lists"
            op == "notspam" -> "ham"
            op == "move" && targetFolder?.role == "custom" && targetFolder.owner.isEmpty() && settings.askRuleOnMove -> "folder"   // owner — строка, не null
            else -> null
        }
        var askMails = if (askKind == null) emptyList() else senders ?: messages.filter { it.uid in uids }.map { it.from.mail }
        // Правило «письма от … → сюда» уже есть (из веба или прошлого ответа «да») — второй раз не спрашиваем.
        if (askKind == "folder") askMails = askMails.filter { !ruleCoversSender(rules, it, target) }
        if (askKind == "folder" && askMails.isEmpty()) askKind = null
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
                    actGroups(groups, op, target, label, until)
                    version++
                    if (op == "remind") Toasts.show(opText(op, uids.size, target))
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
        // Ящик и его API запоминаем сейчас: окно «Отменить» может пережить переключение на другой ящик.
        val via = api
        val owner = Session.account?.key
        /** Отправить на сервер; не вышло — вернуть строки (несколько папок: часть могла уйти — перечитать список). */
        suspend fun perform(after: () -> Unit) {
            try {
                actGroups(groups, op, target, label, until, via)
                version++
                onDone()
                after()
                // «Выделить все на экране → Удалить» опустошало список, а следующие письма подтягиваются
                // только прокруткой к концу — прокручивать было нечего (обращение №61). Дочитываем сами.
                if (owner == Session.account?.key && messages.size < pageSize && messages.size < total) loadMore()
            } catch (e: ApiException) {
                Toasts.error(e)
                // Ящик уже другой — его списку чужие строки не нужны.
                if (owner == Session.account?.key) { restore(removed); if (groups.size > 1) load() }
            } finally {
                uids.forEach { pending.remove(it) }
            }
        }
        if (askKind != null) {
            scope.launch {
                perform {
                    Toasts.show(opText(op, uids.size, target))
                    SenderRules.offer(SenderAsk(askKind, askMails, targetFolder))
                }
            }
            return
        }
        // Окно «Отменить» выключено в настройках — действие сразу, как обещает подпись «Не ждать».
        if (settings.undoSeconds <= 0) {
            scope.launch { perform { Toasts.show(opText(op, uids.size, target)) } }
            return
        }
        val seconds = settings.undoSeconds.coerceAtLeast(4)
        var undone = false
        lateinit var toast: Toasts.ActionToast
        toast = Toasts.action(opText(op, uids.size, target), "Отменить", seconds, onTimeout = {
            pendingToasts.remove(toast)
            if (undone) return@action
            scope.launch { perform {} }
        }) {
            pendingToasts.remove(toast)
            undone = true
            uids.forEach { pending.remove(it) }
            restore(removed)
        }
        pendingToasts.add(toast)
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
    fun reloadRules() { scope.launch { runCatching { rules = api.rules().rules } } }
    fun bump() { version++ }
}

/**
 * Есть ли среди правил такое, что уже кладёт письма [mail] в папку [folder]: условие по «От» (равно, содержит,
 * начинается, заканчивается — как в веб-почте) и действие «переместить» в эту папку либо «в папку по адресу /
 * домену отправителя» (такое раскладывает само, в какую бы папку ни переносили). Выключенные правила не в счёт.
 */
fun ruleCoversSender(rules: List<Rule>, mail: String, folder: String?): Boolean {
    val m = mail.trim().lowercase()
    if (m.isEmpty()) return false
    return rules.any { r ->
        r.enabled && r.conditions.any { c ->
            val v = c.value.trim().lowercase()
            c.field == "from" && v.isNotEmpty() && when (c.op) {
                "is" -> m == v
                "contains" -> v in m
                "starts" -> m.startsWith(v)
                "ends" -> m.endsWith(v)
                else -> false
            }
        } && r.actions.any { a ->
            (a.type == "move" && folder != null && a.value == folder) || a.type == "move_by_sender" || a.type == "move_by_domain"
        }
    }
}
