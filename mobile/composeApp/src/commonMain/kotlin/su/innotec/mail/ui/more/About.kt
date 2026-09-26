package su.innotec.mail.ui.more

import androidx.compose.runtime.getValue
import androidx.compose.runtime.setValue
import su.innotec.mail.api.ApiException
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Button
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.mutableStateOf
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.launch
import su.innotec.mail.AppInfo
import su.innotec.mail.Screen
import su.innotec.mail.data.Session
import su.innotec.mail.platform.FileStore
import su.innotec.mail.platform.PlatformInfo
import su.innotec.mail.platform.Sys
import su.innotec.mail.platform.Updater
import su.innotec.mail.ui.Divider
import su.innotec.mail.ui.ListRow
import su.innotec.mail.ui.P
import su.innotec.mail.ui.SectionTitle
import su.innotec.mail.ui.Toasts
import su.innotec.mail.ui.login.compareVersions
import su.innotec.mail.ui.mail.SubBar
import kotlin.time.Clock

/** «Что нового» из CHANGELOG: пункты «- …» — маркером «•», продолжения строк склеены, **жирный** без звёздочек (как на странице /app). */
fun releaseNotes(md: String): String {
    val items = mutableListOf<String>()
    for (line in md.lines()) {
        val t = line.trim()
        when {
            t.startsWith("- ") || t.startsWith("* ") || t.startsWith("• ") -> items += t.substring(2).trim()
            t.isNotEmpty() && items.isNotEmpty() -> items[items.lastIndex] = items.last() + " " + t
            t.isNotEmpty() -> items += t
        }
    }
    val bullet = if (items.size > 1) "• " else ""
    return items.joinToString("\n") { bullet + it.replace(Regex("""\*\*(.+?)\*\*"""), "$1") }
}

/** Выпуск приложения — на своём сервере почты (страница /app), без магазинов и сторонних площадок. */
data class Release(val version: String, val notes: String, val page: String, val url: String, val size: Long, val sha256: String, val code: Int = 0)

/**
 * Выложен ли выпуск новее установленного: по версии, а при равной версии — по номеру сборки
 * (пересобрали 1.2.6 с исправлением, версию не подняли — code вырос, обновиться надо).
 */
fun isNewerRelease(version: String, code: Int, ownVersion: String = AppInfo.VERSION, ownCode: Int = AppInfo.CODE): Boolean {
    val d = compareVersions(version, ownVersion)
    return d > 0 || (d == 0 && code > ownCode)
}

/**
 * Проверка скачанного файла: сходится ли SHA-256 с тем, что объявил сервер; если сервер хеш не дал —
 * хотя бы размер. null — файл в порядке, иначе текст ошибки. Не удалось прочитать файл — тоже ошибка:
 * ставить непроверенное нельзя.
 */
fun verifyDownload(sha256: String?, expectedSha: String, actualSize: Long, expectedSize: Long): String? = when {
    expectedSha.isNotBlank() -> when {
        sha256 == null -> "Не удалось проверить файл обновления"
        !sha256.equals(expectedSha, true) -> "Файл обновления повреждён — попробуйте ещё раз"
        else -> null
    }
    expectedSize > 0 && actualSize != expectedSize -> "Файл обновления скачался не полностью — попробуйте ещё раз"
    else -> null
}

/**
 * Обновление приложения со своего сервера: /api/v1/app/latest говорит, какая версия выложена,
 * файл качается с /app/pochta.apk, перед установкой сверяется SHA-256 — битый или подменённый файл не ставится.
 * Только там, где приложение умеет ставить себя само (Android); на ПК и iPhone обновление — со страницы /app.
 */
object Updates {
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.Main)
    var available by mutableStateOf<Release?>(null); private set
    var checking by mutableStateOf(false); private set
    var progress by mutableStateOf<Float?>(null); private set
    var lastError by mutableStateOf<String?>(null); private set

    /**
     * Скачано и проверено, ждёт разрешения на установку: после возврата из настроек ставится само.
     * Помним на диске — пока пользователь ходит в настройки, систему ничто не держит от того, чтобы убить процесс,
     * и после пересоздания приложения resume() иначе не нашёл бы файл. Свои настройки (Session) сюда не трогаем.
     */
    private val store by lazy { su.innotec.mail.platform.KeyValueStore("updates") }
    private const val PENDING = "pending"

    private fun rememberPending(f: su.innotec.mail.platform.SavedFile?, r: Release?) {
        // Строки через перевод строки: имён файлов с ним не бывает, а сериализатор для одного поля ни к чему.
        store.put(PENDING, if (f == null || r == null) null else listOf(r.version, f.name, f.location, f.mime ?: "", f.size.toString(), r.sha256, r.size.toString()).joinToString("\n"))
    }

    fun resume() {
        if (!Updater.canInstall || !Updater.allowed) return
        val raw = store.get(PENDING) ?: return
        val p = raw.split('\n')
        if (p.size < 7) { store.put(PENDING, null); return }
        val version = p[0]
        val file = su.innotec.mail.platform.SavedFile(p[1], p[2], p[3].ifBlank { null }, p[4].toLongOrNull() ?: 0)
        store.put(PENDING, null)
        // Уже стоит эта или более новая версия — файл устарел, ставить нечего.
        if (compareVersions(version, AppInfo.VERSION) <= 0) return
        scope.launch {
            // Файл мог пропасть (очистка кэша) или измениться — перед установкой сверяем ещё раз, не на главном потоке.
            val problem = kotlinx.coroutines.withContext(Dispatchers.Default) { verifyDownload(su.innotec.mail.platform.sha256Of(file), p[5], file.size, p[6].toLongOrNull() ?: 0) }
            if (problem != null) { Toasts.show(problem); return@launch }
            Updater.install(file)
        }
    }

    suspend fun latest(): Release? {
        val api = Session.api ?: return null
        val r = try { api.appLatest() } catch (e: ApiException) { if (e.status == 404) return null else throw e }
        return Release(r.version, r.notes, r.page, r.url, r.size, r.sha256, r.code)
    }

    fun check(manual: Boolean, offer: Boolean = false) {
        // Где само не ставится (ПК, iPhone) — не спрашиваем сервер и «Доступна версия» не показываем: обновляются со страницы /app.
        if (!Updater.canInstall) { available = null; return }
        if (checking) return
        checking = true; lastError = null
        scope.launch {
            try {
                val r = latest()
                available = r?.takeIf { isNewerRelease(it.version, it.code) }
                Session.updatePrefs { it.copy(lastUpdateCheck = Clock.System.now().toEpochMilliseconds()) }
                if (manual && available == null) Toasts.show("Установлена последняя версия")
                // При запуске — предложить сразу, не заставляя идти в «О приложении».
                available?.let { rel -> if (offer) Toasts.action("Доступна «Почта» ${rel.version}", "Обновить", 12) { install(rel) } }
            } catch (e: Throwable) {
                lastError = e.message
                if (manual) Toasts.show("Не удалось проверить обновления: ${e.message}")
            } finally { checking = false }
        }
    }

    /** Раз в сутки: при запуске приложения (с предложением обновиться) и при открытии «Ещё». */
    fun checkQuietly(offer: Boolean = false) {
        val day = 24 * 3600 * 1000L
        if (Clock.System.now().toEpochMilliseconds() - Session.prefs.lastUpdateCheck > day) check(false, offer)
    }

    fun install(r: Release) {
        if (!Updater.canInstall) { Sys.openUrl(r.page); return }
        if (progress != null) return
        progress = 0f
        scope.launch {
            try {
                val saved = Session.api!!.download(r.url) { len, _, _, ch ->
                    val total = len ?: r.size
                    FileStore.save("Pochta-${r.version}.apk", "application/vnd.android.package-archive", ch, total, { n -> progress = if (total > 0) n.toFloat() / total else null }, forOpen = true)
                }
                // Скачалось не то (оборвалось, подменили по дороге) или не прочиталось — не ставим.
                val problem = kotlinx.coroutines.withContext(Dispatchers.Default) { verifyDownload(su.innotec.mail.platform.sha256Of(saved), r.sha256, saved.size, r.size) }
                if (problem != null) { Toasts.show(problem); return@launch }
                if (!Updater.allowed) {
                    // Разрешение даётся один раз; после возврата из настроек установка продолжится сама (resume).
                    rememberPending(saved, r)
                    Toasts.show("Включите «Разрешить установку приложений» и вернитесь — обновление установится само")
                    kotlinx.coroutines.delay(1200)
                    Updater.askPermission()
                } else Updater.install(saved)
            } catch (e: Throwable) {
                Toasts.show("Не удалось скачать обновление: ${e.message}")
            } finally { progress = null }
        }
    }
}

class AboutScreen : Screen() {
    @Composable
    override fun Content() {
        LaunchedEffect(Unit) { if (Updates.available == null) Updates.check(false) }
        val acc = Session.account
        Column(Modifier.fillMaxSize().background(P.bg)) {
            SubBar("О приложении")
            Column(Modifier.verticalScroll(rememberScrollState())) {
                Column(Modifier.fillMaxWidth().background(P.surface).padding(16.dp)) {
                    Text("Почта", style = MaterialTheme.typography.titleLarge)
                    Text("Версия ${AppInfo.VERSION} · ${PlatformInfo.os}", color = P.muted)
                    Spacer(Modifier.height(12.dp))
                    val r = Updates.available
                    when {
                        Updates.progress != null -> Column {
                            Text("Скачивается обновление ${Updates.available?.version ?: ""}… ${((Updates.progress ?: 0f) * 100).toInt()}%", style = MaterialTheme.typography.bodyMedium)
                            LinearProgressIndicator(progress = { Updates.progress ?: 0f }, modifier = Modifier.fillMaxWidth().padding(top = 6.dp))
                        }
                        r != null -> {
                            Text("Доступна версия ${r.version}", style = MaterialTheme.typography.titleSmall, color = P.accentInk)
                            if (r.notes.isNotBlank()) Text(releaseNotes(r.notes).take(1500), style = MaterialTheme.typography.bodySmall, color = P.muted, modifier = Modifier.padding(vertical = 6.dp))
                            Button(onClick = { Updates.install(r) }) { Text("Обновить") }
                        }
                        Updater.canInstall -> OutlinedButton(onClick = { Updates.check(true) }, enabled = !Updates.checking) { Text(if (Updates.checking) "Проверяю…" else "Проверить обновления") }
                        // ПК и iPhone сами себя не ставят — новые версии на странице приложения (ссылка ниже).
                        else -> Text("Новые версии — на странице приложения", style = MaterialTheme.typography.bodySmall, color = P.muted)
                    }
                }
                acc?.let { a ->
                    ListRow("Страница приложения", a.origin.substringAfter("://") + "/app — скачать, показать коллегам", icon = "download") { Sys.openUrl(a.origin + "/app") }
                }
                SectionTitle("Сервер")
                Column(Modifier.background(P.surface)) {
                    ListRow(acc?.serverName?.ifBlank { null } ?: "Почтовый сервер", acc?.origin, icon = "globe")
                    ListRow("Открыть веб-почту", icon = "laptop") { acc?.let { Sys.openUrl(it.origin + "/mail") } }
                }
                Divider()
                Text("Пароль на устройстве не хранится: приложение входит по токену, который можно отозвать в веб-почте («Настройки → Безопасность»). Уведомления проверяются на вашем сервере, текст писем не проходит через серверы Google или Apple.",
                    Modifier.padding(16.dp), style = MaterialTheme.typography.bodySmall, color = P.faint)
            }
        }
    }
}
