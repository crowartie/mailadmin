package su.innotec.mail.ui.login

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.systemBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
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
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.input.VisualTransformation
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.launch
import su.innotec.mail.AppInfo
import su.innotec.mail.api.ApiException
import su.innotec.mail.api.DeviceInfo
import su.innotec.mail.api.Discovery
import su.innotec.mail.api.LoginCodeRequest
import su.innotec.mail.api.LoginRequest
import su.innotec.mail.data.Account
import su.innotec.mail.data.Session
import su.innotec.mail.platform.PlatformInfo
import su.innotec.mail.platform.deviceName
import su.innotec.mail.ui.Ico
import su.innotec.mail.ui.P

/** Сравнение версий «1.2.3»: <0, 0, >0. */
fun compareVersions(a: String, b: String): Int {
    val x = a.split('.', '-').map { it.toIntOrNull() ?: 0 }
    val y = b.split('.', '-').map { it.toIntOrNull() ?: 0 }
    for (i in 0 until maxOf(x.size, y.size)) {
        val d = (x.getOrElse(i) { 0 }).compareTo(y.getOrElse(i) { 0 })
        if (d != 0) return d
    }
    return 0
}

/** Откуда брать сервер: введён явно — он; иначе mail.<домен адреса>, затем сам домен. */
fun serverCandidates(login: String, server: String): List<String> {
    val s = server.trim().trimEnd('/')
    if (s.isNotEmpty()) return listOf(if (s.startsWith("http://") || s.startsWith("https://")) s else "https://$s")
    val domain = login.substringAfter('@', "").trim().lowercase()
    if (domain.isEmpty()) return emptyList()
    return listOf("https://mail.$domain", "https://$domain")
}

/** Хост из адреса (для поля «IP сервера»). */
fun hostOf(origin: String): String = origin.substringAfter("://").substringBefore('/').substringBefore(':')

class LoginModel {
    var login by mutableStateOf("")
    var password by mutableStateOf("")
    var server by mutableStateOf("")
    var ip by mutableStateOf("")
    var advanced by mutableStateOf(false)
    var code by mutableStateOf("")
    var challenge by mutableStateOf<String?>(null)
    var busy by mutableStateOf(false)
    var error by mutableStateOf<String?>(null)
    private var origin: String = ""
    private var discovery: Discovery? = null

    private fun hosts(o: String) = if (ip.isBlank()) emptyMap() else mapOf(hostOf(o) to ip.trim())

    private fun device() = DeviceInfo(deviceName(), PlatformInfo.kind, AppInfo.VERSION)

    private suspend fun discover(): Pair<String, Discovery> {
        val cands = serverCandidates(login, server)
        if (cands.isEmpty()) throw ApiException(0, "invalid", "Введите адрес почты полностью, например ivanov@innotec.su")
        var last: ApiException? = null
        for (o in cands) {
            try {
                val d = Session.anonymous(o, hosts(o)).discover()
                if (d.api.isBlank()) continue
                val realOrigin = d.api.substringBefore("/api/")
                return realOrigin to d
            } catch (e: ApiException) {
                last = e
            }
        }
        throw ApiException(0, "discover", if (last?.isNetwork == true)
            "Не удалось найти почтовый сервер. Проверьте интернет или укажите адрес сервера в «Дополнительно»."
        else "По этому адресу нет нашей почты. Укажите адрес сервера в «Дополнительно».")
    }

    suspend fun submit() {
        if (busy) return
        error = null
        busy = true
        try {
            val (o, d) = discover()
            if (compareVersions(AppInfo.VERSION, d.minApp) < 0) {
                throw ApiException(0, "old", "Эта версия приложения устарела для сервера. Обновите приложение (нужна ${d.minApp} или новее).")
            }
            origin = o; discovery = d
            val r = Session.anonymous(o, hosts(o)).login(LoginRequest(login.trim(), password, device()))
            if (r.token == null && r.challenge != null) {
                challenge = r.challenge
                return
            }
            finish(r.token ?: throw ApiException(0, "invalid", r.message ?: "Сервер не выдал вход"), r.user, r.name, r.device.id)
        } catch (e: ApiException) {
            error = e.message
        } finally {
            busy = false
        }
    }

    suspend fun submitCode() {
        val ch = challenge ?: return
        if (busy) return
        error = null
        busy = true
        try {
            val r = Session.anonymous(origin, hosts(origin)).loginCode(LoginCodeRequest(ch, code.trim(), device()))
            finish(r.token ?: throw ApiException(0, "invalid", r.message ?: "Код не подошёл"), r.user, r.name, r.device.id)
        } catch (e: ApiException) {
            error = e.message
            if (e.status == 410 || e.code == "expired") challenge = null
        } finally {
            busy = false
        }
    }

    private fun finish(token: String, user: String, name: String, deviceId: Long) {
        val d = discovery
        password = ""
        Session.signIn(
            Account(
                origin = origin, token = token, user = user.ifBlank { login.trim() }, name = name,
                serverName = d?.name ?: "", features = d?.features ?: emptyList(), hosts = hosts(origin), deviceId = deviceId,
            )
        )
    }
}

@Composable
fun LoginScreen() {
    val m = remember { LoginModel() }
    val scope = rememberCoroutineScope()
    Box(Modifier.fillMaxSize().background(P.bg).systemBarsPadding().imePadding(), contentAlignment = Alignment.Center) {
        Column(
            Modifier.widthIn(max = 420.dp).fillMaxWidth().verticalScroll(rememberScrollState()).padding(24.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            Box(Modifier.size(64.dp).clip(RoundedCornerShape(18.dp)).background(P.accent), contentAlignment = Alignment.Center) {
                Ico("mail", size = 34.dp, tint = Color.White)
            }
            Spacer(Modifier.height(16.dp))
            Text("Почта", style = MaterialTheme.typography.titleLarge)
            Spacer(Modifier.height(4.dp))
            Text(
                if (m.challenge == null) "Войдите рабочим адресом и паролем" else "Двухфакторная защита",
                style = MaterialTheme.typography.bodyMedium, color = P.muted,
            )
            Spacer(Modifier.height(24.dp))

            Session.signedOutReason?.let {
                Text(it, Modifier.fillMaxWidth().clip(RoundedCornerShape(8.dp)).background(P.warnSoft).padding(12.dp), color = P.warnInk, style = MaterialTheme.typography.bodyMedium)
                Spacer(Modifier.height(16.dp))
            }

            if (m.challenge == null) {
                OutlinedTextField(
                    m.login, { m.login = it.trim() }, Modifier.fillMaxWidth().testTag("login"), label = { Text("Адрес почты") }, singleLine = true,
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Email, imeAction = ImeAction.Next),
                )
                Spacer(Modifier.height(12.dp))
                var show by remember { mutableStateOf(false) }
                OutlinedTextField(
                    m.password, { m.password = it }, Modifier.fillMaxWidth().testTag("password"), label = { Text("Пароль") }, singleLine = true,
                    visualTransformation = if (show) VisualTransformation.None else PasswordVisualTransformation(),
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password, imeAction = ImeAction.Go),
                    keyboardActions = KeyboardActions(onGo = { scope.launch { m.submit() } }),
                    trailingIcon = { Box(Modifier.clickable { show = !show }.padding(8.dp)) { Ico(if (show) "eyeoff" else "eye", tint = P.muted) } },
                )
                Spacer(Modifier.height(8.dp))
                TextButton(onClick = { m.advanced = !m.advanced }, modifier = Modifier.align(Alignment.Start)) {
                    Text(if (m.advanced) "Скрыть дополнительное" else "Дополнительно")
                }
                if (m.advanced) {
                    OutlinedTextField(
                        m.server, { m.server = it.trim() }, Modifier.fillMaxWidth(), label = { Text("Адрес сервера") }, singleLine = true,
                        placeholder = { Text("mail.example.ru — если не находится сам") },
                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Uri),
                    )
                    Spacer(Modifier.height(12.dp))
                    OutlinedTextField(
                        m.ip, { m.ip = it.trim() }, Modifier.fillMaxWidth(), label = { Text("IP сервера (необязательно)") }, singleLine = true,
                        supportingText = { Text("Если имя сервера в этой сети не открывается") },
                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Uri),
                    )
                }
            } else {
                Text("Введите шесть цифр из приложения-аутентификатора.", style = MaterialTheme.typography.bodyMedium, color = P.muted)
                Spacer(Modifier.height(12.dp))
                OutlinedTextField(
                    m.code, { v -> m.code = v.filter { it.isDigit() }.take(6) }, Modifier.fillMaxWidth().testTag("code"), label = { Text("Код") }, singleLine = true,
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.NumberPassword, imeAction = ImeAction.Go),
                    keyboardActions = KeyboardActions(onGo = { scope.launch { m.submitCode() } }),
                )
            }

            m.error?.let {
                Spacer(Modifier.height(12.dp))
                Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(8.dp)).background(P.noSoft).padding(12.dp), verticalAlignment = Alignment.CenterVertically) {
                    Ico("warn", tint = P.noInk, size = 18.dp); Spacer(Modifier.width(8.dp))
                    Text(it, color = P.noInk, style = MaterialTheme.typography.bodyMedium, modifier = Modifier.testTag("login-error"))
                }
            }
            Spacer(Modifier.height(20.dp))
            Button(
                onClick = { scope.launch { if (m.challenge == null) m.submit() else m.submitCode() } },
                enabled = !m.busy && (if (m.challenge == null) m.login.contains('@') && m.password.isNotEmpty() else m.code.length == 6),
                modifier = Modifier.fillMaxWidth().height(48.dp).testTag("submit"),
            ) {
                if (m.busy) CircularProgressIndicator(Modifier.size(20.dp), strokeWidth = 2.dp, color = P.accentOn)
                else Text(if (m.challenge == null) "Войти" else "Подтвердить", fontWeight = FontWeight.SemiBold)
            }
            if (m.challenge != null) {
                TextButton(onClick = { m.challenge = null; m.code = "" }) { Text("Назад") }
            }
            Spacer(Modifier.height(24.dp))
            Text(
                "Пароль проверяется один раз и на телефоне не хранится. Выйти на этом устройстве можно в настройках приложения или в веб-почте: «Настройки → Безопасность».",
                style = MaterialTheme.typography.bodySmall, color = P.faint,
            )
            Spacer(Modifier.height(8.dp))
            Text("Версия ${AppInfo.VERSION}", style = MaterialTheme.typography.bodySmall, color = P.faint)
        }
    }
}
