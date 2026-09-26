package su.innotec.mail.ui.mail

import androidx.compose.animation.AnimatedVisibility
import androidx.compose.foundation.ExperimentalFoundationApi
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.combinedClickable
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

    LaunchedEffect(s.scrollTopSignal) { if (s.scrollTopSignal > 0) list.animateScrollToItem(0) }
    LaunchedEffect(s.query) { list.scrollToItem(0) }
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
        FolderPicker("Перенести в папку", s.query.folder, onDismiss = { moveFor = null }) { f -> s.act("move", uids, target = f.path) }
    }
    labelFor?.let { uids -> LabelDialog(uids, onDismiss = { labelFor = null }) }
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

@Composable
private fun SearchBar(onClose: () -> Unit) {
    val s = MailStore
    var text by remember { mutableStateOf(s.query.q) }
    val focus = remember { androidx.compose.ui.focus.FocusRequester() }
    Row(Modifier.fillMaxWidth().height(56.dp).padding(horizontal = 4.dp), verticalAlignment = Alignment.CenterVertically) {
        IconBtn("back", "Закрыть поиск") { onClose() }
        TextField(
            text, { text = it },
            modifier = Modifier.weight(1f).focusRequester(focus).testTag("search-field"),
            placeholder = { Text("Поиск: от:, тема:, файл:, есть:флажок…") },
            singleLine = true,
            colors = TextFieldDefaults.colors(focusedContainerColor = Color.Transparent, unfocusedContainerColor = Color.Transparent, focusedIndicatorColor = Color.Transparent, unfocusedIndicatorColor = Color.Transparent),
            keyboardOptions = KeyboardOptions(imeAction = ImeAction.Search),
            keyboardActions = KeyboardActions(onSearch = { s.search(text, s.query.everywhere) }),
        )
        if (text.isNotEmpty()) IconBtn("x", "Очистить") { text = ""; s.clearSearch() }
    }
    LaunchedEffect(Unit) { runCatching { focus.requestFocus() } }
}

@Composable
private fun SelectionBar(count: Int, onClose: () -> Unit, onAll: () -> Unit, onMove: () -> Unit, onLabel: () -> Unit, onSnooze: () -> Unit) {
    val s = MailStore
    var more by remember { mutableStateOf(false) }
    val uids = s.selected.toList()
    val allSeen = s.messages.filter { it.uid in uids }.all { it.seen }
    val allFlagged = s.messages.filter { it.uid in uids }.all { it.flagged }
    val role = s.currentFolder?.role
    Row(Modifier.fillMaxWidth().height(56.dp).background(P.accentSoft).padding(horizontal = 4.dp), verticalAlignment = Alignment.CenterVertically) {
        IconBtn("x", "Снять выделение", tint = P.accentInk) { onClose() }
        Text("$count", Modifier.weight(1f).padding(start = 4.dp), style = MaterialTheme.typography.titleMedium, color = P.accentInk)
        IconBtn(if (allSeen) "unread" else "eye", if (allSeen) "Непрочитано" else "Прочитано", tint = P.accentInk) { s.act(if (allSeen) "unseen" else "seen", uids) }
        IconBtn("archive", "В архив", tint = P.accentInk) { s.act("archive", uids) }
        IconBtn("trash", "Удалить", tint = P.accentInk, modifier = Modifier.testTag("sel-delete")) { s.act("delete", uids) }
        Box {
            IconBtn("dots", "Ещё", tint = P.accentInk) { more = true }
            DropdownMenu(more, { more = false }) {
                DropdownMenuItem({ Text("Выделить все на экране") }, { more = false; onAll() }, leadingIcon = { Ico("check") })
                if (s.total > s.messages.size || s.messages.size > 1) {
                    DropdownMenuItem({ Text("Все письма папки: прочитаны (${s.total})") }, { more = false; s.actAll("seen") }, leadingIcon = { Ico("eye") })
                }
                DropdownMenuItem({ Text(if (allFlagged) "Снять флажок" else "Флажок") }, { more = false; s.act(if (allFlagged) "unflag" else "flag", uids) }, leadingIcon = { Ico("flag") })
                DropdownMenuItem({ Text("Перенести в папку…") }, { more = false; onMove() }, leadingIcon = { Ico("folder") })
                DropdownMenuItem({ Text("Метка…") }, { more = false; onLabel() }, leadingIcon = { Ico("tag") })
                DropdownMenuItem({ Text("Отложить…") }, { more = false; onSnooze() }, leadingIcon = { Ico("clock") })
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
    val folder = m.folder ?: MailStore.query.folder
    val role = MailStore.folders.firstOrNull { it.path == folder }?.role ?: m.folderRole
    if (role == "drafts") { Nav.push(ComposeScreen(ComposeStart.Draft(m.uid))); return }
    MailStore.markOpened(m.uid)
    if (wide) MailStore.openUid = m.uid else Nav.push(MessageScreen(folder, m.uid))
}

/** Какая строка сейчас «приоткрыта» жестом: открыта всегда одна, остальные закрываются. */
private object SwipeOpen { var key by mutableStateOf<Long?>(null) }

/**
 * Жест по строке — как в почте iOS, Gmail и Outlook:
 *  · тянешь — выезжает полоса действия;
 *  · отпустил, не дотянув до середины, — полоса «залипает» открытой: её можно нажать или закрыть касанием;
 *  · дотянул за 55 % ширины — лёгкая отдача, и при отпускании действие выполняется.
 * Решение — по положению строки с поправкой на скорость (смещение + скорость × 0,18), как в UIKit:
 * короткий быстрый бросок до конца не долетает и письмо не удаляет.
 */
@Composable
private fun SwipeRow(m: MessageSummary, onMove: (List<Long>) -> Unit, onSnooze: (List<Long>) -> Unit, content: @Composable () -> Unit) {
    val prefs = Session.prefs
    val scope = rememberCoroutineScope()
    val haptic = androidx.compose.ui.platform.LocalHapticFeedback.current
    val density = androidx.compose.ui.platform.LocalDensity.current
    val offset = remember(m.uid) { androidx.compose.animation.core.Animatable(0f) }
    var width by remember { mutableStateOf(1f) }
    var armed by remember { mutableStateOf(false) }
    val reveal = with(density) { 96.dp.toPx() }
    // Порог удаления — в «сантиметрах пальца», а не в долях экрана: 45 % строки, но не дальше 200 dp.
    // На широком планшете 55 % строки означало тянуть через пол-экрана.
    val commitPx = with(density) { 200.dp.toPx() }
    fun commitLine() = minOf(width * 0.45f, commitPx)
    val canRight = prefs.swipeRight != "none"
    val canLeft = prefs.swipeLeft != "none"

    // Другую строку открыли — эту закрываем.
    LaunchedEffect(SwipeOpen.key) { if (SwipeOpen.key != m.uid && offset.value != 0f) offset.animateTo(0f) }

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
    // Действия, после которых письмо уходит из папки: строка уезжает целиком, иначе возвращается на место.
    fun leaves(op: String) = op in setOf("delete", "archive", "spam")

    fun commit(right: Boolean) {
        val op = if (right) prefs.swipeRight else prefs.swipeLeft
        scope.launch {
            if (leaves(op)) offset.animateTo(if (right) width else -width, androidx.compose.animation.core.tween(160))
            SwipeOpen.key = null
            perform(op)
            if (!leaves(op)) offset.animateTo(0f)
        }
    }

    val drag = androidx.compose.foundation.gestures.rememberDraggableState { d ->
        val min = if (canLeft) -width else 0f
        val max = if (canRight) width else 0f
        val v = (offset.value + d).coerceIn(min, max)
        scope.launch { offset.snapTo(v) }
        val now = kotlin.math.abs(v) >= commitLine()
        if (now != armed) { armed = now; if (now) haptic.performHapticFeedback(androidx.compose.ui.hapticfeedback.HapticFeedbackType.LongPress) }
    }

    Box(Modifier.fillMaxWidth().onSizeChanged { width = it.width.toFloat().coerceAtLeast(1f) }) {
        val x = offset.value
        if (x != 0f) {
            val right = x > 0
            val op = if (right) prefs.swipeRight else prefs.swipeLeft
            val (icon, color, text) = swipeLook(op)
            val far = kotlin.math.abs(x) >= commitLine()
            // Открытая часть подложки — от края строки до её сдвинутого края; кнопка по центру этой части.
            val revealDp = with(density) { kotlin.math.abs(x).toDp() }
            Box(Modifier.matchParentSize().background(if (far) color else color.copy(alpha = .9f))) {
                Column(
                    Modifier.align(if (right) Alignment.CenterStart else Alignment.CenterEnd).width(revealDp).fillMaxHeight()
                        .clickable { commit(right) },
                    horizontalAlignment = Alignment.CenterHorizontally,
                    verticalArrangement = Arrangement.Center,
                ) {
                    Ico(icon, tint = Color.White, size = if (far) 26.dp else 22.dp)
                    Text(text, color = Color.White, fontWeight = FontWeight.SemiBold, style = MaterialTheme.typography.labelMedium, maxLines = 1)
                }
            }
        }
        Box(
            Modifier.offset { androidx.compose.ui.unit.IntOffset(offset.value.roundToInt(), 0) }
                .draggable(
                    state = drag,
                    orientation = androidx.compose.foundation.gestures.Orientation.Horizontal,
                    onDragStarted = { SwipeOpen.key = m.uid },
                    onDragStopped = { velocity ->
                        val projected = offset.value + velocity * 0.18f
                        armed = false
                        when {
                            kotlin.math.abs(offset.value) >= commitLine() || (kotlin.math.abs(projected) >= commitLine() * 1.5f && kotlin.math.abs(offset.value) >= commitLine() * 0.7f) ->
                                commit(offset.value > 0)
                            kotlin.math.abs(projected) >= reveal / 2 -> offset.animateTo(if (offset.value > 0) reveal else -reveal)
                            else -> { offset.animateTo(0f); if (SwipeOpen.key == m.uid) SwipeOpen.key = null }
                        }
                    },
                ),
        ) {
            content()
            // Открытую полосу закрывает касание по самой строке (а не открывает письмо).
            if (offset.value != 0f) Box(Modifier.matchParentSize().clickable { scope.launch { offset.animateTo(0f) }; SwipeOpen.key = null })
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
