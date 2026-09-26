package su.innotec.mail.ui.more

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
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import io.ktor.client.request.get
import io.ktor.client.request.header
import io.ktor.client.request.prepareGet
import io.ktor.client.statement.bodyAsChannel
import io.ktor.client.statement.bodyAsText
import io.ktor.http.isSuccess
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.launch
import kotlinx.serialization.Serializable
import kotlinx.serialization.builtins.ListSerializer
import su.innotec.mail.AppInfo
import su.innotec.mail.Screen
import su.innotec.mail.api.ApiJson
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

@Serializable
data class GhAsset(val name: String = "", val browser_download_url: String = "", val size: Long = 0)

@Serializable
data class GhRelease(val tag_name: String = "", val name: String = "", val body: String = "", val html_url: String = "", val draft: Boolean = false, val prerelease: Boolean = false, val assets: List<GhAsset> = emptyList())

data class Release(val version: String, val notes: String, val page: String, val apk: GhAsset?)

/**
 * Обновление приложения: выпуски лежат в GitHub Releases репозитория (метка mobile-vX.Y.Z, файл .apk).
 * Запрос идёт без токена почты — на GitHub уходит только обычное обращение к открытому API.
 */
object Updates {
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.Main)
    var available by mutableStateOf<Release?>(null); private set
    var checking by mutableStateOf(false); private set
    var progress by mutableStateOf<Float?>(null); private set
    var lastError by mutableStateOf<String?>(null); private set

    suspend fun latest(): Release? {
        val http = Session.client(emptyMap())
        val r = http.get("https://api.github.com/repos/${AppInfo.REPO}/releases?per_page=20") { header("Accept", "application/vnd.github+json") }
        if (!r.status.isSuccess()) throw IllegalStateException("GitHub ответил ${r.status.value}")
        val list = ApiJson.decodeFromString(ListSerializer(GhRelease.serializer()), r.bodyAsText())
        val rel = list.filter { !it.draft && !it.prerelease && it.tag_name.startsWith("mobile-v") }
            .maxWithOrNull { a, b -> compareVersions(a.tag_name.removePrefix("mobile-v"), b.tag_name.removePrefix("mobile-v")) } ?: return null
        return Release(rel.tag_name.removePrefix("mobile-v"), rel.body, rel.html_url, rel.assets.firstOrNull { it.name.endsWith(".apk") })
    }

    fun check(manual: Boolean) {
        if (checking) return
        checking = true; lastError = null
        scope.launch {
            try {
                val r = latest()
                available = r?.takeIf { compareVersions(it.version, AppInfo.VERSION) > 0 }
                Session.updatePrefs { it.copy(lastUpdateCheck = Clock.System.now().toEpochMilliseconds()) }
                if (manual && available == null) Toasts.show("Установлена последняя версия")
            } catch (e: Throwable) {
                lastError = e.message
                if (manual) Toasts.show("Не удалось проверить обновления: ${e.message}")
            } finally { checking = false }
        }
    }

    /** Раз в сутки при открытии «Ещё». */
    fun checkQuietly() {
        val day = 24 * 3600 * 1000L
        if (Clock.System.now().toEpochMilliseconds() - Session.prefs.lastUpdateCheck > day) check(false)
    }

    fun install(r: Release) {
        val apk = r.apk
        if (!Updater.canInstall || apk == null) { Sys.openUrl(r.page); return }
        progress = 0f
        scope.launch {
            try {
                val saved = Session.client(emptyMap()).prepareGet(apk.browser_download_url).execute { resp ->
                    if (!resp.status.isSuccess()) throw IllegalStateException("GitHub ответил ${resp.status.value}")
                    FileStore.save(apk.name, "application/vnd.android.package-archive", resp.bodyAsChannel(), apk.size, { n -> progress = if (apk.size > 0) n.toFloat() / apk.size else null }, forOpen = true)
                }
                if (!Updater.install(saved)) Toasts.show("Разрешите установку из этого приложения и нажмите «Обновить» ещё раз")
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
                        Updates.progress != null -> LinearProgressIndicator(progress = { Updates.progress ?: 0f }, modifier = Modifier.fillMaxWidth())
                        r != null -> {
                            Text("Доступна версия ${r.version}", style = MaterialTheme.typography.titleSmall, color = P.accentInk)
                            if (r.notes.isNotBlank()) Text(r.notes.take(1500), style = MaterialTheme.typography.bodySmall, color = P.muted, modifier = Modifier.padding(vertical = 6.dp))
                            Button(onClick = { Updates.install(r) }) { Text(if (Updater.canInstall) "Обновить" else "Открыть страницу выпуска") }
                        }
                        else -> OutlinedButton(onClick = { Updates.check(true) }, enabled = !Updates.checking) { Text(if (Updates.checking) "Проверяю…" else "Проверить обновления") }
                    }
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
