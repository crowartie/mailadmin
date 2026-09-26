package su.innotec.mail.platform

import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import io.ktor.client.HttpClient
import io.ktor.client.HttpClientConfig
import io.ktor.client.plugins.HttpTimeout
import io.ktor.client.plugins.contentnegotiation.ContentNegotiation
import io.ktor.client.plugins.defaultRequest
import io.ktor.client.request.header
import io.ktor.http.HttpHeaders
import io.ktor.serialization.kotlinx.json.json
import io.ktor.utils.io.ByteReadChannel
import su.innotec.mail.AppInfo
import su.innotec.mail.api.ApiJson
import su.innotec.mail.api.LocalFile

/** Что за устройство: в User-Agent (журнал действий сервера) и в имени устройства при входе. */
expect object PlatformInfo {
    /** android | ios | desktop — как ждёт /api/v1/login. */
    val kind: String
    /** «Android 16», «Windows 11». */
    val os: String
    /** «Pixel 7», «ПК». */
    val model: String
}

/** Только ASCII: заголовок User-Agent с кириллицей OkHttp не пропускает. */
fun userAgent(): String {
    fun ascii(s: String) = s.map { if (it.code in 32..126) it else '?' }.joinToString("").replace(Regex("\\?+"), "?").trim()
    return "MailadminApp/${AppInfo.VERSION} (${ascii(PlatformInfo.os)}; ${ascii(PlatformInfo.model)})"
}

fun deviceName(): String = "${PlatformInfo.model}, ${PlatformInfo.os}"

/**
 * HTTP-клиент платформы. [hosts] — «имя → IP» для сети, где имя сервера не разрешается
 * (внутри офиса, эмулятор): сертификат проверяется по имени, соединение идёт на IP.
 */
expect fun platformHttpClient(hosts: Map<String, String>, block: HttpClientConfig<*>.() -> Unit): HttpClient

fun createHttpClient(hosts: Map<String, String> = emptyMap()): HttpClient = platformHttpClient(hosts) {
    expectSuccess = false
    install(ContentNegotiation) { json(ApiJson) }
    install(HttpTimeout) {
        connectTimeoutMillis = 15_000
        requestTimeoutMillis = 60_000
        socketTimeoutMillis = 60_000
    }
    defaultRequest { header(HttpHeaders.UserAgent, userAgent()) }
}

/** Небольшое хранилище «ключ → строка». На Android шифруется ключом из Android Keystore. */
expect class KeyValueStore(name: String) {
    fun get(key: String): String?
    fun put(key: String, value: String?)
}

/** Куда сохранять и чем открывать файлы. */
expect object FileStore {
    /** Сохранить поток в «Загрузки» (или временную папку при open=true) и вернуть путь/адрес. */
    suspend fun save(name: String, mime: String?, channel: ByteReadChannel, total: Long?, progress: (Long) -> Unit, forOpen: Boolean): SavedFile
    /** Открыть сохранённый файл подходящей программой. */
    fun open(file: SavedFile): Boolean
    /** Поделиться файлом (Android: меню «Поделиться»). */
    fun share(file: SavedFile): Boolean
}

data class SavedFile(val name: String, val location: String, val mime: String?, val size: Long)

/** Системные действия: ссылка в браузере, звонок, письмо, буфер обмена. */
expect object Sys {
    fun openUrl(url: String): Boolean
    fun dial(phone: String): Boolean
    fun copy(text: String)
    fun shareText(text: String): Boolean
}

/** Выбор файлов на устройстве. */
@Composable
expect fun rememberFilePicker(multiple: Boolean, mimes: List<String> = listOf("*/*"), onPicked: (List<LocalFile>) -> Unit): () -> Unit

/**
 * Письмо в HTML: WebView на Android, WKWebView на iPhone, упрощённый показ на ПК.
 * [loadResource] отдаёт встроенные картинки письма (запросы к /mail/api/… с токеном).
 */
@Composable
expect fun HtmlView(
    html: String,
    dark: Boolean,
    modifier: Modifier,
    onLink: (String) -> Unit,
    loadResource: suspend (path: String) -> Pair<String, ByteArray>?,
)

/** Цвет значков строки состояния по теме приложения (а не системы). */
@Composable
expect fun SystemBarsTheme(dark: Boolean)

/** Кнопка «Назад» системы (Android); на ПК — Esc обрабатывается окном. */
@Composable
expect fun BackHandler(enabled: Boolean, onBack: () -> Unit)

/** Уведомления о новых письмах и фоновая проверка. */
expect object Notifier {
    fun ensurePermission()
    fun schedule(enabled: Boolean)
    fun show(id: Int, title: String, text: String, folder: String, uid: Long)
    /** Мгновенные уведомления (служба раз в минуту) есть только на Android. */
    val fastAvailable: Boolean
    fun fast(enabled: Boolean)
}

/** Установка обновления (Android: APK из GitHub Releases). */
expect object Updater {
    val canInstall: Boolean
    /** Разрешено ли приложению ставить обновления (Android: «Установка из внешних источников»). */
    val allowed: Boolean
    /** Открыть системную настройку этого разрешения. */
    fun askPermission()
    fun install(file: SavedFile): Boolean
}

/** Печать письма (Android: системная печать, в том числе «Сохранить как PDF»). */
expect object Printer {
    val available: Boolean
    fun print(title: String, html: String, loadResource: suspend (path: String) -> Pair<String, ByteArray>?): Boolean
}

/** Картинка из байтов (для просмотра вложений); большие уменьшаются до [maxSide] точек по большей стороне. */
expect fun decodeImage(bytes: ByteArray, maxSide: Int = 4096): androidx.compose.ui.graphics.ImageBitmap?

/** PDF постранично (Android — PdfRenderer). null — платформа не умеет, файл откроют в другой программе. */
expect fun openPdf(bytes: ByteArray): PdfDoc?

interface PdfDoc {
    val pages: Int
    /** Страница шириной [width] точек. */
    fun render(page: Int, width: Int): androidx.compose.ui.graphics.ImageBitmap?
    fun close()
}

/**
 * Кэш писем на устройстве — в закрытой папке приложения (как у почты Gmail и Outlook): список и открытые
 * письма показываются сразу при запуске и без сети. Стирается при выходе из аккаунта.
 */
expect object DiskCache {
    fun read(name: String): String?
    fun write(name: String, text: String)
    fun clear()
}

/** Уменьшить картинку до [maxSide] точек и сжать в JPEG (фото контакта). null — платформа не умеет. */
expect fun shrinkToJpeg(bytes: ByteArray, maxSide: Int): ByteArray?

/** SHA-256 скачанного файла (сверка обновления с тем, что выложено на сервере). null — не удалось прочитать. */
expect fun sha256Of(file: SavedFile): String?
