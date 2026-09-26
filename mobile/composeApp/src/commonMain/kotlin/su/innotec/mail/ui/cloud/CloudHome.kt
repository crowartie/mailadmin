package su.innotec.mail.ui.cloud

import su.innotec.mail.ui.Viewable
import su.innotec.mail.ui.ViewerScreen
import su.innotec.mail.ui.ViewItem
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.focus.focusRequester
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.combinedClickable
import androidx.compose.foundation.ExperimentalFoundationApi
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Checkbox
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.ExtendedFloatingActionButton
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.pulltorefresh.PullToRefreshBox
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
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.io.Source
import kotlinx.io.readByteArray
import su.innotec.mail.Nav
import su.innotec.mail.Screen
import su.innotec.mail.api.ApiException
import su.innotec.mail.api.CloudFileRef
import su.innotec.mail.api.CloudItem
import su.innotec.mail.api.CloudListing
import su.innotec.mail.api.LocalFile
import su.innotec.mail.data.Session
import su.innotec.mail.platform.Sys
import su.innotec.mail.platform.rememberFilePicker
import su.innotec.mail.ui.ChoiceDialog
import su.innotec.mail.ui.Chip
import su.innotec.mail.ui.ConfirmDialog
import su.innotec.mail.ui.Divider
import su.innotec.mail.ui.Empty
import su.innotec.mail.ui.ErrorBox
import su.innotec.mail.ui.Fmt
import su.innotec.mail.ui.Ico
import su.innotec.mail.ui.InputDialog
import su.innotec.mail.ui.Loading
import su.innotec.mail.ui.P
import su.innotec.mail.ui.Toasts
import su.innotec.mail.ui.Transfers
import su.innotec.mail.ui.launchSafe
import su.innotec.mail.ui.mail.IconBtn
import su.innotec.mail.ui.mail.SubBar
import su.innotec.mail.ui.mail.fileIcon

/** Загрузка в облако частями (как cloudUpload.js): обрыв связи — докачка с недостающей части. */
object CloudUploads {
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.Main)

    class Job(val name: String, val size: Long) {
        var done by mutableStateOf(0L)
        var error by mutableStateOf<String?>(null)
        var finished by mutableStateOf(false)
    }

    val jobs = mutableStateListOf<Job>()

    fun upload(path: String, files: List<LocalFile>, onDone: () -> Unit) {
        files.forEach { f ->
            val job = Job(f.name, f.size)
            jobs.add(job)
            scope.launch {
                try {
                    run(path, f, job)
                    job.finished = true
                    onDone()
                    delay(2500)
                    jobs.remove(job)
                } catch (e: ApiException) {
                    job.error = e.message
                    if (e.isAuth) Toasts.error(e)
                }
            }
        }
    }

    private suspend fun run(path: String, f: LocalFile, job: Job) {
        val api = Session.api!!
        var st = api.cloudUploadStart(path, f.name, f.size)
        var attempts = 0
        while (true) {
            try {
                var src: Source? = null
                var pos = 0L
                try {
                    for (n in 1..st.chunks) {
                        val from = (n - 1) * st.chunkSize
                        val len = minOf(st.chunkSize, f.size - from)
                        if (n in st.have) { job.done = maxOf(job.done, from + len); continue }
                        if (src == null || pos != from) { src?.close(); src = f.open(); if (from > 0) src.skip(from); pos = from }
                        val bytes = src.readByteArray(len.toInt())
                        pos += len
                        api.cloudUploadChunk(st.id, n, bytes)
                        job.done = from + len
                    }
                } finally { src?.close() }
                api.cloudUploadFinish(st.id)
                return
            } catch (e: ApiException) {
                if (e.status in 400..499 && e.status != 408 && e.status != 429) throw e
                if (++attempts > 5) throw e
                delay(2000L * attempts)
                // Что сервер уже принял — от этого места и продолжаем.
                st = runCatching { api.cloudUploadStatus(st.id) }.getOrElse { st }
            }
        }
    }
}

object CloudStore {
    var path by mutableStateOf("")
    var tab by mutableStateOf("files")
    var version by mutableStateOf(0)
    fun bump() { version++ }
    fun reset() { path = ""; tab = "files" }
}

private fun lifeText(i: CloudItem): String? {
    val l = i.life ?: return null
    if (l.pinned) return "закреплён"
    val d = l.days ?: return null
    return if (d <= 3) "удалится через $d ${Fmt.plural(d, "день", "дня", "дней")}" else null
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun CloudHome() {
    val s = CloudStore
    var listing by remember { mutableStateOf<CloudListing?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var loading by remember { mutableStateOf(false) }
    var dialog by remember { mutableStateOf<Pair<String, CloudItem?>?>(null) }
    var menu by remember { mutableStateOf(false) }
    // Выбор нескольких (долгое нажатие) и поиск по открытой папке — как в веб-облаке.
    val picked = remember { mutableStateListOf<String>() }
    var searching by remember { mutableStateOf(false) }
    var query by remember { mutableStateOf("") }
    val scope = rememberCoroutineScope()
    val api = Session.api!!
    LaunchedEffect(s.path, s.tab) { picked.clear(); query = ""; searching = false }
    fun reload() {
        loading = true; error = null
        scope.launch {
            try {
                listing = when (s.tab) {
                    "recent" -> api.cloudRecent(); "links" -> api.cloudLinks(); "trash" -> api.cloudTrash(); else -> api.cloudList(s.path)
                }
            } catch (e: ApiException) { if (e.isAuth) Toasts.error(e) else error = e.message } finally { loading = false }
        }
    }
    LaunchedEffect(s.path, s.tab, s.version) { reload() }
    su.innotec.mail.platform.BackHandler(s.tab == "files" && s.path.isNotEmpty() && picked.isEmpty() && !searching) { s.path = s.path.substringBeforeLast('/', "") }
    su.innotec.mail.platform.BackHandler(picked.isNotEmpty()) { picked.clear() }
    su.innotec.mail.platform.BackHandler(searching && picked.isEmpty()) { searching = false; query = "" }
    val pick = rememberFilePicker(multiple = true) { files -> CloudUploads.upload(s.path, files) { s.bump() } }

    Box(Modifier.fillMaxSize().background(P.bg)) {
        Column(Modifier.fillMaxSize()) {
            Column(Modifier.background(P.surface).statusBarsPadding()) {
                val chosen = listing?.items?.filter { it.path in picked }.orEmpty()
                if (picked.isNotEmpty()) Row(Modifier.fillMaxWidth().height(56.dp).background(P.accentSoft).padding(horizontal = 4.dp), verticalAlignment = Alignment.CenterVertically) {
                    IconBtn("x", "Снять выбор", tint = P.accentInk) { picked.clear() }
                    Text("${picked.size}", Modifier.weight(1f).padding(start = 4.dp), style = MaterialTheme.typography.titleMedium, color = P.accentInk)
                    if (s.tab == "trash") {
                        IconBtn("refresh", "Восстановить", tint = P.accentInk) { val ids = chosen.mapNotNull { it.id }; picked.clear(); scope.launchSafe { ids.forEach { api.cloudRestore(it) }; s.bump(); Toasts.show("Восстановлено: ${ids.size}") } }
                    } else {
                        if (chosen.none { it.dir }) IconBtn("mail", "Отправить письмом", tint = P.accentInk) {
                            val refs = chosen.map { CloudFileRef(it.path, it.name, it.size ?: 0) }; picked.clear()
                            Nav.push(su.innotec.mail.ui.mail.ComposeScreen(su.innotec.mail.ui.mail.ComposeStart.New(cloudFiles = refs)))
                        }
                        IconBtn("move", "Перенести", tint = P.accentInk) { dialog = "move-many" to null }
                        IconBtn("trash", "Удалить", tint = P.accentInk) { dialog = "delete-many" to null }
                    }
                }
                else if (searching) Row(Modifier.fillMaxWidth().height(56.dp).padding(horizontal = 4.dp), verticalAlignment = Alignment.CenterVertically) {
                    IconBtn("back", "Закрыть поиск") { searching = false; query = "" }
                    val focus = remember { androidx.compose.ui.focus.FocusRequester() }
                    androidx.compose.material3.TextField(query, { query = it }, Modifier.weight(1f).focusRequester(focus).testTag("cloud-search"),
                        placeholder = { Text("Поиск в этой папке") }, singleLine = true,
                        colors = androidx.compose.material3.TextFieldDefaults.colors(focusedContainerColor = Color.Transparent, unfocusedContainerColor = Color.Transparent,
                            focusedIndicatorColor = Color.Transparent, unfocusedIndicatorColor = Color.Transparent))
                    if (query.isNotEmpty()) IconBtn("x", "Очистить") { query = "" }
                    LaunchedEffect(Unit) { runCatching { focus.requestFocus() } }
                }
                else Row(Modifier.fillMaxWidth().height(56.dp).padding(horizontal = 4.dp), verticalAlignment = Alignment.CenterVertically) {
                    if (s.tab == "files" && s.path.isNotEmpty()) IconBtn("back", "Выше") { s.path = s.path.substringBeforeLast('/', "") } else Spacer(Modifier.width(12.dp))
                    Text(if (s.tab == "files") s.path.substringAfterLast('/').ifBlank { "Облако" } else "Облако", Modifier.weight(1f), style = MaterialTheme.typography.titleMedium, maxLines = 1, overflow = TextOverflow.Ellipsis)
                    IconBtn("search", "Поиск") { searching = true }
                    Box {
                        IconBtn("dots", "Ещё") { menu = true }
                        DropdownMenu(menu, { menu = false }) {
                            if (s.tab == "files") DropdownMenuItem({ Text("Новая папка") }, { menu = false; dialog = "mkdir" to null }, leadingIcon = { Ico("folder") })
                            if (s.tab == "trash") DropdownMenuItem({ Text("Очистить корзину") }, { menu = false; dialog = "empty" to null }, leadingIcon = { Ico("trash") })
                            DropdownMenuItem({ Text("Обновить") }, { menu = false; reload() }, leadingIcon = { Ico("refresh") })
                        }
                    }
                }
                Row(Modifier.fillMaxWidth().horizontalScroll(rememberScrollState()).padding(horizontal = 12.dp, vertical = 6.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    listOf("files" to "Файлы", "recent" to "Недавние", "links" to "Со ссылкой", "trash" to "Корзина").forEach { (k, t) -> Chip(t, s.tab == k, { s.tab = k }) }
                }
                listing?.takeIf { it.quota > 0 }?.let { l ->
                    Column(Modifier.padding(horizontal = 16.dp, vertical = 4.dp)) {
                        LinearProgressIndicator(progress = { (l.used.toFloat() / l.quota).coerceIn(0f, 1f) }, modifier = Modifier.fillMaxWidth().clip(RoundedCornerShape(2.dp)), color = if (l.used > l.quota * 0.9) P.no else P.accent)
                        Text("Занято ${Fmt.size(l.used)} из ${Fmt.size(l.quota)}" + if (l.fileDays > 0) " · файлы хранятся ${l.fileDays} дн. с последнего обращения" else "",
                            style = MaterialTheme.typography.bodySmall, color = P.muted, modifier = Modifier.padding(top = 4.dp))
                    }
                }
                CloudUploads.jobs.forEach { j ->
                    Row(Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 4.dp), verticalAlignment = Alignment.CenterVertically) {
                        Ico(if (j.finished) "check" else if (j.error != null) "warn" else "upload", size = 16.dp, tint = if (j.error != null) P.no else P.accent)
                        Spacer(Modifier.width(8.dp))
                        Column(Modifier.weight(1f)) {
                            Text(j.name, style = MaterialTheme.typography.bodySmall, maxLines = 1, overflow = TextOverflow.Ellipsis)
                            if (j.error != null) Text(j.error!!, style = MaterialTheme.typography.bodySmall, color = P.no)
                            else LinearProgressIndicator(progress = { if (j.size > 0) j.done.toFloat() / j.size else 1f }, modifier = Modifier.fillMaxWidth())
                        }
                        if (j.error != null) IconBtn("x", "Убрать", tint = P.muted) { CloudUploads.jobs.remove(j) }
                    }
                }
                Transfers.active?.let { LinearProgressIndicator(progress = { Transfers.progress }, modifier = Modifier.fillMaxWidth()) }
                Divider()
            }
            PullToRefreshBox(isRefreshing = loading && listing != null, onRefresh = { reload() }, modifier = Modifier.weight(1f)) {
                val l = listing
                when {
                    error != null && l == null -> ErrorBox(error!!, { reload() })
                    l == null -> Loading()
                    l.items.isEmpty() -> Empty(if (s.tab == "trash") "trash" else "cloud", when (s.tab) { "trash" -> "Корзина пуста"; "links" -> "Ссылок нет"; "recent" -> "Пока пусто"; else -> "Папка пуста" },
                        if (s.tab == "files") "Загрузите файлы — кнопка внизу" else null)
                    else -> LazyColumn(Modifier.fillMaxSize().testTag("cloud-list")) {
                        val all = if (s.tab == "files") l.items.sortedWith(compareBy({ !it.dir }, { it.name.lowercase() })) else l.items
                        val items = if (query.isBlank()) all else all.filter { it.name.contains(query.trim(), ignoreCase = true) }
                        if (items.isEmpty()) item { Text("Ничего не нашлось", Modifier.padding(24.dp), color = P.muted) }
                        items(items.size, key = { items[it].path + (items[it].id ?: 0) }) { i ->
                            val it1 = items[i]
                            val key = it1.path
                            CloudRow(it1, trash = s.tab == "trash", selected = key in picked, selecting = picked.isNotEmpty(),
                                onToggle = { if (key in picked) picked.remove(key) else picked.add(key) },
                                onOpen = { it0 ->
                                    if (it0.dir && s.tab != "trash") { s.tab = "files"; s.path = it0.path }
                                    else if (!it0.dir) {
                                        // Картинки, PDF и документы Office — прямо в приложении, листая файлы папки (документ — через PDF облака).
                                        val views = items.filter { !it.dir && Viewable.kind(it.name, it.type).let { k -> k == "image" || k == "pdf" } }
                                            .map { ViewItem(it.name, api.cloudFilePath(it.path), Viewable.kind(it.name, it.type)!!) }
                                        val at = views.indexOfFirst { it.path == api.cloudFilePath(it0.path) }
                                        if (at >= 0 && s.tab != "trash") Nav.push(ViewerScreen(views, at))
                                        else Transfers.fetch(api.cloudFilePath(it0.path), it0.name, Transfers.Then.OPEN) { s.bump() }
                                    }
                                }, onAction = { kind, it0 -> dialog = kind to it0 })
                        }
                        item { Spacer(Modifier.height(96.dp)) }
                    }
                }
            }
        }
        if (s.tab == "files" && picked.isEmpty()) ExtendedFloatingActionButton(
            onClick = { pick() }, containerColor = P.accent, contentColor = P.accentOn,
            modifier = Modifier.align(Alignment.BottomEnd).padding(16.dp).testTag("cloud-upload"),
            icon = { Ico("upload") }, text = { Text("Загрузить") },
        )
    }

    val d = dialog
    if (d != null) {
        val (kind, item) = d
        val close = { dialog = null }
        when (kind) {
            "mkdir" -> InputDialog("Новая папка", "Название", confirm = "Создать", onDismiss = close) { n -> scope.launchSafe { api.cloudMkdir(s.path, n); s.bump() } }
            "rename" -> InputDialog("Переименовать", "Название", initial = item!!.name, onDismiss = close) { n -> scope.launchSafe { api.cloudRename(item.path, n); s.bump() } }
            "delete" -> ConfirmDialog("Удалить «${item!!.name}»?", "Попадёт в корзину облака, ссылки перестанут работать.", "Удалить", danger = true, onDismiss = close) {
                scope.launchSafe { api.cloudDelete(listOf(item.path)); s.bump(); Toasts.show("В корзине") }
            }
            "move" -> CloudFolderPicker("Перенести «${item!!.name}» в…", onDismiss = close) { to -> scope.launchSafe { api.cloudMove(listOf(item.path), to); s.bump(); Toasts.show("Перенесено") } }
            "link" -> LinkDialog(item!!, onDismiss = close) { s.bump() }
            "restore" -> { close(); scope.launchSafe { val r = api.cloudRestore(item!!.id ?: 0); s.bump(); Toasts.show("Восстановлено: ${r.path}") } }
            "purge" -> ConfirmDialog("Удалить «${item!!.name}» навсегда?", confirm = "Удалить", danger = true, onDismiss = close) { scope.launchSafe { api.cloudPurge(item.id ?: 0); s.bump() } }
            "delete-many" -> ConfirmDialog("Удалить выбранное (${picked.size})?", "Попадёт в корзину облака, ссылки перестанут работать.", "Удалить", danger = true, onDismiss = close) {
                val paths = picked.toList(); picked.clear()
                scope.launchSafe { api.cloudDelete(paths); s.bump(); Toasts.show("В корзине: ${paths.size}") }
            }
            "move-many" -> CloudFolderPicker("Перенести выбранное (${picked.size}) в…", onDismiss = close) { to ->
                val paths = picked.toList(); picked.clear()
                scope.launchSafe { api.cloudMove(paths, to); s.bump(); Toasts.show("Перенесено: ${paths.size}") }
            }
            "empty" -> ConfirmDialog("Очистить корзину облака?", "Файлы будут удалены навсегда.", "Очистить", danger = true, onDismiss = close) { scope.launchSafe { api.cloudEmptyTrash(); s.bump() } }
            "pin" -> { close(); scope.launchSafe { val on = item!!.life?.pinned != true; api.cloudPin(item.path, on); s.bump(); Toasts.show(if (on) "Закреплено — не удалится по сроку" else "Закрепление снято") } }
            "save" -> { close(); Transfers.fetch(api.cloudFilePath(item!!.path), item.name, Transfers.Then.SAVE) }
            "share" -> { close(); Transfers.fetch(api.cloudFilePath(item!!.path), item.name, Transfers.Then.SHARE) }
            "mail" -> { close(); Nav.push(su.innotec.mail.ui.mail.ComposeScreen(su.innotec.mail.ui.mail.ComposeStart.New(cloudFiles = listOf(CloudFileRef(item!!.path, item.name, item.size ?: 0))))) }
        }
    }
}

@OptIn(ExperimentalFoundationApi::class)
@Composable
private fun CloudRow(i: CloudItem, trash: Boolean, selected: Boolean, selecting: Boolean, onToggle: () -> Unit, onOpen: (CloudItem) -> Unit, onAction: (String, CloudItem) -> Unit) {
    var menu by remember { mutableStateOf(false) }
    Row(
        Modifier.fillMaxWidth().background(if (selected) P.accentSoft else P.surface)
            .combinedClickable(onClick = { if (selecting) onToggle() else onOpen(i) }, onLongClick = onToggle).padding(start = 16.dp, end = 4.dp, top = 8.dp, bottom = 8.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(Modifier.size(40.dp).clip(RoundedCornerShape(10.dp)).background(if (selected) P.accent else if (i.dir) P.accentSoft else P.surface2).clickable { onToggle() }, contentAlignment = Alignment.Center) {
            if (selected) Ico("check", tint = P.accentOn) else Ico(if (i.dir) "folder" else fileIcon(i.name, i.type), tint = if (i.dir) P.accentInk else P.muted)
        }
        Spacer(Modifier.width(12.dp))
        Column(Modifier.weight(1f)) {
            Text(i.name, style = MaterialTheme.typography.bodyLarge, maxLines = 1, overflow = TextOverflow.Ellipsis)
            val meta = listOfNotNull(
                if (!i.dir) i.size?.let { Fmt.size(it) } else null,
                (i.deleted ?: i.modified)?.let { Fmt.listDate(it) },
                if (i.link != null) "ссылка" else null,
                lifeText(i),
                if (trash) i.path.substringBeforeLast('/', "").ifBlank { null }?.let { "из «$it»" } else null,
            ).joinToString(" · ")
            if (meta.isNotBlank()) Text(meta, style = MaterialTheme.typography.bodySmall, color = if (lifeText(i)?.startsWith("удалится") == true) P.warnInk else P.muted, maxLines = 1, overflow = TextOverflow.Ellipsis)
        }
        if (i.life?.pinned == true) Ico("pin", size = 15.dp, tint = P.faint)
        if (i.link != null) Ico("link", size = 15.dp, tint = P.accent)
        Box {
            IconBtn("dots", "Действия", tint = P.muted) { menu = true }
            DropdownMenu(menu, { menu = false }) {
                @Composable fun item(t: String, icon: String, k: String, danger: Boolean = false) =
                    DropdownMenuItem({ Text(t, color = if (danger) P.no else P.text) }, { menu = false; onAction(k, i) }, leadingIcon = { Ico(icon, tint = if (danger) P.no else P.muted) })
                if (trash) { item("Восстановить", "refresh", "restore"); item("Удалить навсегда", "trash", "purge", true) }
                else {
                    if (!i.dir) { item("Сохранить на устройство", "download", "save"); item("Поделиться файлом", "share", "share"); item("Отправить письмом", "mail", "mail") }
                    item(if (i.link != null) "Ссылка…" else "Создать ссылку", "link", "link")
                    item(if (i.life?.pinned == true) "Открепить" else "Закрепить (не удалять по сроку)", "pin", "pin")
                    item("Переименовать", "edit", "rename")
                    item("Перенести…", "move", "move")
                    item("Удалить", "trash", "delete", true)
                }
            }
        }
    }
    Divider()
}

@Composable
private fun LinkDialog(i: CloudItem, onDismiss: () -> Unit, onChanged: () -> Unit) {
    var link by remember { mutableStateOf(i.link) }
    var days by remember { mutableStateOf(30) }
    var withPassword by remember { mutableStateOf(i.link?.hasPassword ?: false) }
    var password by remember { mutableStateOf<String?>(null) }
    val scope = rememberCoroutineScope()
    val api = Session.api!!
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text("Ссылка на «${i.name}»") },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                val l = link
                if (l != null) {
                    Text(l.url, style = MaterialTheme.typography.bodyMedium, color = P.accentInk, modifier = Modifier.clickable { Sys.copy(l.url); Toasts.show("Ссылка скопирована") })
                    Text(l.expiresAt?.let { "Действует до ${Fmt.full(it)}" } ?: "Бессрочная", style = MaterialTheme.typography.bodySmall, color = P.muted)
                    password?.let { Text("Пароль: $it — сообщите получателю, повторно не показывается", color = P.warnInk, style = MaterialTheme.typography.bodyMedium) }
                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        TextButton(onClick = { Sys.copy(l.url); Toasts.show("Ссылка скопирована") }) { Text("Копировать") }
                        TextButton(onClick = { Sys.shareText(l.url) }) { Text("Поделиться") }
                        TextButton(onClick = { scope.launchSafe { api.cloudUnlink(i.path); link = null; onChanged(); Toasts.show("Ссылка удалена") } }) { Text("Удалить", color = P.no) }
                    }
                } else {
                    Text("Срок действия", style = MaterialTheme.typography.labelLarge, color = P.muted)
                    Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                        listOf(7 to "Неделя", 30 to "Месяц", 90 to "3 месяца", 0 to "Бессрочно").forEach { (d, t) -> Chip(t, days == d, { days = d }) }
                    }
                    Row(verticalAlignment = Alignment.CenterVertically) { Text("С паролем", Modifier.weight(1f)); Switch(withPassword, { withPassword = it }) }
                }
            }
        },
        confirmButton = {
            if (link == null) TextButton(onClick = {
                scope.launchSafe {
                    val r = api.cloudLink(i.path, days, withPassword)
                    link = r.link; password = r.link.password
                    Sys.copy(r.link.url); Toasts.show("Ссылка создана и скопирована"); onChanged()
                }
            }) { Text("Создать") } else TextButton(onClick = onDismiss) { Text("Готово") }
        },
        dismissButton = { if (link == null) TextButton(onClick = onDismiss) { Text("Отмена") } },
    )
}

/** Выбор папки облака (перенести). */
@Composable
fun CloudFolderPicker(title: String, onDismiss: () -> Unit, onPick: (String) -> Unit) {
    var path by remember { mutableStateOf("") }
    var list by remember { mutableStateOf<List<CloudItem>?>(null) }
    LaunchedEffect(path) { list = null; list = runCatching { Session.api!!.cloudFolders(path).items }.getOrDefault(emptyList()) }
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(title) },
        text = {
            Column {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    if (path.isNotEmpty()) IconBtn("back", "Выше") { path = path.substringBeforeLast('/', "") }
                    Text("/" + path, style = MaterialTheme.typography.bodyMedium, color = P.muted)
                }
                val l = list
                if (l == null) Loading(Modifier.height(80.dp).fillMaxWidth())
                else if (l.isEmpty()) Text("Вложенных папок нет", color = P.faint, modifier = Modifier.padding(8.dp))
                else LazyColumn(Modifier.height(260.dp)) {
                    items(l.size) { n ->
                        val f = l[n]
                        Row(Modifier.fillMaxWidth().clickable { path = f.path }.padding(vertical = 10.dp), verticalAlignment = Alignment.CenterVertically) {
                            Ico("folder", tint = P.accentInk); Spacer(Modifier.width(10.dp)); Text(f.name)
                        }
                    }
                }
            }
        },
        confirmButton = { TextButton(onClick = { onDismiss(); onPick(path) }) { Text("Сюда") } },
        dismissButton = { TextButton(onClick = onDismiss) { Text("Отмена") } },
    )
}

/** Выбор файлов облака для письма: они уйдут ссылками (как «Из облака» в веб-почте). */
class CloudPickerScreen(val onPicked: (List<CloudFileRef>) -> Unit) : Screen() {
    @Composable
    override fun Content() {
        var path by remember { mutableStateOf("") }
        val chosen = remember { mutableStateListOf<CloudItem>() }
        Column(Modifier.fillMaxSize().background(P.bg)) {
            SubBar("Файлы из облака", if (chosen.isEmpty()) "Выберите файлы" else "Выбрано: ${chosen.size}") {
                TextButton(enabled = chosen.isNotEmpty(), onClick = {
                    onPicked(chosen.map { CloudFileRef(it.path, it.name, it.size ?: 0) }); Nav.pop()
                }) { Text("Приложить", fontWeight = FontWeight.SemiBold) }
            }
            if (path.isNotEmpty()) Row(Modifier.fillMaxWidth().background(P.surface).clickable { path = path.substringBeforeLast('/', "") }.padding(12.dp), verticalAlignment = Alignment.CenterVertically) {
                Ico("back", tint = P.muted); Spacer(Modifier.width(10.dp)); Text("/" + path, color = P.muted)
            }
            su.innotec.mail.ui.mail.Loader(path, { Session.api!!.cloudList(path) }) { l, _ ->
                val items = l.items.sortedWith(compareBy({ !it.dir }, { it.name.lowercase() }))
                if (items.isEmpty()) Empty("cloud", "Папка пуста")
                else LazyColumn(Modifier.fillMaxSize()) {
                    items(items.size) { n ->
                        val i = items[n]
                        val on = chosen.any { it.path == i.path }
                        Row(Modifier.fillMaxWidth().background(P.surface).clickable { if (i.dir) path = i.path else if (on) chosen.removeAll { it.path == i.path } else chosen.add(i) }
                            .padding(horizontal = 8.dp, vertical = 6.dp), verticalAlignment = Alignment.CenterVertically) {
                            if (i.dir) Box(Modifier.size(48.dp), contentAlignment = Alignment.Center) { Ico("folder", tint = P.accentInk) }
                            else Checkbox(on, { v -> if (v) chosen.add(i) else chosen.removeAll { it.path == i.path } })
                            Column(Modifier.weight(1f)) {
                                Text(i.name, maxLines = 1, overflow = TextOverflow.Ellipsis)
                                if (!i.dir) Text(Fmt.size(i.size), style = MaterialTheme.typography.bodySmall, color = P.muted)
                            }
                        }
                        Divider()
                    }
                }
            }
        }
    }
}
