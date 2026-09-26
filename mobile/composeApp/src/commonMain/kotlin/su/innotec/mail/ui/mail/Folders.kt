package su.innotec.mail.ui.mail

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.combinedClickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Button
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
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
import su.innotec.mail.Nav
import su.innotec.mail.api.Folder
import su.innotec.mail.data.Session
import su.innotec.mail.ui.ConfirmDialog
import su.innotec.mail.ui.Ico
import su.innotec.mail.ui.InputDialog
import su.innotec.mail.ui.Fmt
import su.innotec.mail.ui.P
import su.innotec.mail.ui.SectionTitle
import su.innotec.mail.ui.hexColor
import su.innotec.mail.ui.launchSafe
import su.innotec.mail.ui.Toasts

/** Порядок и значки системных папок — как FolderNav.vue. */
private val ROLE_ORDER = mapOf("inbox" to 0, "drafts" to 1, "sent" to 2, "archive" to 3, "lists" to 4, "spam" to 5, "trash" to 6, "snoozed" to 7)

fun roleIcon(role: String): String = when (role) {
    "inbox" -> "inbox"; "drafts" -> "edit"; "sent" -> "send"; "archive" -> "archive"; "lists" -> "ul"
    "spam" -> "spam"; "trash" -> "trash"; "snoozed" -> "clock"; "shared" -> "users"
    else -> "folder"
}

/** Заголовок списка: «Важное», метка или имя папки. */
fun listTitle(q: ListQuery): String {
    if (q.q.isNotBlank()) return if (q.everywhere) "Поиск по всей почте" else "Поиск"
    if (q.filter == "flagged") return "Важное"
    if (q.filter.startsWith("label:")) return MailStore.labels.firstOrNull { "label:" + it.id == q.filter }?.name ?: "Метка"
    return MailStore.folders.firstOrNull { it.path == q.folder }?.let { f -> if (f.isShared) "${f.name} · ${f.ownerName.ifBlank { f.owner }}" else f.name }
        ?: if (q.folder.equals("INBOX", true)) "Входящие" else q.folder.substringAfterLast('/')
}

data class FolderGroups(val system: List<Folder>, val custom: List<Folder>, val shared: Map<String, List<Folder>>)

fun groupFolders(all: List<Folder>): FolderGroups {
    val own = all.filter { !it.isShared }
    val system = own.filter { it.role in ROLE_ORDER }.sortedBy { ROLE_ORDER[it.role] }
    val custom = own.filter { it.role !in ROLE_ORDER }
    val shared = all.filter { it.isShared }.groupBy { it.ownerName.ifBlank { it.owner } }
    return FolderGroups(system, custom, shared)
}

/** Список папок: боковая панель (планшет) и выдвижное меню (телефон). */
@Composable
fun FolderList(onPicked: () -> Unit, modifier: Modifier = Modifier) {
    val store = MailStore
    val g = groupFolders(store.folders)
    val q = store.query
    var menuFor by remember { mutableStateOf<Folder?>(null) }
    var dialog by remember { mutableStateOf<String?>(null) }
    val scope = rememberCoroutineScope()
    val acc = Session.account

    LazyColumn(modifier.fillMaxSize().testTag("folders")) {
        item {
            Column(Modifier.statusBarsPadding().padding(start = 16.dp, end = 16.dp, top = 16.dp, bottom = 8.dp)) {
                Text(acc?.name?.ifBlank { null } ?: "Почта", style = MaterialTheme.typography.titleMedium, maxLines = 1, overflow = TextOverflow.Ellipsis)
                Text(acc?.user ?: "", style = MaterialTheme.typography.bodySmall, color = P.muted, maxLines = 1, overflow = TextOverflow.Ellipsis)
            }
        }
        // «Под рукой» — закреплённые письма (как вкладки внизу веб-почты): касание открывает, долгое — убирает.
        if (Pinned.items.isNotEmpty()) {
            item { SectionTitle("Под рукой") }
            items(Pinned.items.size, key = { "pin:" + Pinned.items[it].folder + ":" + Pinned.items[it].uid }) { i ->
                val p = Pinned.items[i]
                Row(
                    Modifier.fillMaxWidth().padding(horizontal = 8.dp).clip(RoundedCornerShape(8.dp))
                        .combinedClickable(onClick = { Nav.push(MessageScreen(p.folder, p.uid)); onPicked() }, onLongClick = { Pinned.remove(p.folder, p.uid); Toasts.show("Письмо убрано из-под руки") })
                        .padding(horizontal = 12.dp, vertical = 8.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Ico("pin", size = 18.dp, tint = P.accent)
                    Spacer(Modifier.width(14.dp))
                    Column(Modifier.weight(1f)) {
                        Text(p.subject.ifBlank { "(без темы)" }, style = MaterialTheme.typography.bodyMedium, maxLines = 1, overflow = TextOverflow.Ellipsis)
                        if (p.from.isNotBlank()) Text(p.from, style = MaterialTheme.typography.bodySmall, color = P.muted, maxLines = 1, overflow = TextOverflow.Ellipsis)
                    }
                }
            }
        }
        items(g.system.size) { i ->
            val f = g.system[i]
            FolderRow(f.name, roleIcon(f.role), f.unread, f.total, selected = q.folder == f.path && q.filter == "all" && q.q.isEmpty(),
                showTotal = f.role in setOf("drafts", "trash", "spam"), onLong = { menuFor = f }) { store.go(f.path); onPicked() }
            if (f.role == "inbox") {
                FolderRow("Важное", "flag", 0, 0, selected = q.folder == f.path && q.filter == "flagged") { store.go(f.path, "flagged"); onPicked() }
            }
        }
        item {
            FolderRow("Карантин", "shield", store.quarantineCount, 0, selected = false, showTotal = false, strong = false) {
                Nav.push(QuarantineScreen()); onPicked()
            }
            if (store.outboxCount > 0) {
                FolderRow("Ждут отправки", "clock", store.outboxCount, 0, selected = false, strong = false) { Nav.push(OutboxScreen()); onPicked() }
            }
        }
        item {
            Row(verticalAlignment = Alignment.CenterVertically) {
                SectionTitle("Мои папки", Modifier.weight(1f))
                Box(Modifier.padding(end = 8.dp, top = 8.dp).clip(CircleShape).clickable { dialog = "new" }.padding(8.dp)) { Ico("plus", size = 18.dp, tint = P.muted) }
            }
        }
        items(g.custom.size) { i ->
            val f = g.custom[i]
            FolderRow(f.name, "folder", f.unread, f.total, selected = q.folder == f.path && q.filter == "all", depth = f.depth, onLong = { menuFor = f }) { store.go(f.path); onPicked() }
        }
        if (g.shared.isNotEmpty()) {
            item { SectionTitle("Общие папки") }
            g.shared.forEach { (owner, list) ->
                item { Text(owner, Modifier.padding(start = 20.dp, top = 6.dp, bottom = 2.dp), style = MaterialTheme.typography.labelLarge, color = P.muted) }
                items(list.size) { i ->
                    val f = list[i]
                    FolderRow(f.name, roleIcon(f.srole.ifBlank { "folder" }), f.unread, f.total, selected = q.folder == f.path, depth = f.depth + 1) { store.go(f.path); onPicked() }
                }
            }
        }
        if (store.labels.isNotEmpty()) {
            item { SectionTitle("Метки") }
            items(store.labels.size) { i ->
                val l = store.labels[i]
                val inbox = store.folders.firstOrNull { it.role == "inbox" }?.path ?: "INBOX"
                Row(
                    Modifier.fillMaxWidth().padding(horizontal = 8.dp).clip(RoundedCornerShape(8.dp))
                        .background(if (q.filter == "label:${l.id}") P.accentSoft else androidx.compose.ui.graphics.Color.Transparent)
                        .clickable { store.go(inbox, "label:${l.id}"); onPicked() }.padding(horizontal = 12.dp, vertical = 11.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Box(Modifier.size(10.dp).clip(CircleShape).background(hexColor(l.color)))
                    Spacer(Modifier.width(16.dp))
                    Text(l.name, style = MaterialTheme.typography.bodyLarge)
                }
            }
        }
        store.quota?.takeIf { it.limitKb > 0 }?.let { qt ->
            item(key = "quota") {
                Column(Modifier.fillMaxWidth().padding(horizontal = 20.dp, vertical = 12.dp)) {
                    Text("Занято ${Fmt.size(qt.usedKb * 1024)} из ${Fmt.size(qt.limitKb * 1024)}", style = MaterialTheme.typography.bodySmall, color = if (qt.percent >= 90) P.no else P.muted)
                    androidx.compose.material3.LinearProgressIndicator(
                        progress = { (qt.percent / 100f).coerceIn(0f, 1f) }, modifier = Modifier.fillMaxWidth().padding(top = 4.dp),
                        color = if (qt.percent >= 90) P.no else P.accent, trackColor = P.border,
                    )
                }
            }
        }
        item { Spacer(Modifier.height(24.dp)) }
    }

    menuFor?.let { f ->
        FolderMenu(f, onDismiss = { menuFor = null }, onRename = { dialog = "rename:" + f.path }, onDelete = { dialog = "delete:" + f.path },
            onEmpty = { dialog = "empty:" + f.path }, onNewSub = { dialog = "sub:" + f.path }, onShare = { Nav.push(FolderSharesScreen(f)); onPicked() },
            onRule = { dialog = "rule:" + f.path })
    }
    when (val d = dialog) {
        null -> {}
        "new" -> InputDialog("Новая папка", "Название", confirm = "Создать", onDismiss = { dialog = null }) { name ->
            scope.launchSafe { Session.api!!.createFolder(name, null); store.refreshFolders(); Toasts.show("Папка создана") }
        }
        else -> {
            val (kind, path) = d.substringBefore(':') to d.substringAfter(':')
            val f = store.folders.firstOrNull { it.path == path }
            when (kind) {
                "sub" -> InputDialog("Папка внутри «${f?.name}»", "Название", confirm = "Создать", onDismiss = { dialog = null }) { name ->
                    scope.launchSafe { Session.api!!.createFolder(name, path); store.refreshFolders(); Toasts.show("Папка создана") }
                }
                "rename" -> InputDialog("Переименовать папку", "Название", initial = f?.name ?: "", onDismiss = { dialog = null }) { name ->
                    scope.launchSafe { Session.api!!.renameFolder(path, name); store.refreshFolders(); if (store.query.folder == path) store.go("INBOX") }
                }
                "delete" -> ConfirmDialog("Удалить папку «${f?.name}»?", "Письма из неё попадут в корзину.", "Удалить", danger = true, onDismiss = { dialog = null }) {
                    scope.launchSafe { Session.api!!.deleteFolder(path); store.refreshFolders(); if (store.query.folder == path) store.go("INBOX") }
                }
                "empty" -> ConfirmDialog("Очистить «${f?.name}»?", "Все письма в папке будут удалены навсегда.", "Очистить", danger = true, onDismiss = { dialog = null }) {
                    scope.launchSafe { Session.api!!.emptyFolder(path); store.refreshFolders(); if (store.query.folder == path) store.load(); Toasts.show("Папка очищена") }
                }
                "rule" -> if (f != null) FolderRuleDialog(f, onDismiss = { dialog = null })
            }
        }
    }
}

/** Что ввели в «Правило для этой папки»: адрес → (address, адрес), «@домен» или «домен.ру» → (domain, домен). */
fun senderMatchOf(input: String): Pair<String, String>? {
    val v = input.trim().lowercase().removePrefix("<").removeSuffix(">")
    if (v.isEmpty() || ' ' in v) return null
    return when {
        v.startsWith("@") -> v.drop(1).takeIf { it.contains('.') }?.let { "domain" to it }
        '@' in v -> v.takeIf { it.substringAfter('@').contains('.') }?.let { "address" to it }
        '.' in v -> "domain" to v
        else -> null
    }
}

/**
 * «Правило для этой папки…» из меню папки — как в веб-почте (там ведёт в «Правила» с выбранной папкой):
 * письма от адреса или с домена класть сюда, через /sender/mark {kind: folder} — сразу и уже полученные.
 */
@Composable
private fun FolderRuleDialog(f: Folder, onDismiss: () -> Unit) {
    var text by remember { mutableStateOf("") }
    var resort by remember { mutableStateOf(true) }
    val scope = rememberCoroutineScope()
    val match = senderMatchOf(text)
    androidx.compose.material3.AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text("Правило для «${f.name}»") },
        text = {
            Column {
                Text("Письма от кого класть в эту папку — адрес (ivanov@polyus.ru) или весь домен (@polyus.ru):", style = MaterialTheme.typography.bodyMedium, color = P.muted)
                Spacer(Modifier.height(10.dp))
                androidx.compose.material3.OutlinedTextField(text, { text = it }, Modifier.fillMaxWidth(), label = { Text("Адрес или домен") }, singleLine = true,
                    keyboardOptions = androidx.compose.foundation.text.KeyboardOptions(keyboardType = androidx.compose.ui.text.input.KeyboardType.Email))
                Row(Modifier.padding(top = 8.dp).clickable { resort = !resort }, verticalAlignment = Alignment.CenterVertically) {
                    androidx.compose.material3.Switch(resort, { resort = it }); Spacer(Modifier.width(10.dp))
                    Text("Сразу разложить уже полученные письма", style = MaterialTheme.typography.bodyMedium)
                }
                Text("Правило появится в «Ещё → Правила», там его можно изменить или удалить.", style = MaterialTheme.typography.bodySmall, color = P.faint)
            }
        },
        confirmButton = {
            androidx.compose.material3.TextButton(enabled = match != null, onClick = {
                val (kind, value) = match ?: return@TextButton
                onDismiss()
                scope.launchSafe {
                    Session.api!!.markSender("folder", kind, value, resort, f.path)
                    Toasts.show((if (kind == "domain") "Письма с @$value" else "Письма от $value") + " будут попадать в «${f.name}»")
                    MailStore.refreshFolders(); MailStore.load(); MailStore.reloadRules()
                }
            }) { Text("Сохранить") }
        },
        dismissButton = { androidx.compose.material3.TextButton(onClick = onDismiss) { Text("Отмена") } },
    )
}

@Composable
private fun FolderMenu(f: Folder, onDismiss: () -> Unit, onRename: () -> Unit, onDelete: () -> Unit, onEmpty: () -> Unit, onNewSub: () -> Unit, onShare: () -> Unit, onRule: () -> Unit) {
    val custom = f.role !in ROLE_ORDER && !f.isShared
    // Как в веб-почте: правило не предлагается для черновиков, отправленных, корзины и чужих папок.
    val ruleable = !f.isShared && f.role !in setOf("drafts", "sent", "trash", "shared")
    androidx.compose.material3.AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(f.name) },
        text = {
            Column {
                @Composable fun item(icon: String, text: String, danger: Boolean = false, action: () -> Unit) {
                    Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(8.dp)).clickable { onDismiss(); action() }.padding(vertical = 12.dp, horizontal = 4.dp), verticalAlignment = Alignment.CenterVertically) {
                        Ico(icon, tint = if (danger) P.no else P.muted); Spacer(Modifier.width(14.dp)); Text(text, color = if (danger) P.no else P.text)
                    }
                }
                item("plus", "Новая папка внутри", action = onNewSub)
                if (ruleable) item("filter", "Правило для этой папки…", action = onRule)
                item("share", "Общий доступ…", action = onShare)
                if (custom) item("edit", "Переименовать", action = onRename)
                if (f.role in setOf("trash", "spam")) item("trash", "Очистить папку", danger = true, action = onEmpty)
                if (custom) item("trash", "Удалить папку", danger = true, action = onDelete)
            }
        },
        confirmButton = {},
        dismissButton = { androidx.compose.material3.TextButton(onClick = onDismiss) { Text("Закрыть") } },
    )
}

@Composable
private fun FolderRow(
    name: String, icon: String, unread: Int, total: Int, selected: Boolean,
    showTotal: Boolean = false, depth: Int = 0, strong: Boolean = true, onLong: (() -> Unit)? = null, onClick: () -> Unit,
) {
    Row(
        Modifier.fillMaxWidth().padding(horizontal = 8.dp).clip(RoundedCornerShape(8.dp))
            .background(if (selected) P.accentSoft else androidx.compose.ui.graphics.Color.Transparent)
            .combinedClickable(onClick = onClick, onLongClick = onLong)
            .padding(start = 12.dp + (depth * 14).dp, end = 12.dp, top = 11.dp, bottom = 11.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Ico(icon, tint = if (selected) P.accentInk else P.muted)
        Spacer(Modifier.width(16.dp))
        Text(name, Modifier.weight(1f), style = MaterialTheme.typography.bodyLarge, color = if (selected) P.accentInk else P.text,
            fontWeight = if (unread > 0 && strong) FontWeight.SemiBold else FontWeight.Normal, maxLines = 1, overflow = TextOverflow.Ellipsis)
        when {
            unread > 0 -> Text(unread.toString(), style = MaterialTheme.typography.labelLarge, color = if (strong) P.accentInk else P.muted, fontWeight = FontWeight.SemiBold)
            showTotal && total > 0 -> Text(total.toString(), style = MaterialTheme.typography.labelMedium, color = P.faint)
        }
    }
}

/** Выбор папки (перенести, сохранить в…); внизу — «Новая папка…»: создать и сразу выбрать её. */
@Composable
fun FolderPicker(title: String, exclude: String?, onDismiss: () -> Unit, onPick: (Folder) -> Unit) {
    val g = groupFolders(MailStore.folders)
    val list = (g.system.filter { it.role !in setOf("drafts") } + g.custom + g.shared.values.flatten().filter { !it.readonly })
        .filter { it.path != exclude }
    var creating by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    if (creating) {
        InputDialog("Новая папка", "Название", confirm = "Создать и выбрать", onDismiss = { creating = false; onDismiss() }) { name ->
            scope.launchSafe {
                val api = Session.api!!
                val before = MailStore.folders.map { it.path }.toSet()
                api.createFolder(name, null)
                val fresh = api.folders()
                MailStore.applyFolders(fresh)
                // Созданную узнаём по имени среди новых путей (сервер сам решает, где она лежит — «INBOX/…» или корень).
                val made = fresh.firstOrNull { it.path !in before && it.name == name } ?: fresh.firstOrNull { it.name == name && !it.isShared }
                if (made != null) onPick(made) else Toasts.show("Папка создана")
            }
        }
        return
    }
    androidx.compose.material3.AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(title) },
        text = {
            LazyColumn {
                items(list.size) { i ->
                    val f = list[i]
                    Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(8.dp)).clickable { onDismiss(); onPick(f) }.padding(vertical = 11.dp, horizontal = 4.dp + (f.depth * 12).dp),
                        verticalAlignment = Alignment.CenterVertically) {
                        Ico(roleIcon(if (f.isShared) f.srole.ifBlank { "shared" } else f.role), tint = P.muted); Spacer(Modifier.width(14.dp))
                        Text(if (f.isShared) "${f.name} · ${f.ownerName.ifBlank { f.owner }}" else f.name, maxLines = 1, overflow = TextOverflow.Ellipsis)
                    }
                }
                item {
                    Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(8.dp)).clickable { creating = true }.padding(vertical = 11.dp, horizontal = 4.dp), verticalAlignment = Alignment.CenterVertically) {
                        Ico("plus", tint = P.accentInk); Spacer(Modifier.width(14.dp))
                        Text("Новая папка…", color = P.accentInk, fontWeight = FontWeight.Medium)
                    }
                }
            }
        },
        confirmButton = {},
        dismissButton = { androidx.compose.material3.TextButton(onClick = onDismiss) { Text("Отмена") } },
    )
}
