package su.innotec.mail.ui.mail

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
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
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.async
import kotlinx.coroutines.awaitAll
import kotlinx.coroutines.launch
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.booleanOrNull
import kotlinx.serialization.json.intOrNull
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import su.innotec.mail.api.ApiException
import su.innotec.mail.api.Folder
import su.innotec.mail.data.Session
import su.innotec.mail.ui.Fmt
import su.innotec.mail.ui.P
import su.innotec.mail.ui.Toasts

/**
 * Вопрос после «Спам», «Рассылка», «Не спам» и переноса в свою папку — как в веб-почте:
 * только это письмо или все письма с адреса / с домена, и сразу разложить уже полученные.
 * [kind] — spam | lists | ham | folder.
 */
data class SenderAsk(val kind: String, val mails: List<String>, val folder: Folder? = null) {
    val domains get() = mails.map { it.substringAfter('@') }.filter { it.isNotBlank() }.distinct()
}

object SenderRules {
    var ask by mutableStateOf<SenderAsk?>(null)
    /** Отказались делать правило «отправитель → папка» — до перезапуска про эту пару не спрашиваем (обращение №34 в вебе). */
    private val refused = mutableSetOf<String>()

    fun offer(a: SenderAsk) {
        val mails = a.mails.map { it.lowercase() }.filter { it.contains('@') }.distinct()
        if (mails.isEmpty()) return
        if (a.kind == "folder" && mails.all { "$it>${a.folder?.path}" in refused }) return
        ask = a.copy(mails = mails)
    }

    fun refuse() {
        val a = ask ?: return
        if (a.kind == "folder") a.mails.forEach { refused += "$it>${a.folder?.path}" }
        ask = null
    }
}

private val TITLE = mapOf("spam" to "Это спам", "lists" to "Это рассылка", "ham" to "Это не спам")

@Composable
fun SenderRuleHost() {
    val a = SenderRules.ask ?: return
    var resort by remember(a) { mutableStateOf(true) }
    var busy by remember(a) { mutableStateOf(false) }
    val scope = rememberCoroutineScope()

    fun mark(match: String) {
        if (busy) return
        busy = true
        val values = if (match == "domain") a.domains else a.mails
        scope.launch {
            try {
                val api = Session.api!!
                val results = values.map { v -> async { api.markSender(a.kind, match, v, resort, a.folder?.path).jsonObject } }.awaitAll()
                var moved = 0; var global = false; var personalOnly = false; var votes: Pair<Int, Int>? = null
                results.forEach { r: JsonObject ->
                    moved += r["moved"]?.jsonPrimitive?.intOrNull ?: 0
                    global = global || r["global"]?.jsonPrimitive?.booleanOrNull == true
                    personalOnly = personalOnly || r["personalOnly"]?.jsonPrimitive?.booleanOrNull == true
                    val th = r["threshold"]?.jsonPrimitive?.intOrNull ?: 0
                    if (th > 1 && r["personalOnly"]?.jsonPrimitive?.booleanOrNull != true) votes = (r["votes"]?.jsonPrimitive?.intOrNull ?: 0) to th
                }
                SenderRules.ask = null
                val who = if (values.size == 1) values[0] else "${values.size} " + if (match == "domain") Fmt.plural(values.size, "домен", "домена", "доменов") else Fmt.plural(values.size, "адрес", "адреса", "адресов")
                val tail = when {
                    a.kind == "folder" -> ""
                    personalOnly -> " Отправитель вашего домена: правило только у вас."
                    a.kind == "ham" -> if (global) " Фильтр больше не тронет эти письма — у всех сотрудников." else " Заявка на исключение ушла администратору."
                    global -> " Правило стало общим для всех сотрудников."
                    votes != null -> " Станет общим, когда так отметят ${votes!!.second} ${Fmt.plural(votes!!.second, "сотрудник", "сотрудника", "сотрудников")} (сейчас ${votes!!.first})."
                    else -> ""
                }
                Toasts.show("$who: правило добавлено" + (if (moved > 0) ", перемещено писем: $moved" else "") + "." + tail)
                MailStore.refreshFolders(); MailStore.load()
                MailStore.reloadRules()   // про этого отправителя больше не спрашиваем
            } catch (e: ApiException) {
                busy = false; Toasts.error(e)
            }
        }
    }

    AlertDialog(
        onDismissRequest = { SenderRules.refuse() },
        title = { Text(if (a.kind == "folder") "В папку «${a.folder?.name}»" else TITLE[a.kind] ?: "") },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                Text(when (a.kind) {
                    "folder" -> "Письмо перемещено. Класть в «${a.folder?.name}» все письма от этого отправителя — и те, что придут потом?"
                    "ham" -> "Письмо вернулось во «Входящие». Чтобы фильтр больше не задерживал такие письма, добавьте отправителя в исключения:"
                    else -> "Письмо перемещено. Сделать так со всеми письмами от этого отправителя — и с теми, что придут потом?"
                }, color = P.muted)
                Button(onClick = { mark("address") }, enabled = !busy, modifier = Modifier.fillMaxWidth()) {
                    Text((if (a.kind == "ham") "Адрес " else "Все с адреса ") + if (a.mails.size == 1) a.mails[0] else "${a.mails.size} " + Fmt.plural(a.mails.size, "адрес", "адреса", "адресов"),
                        fontWeight = FontWeight.SemiBold, maxLines = 2)
                }
                OutlinedButton(onClick = { mark("domain") }, enabled = !busy, modifier = Modifier.fillMaxWidth()) {
                    Text((if (a.kind == "ham") "Весь домен " else "Все с домена ") + if (a.domains.size == 1) "@" + a.domains[0] else "${a.domains.size} " + Fmt.plural(a.domains.size, "домен", "домена", "доменов"), maxLines = 2)
                }
                if (a.kind != "ham") Row(Modifier.clickable { resort = !resort }, verticalAlignment = Alignment.CenterVertically) {
                    Switch(resort, { resort = it }); Spacer(Modifier.width(10.dp))
                    Text("Сразу разложить уже полученные письма", style = MaterialTheme.typography.bodyMedium)
                }
                Text(when (a.kind) {
                    "folder" -> "Правило появится в «Ещё → Правила», там его можно изменить или удалить."
                    "ham" -> "Исключение действует для всей компании: сервер перестанет считать эти письма спамом."
                    else -> "Правило появится в ваших «Правилах». Когда так же отметят несколько сотрудников, оно станет общим для всех ящиков."
                }, style = MaterialTheme.typography.bodySmall, color = P.faint)
                if (a.kind == "folder") TextButton(onClick = {
                    SenderRules.ask = null
                    scope.launch {
                        runCatching { Session.api!!.saveSettings(kotlinx.serialization.json.buildJsonObject { put("ask_rule_on_move", kotlinx.serialization.json.JsonPrimitive(false)) }) }
                        MailStore.reloadSettings()
                        Toasts.show("Больше не спрашиваю. Включить обратно — «Ещё → Настройки»")
                    }
                }, modifier = Modifier.padding(0.dp)) { Text("Больше не спрашивать", color = P.muted) }
            }
        },
        confirmButton = { TextButton(onClick = { SenderRules.refuse() }) { Text("Только это письмо") } },
    )
}
