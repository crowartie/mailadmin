package su.innotec.mail.ui.more

import su.innotec.mail.ui.Fmt
import kotlinx.datetime.atTime
import kotlinx.datetime.LocalTime
import kotlinx.datetime.LocalDate
import androidx.compose.foundation.background
import androidx.compose.foundation.border
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
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import su.innotec.mail.Nav
import su.innotec.mail.Screen
import su.innotec.mail.api.AutoReply
import su.innotec.mail.api.Rule
import su.innotec.mail.api.RuleAction
import su.innotec.mail.api.RuleCondition
import su.innotec.mail.api.Rules
import su.innotec.mail.data.Session
import su.innotec.mail.platform.BackHandler
import su.innotec.mail.ui.ChoiceDialog
import su.innotec.mail.ui.ConfirmDialog
import su.innotec.mail.ui.Divider
import su.innotec.mail.ui.Empty
import su.innotec.mail.ui.P
import su.innotec.mail.ui.SectionTitle
import su.innotec.mail.ui.Toasts
import su.innotec.mail.ui.launchSafe
import su.innotec.mail.ui.mail.FolderPicker
import su.innotec.mail.ui.mail.IconBtn
import su.innotec.mail.ui.mail.Loader
import su.innotec.mail.ui.mail.MailStore
import su.innotec.mail.ui.mail.SubBar

// Подписи — как useMailRules.js веб-почты.
val RULE_FIELDS = linkedMapOf("from" to "Отправитель", "to" to "Получатель", "recipient" to "Кому или копия", "subject" to "Тема", "body" to "Текст письма", "header" to "Заголовок", "size" to "Размер, КБ")
val RULE_OPS = linkedMapOf("contains" to "содержит", "not_contains" to "не содержит", "is" to "равно", "starts" to "начинается с", "ends" to "заканчивается на", "over" to "больше", "under" to "меньше")
val RULE_ACTIONS = linkedMapOf(
    "move" to "Переместить в папку", "copy" to "Копию в папку", "move_by_sender" to "В папку по адресу отправителя", "move_by_domain" to "В папку по домену отправителя",
    "label" to "Поставить метку", "flag" to "Флажок", "seen" to "Пометить прочитанным", "forward" to "Переслать на адрес", "forward_copy" to "Переслать копию на адрес",
    "discard" to "Удалить", "reply" to "Ответить текстом", "stop" to "Не проверять другие правила",
)
private fun opsFor(field: String) = if (field == "size") listOf("over", "under") else listOf("contains", "not_contains", "is", "starts", "ends")
private fun needsValue(type: String) = type in setOf("move", "copy", "label", "forward", "forward_copy", "reply")

fun ruleSummary(r: Rule): String {
    val c = r.conditions.joinToString(if (r.match == "any") " или " else " и ") { c ->
        if (c.field == "size") "размер ${RULE_OPS[c.op]} ${c.value} КБ" else "${RULE_FIELDS[c.field]?.lowercase()}${if (c.field == "header") " " + (c.header ?: "") else ""} ${RULE_OPS[c.op]} «${c.value}»"
    }
    val a = r.actions.joinToString(", ") { a ->
        when (a.type) {
            "move", "copy" -> "${RULE_ACTIONS[a.type]?.lowercase()} «${MailStore.folders.firstOrNull { it.path == a.value }?.name ?: a.value}»"
            "label" -> "метка «${MailStore.labels.firstOrNull { it.id.toString() == a.value }?.name ?: a.value}»"
            "forward", "forward_copy" -> "${RULE_ACTIONS[a.type]?.lowercase()} ${a.value}"
            else -> RULE_ACTIONS[a.type]?.lowercase() ?: a.type
        }
    }
    return "Если $c → $a"
}

class RulesScreen : Screen() {
    @Composable
    override fun Content() {
        var data by remember { mutableStateOf<Rules?>(null) }
        var key by remember { mutableStateOf(0) }
        val scope = rememberCoroutineScope()
        fun save(r0: Rules) {
            // Удалённое правило приходит из RuleEditScreen с id "__delete__".
            val r = r0.copy(rules = r0.rules.filter { it.id != "__delete__" })
            scope.launchSafe { Session.api!!.saveRules(r.rules, r.autoreply); data = r; Toasts.show("Правила сохранены") }
        }
        Column(Modifier.fillMaxSize().background(P.bg)) {
            SubBar("Правила и автоответ") {
                IconBtn("plus", "Новое правило") { Nav.push(RuleEditScreen(null) { nr -> val d = data ?: Rules(); save(d.copy(rules = d.rules + nr)) }) }
            }
            Loader(key, { Session.api!!.rules() }) { first, _ ->
                val d = data ?: first
                Column(Modifier.verticalScroll(rememberScrollState())) {
                    SectionTitle("Автоответ")
                    AutoReplyCard(d.autoreply ?: AutoReply()) { ar -> save(d.copy(autoreply = ar)) }
                    SectionTitle("Правила")
                    if (d.rules.isEmpty()) Empty("filter", "Правил нет", "Правило раскладывает письма само: по отправителю, теме, размеру", Modifier.fillMaxWidth().height(220.dp))
                    d.rules.forEachIndexed { i, r ->
                        Row(Modifier.fillMaxWidth().background(P.surface).clickable {
                            Nav.push(RuleEditScreen(r) { nr -> save(d.copy(rules = d.rules.mapIndexed { j, x -> if (j == i) nr else x })) })
                        }.padding(start = 16.dp, end = 8.dp, top = 10.dp, bottom = 10.dp), verticalAlignment = Alignment.CenterVertically) {
                            Column(Modifier.weight(1f)) {
                                Text(r.name.ifBlank { "Правило ${i + 1}" }, style = MaterialTheme.typography.bodyLarge, color = if (r.enabled) P.text else P.faint)
                                Text(ruleSummary(r), style = MaterialTheme.typography.bodySmall, color = P.muted, maxLines = 3, overflow = TextOverflow.Ellipsis)
                            }
                            // Порядок важен: правила выполняются сверху вниз (как в веб-почте), поэтому их можно переставлять.
                            if (d.rules.size > 1) Column {
                                IconBtn("up", "Выше", tint = if (i > 0) P.muted else P.border2) { if (i > 0) save(d.copy(rules = d.rules.toMutableList().apply { add(i - 1, removeAt(i)) })) }
                                IconBtn("down", "Ниже", tint = if (i < d.rules.lastIndex) P.muted else P.border2) { if (i < d.rules.lastIndex) save(d.copy(rules = d.rules.toMutableList().apply { add(i + 1, removeAt(i)) })) }
                            }
                            Switch(r.enabled, { v -> save(d.copy(rules = d.rules.mapIndexed { j, x -> if (j == i) x.copy(enabled = v) else x })) })
                        }
                        Divider()
                    }
                    if (d.rules.isNotEmpty()) TextButton(onClick = {
                        scope.launchSafe { Session.api!!.applyRules(); Toasts.show("Правила применены к «Входящим»"); MailStore.load(); MailStore.refreshFolders() }
                    }, modifier = Modifier.padding(8.dp)) { Text("Применить к письмам во «Входящих»") }
                    Spacer(Modifier.height(40.dp))
                }
            }
        }
    }
}

@Composable
private fun AutoReplyCard(a: AutoReply, onSave: (AutoReply) -> Unit) {
    var open by remember { mutableStateOf(false) }
    var subject by remember(a) { mutableStateOf(a.subject) }
    var body by remember(a) { mutableStateOf(a.body) }
    var from by remember(a) { mutableStateOf(a.from?.let { runCatching { LocalDate.parse(it.take(10)) }.getOrNull() }) }
    var to by remember(a) { mutableStateOf(a.to?.let { runCatching { LocalDate.parse(it.take(10)) }.getOrNull() }) }
    var days by remember(a) { mutableStateOf(a.days ?: 1) }
    var pick by remember { mutableStateOf<String?>(null) }
    Column(Modifier.fillMaxWidth().background(P.surface).padding(16.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f)) {
                Text(if (a.enabled) "Включён" else "Выключен", style = MaterialTheme.typography.bodyLarge, color = if (a.enabled) P.okInk else P.text)
                Text(if (a.enabled) listOfNotNull(from?.let { "с " + Fmt.dateShort(it) }, to?.let { "по " + Fmt.dateShort(it) }).joinToString(" ").ifBlank { "без срока" } else "Отвечает всем, пока вы в отпуске",
                    style = MaterialTheme.typography.bodySmall, color = P.muted)
            }
            Switch(a.enabled, { v -> if (v && body.isBlank()) open = true else onSave(a.copy(enabled = v)) })
        }
        TextButton(onClick = { open = !open }) { Text(if (open) "Свернуть" else "Текст и период") }
        if (open) Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
            OutlinedTextField(subject, { subject = it }, Modifier.fillMaxWidth(), label = { Text("Тема (необязательно)") }, singleLine = true)
            OutlinedTextField(body, { body = it }, Modifier.fillMaxWidth().height(140.dp), label = { Text("Текст ответа") })
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                Pill(from?.let { "С " + Fmt.dateShort(it) } ?: "С сегодня") { pick = "from" }
                Pill(to?.let { "По " + Fmt.dateShort(it) } ?: "Без окончания") { pick = "to" }
            }
            if (from != null || to != null) TextButton(onClick = { from = null; to = null }) { Text("Без срока") }
            Text("Отвечать одному адресу не чаще, чем раз в", style = MaterialTheme.typography.labelMedium, color = P.muted)
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                listOf(1 to "день", 3 to "3 дня", 7 to "неделю").forEach { (v, t) -> su.innotec.mail.ui.Chip(t, days == v, { days = v }) }
            }
            TextButton(enabled = body.isNotBlank(), onClick = {
                if (from != null && to != null && to!! < from!!) { Toasts.show("Дата окончания раньше начала"); return@TextButton }
                open = false
                onSave(AutoReply(enabled = true, subject = subject, body = body, from = from?.toString(), to = to?.toString(), days = days))
            }) { Text("Сохранить и включить", fontWeight = FontWeight.SemiBold) }
        }
    }
    pick?.let { k ->
        val init = (if (k == "from") from else to) ?: Fmt.today()
        su.innotec.mail.ui.calendar.DateTimePick(init.atTime(LocalTime(0, 0)), withTime = false, onDismiss = { pick = null }) { v -> if (k == "from") from = v.date else to = v.date }
    }
}

class RuleEditScreen(private val existing: Rule?, private val onSave: (Rule) -> Unit) : Screen() {
    override val fullScreen: Boolean get() = true

    @Composable
    override fun Content() {
        var r by remember { mutableStateOf(existing ?: Rule(id = "r" + kotlin.random.Random.nextLong(1, 1_000_000_000), enabled = true, match = "all", conditions = listOf(RuleCondition()), actions = listOf(RuleAction()))) }
        var pick by remember { mutableStateOf<Pair<String, Int>?>(null) }
        var remove by remember { mutableStateOf(false) }
        BackHandler(true) { Nav.pop() }
        fun problem(): String? {
            r.conditions.firstOrNull { it.value.isBlank() }?.let { return "Заполните условие «${RULE_FIELDS[it.field]}» — пустое совпадает со всеми письмами" }
            r.actions.firstOrNull { needsValue(it.type) && it.value.isBlank() }?.let { return "Выберите, что подставить в действие «${RULE_ACTIONS[it.type]}»" }
            if (r.actions.isEmpty()) return "Нужно хотя бы одно действие"
            return null
        }
        Column(Modifier.fillMaxSize().background(P.surface)) {
            Row(Modifier.fillMaxWidth().statusBarsPadding().height(56.dp).padding(horizontal = 4.dp), verticalAlignment = Alignment.CenterVertically) {
                IconBtn("x", "Закрыть") { Nav.pop() }
                Text(if (existing == null) "Новое правило" else "Правило", Modifier.weight(1f), style = MaterialTheme.typography.titleMedium)
                if (existing != null) IconBtn("trash", "Удалить") { remove = true }
                TextButton(onClick = { val p = problem(); if (p != null) Toasts.show(p) else { Nav.pop(); onSave(r) } }) { Text("Сохранить", fontWeight = FontWeight.SemiBold) }
            }
            Divider()
            Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                OutlinedTextField(r.name, { r = r.copy(name = it) }, Modifier.fillMaxWidth(), label = { Text("Название") }, singleLine = true)
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text("Если", style = MaterialTheme.typography.titleSmall)
                    Spacer(Modifier.padding(4.dp))
                    Pill(if (r.match == "any") "любое из условий" else "все условия") { r = r.copy(match = if (r.match == "any") "all" else "any") }
                }
                r.conditions.forEachIndexed { i, c ->
                    Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(10.dp)).border(1.dp, P.border, RoundedCornerShape(10.dp)).padding(10.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Pill(RULE_FIELDS[c.field] ?: c.field) { pick = "field" to i }
                            Spacer(Modifier.padding(3.dp))
                            Pill(RULE_OPS[c.op] ?: c.op) { pick = "op" to i }
                            Spacer(Modifier.weight(1f))
                            if (r.conditions.size > 1) IconBtn("x", "Убрать условие", tint = P.muted) { r = r.copy(conditions = r.conditions.filterIndexed { j, _ -> j != i }) }
                        }
                        if (c.field == "header") OutlinedTextField(c.header ?: "", { v -> r = r.copy(conditions = r.conditions.mapIndexed { j, x -> if (j == i) x.copy(header = v.filter { ch -> ch.isLetterOrDigit() || ch == '-' }) else x }) },
                            Modifier.fillMaxWidth(), label = { Text("Имя заголовка, например List-Id") }, singleLine = true)
                        OutlinedTextField(c.value, { v -> r = r.copy(conditions = r.conditions.mapIndexed { j, x -> if (j == i) x.copy(value = if (c.field == "size") v.filter { it.isDigit() } else v) else x }) },
                            Modifier.fillMaxWidth(), label = { Text(if (c.field == "size") "КБ" else "Значение") }, singleLine = true)
                    }
                }
                if (r.conditions.size < 10) TextButton(onClick = { r = r.copy(conditions = r.conditions + RuleCondition()) }) { Text("+ условие") }
                Text("То", style = MaterialTheme.typography.titleSmall)
                r.actions.forEachIndexed { i, a ->
                    Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(10.dp)).border(1.dp, P.border, RoundedCornerShape(10.dp)).padding(10.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Pill(RULE_ACTIONS[a.type] ?: a.type) { pick = "action" to i }
                            Spacer(Modifier.weight(1f))
                            if (r.actions.size > 1) IconBtn("x", "Убрать действие", tint = P.muted) { r = r.copy(actions = r.actions.filterIndexed { j, _ -> j != i }) }
                        }
                        when (a.type) {
                            "move", "copy" -> Pill(MailStore.folders.firstOrNull { it.path == a.value }?.name ?: "Выбрать папку…") { pick = "folder" to i }
                            "label" -> Pill(MailStore.labels.firstOrNull { it.id.toString() == a.value }?.name ?: "Выбрать метку…") { pick = "label" to i }
                            "forward", "forward_copy" -> OutlinedTextField(a.value, { v -> r = r.copy(actions = r.actions.mapIndexed { j, x -> if (j == i) x.copy(value = v.trim()) else x }) }, Modifier.fillMaxWidth(), label = { Text("Адрес") }, singleLine = true)
                            "reply" -> OutlinedTextField(a.value, { v -> r = r.copy(actions = r.actions.mapIndexed { j, x -> if (j == i) x.copy(value = v) else x }) }, Modifier.fillMaxWidth(), label = { Text("Текст ответа") }, minLines = 3)
                        }
                    }
                }
                if (r.actions.size < 6) TextButton(onClick = { r = r.copy(actions = r.actions + RuleAction()) }) { Text("+ действие") }
                Row(verticalAlignment = Alignment.CenterVertically) { Text("Дальше правила не проверять", Modifier.weight(1f)); Switch(r.stop, { r = r.copy(stop = it) }) }
                Spacer(Modifier.height(40.dp))
            }
        }
        val p = pick
        if (p != null) {
            val (kind, i) = p
            val close = { pick = null }
            when (kind) {
                "field" -> ChoiceDialog("Что проверять", RULE_FIELDS.keys.toList(), { RULE_FIELDS[it]!! }, r.conditions[i].field, onDismiss = close) { f ->
                    r = r.copy(conditions = r.conditions.mapIndexed { j, x -> if (j == i) x.copy(field = f, op = if (x.op in opsFor(f)) x.op else opsFor(f).first(), value = if (f == "size") x.value.filter { it.isDigit() } else x.value) else x })
                }
                "op" -> ChoiceDialog("Условие", opsFor(r.conditions[i].field), { RULE_OPS[it]!! }, r.conditions[i].op, onDismiss = close) { o -> r = r.copy(conditions = r.conditions.mapIndexed { j, x -> if (j == i) x.copy(op = o) else x }) }
                "action" -> ChoiceDialog("Действие", RULE_ACTIONS.keys.toList(), { RULE_ACTIONS[it]!! }, r.actions[i].type, onDismiss = close) { t -> r = r.copy(actions = r.actions.mapIndexed { j, x -> if (j == i) x.copy(type = t, value = "") else x }) }
                "folder" -> FolderPicker("Папка", null, onDismiss = close) { f -> r = r.copy(actions = r.actions.mapIndexed { j, x -> if (j == i) x.copy(value = f.path) else x }) }
                "label" -> ChoiceDialog("Метка", MailStore.labels, { it.name }, null, onDismiss = close) { l -> r = r.copy(actions = r.actions.mapIndexed { j, x -> if (j == i) x.copy(value = l.id.toString()) else x }) }
            }
        }
        if (remove) ConfirmDialog("Удалить правило?", confirm = "Удалить", danger = true, onDismiss = { remove = false }) { Nav.pop(); onSave(r.copy(id = "__delete__")) }
    }
}

@Composable
private fun Pill(text: String, onClick: () -> Unit) {
    Text(text, Modifier.clip(RoundedCornerShape(8.dp)).background(P.accentSoft).clickable(onClick = onClick).padding(horizontal = 10.dp, vertical = 6.dp),
        color = P.accentInk, style = MaterialTheme.typography.bodyMedium)
}
