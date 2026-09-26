package su.innotec.mail.ui.mail

import androidx.compose.animation.AnimatedVisibility
import androidx.compose.foundation.ExperimentalFoundationApi
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.combinedClickable
import androidx.compose.foundation.gestures.awaitEachGesture
import androidx.compose.foundation.gestures.awaitFirstDown
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyListState
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.DatePicker
import androidx.compose.material3.DatePickerDialog
import androidx.compose.material3.DrawerValue
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.ExtendedFloatingActionButton
import androidx.compose.material3.FloatingActionButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.ModalDrawerSheet
import androidx.compose.material3.ModalNavigationDrawer
import androidx.compose.material3.SwipeToDismissBox
import androidx.compose.material3.SwipeToDismissBoxValue
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TextField
import androidx.compose.material3.TextFieldDefaults
import androidx.compose.material3.pulltorefresh.PullToRefreshBox
import androidx.compose.material3.rememberDatePickerState
import androidx.compose.material3.rememberDrawerState
import androidx.compose.material3.rememberSwipeToDismissBoxState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.derivedStateOf
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.runtime.snapshotFlow
import androidx.compose.ui.input.pointer.PointerEventPass
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import kotlin.math.roundToInt
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.gestures.draggable
import androidx.compose.ui.layout.onSizeChanged
import androidx.compose.ui.draw.clipToBounds
import androidx.compose.ui.focus.focusRequester
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.flow.distinctUntilChanged
import kotlinx.coroutines.launch
import kotlinx.datetime.TimeZone
import kotlinx.datetime.toLocalDateTime
import kotlin.time.Instant
import su.innotec.mail.DeepLink
import su.innotec.mail.LocalWindow
import su.innotec.mail.Nav
import su.innotec.mail.WindowKind
import su.innotec.mail.api.MessageSummary
import su.innotec.mail.data.Session
import su.innotec.mail.ui.Avatar
import su.innotec.mail.ui.Chip
import su.innotec.mail.ui.Divider
import su.innotec.mail.ui.Empty
import su.innotec.mail.ui.ErrorBox
import su.innotec.mail.ui.Fmt
import su.innotec.mail.ui.Ico
import su.innotec.mail.ui.Loading
import su.innotec.mail.ui.P
import su.innotec.mail.ui.hexColor

@Composable
fun MailHome() {
    LaunchedEffect(Unit) { MailStore.start() }
    // Открыть письмо из уведомления.
    LaunchedEffect(DeepLink.pending) {
        DeepLink.pending?.let { (folder, uid) ->
            DeepLink.pending = null
            Nav.push(MessageScreen(folder, uid))
        }
    }
    LaunchedEffect(DeepLink.mailto) {
        DeepLink.mailto?.let { url ->
            DeepLink.mailto = null
            Nav.push(ComposeScreen(ComposeStart.Mailto(url)))
        }
    }
    when (LocalWindow.current) {
        WindowKind.WIDE -> Row(Modifier.fillMaxSize()) {
            Box(Modifier.width(280.dp).fillMaxHeight().background(P.surface2)) { FolderList(onPicked = {}) }
            Box(Modifier.width(1.dp).fillMaxHeight().background(P.border))
            Box(Modifier.width(400.dp).fillMaxHeight()) { MessageListPane(showMenu = false, onMenu = {}) }
            Box(Modifier.width(1.dp).fillMaxHeight().background(P.border))
            Box(Modifier.weight(1f).fillMaxHeight().background(P.surface)) { ReaderPane() }
        }
        WindowKind.TABLET -> WithDrawer { open ->
            Row(Modifier.fillMaxSize()) {
                Box(Modifier.width(360.dp).fillMaxHeight()) { MessageListPane(showMenu = true, onMenu = open) }
                Box(Modifier.width(1.dp).fillMaxHeight().background(P.border))
                Box(Modifier.weight(1f).fillMaxHeight().background(P.surface)) { ReaderPane() }
            }
        }
        WindowKind.PHONE -> WithDrawer { open -> MessageListPane(showMenu = true, onMenu = open) }
    }
}

@Composable
private fun WithDrawer(content: @Composable (open: () -> Unit) -> Unit) {
    val drawer = rememberDrawerState(DrawerValue.Closed)
    val scope = rememberCoroutineScope()
    su.innotec.mail.platform.BackHandler(drawer.isOpen) { scope.launch { drawer.close() } }
    // Жестом от края не открываем: он перехватывал смахивание строк (удаление вместо папок).
    // clipToBounds — закрытая панель не должна вылезать левее своей области (на планшете — на полосу разделов).
    ModalNavigationDrawer(
        modifier = Modifier.clipToBounds(),
        gesturesEnabled = drawer.isOpen,
        drawerState = drawer,
        drawerContent = {
            ModalDrawerSheet(drawerContainerColor = P.surface, modifier = Modifier.width(300.dp)) {
                FolderList(onPicked = { scope.launch { drawer.close() } })
            }
        },
    ) { content { scope.launch { drawer.open() } } }
}

/** Правая панель планшета: открытое письмо или заглушка. */
@Composable
private fun ReaderPane() {
    val uid = MailStore.openUid
    if (uid == null) {
        Empty("mail", "Выберите письмо", "Оно откроется здесь")
    } else {
        val folder = MailStore.folderOf(uid)
        androidx.compose.runtime.key(folder, uid) { MessageContent(folder, uid, inPane = true, onClose = { MailStore.openUid = null }) }
    }
}

@OptIn(ExperimentalMaterial3Api::class, ExperimentalFoundationApi::class)
@Composable
private fun MessageListPane(showMenu: Boolean, onMenu: () -> Unit) {
    val s = MailStore
    val list = rememberLazyListState()
    val scope = rememberCoroutineScope()
    var searching by remember { mutableStateOf(s.query.q.isNotEmpty()) }
    var menu by remember { mutableStateOf(false) }
    var pickDate by remember { mutableStateOf(false) }
    var moveFor by remember { mutableStateOf<List<Long>?>(null) }
    var labelFor by remember { mutableStateOf<List<Long>?>(null) }
    var snoozeFor by remember { mutableStateOf<List<Long>?>(null) }
    val wide = LocalWindow.current != WindowKind.PHONE
    val selecting = s.selected.isNotEmpty()
    LaunchedEffect(selecting) { if (!selecting) s.allFolder = false }
    var confirmAll by remember { mutableStateOf<String?>(null) }

    LaunchedEffect(s.scrollTopSignal) { if (s.scrollTopSignal > 0) list.animateScrollToItem(0) }
    LaunchedEffect(s.query) { list.scrollToItem(0) }
    // Продолжили работать — прокрутили список: приоткрытая жестом строка закрывается.
    LaunchedEffect(list.isScrollInProgress) { if (list.isScrollInProgress) SwipeOpen.key = null }
    // Подгрузка при прокрутке к концу.
    LaunchedEffect(list) {
        snapshotFlow { list.layoutInfo.visibleItemsInfo.lastOrNull()?.index ?: 0 }
            .distinctUntilChanged()
            .collect { last -> if (last >= s.messages.size - 10) s.loadMore() }
    }

    su.innotec.mail.platform.BackHandler(selecting) { s.selected.clear() }
    su.innotec.mail.platform.BackHandler(!selecting && searching) { searching = false; s.clearSearch() }

    Box(Modifier.fillMaxSize().background(P.bg)) {
        Column(Modifier.fillMaxSize()) {
            // ---------- верхняя панель ----------
            Column(Modifier.background(P.surface).statusBarsPadding()) {
                when {
                    selecting -> SelectionBar(
                        count = s.selected.size,
                        onClose = { s.selected.clear() },
                        onAll = { s.selected.clear(); s.selected.addAll(s.messages.map { it.uid }) },
                        onMove = { moveFor = s.selected.toList() },
                        onLabel = { labelFor = s.selected.toList() },
                        onSnooze = { snoozeFor = s.selected.toList() },
                        onConfirmAll = { confirmAll = it },
                    )
                    searching -> SearchBar(onClose = { searching = false; s.clearSearch() })
                    else -> Row(Modifier.fillMaxWidth().height(56.dp).padding(horizontal = 4.dp), verticalAlignment = Alignment.CenterVertically) {
                        if (showMenu) IconBtn("menu", "Папки", Modifier.testTag("open-folders")) { onMenu() }
                        else Spacer(Modifier.width(12.dp))
                        Column(Modifier.weight(1f).padding(horizontal = 4.dp)) {
                            Text(listTitle(s.query), style = MaterialTheme.typography.titleMedium, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.testTag("list-title"))
                            if (s.total > 0) Text("${s.total} ${Fmt.plural(s.total, "письмо", "письма", "писем")}", style = MaterialTheme.typography.bodySmall, color = P.muted)
                        }
                        IconBtn("search", "Поиск", Modifier.testTag("search")) { searching = true }
                        Box {
                            IconBtn("dots", "Ещё") { menu = true }
                            DropdownMenu(menu, { menu = false }) {
                                DropdownMenuItem({ Text("Обновить") }, { menu = false; s.load(); s.refreshFolders() }, leadingIcon = { Ico("refresh") })
                                DropdownMenuItem({ Text("Перейти к дате…") }, { menu = false; pickDate = true }, leadingIcon = { Ico("cal") })
                                DropdownMenuItem({ Text("Выбрать письма") }, { menu = false; s.messages.firstOrNull()?.let { s.selected.add(it.uid) } }, leadingIcon = { Ico("check") })
                                Divider()
                                listOf("date" to "Сначала новые", "date-asc" to "Сначала старые", "from" to "По отправителю", "subject" to "По теме", "size" to "По размеру").forEach { (k, t) ->
                                    DropdownMenuItem({ Text(t, fontWeight = if (s.query.sort == k) FontWeight.SemiBold else FontWeight.Normal) }, { menu = false; s.setSort(k) },
                                        trailingIcon = { if (s.query.sort == k) Ico("check", tint = P.accent) })
                                }
                            }
                        }
                    }
                }
                if (!selecting) FilterChips()
                if (s.offline) Row(Modifier.fillMaxWidth().background(P.warnSoft).padding(horizontal = 16.dp, vertical = 6.dp), verticalAlignment = Alignment.CenterVertically) {
                    Ico("warn", size = 16.dp, tint = P.warnInk); Spacer(Modifier.width(8.dp))
                    Text("Нет связи — показаны сохранённые письма", Modifier.weight(1f), style = MaterialTheme.typography.bodySmall, color = P.warnInk)
                    TextButton(onClick = { s.load() }) { Text("Повторить") }
                }
                Divider()
            }

            // ---------- список ----------
            PullToRefreshBox(isRefreshing = s.loading && s.messages.isNotEmpty(), onRefresh = { s.load(); s.refreshFolders() }, modifier = Modifier.weight(1f)) {
                when {
                    s.loading && s.messages.isEmpty() -> Loading()
                    s.error != null && s.messages.isEmpty() -> ErrorBox(s.error!!, { s.load() })
                    s.messages.isEmpty() -> Empty(
                        if (s.query.q.isNotEmpty()) "search" else "inbox",
                        if (s.query.q.isNotEmpty()) "Ничего не нашлось" else "Писем нет",
                        if (s.query.q.isNotEmpty() && !s.query.everywhere) "Попробуйте искать по всей почте" else null,
                    )
                    else -> MessageRows(list, onMove = { moveFor = it }, onSnooze = { snoozeFor = it })
                }
            }
        }

        if (!selecting) {
            ExtendedFloatingActionButton(
                onClick = { Nav.push(ComposeScreen(ComposeStart.New())) },
                containerColor = P.accent, contentColor = P.accentOn,
                modifier = Modifier.align(Alignment.BottomEnd).padding(16.dp).testTag("compose"),
                icon = { Ico("edit") }, text = { Text("Написать") },
                expanded = wide || !list.isScrollInProgress,
            )
        }
    }

    if (pickDate) {
        val st = rememberDatePickerState()
        DatePickerDialog(
            onDismissRequest = { pickDate = false },
            confirmButton = {
                TextButton(onClick = {
                    pickDate = false
                    st.selectedDateMillis?.let { ms ->
                        val d = Instant.fromEpochMilliseconds(ms).toLocalDateTime(TimeZone.UTC).date
                        s.jumpToDate(d.toString()) { off -> scope.launch { list.scrollToItem(off) } }
                    }
                }) { Text("Перейти") }
            },
            dismissButton = { TextButton(onClick = { pickDate = false }) { Text("Отмена") } },
        ) { DatePicker(st, title = { Text("К письмам за дату", Modifier.padding(start = 24.dp, top = 16.dp)) }) }
    }
    moveFor?.let { uids ->
        FolderPicker("Перенести в папку", s.query.folder, onDismiss = { moveFor = null }) { f -> if (s.allFolder) s.actAll("move", target = f.path) else s.act("move", uids, target = f.path) }
    }
    labelFor?.let { uids ->
        if (s.allFolder) su.innotec.mail.ui.ChoiceDialog("Метка для всех ${s.total} ${Fmt.plural(s.total, "письма", "писем", "писем")}", s.labels, { it.name }, null, onDismiss = { labelFor = null }) { l -> s.actAll("label", label = l.id) }
        else LabelDialog(uids, onDismiss = { labelFor = null })
    }
    confirmAll?.let { op ->
        su.innotec.mail.ui.ConfirmDialog(
            (if (op == "delete") "Удалить" else "В архив") + " все ${s.total} ${Fmt.plural(s.total, "письмо", "письма", "писем")}?",
            "Действие коснётся всех писем папки" + (if (s.query.q.isNotEmpty() || s.query.filter != "all") " по текущему отбору" else "") + ", а не только видимых на экране.",
            if (op == "delete") "Удалить" else "В архив", danger = op == "delete", onDismiss = { confirmAll = null },
        ) { s.actAll(op) }
    }
    snoozeFor?.let { uids -> SnoozeDialog(onDismiss = { snoozeFor = null }) { until -> s.act("snooze", uids, until = until) } }
}

@Composable
fun IconBtn(icon: String, label: String, modifier: Modifier = Modifier, tint: Color = P.text, onClick: () -> Unit) {
    Box(modifier.size(44.dp).clip(CircleShape).clickable(onClick = onClick), contentAlignment = Alignment.Center) {
        Ico(icon, tint = tint, contentDescription = label)
    }
}

@Composable
private fun FilterChips() {
    val s = MailStore
    val f = s.query.filter
    val base = listOf("all" to "Все", "unread" to "Непрочитанные", "flagged" to "С флажком", "attach" to "С вложениями")
    Row(Modifier.fillMaxWidth().horizontalScroll(rememberScrollState()).padding(horizontal = 12.dp, vertical = 8.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        base.forEach { (k, t) -> Chip(t, f == k, { s.setFilter(k) }) }
        if (s.query.q.isNotEmpty()) Chip("Везде", s.query.everywhere, { s.search(s.query.q, !s.query.everywhere) }, icon = "globe")
    }
}

/** Где искать — как переключатель поля поиска в веб-почте; превращается в оператор сервера (от:, тема: …). */
private val SCOPES = listOf("" to "Везде", "от" to "От кого", "кому" to "Кому", "переписка" to "Переписка", "тема" to "Тема", "текст" to "Текст", "файл" to "Файл")

@Composable
private fun SearchBar(onClose: () -> Unit) {
    val s = MailStore
    var text by remember { mutableStateOf(s.query.q) }
    var scope by remember { mutableStateOf("") }
    val focus = remember { androidx.compose.ui.focus.FocusRequester() }
    fun go() {
        val t = text.trim()
        // Оператор уже набран руками (от:ivan) — оставляем как есть.
        val q = if (scope.isEmpty() || t.isEmpty() || Regex("^\\p{L}+:").containsMatchIn(t)) t else "$scope:" + if (t.contains(' ')) "\"$t\"" else t
        s.search(q, s.query.everywhere)
    }
    Column {
        Row(Modifier.fillMaxWidth().height(56.dp).padding(horizontal = 4.dp), verticalAlignment = Alignment.CenterVertically) {
            IconBtn("back", "Закрыть поиск") { onClose() }
            TextField(
                text, { text = it },
                modifier = Modifier.weight(1f).focusRequester(focus).testTag("search-field"),
                placeholder = { Text(if (scope.isEmpty()) "Поиск: от:, тема:, файл:, есть:флажок…" else "Искать: " + SCOPES.first { it.first == scope }.second.lowercase()) },
                singleLine = true,
                colors = TextFieldDefaults.colors(focusedContainerColor = Color.Transparent, unfocusedContainerColor = Color.Transparent, focusedIndicatorColor = Color.Transparent, unfocusedIndicatorColor = Color.Transparent),
                keyboardOptions = KeyboardOptions(imeAction = ImeAction.Search),
                keyboardActions = KeyboardActions(onSearch = { go() }),
            )
            if (text.isNotEmpty()) IconBtn("x", "Очистить") { text = ""; s.clearSearch() }
        }
        Row(Modifier.fillMaxWidth().horizontalScroll(rememberScrollState()).padding(start = 12.dp, end = 12.dp, bottom = 6.dp), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            SCOPES.forEach { (k, t) -> Chip(t, scope == k, { scope = k; if (text.isNotBlank()) go() }) }
        }
    }
    LaunchedEffect(Unit) { runCatching { focus.requestFocus() } }
}

@Composable
private fun SelectionBar(count: Int, onClose: () -> Unit, onAll: () -> Unit, onMove: () -> Unit, onLabel: () -> Unit, onSnooze: () -> Unit, onConfirmAll: (String) -> Unit) {
    val s = MailStore
    var more by remember { mutableStateOf(false) }
    var remind by remember { mutableStateOf(false) }
    val uids = s.selected.toList()
    if (remind) RemindDialog(onDismiss = { remind = false }) { until -> s.act("remind", uids, until = until) }
    val allSeen = s.messages.filter { it.uid in uids }.all { it.seen }
    val allFlagged = s.messages.filter { it.uid in uids }.all { it.flagged }
    val role = s.currentFolder?.role
    Row(Modifier.fillMaxWidth().height(56.dp).background(P.accentSoft).padding(horizontal = 4.dp), verticalAlignment = Alignment.CenterVertically) {
        IconBtn("x", "Снять выделение", tint = P.accentInk) { onClose() }
        val all = s.allFolder
        Text(if (all) "Все ${s.total}" else "$count", Modifier.weight(1f).padding(start = 4.dp), style = MaterialTheme.typography.titleMedium, color = P.accentInk, maxLines = 1)
        IconBtn(if (allSeen) "unread" else "eye", if (allSeen) "Непрочитано" else "Прочитано", tint = P.accentInk) { val op = if (allSeen) "unseen" else "seen"; if (all) s.actAll(op) else s.act(op, uids) }
        IconBtn("archive", "В архив", tint = P.accentInk) { if (all) onConfirmAll("archive") else s.act("archive", uids) }
        IconBtn("trash", "Удалить", tint = P.accentInk, modifier = Modifier.testTag("sel-delete")) { if (all) onConfirmAll("delete") else s.act("delete", uids) }
        Box {
            IconBtn("dots", "Ещё", tint = P.accentInk) { more = true }
            DropdownMenu(more, { more = false }) {
                if (!all) DropdownMenuItem({ Text("Выделить все на экране") }, { more = false; onAll() }, leadingIcon = { Ico("check") })
                if (!all && s.total > s.messages.size) DropdownMenuItem({ Text("Выбрать все письма папки (${s.total})") }, { more = false; onAll(); s.allFolder = true }, leadingIcon = { Ico("check") })
                if (all) DropdownMenuItem({ Text("Только видимые на экране") }, { more = false; s.allFolder = false }, leadingIcon = { Ico("check") })
                DropdownMenuItem({ Text(if (allFlagged) "Снять флажок" else "Флажок") }, { more = false; val op = if (allFlagged) "unflag" else "flag"; if (all) s.actAll(op) else s.act(op, uids) }, leadingIcon = { Ico("flag") })
                DropdownMenuItem({ Text("Перенести в папку…") }, { more = false; onMove() }, leadingIcon = { Ico("folder") })
                DropdownMenuItem({ Text("Метка…") }, { more = false; onLabel() }, leadingIcon = { Ico("tag") })
                if (all) return@DropdownMenu
                DropdownMenuItem({ Text("Отложить…") }, { more = false; onSnooze() }, leadingIcon = { Ico("clock") })
                DropdownMenuItem({ Text("Напомнить, если не ответят…") }, { more = false; remind = true }, leadingIcon = { Ico("bell") })
                if (role == "spam") DropdownMenuItem({ Text("Не спам") }, { more = false; s.act("notspam", uids) }, leadingIcon = { Ico("inbox") })
                else DropdownMenuItem({ Text("Это спам") }, { more = false; s.act("spam", uids) }, leadingIcon = { Ico("spam") })
                if (role != "lists") DropdownMenuItem({ Text("Это рассылка") }, { more = false; s.act("lists", uids) }, leadingIcon = { Ico("ul") })
                if (role == "snoozed") DropdownMenuItem({ Text("Вернуть во «Входящие»") }, { more = false; s.act("unsnooze", uids) }, leadingIcon = { Ico("inbox") })
                if (count == 1 || uids.size <= 20) DropdownMenuItem({ Text("Переслать вложением") }, {
                    more = false
                    Nav.push(ComposeScreen(ComposeStart.ForwardAsAttachment(uids.map { u -> s.folderOf(u) to u })))
                    s.selected.clear()
                }, leadingIcon = { Ico("fwd") })
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class, ExperimentalFoundationApi::class)
@Composable
private fun MessageRows(list: LazyListState, onMove: (List<Long>) -> Unit, onSnooze: (List<Long>) -> Unit) {
    val s = MailStore
    val groups = remember(s.messages.toList(), s.query.sort) {
        if (s.query.sort != "date" && s.query.sort != "date-asc") listOf("" to s.messages.toList())
        else s.messages.toList().groupBy { Fmt.group(it.date) }.toList()
    }
    val wide = LocalWindow.current != WindowKind.PHONE
    LazyColumn(state = list, modifier = Modifier.fillMaxSize().testTag("message-list")) {
        groups.forEach { (title, rows) ->
            if (title.isNotEmpty()) item(key = "g:$title") {
                Text(title.uppercase(), Modifier.fillMaxWidth().background(P.bg).padding(start = 16.dp, top = 12.dp, bottom = 6.dp),
                    style = MaterialTheme.typography.labelMedium, color = P.faint)
            }
            items(rows.size, key = { i -> "m:" + (rows[i].folder ?: "") + ":" + rows[i].uid }) { i ->
                val m = rows[i]
                SwipeRow(m, onMove = onMove, onSnooze = onSnooze) {
                    MessageRow(
                        m,
                        selected = m.uid in s.selected,
                        open = wide && s.openUid == m.uid,
                        selecting = s.selected.isNotEmpty(),
                        onClick = {
                            if (s.selected.isNotEmpty()) toggle(m.uid)
                            else openMessage(m, wide)
                        },
                        onLong = { toggle(m.uid) },
                    )
                }
            }
        }
        if (s.loadingMore) item { Box(Modifier.fillMaxWidth().padding(16.dp), contentAlignment = Alignment.Center) { androidx.compose.material3.CircularProgressIndicator(Modifier.size(22.dp), strokeWidth = 2.dp) } }
        item { Spacer(Modifier.height(96.dp)) }
    }
}

private fun toggle(uid: Long) {
    val s = MailStore.selected
    if (uid in s) s.remove(uid) else s.add(uid)
}

private fun openMessage(m: MessageSummary, wide: Boolean) {
    SwipeOpen.key = null
    val folder = m.folder ?: MailStore.query.folder
    val role = MailStore.folders.firstOrNull { it.path == folder }?.role ?: m.folderRole
    if (role == "drafts") { Nav.push(ComposeScreen(ComposeStart.Draft(m.uid))); return }
    MailStore.markOpened(m.uid)
    if (wide) MailStore.openUid = m.uid else Nav.push(MessageScreen(folder, m.uid))
}

/** Какая строка сейчас «приоткрыта» жестом: открыта всегда одна; прокрутка списка или другая строка её закрывают. */
object SwipeOpen { var key by mutableStateOf<Long?>(null) }

/**
 * Жест по строке — как в почте iOS, Gmail и Outlook. После отпускания строка всегда оказывается ровно
 * в одном из трёх положений, в промежуточном не остаётся никогда:
 *  · закрыта — сдвиг меньше касания пальца (16 dp);
 *  · открыта на ширину кнопки (88 dp) — любой больший сдвиг, в том числе случайный и неторопливый;
 *    любое другое действие (прокрутка, касание письма, другая строка) её закрывает;
 *  · действие выполнено — если:
 *      – нажали на открытую кнопку;
 *      – по открытой строке ещё раз провели в ту же сторону, на любую длину: повтор жеста — это намерение;
 *      – одним уверенным жестом: строка ушла заметно дальше кнопки (140 dp), а палец ещё быстро шёл дальше;
 *      – или дотянули за порог (45 % строки, но не дальше 200 dp).
 *    Жест по открытой строке в обратную сторону её закрывает.
 * Положение во время перетаскивания меняется напрямую (без очереди корутин): раньше запоздалый шаг
 * перетаскивания отменял доводку, и строка застревала где бросили.
 */
@Composable
private fun SwipeRow(m: MessageSummary, onMove: (List<Long>) -> Unit, onSnooze: (List<Long>) -> Unit, content: @Composable () -> Unit) {
    val prefs = Session.prefs
    val scope = rememberCoroutineScope()
    val haptic = androidx.compose.ui.platform.LocalHapticFeedback.current
    val density = androidx.compose.ui.platform.LocalDensity.current
    var x by remember(m.uid) { mutableStateOf(0f) }
    var settle by remember { mutableStateOf<kotlinx.coroutines.Job?>(null) }
    var width by remember { mutableStateOf(1f) }
    var armed by remember { mutableStateOf(false) }
    var busy by remember(m.uid) { mutableStateOf(false) }   // действие уже выполняется — не закрывать строку
    var startX by remember { mutableStateOf(0f) }            // где была строка, когда жест начался
    val reveal = with(density) { 88.dp.toPx() }
    val slop = with(density) { 16.dp.toPx() }
    val commitPx = with(density) { 200.dp.toPx() }
    // Удаление одним движением: строка ушла заметно дальше кнопки (зона «залипания» 88…140 dp держит её
    // открытой) и палец в момент отпускания ещё быстро шёл дальше. При 88 dp и 1000 dp/с срабатывало слишком легко.
    val flingDist = with(density) { 140.dp.toPx() }
    val flingPx = with(density) { 1600.dp.toPx() }          // скорость пальца, в секунду
    fun commitLine() = minOf(width * 0.45f, commitPx)
    val canRight = prefs.swipeRight != "none"
    val canLeft = prefs.swipeLeft != "none"

    fun animateTo(target: Float, after: () -> Unit = {}) {
        settle?.cancel()
        settle = scope.launch {
            androidx.compose.animation.core.animate(x, target, animationSpec = androidx.compose.animation.core.tween(180)) { v, _ -> x = v }
            after()
        }
    }

    // Открыли другую строку или прокрутили список — эта закрывается.
    // Своя строка, выполняющая действие, не закрывается: иначе закрытие отменило бы доводку и само действие.
    LaunchedEffect(SwipeOpen.key) { if (SwipeOpen.key != m.uid && x != 0f && !busy) animateTo(0f) }

    fun perform(op: String) {
        when (op) {
            "read" -> MailStore.act(if (m.seen) "unseen" else "seen", listOf(m.uid))
            "flag" -> MailStore.act(if (m.flagged) "unflag" else "flag", listOf(m.uid))
            "move" -> onMove(listOf(m.uid))
            "snooze" -> onSnooze(listOf(m.uid))
            "none" -> {}
            else -> MailStore.act(op, listOf(m.uid))
        }
    }
    fun leaves(op: String) = op in setOf("delete", "archive", "spam")

    fun commit(right: Boolean) {
        val op = if (right) prefs.swipeRight else prefs.swipeLeft
        busy = true
        SwipeOpen.key = null
        if (leaves(op)) animateTo(if (right) width else -width) { perform(op); busy = false }
        else { perform(op); animateTo(0f) { busy = false } }
    }

    val drag = androidx.compose.foundation.gestures.rememberDraggableState { d ->
        val min = if (canLeft) -width else 0f
        val max = if (canRight) width else 0f
        x = (x + d).coerceIn(min, max)
        val now = kotlin.math.abs(x) >= commitLine() || (startX != 0f && x * startX > 0 && kotlin.math.abs(x) - kotlin.math.abs(startX) >= slop)
        if (now != armed) { armed = now; if (now) haptic.performHapticFeedback(androidx.compose.ui.hapticfeedback.HapticFeedbackType.LongPress) }
    }

    Box(Modifier.fillMaxWidth().onSizeChanged { width = it.width.toFloat().coerceAtLeast(1f) }) {
        if (x != 0f) {
            val right = x > 0
            val op = if (right) prefs.swipeRight else prefs.swipeLeft
            val (icon, color, text) = swipeLook(op)
            val far = kotlin.math.abs(x) >= commitLine()
            val revealDp = with(density) { kotlin.math.abs(x).toDp() }
            Box(Modifier.matchParentSize().background(if (far) color else color.copy(alpha = .9f))) {
                Column(
                    Modifier.align(if (right) Alignment.CenterStart else Alignment.CenterEnd).width(revealDp).fillMaxHeight()
                        .clickable { commit(right) },
                    horizontalAlignment = Alignment.CenterHorizontally,
                    verticalArrangement = Arrangement.Center,
                ) {
                    if (revealDp > 40.dp) {
                        Ico(icon, tint = Color.White, size = if (far) 26.dp else 22.dp)
                        Text(text, color = Color.White, fontWeight = FontWeight.SemiBold, style = MaterialTheme.typography.labelMedium, maxLines = 1)
                    }
                }
            }
        }
        Box(
            Modifier.offset { androidx.compose.ui.unit.IntOffset(x.roundToInt(), 0) }
                // Открытую полосу закрывает касание по строке (а не открывает письмо). Ловим только касание
                // и только его отпускание забираем себе: жест дальше идёт в draggable, и открытую полосу
                // можно дотянуть до действия. Прозрачный слой поверх строки съедал и жест.
                .pointerInput(m.uid) {
                    awaitEachGesture {
                        val down = awaitFirstDown(requireUnconsumed = false, pass = PointerEventPass.Initial)
                        if (x == 0f) return@awaitEachGesture
                        while (true) {
                            val c = awaitPointerEvent(PointerEventPass.Initial).changes.firstOrNull { it.id == down.id } ?: break
                            if ((c.position - down.position).getDistance() > viewConfiguration.touchSlop) break
                            if (!c.pressed) { c.consume(); animateTo(0f); SwipeOpen.key = null; break }
                        }
                    }
                }
                .draggable(
                    state = drag,
                    orientation = androidx.compose.foundation.gestures.Orientation.Horizontal,
                    onDragStarted = { settle?.cancel(); startX = if (busy) 0f else x; SwipeOpen.key = m.uid },
                    onDragStopped = { velocity ->
                        armed = false
                        val a = kotlin.math.abs(x)
                        val wasOpen = startX != 0f
                        val sameSide = x * startX > 0
                        val further = a - kotlin.math.abs(startX)
                        val flingOn = velocity * x > 0 && kotlin.math.abs(velocity) >= flingPx
                        when {
                            a >= commitLine() -> commit(x > 0)
                            wasOpen && sameSide && further >= slop -> commit(x > 0)
                            wasOpen && (!sameSide || -further >= slop) -> { animateTo(0f); if (SwipeOpen.key == m.uid) SwipeOpen.key = null }
                            !wasOpen && a >= flingDist && flingOn -> commit(x > 0)
                            a >= slop -> animateTo(if (x > 0) reveal else -reveal)
                            else -> { animateTo(0f); if (SwipeOpen.key == m.uid) SwipeOpen.key = null }
                        }
                    },
                ),
        ) {
            content()
        }
    }
}

private fun swipeLook(op: String): Triple<String, Color, String> = when (op) {
    "delete" -> Triple("trash", Color(0xFFC0392B), "Удалить")
    "archive" -> Triple("archive", Color(0xFF16A05C), "В архив")
    "read" -> Triple("eye", Color(0xFF2F6FEB), "Прочитано")
    "flag" -> Triple("flag", Color(0xFFD9791F), "Флажок")
    "move" -> Triple("folder", Color(0xFF5C6BC0), "В папку")
    "snooze" -> Triple("clock", Color(0xFF8E44AD), "Отложить")
    "spam" -> Triple("spam", Color(0xFF6D4C41), "Спам")
    else -> Triple("dots", Color.Gray, "")
}

@OptIn(ExperimentalFoundationApi::class)
@Composable
fun MessageRow(m: MessageSummary, selected: Boolean, open: Boolean, selecting: Boolean, onClick: () -> Unit, onLong: () -> Unit) {
    val s = MailStore
    val role = s.currentFolder?.role
    val who = if (role == "sent" || role == "drafts") (m.toName.ifBlank { m.toMail }).let { "Кому: $it" } else m.from.display
    val bg = when {
        selected -> P.accentSoft
        open -> P.accentSoft
        else -> P.surface
    }
    Row(
        Modifier.fillMaxWidth().background(bg).combinedClickable(onClick = onClick, onLongClick = onLong).padding(start = 12.dp, end = 14.dp, top = 10.dp, bottom = 10.dp).testTag("row-${m.uid}"),
        verticalAlignment = Alignment.Top,
    ) {
        Box(Modifier.padding(top = 2.dp).clickable(onClick = onLong)) {
            if (selected) Box(Modifier.size(40.dp).clip(CircleShape).background(P.accent), contentAlignment = Alignment.Center) { Ico("check", tint = P.accentOn) }
            else Avatar(m.from.display.ifBlank { "?" }, m.from.mail)
        }
        Spacer(Modifier.width(12.dp))
        Column(Modifier.weight(1f)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                if (!m.seen) { Box(Modifier.size(8.dp).clip(CircleShape).background(P.accent)); Spacer(Modifier.width(6.dp)) }
                Text(who, Modifier.weight(1f), style = MaterialTheme.typography.bodyLarge, fontWeight = if (m.seen) FontWeight.Normal else FontWeight.SemiBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
                if (m.hasAttachments) { Ico("clip", size = 15.dp, tint = P.faint); Spacer(Modifier.width(4.dp)) }
                Text(Fmt.listDate(m.date), style = MaterialTheme.typography.bodySmall, color = if (m.seen) P.faint else P.accentInk, fontWeight = if (m.seen) FontWeight.Normal else FontWeight.SemiBold)
            }
            Row(verticalAlignment = Alignment.CenterVertically) {
                if (m.answered) { Ico("reply", size = 14.dp, tint = P.faint); Spacer(Modifier.width(4.dp)) }
                Text(m.subject.ifBlank { "(без темы)" }, Modifier.weight(1f), style = MaterialTheme.typography.bodyMedium, fontWeight = if (m.seen) FontWeight.Normal else FontWeight.Medium,
                    color = P.text, maxLines = 1, overflow = TextOverflow.Ellipsis)
                if (m.flagged) { Spacer(Modifier.width(4.dp)); Ico("flag", size = 15.dp, tint = P.warn) }
            }
            val preview = m.preview?.trim().orEmpty()
            if (preview.isNotEmpty()) Text(preview, style = MaterialTheme.typography.bodySmall, color = P.muted, maxLines = 2, overflow = TextOverflow.Ellipsis)
            if (m.labels.isNotEmpty() || (m.folder != null && s.query.everywhere)) {
                Row(Modifier.padding(top = 4.dp), horizontalArrangement = Arrangement.spacedBy(4.dp)) {
                    if (s.query.everywhere && m.folderName != null) TagPill(m.folderName, P.muted)
                    m.labels.mapNotNull { id -> s.labels.firstOrNull { it.id == id } }.forEach { l -> TagPill(l.name, hexColor(l.color)) }
                }
            }
        }
    }
}

@Composable
fun TagPill(text: String, color: Color) {
    Text(text, Modifier.clip(RoundedCornerShape(4.dp)).background(color.copy(alpha = .15f)).padding(horizontal = 6.dp, vertical = 1.dp),
        style = MaterialTheme.typography.labelSmall, color = color, maxLines = 1)
}
