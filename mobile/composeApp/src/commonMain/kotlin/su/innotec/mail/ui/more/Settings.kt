package su.innotec.mail.ui.more

import androidx.compose.ui.text.font.FontWeight
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.imePadding
import kotlinx.io.readByteArray
import kotlinx.coroutines.launch
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.MaterialTheme
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
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.put
import kotlinx.serialization.json.putJsonArray
import su.innotec.mail.Nav
import su.innotec.mail.Screen
import su.innotec.mail.api.Label
import su.innotec.mail.api.Settings
import su.innotec.mail.api.SharedFile
import su.innotec.mail.data.Session
import su.innotec.mail.platform.Notifier
import su.innotec.mail.platform.Sys
import su.innotec.mail.ui.ChoiceDialog
import su.innotec.mail.ui.ConfirmDialog
import su.innotec.mail.ui.Divider
import su.innotec.mail.ui.Empty
import su.innotec.mail.ui.Fmt
import su.innotec.mail.ui.Html
import su.innotec.mail.ui.Ico
import su.innotec.mail.ui.InputDialog
import su.innotec.mail.ui.ListRow
import su.innotec.mail.ui.P
import su.innotec.mail.ui.SectionTitle
import su.innotec.mail.ui.SwitchRow
import su.innotec.mail.ui.Toasts
import su.innotec.mail.ui.Transfers
import su.innotec.mail.ui.hexColor
import su.innotec.mail.ui.launchSafe
import su.innotec.mail.ui.mail.IconBtn
import su.innotec.mail.ui.mail.Loader
import su.innotec.mail.ui.mail.MailStore
import su.innotec.mail.ui.mail.SubBar

/** Сохранить настройку ящика (PUT /settings) и сразу применить её в приложении. */
private suspend fun patch(p: JsonObject) {
    Session.api!!.saveSettings(p)
    MailStore.setSettingsLocal(Session.api!!.settings())
}

class MailSettingsScreen : Screen() {
    @Composable
    override fun Content() {
        val scope = rememberCoroutineScope()
        var dialog by remember { mutableStateOf<String?>(null) }
        var meta by remember { mutableStateOf<su.innotec.mail.api.ComposeMeta?>(null) }
        var hosts by remember { mutableStateOf<su.innotec.mail.api.Hosts?>(null) }
        LaunchedEffect(Unit) {
            MailStore.reloadSettings()
            meta = runCatching { Session.api!!.composeMeta() }.getOrNull()
            hosts = runCatching { Session.api!!.me().hosts }.getOrNull()
        }
        val s: Settings = MailStore.settings
        Column(Modifier.fillMaxSize().background(P.bg)) {
            SubBar("Настройки почты")
            Column(Modifier.verticalScroll(rememberScrollState())) {
                SectionTitle("Отправитель")
                Column(Modifier.background(P.surface)) {
                    ListRow("Имя в письмах", s.displayName.ifBlank { "как в адресной книге" }, icon = "user") { dialog = "name" }
                    ListRow("Подпись", Html.toText(s.signature).ifBlank { "нет" }.take(120), icon = "edit") { Nav.push(SignatureScreen()) }
                    SwitchRow("Подпись в ответах и пересылке", checked = s.signatureReply) { v -> scope.launchSafe { patch(buildJsonObject { put("signature_reply", v) }) } }
                }
                SectionTitle("Письма")
                Column(Modifier.background(P.surface)) {
                    SwitchRow("«Ответить» — всем участникам", "Кнопка «Ответить» отвечает всем, если в письме несколько адресатов", s.replyAll) { v -> scope.launchSafe { patch(buildJsonObject { put("reply_all", v) }) } }
                    ListRow("Отмена отправки и удаления", if (s.undoSeconds <= 0) "выключена" else "${s.undoSeconds} секунд", icon = "clock") { dialog = "undo" }
                    ListRow("Картинки из интернета", if (s.showImages == "always") "показывать всегда" else "спрашивать", icon = "img") { dialog = "images" }
                    ListRow("Быстрые ответы", s.quickReplies.filter { it.isNotBlank() }.joinToString(" · ").ifBlank { "нет" }, icon = "reply") { dialog = "quick" }
                    SwitchRow("Общие ящики: отмечать прочитанным", "Открытое письмо в общей папке станет прочитанным для всех", s.sharedMarkSeen) { v -> scope.launchSafe { patch(buildJsonObject { put("shared_mark_seen", v) }) } }
                    SwitchRow("Предлагать правило при переносе", "Перенесли письмо в свою папку — спросить, класть ли туда всё от этого отправителя", s.askRuleOnMove) { v -> scope.launchSafe { patch(buildJsonObject { put("ask_rule_on_move", v) }) } }
                }
                if (meta != null && meta!!.identities.isNotEmpty()) {
                    SectionTitle("Мои адреса")
                    Column(Modifier.background(P.surface)) {
                        meta!!.identities.forEach { i ->
                            ListRow(i.mail, when { i.primary -> "основной"; i.shared -> "общий ящик — можно писать от его имени"; else -> "дополнительный адрес" }, icon = if (i.shared) "users" else "mail") {
                                Sys.copy(i.mail); Toasts.show("Адрес скопирован")
                            }
                        }
                    }
                }
                hosts?.let { h ->
                    SectionTitle("Телефон и программы")
                    Column(Modifier.background(P.surface)) {
                        ListRow("Входящие (IMAP)", "${h.imap} : ${h.imapPort}, SSL/TLS", icon = "inbox") { Sys.copy(h.imap); Toasts.show("Скопировано") }
                        ListRow("Исходящие (SMTP)", "${h.smtp} : ${h.smtpPort}, SSL/TLS", icon = "send") { Sys.copy(h.smtp); Toasts.show("Скопировано") }
                        ListRow("Календарь и контакты (CalDAV, CardDAV)", h.dav, icon = "cal") { Sys.copy(h.dav); Toasts.show("Скопировано") }
                        Text("Outlook, Thunderbird и почта Android находят настройки сами по адресу. Для iPhone и Mac есть профиль — откройте «Настройки» веб-почты на самом устройстве. Пароль — от почты или пароль приложения («Ещё → Безопасность»).",
                            Modifier.padding(16.dp), style = MaterialTheme.typography.bodySmall, color = P.muted)
                    }
                }
                Spacer(Modifier.height(40.dp))
            }
        }
        when (dialog) {
            "name" -> InputDialog("Имя в письмах", "Имя", initial = s.displayName, onDismiss = { dialog = null }) { v -> scope.launchSafe { patch(buildJsonObject { put("display_name", v) }) } }
            "undo" -> ChoiceDialog("Сколько ждать перед отправкой и удалением", listOf(0, 5, 10, 20, 30), { if (it == 0) "Не ждать" else "$it секунд" }, s.undoSeconds, onDismiss = { dialog = null }) { v ->
                scope.launchSafe { patch(buildJsonObject { put("undo_seconds", v) }) }
            }
            "images" -> ChoiceDialog("Картинки из интернета", listOf("ask", "always"), { if (it == "always") "Показывать всегда" else "Спрашивать (защита от следящих пикселей)" }, s.showImages, onDismiss = { dialog = null }) { v ->
                scope.launchSafe { patch(buildJsonObject { put("show_images", v) }) }
            }
            "quick" -> QuickRepliesDialog(s.quickReplies, onDismiss = { dialog = null }) { list ->
                scope.launchSafe { patch(buildJsonObject { putJsonArray("quick_replies") { list.forEach { add(JsonPrimitive(it)) } } }) }
            }
        }
    }
}

/**
 * Подпись с оформлением — тем же редактором, что и письмо: жирный, ссылки, логотип картинкой (до 400 КБ).
 * В веб-почте подпись тоже с оформлением; раньше приложение правило её только текстом и теряло ссылки и логотип.
 */
class SignatureScreen : Screen() {
    override val fullScreen: Boolean get() = true

    @Composable
    override fun Content() {
        val scope = rememberCoroutineScope()
        val editor = remember { su.innotec.mail.platform.RichEditorState(MailStore.settings.signature) }
        var saving by remember { mutableStateOf(false) }
        val pickImage = su.innotec.mail.platform.rememberFilePicker(multiple = false, mimes = listOf("image/*")) { list ->
            val f = list.firstOrNull() ?: return@rememberFilePicker
            if (f.size > 400 * 1024) { Toasts.show("Картинка больше 400 КБ — для подписи хватает ширины 300–400 точек"); return@rememberFilePicker }
            scope.launch {
                val bytes = kotlinx.coroutines.withContext(kotlinx.coroutines.Dispatchers.Default) { f.open().use { it.readByteArray() } }
                @OptIn(kotlin.io.encoding.ExperimentalEncodingApi::class)
                editor.image("data:${f.mime};base64," + kotlin.io.encoding.Base64.encode(bytes))
            }
        }
        su.innotec.mail.platform.BackHandler(true) { Nav.pop() }
        Column(Modifier.fillMaxSize().background(P.surface).imePadding()) {
            Row(Modifier.fillMaxWidth().statusBarsPadding().height(56.dp).padding(horizontal = 4.dp), verticalAlignment = Alignment.CenterVertically) {
                IconBtn("x", "Закрыть") { Nav.pop() }
                Text("Подпись", Modifier.weight(1f), style = MaterialTheme.typography.titleMedium)
                TextButton(enabled = !saving, onClick = {
                    saving = true
                    scope.launchSafe {
                        val h = editor.html.let { if (Html.toText(it).isBlank() && !it.contains("<img", true)) "" else it }
                        patch(buildJsonObject { put("signature", h) })
                        Toasts.show("Подпись сохранена"); Nav.pop()
                    }
                }) { Text("Сохранить", fontWeight = FontWeight.SemiBold) }
            }
            Divider()
            Column(Modifier.weight(1f).verticalScroll(rememberScrollState())) {
                su.innotec.mail.platform.RichEditor(editor, P.dark, "Например: С уважением, имя, должность, телефон",
                    Modifier.fillMaxWidth().height(maxOf(200f, editor.contentHeight + 8f).dp), loadResource = { p -> su.innotec.mail.ui.Transfers.inlineResource(p) })
                Text("Подпись добавляется к новым письмам; к ответам — если включено «Подпись в ответах и пересылке».",
                    Modifier.padding(16.dp), style = MaterialTheme.typography.bodySmall, color = P.muted)
            }
            if (editor.rich && editor.focused) su.innotec.mail.ui.mail.FormatBarPublic(editor) { pickImage() }
        }
    }
}

@Composable
private fun QuickRepliesDialog(list: List<String>, onDismiss: () -> Unit, onSave: (List<String>) -> Unit) {
    var items by remember { mutableStateOf(list.filter { it.isNotBlank() }.ifEmpty { listOf("") }) }
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text("Быстрые ответы") },
        text = {
            Column(Modifier.verticalScroll(rememberScrollState())) {
                Text("Готовые фразы под письмом — ответ одним касанием.", style = MaterialTheme.typography.bodySmall, color = P.muted)
                items.forEachIndexed { i, q ->
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        OutlinedTextField(q, { v -> items = items.mapIndexed { j, x -> if (j == i) v else x } }, Modifier.weight(1f), singleLine = true)
                        IconBtn("x", "Убрать", tint = P.muted) { items = items.filterIndexed { j, _ -> j != i } }
                    }
                }
                if (items.size < 6) TextButton(onClick = { items = items + "" }) { Text("+ фраза") }
            }
        },
        confirmButton = { TextButton(onClick = { onDismiss(); onSave(items.map { it.trim() }.filter { it.isNotEmpty() }) }) { Text("Сохранить") } },
        dismissButton = { TextButton(onClick = onDismiss) { Text("Отмена") } },
    )
}

/** Настройки самого приложения: тема, уведомления, жесты. */
class AppSettingsScreen : Screen() {
    @Composable
    override fun Content() {
        val p = Session.prefs
        var dialog by remember { mutableStateOf<String?>(null) }
        Column(Modifier.fillMaxSize().background(P.bg)) {
            SubBar("Оформление и уведомления")
            Column(Modifier.verticalScroll(rememberScrollState())) {
                SectionTitle("Оформление")
                Column(Modifier.background(P.surface)) {
                    ListRow("Тема", when (p.theme) { "dark" -> "тёмная"; "light" -> "светлая"; else -> "как в системе" }, icon = "moon") { dialog = "theme" }
                }
                SectionTitle("Уведомления")
                Column(Modifier.background(P.surface)) {
                    SwitchRow("О новых письмах", if (p.fastNotify) "Мгновенно — включено ниже" else "Проверка примерно раз в 15 минут и сразу при открытии приложения", p.notify) { v ->
                        Session.updatePrefs { it.copy(notify = v) }; Notifier.schedule(v); Notifier.fast(v); if (v) Notifier.ensurePermission()
                    }
                    if (Notifier.fastAvailable && p.notify) SwitchRow("Мгновенно", "Проверка раз в минуту, пока есть сеть; в шторке — тихий постоянный значок. На Huawei и Honor разрешите работу в фоне: Настройки → Батарея → Запуск приложений → Почта → вручную", p.fastNotify) { v ->
                        Session.updatePrefs { it.copy(fastNotify = v) }; Notifier.fast(v); if (v) Notifier.ensurePermission()
                    }
                    SwitchRow("Из общих ящиков", "Например, info@ — если у вас к нему доступ", p.notifyShared) { v -> Session.updatePrefs { it.copy(notifyShared = v) } }
                }
                SectionTitle("Жесты в списке писем")
                Column(Modifier.background(P.surface)) {
                    ListRow("Смахнуть вправо", swipeName(p.swipeRight), icon = "right") { dialog = "right" }
                    ListRow("Смахнуть влево", swipeName(p.swipeLeft), icon = "left") { dialog = "left" }
                }
                Spacer(Modifier.height(40.dp))
            }
        }
        val swipes = listOf("archive", "delete", "read", "flag", "move", "snooze", "spam", "none")
        when (dialog) {
            "theme" -> ChoiceDialog("Тема", listOf("system", "light", "dark"), { when (it) { "dark" -> "Тёмная"; "light" -> "Светлая"; else -> "Как в системе" } }, p.theme, onDismiss = { dialog = null }) { v -> Session.updatePrefs { it.copy(theme = v) } }
            "right" -> ChoiceDialog("Смахнуть вправо", swipes, ::swipeName, p.swipeRight, onDismiss = { dialog = null }) { v -> Session.updatePrefs { it.copy(swipeRight = v) } }
            "left" -> ChoiceDialog("Смахнуть влево", swipes, ::swipeName, p.swipeLeft, onDismiss = { dialog = null }) { v -> Session.updatePrefs { it.copy(swipeLeft = v) } }
        }
    }
}

private fun swipeName(op: String) = when (op) {
    "archive" -> "В архив"; "delete" -> "Удалить"; "read" -> "Прочитано / непрочитано"; "flag" -> "Флажок"; "move" -> "В папку…"
    "snooze" -> "Отложить…"; "spam" -> "В спам"; else -> "Ничего"
}

// ---------- метки ----------

private val LABEL_COLORS = listOf("#2F6FEB", "#16A05C", "#D9791F", "#8E44AD", "#C0392B", "#0E8A9E", "#6D4C41", "#AD1457", "#5C6BC0", "#7F8C8D")

class LabelsScreen : Screen() {
    @Composable
    override fun Content() {
        val scope = rememberCoroutineScope()
        var edit by remember { mutableStateOf<Label?>(null) }
        var remove by remember { mutableStateOf<Label?>(null) }
        LaunchedEffect(Unit) { MailStore.reloadLabels() }
        Column(Modifier.fillMaxSize().background(P.bg)) {
            SubBar("Метки") { IconBtn("plus", "Новая метка") { edit = Label(0, "", LABEL_COLORS.random()) } }
            val list = MailStore.labels
            if (list.isEmpty()) Empty("tag", "Меток нет", "Метки помогают найти письма по теме — по одной или нескольким на письмо")
            else LazyColumn {
                items(list.size) { i ->
                    val l = list[i]
                    Row(Modifier.fillMaxWidth().background(P.surface).clickable { edit = l }.padding(horizontal = 16.dp, vertical = 12.dp), verticalAlignment = Alignment.CenterVertically) {
                        Box(Modifier.size(14.dp).clip(CircleShape).background(hexColor(l.color)))
                        Spacer(Modifier.width(14.dp))
                        Text(l.name, Modifier.weight(1f))
                        IconBtn("trash", "Удалить", tint = P.muted) { remove = l }
                    }
                    Divider()
                }
            }
        }
        edit?.let { l -> LabelDialog(l, onDismiss = { edit = null }) { name, color ->
            scope.launchSafe { if (l.id == 0L) Session.api!!.createLabel(name, color) else Session.api!!.updateLabel(l.id, name, color); MailStore.reloadLabels() }
        } }
        remove?.let { l -> ConfirmDialog("Удалить метку «${l.name}»?", "С писем она пропадёт, сами письма останутся.", "Удалить", danger = true, onDismiss = { remove = null }) {
            scope.launchSafe { Session.api!!.deleteLabel(l.id); MailStore.reloadLabels() }
        } }
    }
}

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun LabelDialog(l: Label, onDismiss: () -> Unit, onSave: (String, String) -> Unit) {
    var name by remember { mutableStateOf(l.name) }
    var color by remember { mutableStateOf(l.color) }
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(if (l.id == 0L) "Новая метка" else "Метка") },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
                OutlinedTextField(name, { name = it }, Modifier.fillMaxWidth(), label = { Text("Название") }, singleLine = true)
                FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    LABEL_COLORS.forEach { h ->
                        Box(Modifier.size(32.dp).clip(CircleShape).background(hexColor(h)).clickable { color = h }, contentAlignment = Alignment.Center) {
                            if (h.equals(color, true)) Ico("check", size = 16.dp, tint = Color.White)
                        }
                    }
                }
            }
        },
        confirmButton = { TextButton(enabled = name.isNotBlank(), onClick = { onDismiss(); onSave(name.trim(), color) }) { Text("Сохранить") } },
        dismissButton = { TextButton(onClick = onDismiss) { Text("Отмена") } },
    )
}

// ---------- файлы по ссылке ----------

class FilesScreen : Screen() {
    @Composable
    override fun Content() {
        var key by remember { mutableStateOf(0) }
        var remove by remember { mutableStateOf<SharedFile?>(null) }
        val scope = rememberCoroutineScope()
        Column(Modifier.fillMaxSize().background(P.bg)) {
            SubBar("Файлы по ссылке")
            Loader(key, { Session.api!!.files() }) { d, _ ->
                if (!d.enabled && d.files.isEmpty()) Empty("link", "Хранилище выключено", "Большие вложения уходят обычными файлами")
                else Column {
                    if (d.quota > 0) Text("Занято ${Fmt.size(d.used)} из ${Fmt.size(d.quota)} · ссылки живут ${d.expireDays} дн.", Modifier.padding(16.dp), style = MaterialTheme.typography.bodySmall, color = P.muted)
                    if (d.files.isEmpty()) Empty("link", "Файлов пока нет", "Сюда попадают вложения, отправленные ссылкой")
                    else LazyColumn {
                        items(d.files.size) { i ->
                            val f = d.files[i]
                            Column(Modifier.fillMaxWidth().background(P.surface).padding(horizontal = 16.dp, vertical = 10.dp)) {
                                Row(verticalAlignment = Alignment.CenterVertically) {
                                    Column(Modifier.weight(1f)) {
                                        Text(f.name, maxLines = 1, overflow = TextOverflow.Ellipsis)
                                        Text(listOfNotNull(Fmt.size(f.size), f.subject.ifBlank { null }?.let { "«$it»" }, "скачано ${f.downloads}").joinToString(" · "),
                                            style = MaterialTheme.typography.bodySmall, color = P.muted, maxLines = 1, overflow = TextOverflow.Ellipsis)
                                        Text(if (f.expired) "Срок ссылки истёк" else f.expires?.let { "Действует до ${Fmt.full(it)}" } ?: "Бессрочно",
                                            style = MaterialTheme.typography.bodySmall, color = if (f.expired) P.no else P.faint)
                                    }
                                    IconBtn("copy", "Копировать ссылку", tint = P.muted) { Sys.copy(f.url); Toasts.show("Ссылка скопирована") }
                                }
                                Row(horizontalArrangement = Arrangement.spacedBy(4.dp)) {
                                    TextButton(onClick = { Transfers.fetch(Session.api!!.fileContentPath(f.token), f.name, Transfers.Then.OPEN) }) { Text("Открыть") }
                                    if (f.mine) TextButton(onClick = { scope.launchSafe { Session.api!!.fileRenew(f.token); key++; Toasts.show("Срок продлён") } }) { Text("Продлить") }
                                    if (f.mine) TextButton(onClick = { remove = f }) { Text("Удалить", color = P.no) }
                                }
                            }
                            Divider()
                        }
                    }
                }
            }
        }
        remove?.let { f -> ConfirmDialog("Удалить «${f.name}»?", "Ссылка в письмах перестанет открываться.", "Удалить", danger = true, onDismiss = { remove = null }) {
            scope.launchSafe { Session.api!!.fileDelete(f.token); key++ }
        } }
    }
}
