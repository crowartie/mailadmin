package su.innotec.mail.ui.mail

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Button
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import su.innotec.mail.Nav
import su.innotec.mail.Screen
import su.innotec.mail.api.ApiException
import su.innotec.mail.api.Folder
import su.innotec.mail.api.FolderShares
import su.innotec.mail.api.OutboxItem
import su.innotec.mail.api.QuarantineItem
import su.innotec.mail.data.Session
import su.innotec.mail.ui.Avatar
import su.innotec.mail.ui.ChoiceDialog
import su.innotec.mail.ui.ConfirmDialog
import su.innotec.mail.ui.Divider
import su.innotec.mail.ui.Empty
import su.innotec.mail.ui.ErrorBox
import su.innotec.mail.ui.Fmt
import su.innotec.mail.ui.Ico
import su.innotec.mail.ui.Loading
import su.innotec.mail.ui.P
import su.innotec.mail.ui.Toasts
import su.innotec.mail.ui.launchSafe

/** Верхняя панель вложенного экрана. */
@Composable
fun SubBar(title: String, subtitle: String? = null, actions: @Composable () -> Unit = {}) {
    Row(Modifier.fillMaxWidth().background(P.surface).statusBarsPadding().height(56.dp).padding(horizontal = 4.dp), verticalAlignment = Alignment.CenterVertically) {
        IconBtn("back", "Назад") { Nav.pop() }
        Column(Modifier.weight(1f).padding(start = 4.dp)) {
            Text(title, style = MaterialTheme.typography.titleMedium, maxLines = 1, overflow = TextOverflow.Ellipsis)
            if (!subtitle.isNullOrBlank()) Text(subtitle, style = MaterialTheme.typography.bodySmall, color = P.muted, maxLines = 1)
        }
        actions()
    }
    Divider()
}

/** Загрузка данных экрана с ошибкой и «Повторить». */
@Composable
fun <T> Loader(key: Any?, load: suspend () -> T, content: @Composable (T, reload: () -> Unit) -> Unit) {
    var data by remember(key) { mutableStateOf<T?>(null) }
    var error by remember(key) { mutableStateOf<String?>(null) }
    var n by remember(key) { mutableStateOf(0) }
    LaunchedEffect(key, n) {
        error = null
        try { data = load() } catch (e: ApiException) { if (e.isAuth) Toasts.error(e) else error = e.message }
    }
    val d = data
    when {
        error != null && d == null -> ErrorBox(error!!, { n++ })
        d == null -> Loading()
        else -> content(d) { n++ }
    }
}

/** Карантин: письма, задержанные антиспамом (QuarantineController). */
class QuarantineScreen : Screen() {
    @Composable
    override fun Content() {
        var items by remember { mutableStateOf<List<QuarantineItem>?>(null) }
        var confirm by remember { mutableStateOf<QuarantineItem?>(null) }
        val scope = rememberCoroutineScope()
        Column(Modifier.fillMaxSize().background(P.bg)) {
            SubBar("Карантин", "Письма, задержанные антиспамом")
            Loader(Unit, { Session.api!!.quarantine() }) { first, _ ->
                val list = items ?: first
                if (list.isEmpty()) Empty("shield", "Карантин пуст", "Сюда попадают письма, которые антиспам счёл опасными")
                else LazyColumn(Modifier.fillMaxSize()) {
                    items(list.size) { i ->
                        val q = list[i]
                        Column(Modifier.fillMaxWidth().background(P.surface).padding(16.dp)) {
                            Row(verticalAlignment = Alignment.CenterVertically) {
                                Text(q.from.ifBlank { "(без отправителя)" }, Modifier.weight(1f), style = MaterialTheme.typography.bodyLarge, maxLines = 1, overflow = TextOverflow.Ellipsis)
                                Text(Fmt.listDate(q.time), style = MaterialTheme.typography.bodySmall, color = P.faint)
                            }
                            Text(q.subject.ifBlank { "(без темы)" }, style = MaterialTheme.typography.bodyMedium, maxLines = 2, overflow = TextOverflow.Ellipsis)
                            Text(listOfNotNull(q.kind.ifBlank { null }, q.score?.let { "оценка $it" }, Fmt.size(q.size)).joinToString(" · "), style = MaterialTheme.typography.bodySmall, color = P.warnInk)
                            Row(Modifier.padding(top = 8.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                                OutlinedButton(onClick = {
                                    scope.launchSafe {
                                        items = Session.api!!.quarantineRelease(q.id).items
                                        Toasts.show("Письмо доставлено во «Входящие»"); MailStore.refreshFolders()
                                    }
                                }) { Text("Доставить") }
                                TextButton(onClick = { confirm = q }) { Text("Удалить", color = P.no) }
                            }
                        }
                        Divider()
                    }
                }
            }
        }
        confirm?.let { q ->
            ConfirmDialog("Удалить письмо из карантина?", q.subject, "Удалить", danger = true, onDismiss = { confirm = null }) {
                scope.launchSafe { items = Session.api!!.quarantineDelete(q.id).items; MailStore.refreshFolders() }
            }
        }
    }
}

/** «Ждут отправки»: отложенная отправка (sendAt) и неудачи. */
class OutboxScreen : Screen() {
    @Composable
    override fun Content() {
        var key by remember { mutableStateOf(0) }
        val scope = rememberCoroutineScope()
        Column(Modifier.fillMaxSize().background(P.bg)) {
            SubBar("Ждут отправки")
            Loader(key, { Session.api!!.outbox() }) { list: List<OutboxItem>, _ ->
                if (list.isEmpty()) Empty("clock", "Нет писем, ждущих отправки")
                else LazyColumn(Modifier.fillMaxSize()) {
                    items(list.size) { i ->
                        val o = list[i]
                        Row(Modifier.fillMaxWidth().background(P.surface).padding(16.dp), verticalAlignment = Alignment.CenterVertically) {
                            Column(Modifier.weight(1f)) {
                                Text(o.subject.ifBlank { "(без темы)" }, style = MaterialTheme.typography.bodyLarge, maxLines = 1, overflow = TextOverflow.Ellipsis)
                                Text(o.recipients, style = MaterialTheme.typography.bodySmall, color = P.muted, maxLines = 1, overflow = TextOverflow.Ellipsis)
                                Text(
                                    if (o.status == "failed") "Не отправилось: ${o.error ?: "ошибка"}" else "Уйдёт ${Fmt.full(o.sendAt)}",
                                    style = MaterialTheme.typography.bodySmall, color = if (o.status == "failed") P.no else P.accentInk,
                                )
                            }
                            if (o.status != "sent") TextButton(onClick = {
                                scope.launchSafe { Session.api!!.cancelOutbox(o.id); Toasts.show("Отправка отменена — письмо в черновиках"); key++; MailStore.refreshFolders() }
                            }) { Text("Отменить") }
                        }
                        Divider()
                    }
                }
            }
        }
    }
}

private val LEVELS = listOf("reader" to "Только читать", "editor" to "Читать и разбирать", "owner" to "Полный доступ")
/** «Полный доступ» (владелец) — только у «Входящих», как в веб-почте: у остальных папок сервер его не даёт. */
private fun levelsFor(f: Folder) = if (f.role == "inbox") LEVELS else LEVELS.filter { it.first != "owner" }

/** Общий доступ к своей папке — кому и с какими правами (FolderController::shares). */
class FolderSharesScreen(private val folder: Folder) : Screen() {
    @Composable
    override fun Content() {
        var data by remember { mutableStateOf<FolderShares?>(null) }
        var adding by remember { mutableStateOf(false) }
        var remove by remember { mutableStateOf<String?>(null) }
        val scope = rememberCoroutineScope()
        val api = Session.api!!
        Column(Modifier.fillMaxSize().background(P.bg)) {
            SubBar("Общий доступ", folder.name) { IconBtn("plus", "Дать доступ") { adding = true } }
            Loader(folder.path, { api.folderShares(folder.path) }) { first, _ ->
                val d = data ?: first
                val names = d.candidates.associate { it.mail to it.name }
                if (d.shares.isEmpty()) Empty("share", "Папка только ваша", "Дайте доступ коллеге — папка появится у него в «Общих папках»") {
                    Button(onClick = { adding = true }) { Text("Дать доступ") }
                } else LazyColumn(Modifier.fillMaxSize()) {
                    items(d.shares.size) { i ->
                        val s = d.shares[i]
                        var levelOpen by remember { mutableStateOf(false) }
                        Row(Modifier.fillMaxWidth().background(P.surface).padding(horizontal = 16.dp, vertical = 10.dp), verticalAlignment = Alignment.CenterVertically) {
                            Avatar(names[s.mail] ?: s.mail, s.mail, 36.dp)
                            Spacer(Modifier.width(12.dp))
                            Column(Modifier.weight(1f)) {
                                Text(names[s.mail] ?: s.mail, maxLines = 1, overflow = TextOverflow.Ellipsis)
                                Text(LEVELS.firstOrNull { it.first == s.level }?.second ?: s.level, style = MaterialTheme.typography.bodySmall, color = P.accentInk,
                                    modifier = Modifier.clip(RoundedCornerShape(4.dp)).clickable { levelOpen = true })
                            }
                            IconBtn("x", "Убрать доступ", tint = P.muted) { remove = s.mail }
                        }
                        Divider()
                        if (levelOpen) ChoiceDialog("Права для ${names[s.mail] ?: s.mail}", levelsFor(folder), { it.second }, LEVELS.firstOrNull { it.first == s.level }, onDismiss = { levelOpen = false }) { l ->
                            scope.launchSafe { data = api.shareFolder(folder.path, s.mail, l.first).also { r -> r.folders?.let { MailStore.applyFolders(it) } }.let { it.copy(candidates = d.candidates) } }
                        }
                    }
                }
                if (adding) AddShareDialog(d, levelsFor(folder), onDismiss = { adding = false }) { mail, level ->
                    scope.launchSafe { data = api.shareFolder(folder.path, mail, level).let { it.copy(candidates = d.candidates) }; Toasts.show("Доступ выдан") }
                }
                remove?.let { mail ->
                    ConfirmDialog("Убрать доступ?", "${names[mail] ?: mail} больше не увидит папку «${folder.name}».", "Убрать", danger = true, onDismiss = { remove = null }) {
                        scope.launchSafe { data = api.unshareFolder(folder.path, mail).let { it.copy(candidates = d.candidates) } }
                    }
                }
            }
        }
    }
}

@Composable
private fun AddShareDialog(d: FolderShares, levels: List<Pair<String, String>>, onDismiss: () -> Unit, onAdd: (String, String) -> Unit) {
    var q by remember { mutableStateOf("") }
    var who by remember { mutableStateOf<String?>(null) }
    var level by remember { mutableStateOf("reader") }
    val list = d.candidates.filter { c -> d.shares.none { it.mail == c.mail } && (q.isBlank() || c.name.contains(q, true) || c.mail.contains(q, true)) }
    androidx.compose.material3.AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text("Дать доступ") },
        text = {
            Column {
                if (who == null) {
                    OutlinedTextField(q, { q = it }, Modifier.fillMaxWidth(), label = { Text("Кому") }, singleLine = true)
                    LazyColumn(Modifier.height(280.dp)) {
                        items(list.size) { i ->
                            val c = list[i]
                            Row(Modifier.fillMaxWidth().clickable { who = c.mail }.padding(vertical = 8.dp), verticalAlignment = Alignment.CenterVertically) {
                                Avatar(c.name, c.mail, 30.dp); Spacer(Modifier.width(10.dp))
                                Column { Text(c.name, maxLines = 1); Text(c.mail, style = MaterialTheme.typography.bodySmall, color = P.muted, maxLines = 1) }
                            }
                        }
                    }
                } else {
                    Text(d.candidates.firstOrNull { it.mail == who }?.name ?: who!!, style = MaterialTheme.typography.titleSmall)
                    Spacer(Modifier.height(8.dp))
                    levels.forEach { (k, t) ->
                        Row(Modifier.fillMaxWidth().clickable { level = k }.padding(vertical = 6.dp), verticalAlignment = Alignment.CenterVertically) {
                            androidx.compose.material3.RadioButton(level == k, { level = k }); Text(t)
                        }
                    }
                }
            }
        },
        confirmButton = { TextButton(enabled = who != null, onClick = { onDismiss(); onAdd(who!!, level) }) { Text("Дать доступ") } },
        dismissButton = { TextButton(onClick = { if (who != null) who = null else onDismiss() }) { Text(if (who != null) "Назад" else "Отмена") } },
    )
}
