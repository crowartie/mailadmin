package su.innotec.mail.ui.more

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.text.selection.SelectionContainer
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
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
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import kotlinx.serialization.json.JsonPrimitive
import su.innotec.mail.Screen
import su.innotec.mail.api.AppPassword
import su.innotec.mail.api.Device
import su.innotec.mail.api.SessionRow
import su.innotec.mail.data.Session
import su.innotec.mail.platform.Sys
import su.innotec.mail.ui.ConfirmDialog
import su.innotec.mail.ui.Divider
import su.innotec.mail.ui.Fmt
import su.innotec.mail.ui.Ico
import su.innotec.mail.ui.InputDialog
import su.innotec.mail.ui.ListRow
import su.innotec.mail.ui.P
import su.innotec.mail.ui.SectionTitle
import su.innotec.mail.ui.Toasts
import su.innotec.mail.ui.launchSafe
import su.innotec.mail.ui.mail.IconBtn
import su.innotec.mail.ui.mail.Loader
import su.innotec.mail.ui.mail.SubBar

class SecurityScreen : Screen() {
    @Composable
    override fun Content() {
        var key by remember { mutableStateOf(0) }
        var dialog by remember { mutableStateOf<String?>(null) }
        var kick by remember { mutableStateOf<SessionRow?>(null) }
        var revoke by remember { mutableStateOf<Device?>(null) }
        var removePass by remember { mutableStateOf<AppPassword?>(null) }
        var newPass by remember { mutableStateOf<AppPassword?>(null) }
        val scope = rememberCoroutineScope()
        val api = Session.api!!
        Column(Modifier.fillMaxSize().background(P.bg)) {
            SubBar("Безопасность")
            Loader(key, { api.security() to api.devices() }) { (sec, devices), _ ->
                Column(Modifier.verticalScroll(rememberScrollState())) {
                    SectionTitle("Двухфакторная защита")
                    Column(Modifier.background(P.surface)) {
                        ListRow(
                            if (sec.totp) "Включена" else "Выключена",
                            if (sec.totp) "При входе нужен код из приложения-аутентификатора" else if (sec.required) "Администратор требует включить её" else "Код из приложения на телефоне при каждом входе",
                            icon = "shield", iconTint = if (sec.totp) P.ok else P.warn,
                        ) { dialog = if (sec.totp) "2fa-off" else "2fa-on" }
                    }
                    SectionTitle("Это приложение на устройствах")
                    Column(Modifier.background(P.surface)) {
                        devices.forEach { d ->
                            ListRow(d.name + if (d.me) " · это устройство" else "", "Приложение ${d.appVersion} · ${d.ip} · ${Fmt.listDate(d.seen)}", icon = "mobile",
                                trailing = { if (!d.me) IconBtn("x", "Выйти на устройстве", tint = P.muted) { revoke = d } })
                        }
                    }
                    SectionTitle("Сеансы веб-почты и программ")
                    Column(Modifier.background(P.surface)) {
                        sec.sessions.forEach { s ->
                            ListRow(s.device.ifBlank { "Неизвестное устройство" } + if (s.me) " · этот" else "",
                                listOf(s.ip, Fmt.listDate(s.seen), if (s.count > 1) "${s.count} входа" else null, if (s.remembered) "запомнен" else null).filterNotNull().filter { it.isNotBlank() }.joinToString(" · "),
                                icon = if (s.kind == "app") "mobile" else "laptop",
                                trailing = { if (!s.me) IconBtn("x", "Завершить", tint = P.muted) { kick = s } })
                        }
                        if (sec.sessions.count { !it.me } > 1) ListRow("Завершить все, кроме этого", icon = "logout", danger = true) { dialog = "kick-others" }
                    }
                    if (sec.appPasswordsAllowed) {
                        SectionTitle("Пароли приложений")
                        Column(Modifier.background(P.surface)) {
                            Text("Для программ, которые не умеют двухфакторную защиту: Outlook, почта на телефоне, принтер-сканер.",
                                Modifier.padding(horizontal = 16.dp, vertical = 8.dp), style = MaterialTheme.typography.bodySmall, color = P.muted)
                            sec.appPasswords.forEach { p ->
                                ListRow(p.name, "создан ${Fmt.listDate(p.created)}" + (p.lastUsed?.let { " · входил ${Fmt.listDate(it)}" } ?: " · не использовался"), icon = "key",
                                    trailing = { IconBtn("trash", "Удалить", tint = P.muted) { removePass = p } })
                            }
                            ListRow("Создать пароль приложения", icon = "plus") { dialog = "app-pass" }
                        }
                    }
                    SectionTitle("Последние входы")
                    Column(Modifier.background(P.surface)) {
                        sec.logins.forEach { l ->
                            val bad = l.result !in setOf("ok", "new_device")
                            ListRow(Fmt.full(l.at), listOf(l.device, l.ip, loginResult(l.result)).filter { it.isNotBlank() }.joinToString(" · "),
                                icon = if (bad) "warn" else "check", iconTint = if (bad) P.no else P.ok)
                        }
                    }
                    Text("Пароль ящика меняет администратор.", Modifier.padding(16.dp), style = MaterialTheme.typography.bodySmall, color = P.faint)
                    Spacer(Modifier.height(24.dp))
                }
            }
        }
        when (dialog) {
            "2fa-on" -> TwoFaEnableDialog(onDismiss = { dialog = null }) { key++ }
            "2fa-off" -> InputDialog("Выключить двухфакторную защиту", "Пароль от почты", confirm = "Выключить", password = true,
                hint = "Подтвердите паролем ящика. Входить станет можно без кода.", onDismiss = { dialog = null }) { pw ->
                scope.launchSafe { api.twofaDisable(pw); key++; Toasts.show("Двухфакторная защита выключена") }
            }
            "kick-others" -> ConfirmDialog("Завершить остальные сеансы?", "Веб-почта на других компьютерах попросит войти заново.", "Завершить", danger = true, onDismiss = { dialog = null }) {
                scope.launchSafe { api.kickOthers(); key++ }
            }
            "app-pass" -> AppPasswordDialog(onDismiss = { dialog = null }) { created -> newPass = created; key++ }
        }
        kick?.let { s -> ConfirmDialog("Завершить сеанс «${s.device}»?", confirm = "Завершить", danger = true, onDismiss = { kick = null }) { scope.launchSafe { api.kickSession(s.id); key++ } } }
        revoke?.let { d -> ConfirmDialog("Выйти на «${d.name}»?", "Приложение на том устройстве попросит войти заново.", "Выйти", danger = true, onDismiss = { revoke = null }) { scope.launchSafe { api.revokeDevice(d.id); key++ } } }
        removePass?.let { p -> ConfirmDialog("Удалить пароль «${p.name}»?", "Программа, которая им пользуется, перестанет входить.", "Удалить", danger = true, onDismiss = { removePass = null }) { scope.launchSafe { api.deleteAppPassword(p.id); key++ } } }
        newPass?.let { p ->
            AlertDialog(
                onDismissRequest = { newPass = null },
                title = { Text("Пароль «${p.name}»") },
                text = {
                    Column {
                        Text("Введите его в программе вместо обычного пароля. Больше он показан не будет.", color = P.muted)
                        Spacer(Modifier.height(12.dp))
                        SelectionContainer { Text(p.plain ?: "", fontFamily = FontFamily.Monospace, fontSize = 20.sp, fontWeight = FontWeight.SemiBold) }
                    }
                },
                confirmButton = { TextButton(onClick = { Sys.copy((p.plain ?: "").replace(" ", "")); Toasts.show("Скопировано") }) { Text("Копировать") } },
                dismissButton = { TextButton(onClick = { newPass = null }) { Text("Готово") } },
            )
        }
    }
}

private fun loginResult(r: String) = when (r) {
    "ok" -> "вход"; "new_device" -> "вход с нового устройства"; "bad_password" -> "неверный пароль"; "bad_code" -> "неверный код"
    "blocked" -> "вход запрещён"; "throttled" -> "слишком много попыток"; else -> r
}

@Composable
private fun TwoFaEnableDialog(onDismiss: () -> Unit, onDone: () -> Unit) {
    var secret by remember { mutableStateOf<String?>(null) }
    var code by remember { mutableStateOf("") }
    val scope = rememberCoroutineScope()
    androidx.compose.runtime.LaunchedEffect(Unit) {
        runCatching { Session.api!!.twofaSetup() }.onSuccess { o -> secret = (o["secret"] as? JsonPrimitive)?.content }.onFailure { Toasts.error(it); onDismiss() }
    }
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text("Включить двухфакторную защиту") },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                Text("1. В приложении-аутентификаторе (Яндекс Ключ, Google Authenticator) добавьте аккаунт вручную и введите ключ:", style = MaterialTheme.typography.bodyMedium)
                val s = secret
                if (s == null) Text("…") else Row(verticalAlignment = Alignment.CenterVertically) {
                    SelectionContainer(Modifier.weight(1f)) { Text(s.chunked(4).joinToString(" "), fontFamily = FontFamily.Monospace, fontWeight = FontWeight.SemiBold) }
                    IconBtn("copy", "Копировать ключ", tint = P.muted) { Sys.copy(s); Toasts.show("Ключ скопирован") }
                }
                s?.let { k ->
                    TextButton(onClick = { Sys.openUrl("otpauth://totp/" + su.innotec.mail.api.enc("Почта:" + (Session.account?.user ?: "")) + "?secret=$k&issuer=" + su.innotec.mail.api.enc("Почта")) }) {
                        Text("Открыть в приложении-аутентификаторе")
                    }
                }
                Text("2. Введите шесть цифр, которые оно покажет:", style = MaterialTheme.typography.bodyMedium)
                OutlinedTextField(code, { v -> code = v.filter { it.isDigit() }.take(6) }, Modifier.fillMaxWidth(), label = { Text("Код") }, singleLine = true,
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.NumberPassword))
            }
        },
        confirmButton = {
            TextButton(enabled = code.length == 6, onClick = {
                scope.launchSafe { Session.api!!.twofaEnable(code); onDismiss(); onDone(); Toasts.show("Двухфакторная защита включена") }
            }) { Text("Включить") }
        },
        dismissButton = { TextButton(onClick = onDismiss) { Text("Отмена") } },
    )
}

@Composable
private fun AppPasswordDialog(onDismiss: () -> Unit, onCreated: (AppPassword) -> Unit) {
    var name by remember { mutableStateOf("") }
    var password by remember { mutableStateOf("") }
    val scope = rememberCoroutineScope()
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text("Новый пароль приложения") },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                OutlinedTextField(name, { name = it }, Modifier.fillMaxWidth(), label = { Text("Для какой программы") }, singleLine = true)
                OutlinedTextField(password, { password = it }, Modifier.fillMaxWidth(), label = { Text("Ваш пароль от почты") }, singleLine = true,
                    visualTransformation = PasswordVisualTransformation(), keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password))
            }
        },
        confirmButton = {
            TextButton(enabled = name.isNotBlank() && password.isNotEmpty(), onClick = {
                scope.launchSafe { val p = Session.api!!.createAppPassword(name.trim(), password); onDismiss(); onCreated(p) }
            }) { Text("Создать") }
        },
        dismissButton = { TextButton(onClick = onDismiss) { Text("Отмена") } },
    )
}
