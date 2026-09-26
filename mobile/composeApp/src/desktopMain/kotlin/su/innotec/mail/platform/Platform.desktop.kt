package su.innotec.mail.platform

import androidx.compose.ui.graphics.toComposeImageBitmap
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.text.selection.SelectionContainer
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberUpdatedState
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.AnnotatedString
import androidx.compose.ui.text.LinkAnnotation
import androidx.compose.ui.text.SpanStyle
import androidx.compose.ui.text.TextLinkStyles
import androidx.compose.ui.text.buildAnnotatedString
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextDecoration
import androidx.compose.ui.text.withLink
import androidx.compose.ui.text.withStyle
import androidx.compose.ui.unit.dp
import io.ktor.client.HttpClient
import io.ktor.client.HttpClientConfig
import io.ktor.client.engine.okhttp.OkHttp
import io.ktor.utils.io.ByteReadChannel
import io.ktor.utils.io.readAvailable
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import kotlinx.io.asSource
import kotlinx.io.buffered
import okhttp3.Dns
import su.innotec.mail.api.LocalFile
import java.awt.Desktop
import java.awt.FileDialog
import java.awt.Frame
import java.awt.SystemTray
import java.awt.Toolkit
import java.awt.TrayIcon
import java.awt.datatransfer.StringSelection
import java.io.File
import java.net.InetAddress
import java.net.URI
import java.util.Properties

actual object PlatformInfo {
    actual val kind: String = "desktop"
    actual val os: String get() = "${System.getProperty("os.name")} ${System.getProperty("os.version")}"
    actual val model: String get() = runCatching { InetAddress.getLocalHost().hostName }.getOrDefault("PC")
}

actual fun platformHttpClient(hosts: Map<String, String>, block: HttpClientConfig<*>.() -> Unit): HttpClient = HttpClient(OkHttp) {
    engine {
        config {
            if (hosts.isNotEmpty()) dns(object : Dns {
                override fun lookup(hostname: String): List<InetAddress> =
                    hosts[hostname]?.let { listOf(InetAddress.getByName(it)) } ?: Dns.SYSTEM.lookup(hostname)
            })
        }
    }
    block()
}

/** Папка настроек приложения на ПК: ~/.mailadmin (можно переопределить -Dmailadmin.home для тестов). */
val appHome: File get() = File(System.getProperty("mailadmin.home") ?: (System.getProperty("user.home") + File.separator + ".mailadmin")).apply { mkdirs() }

actual class KeyValueStore actual constructor(name: String) {
    private val file = File(appHome, "$name.properties")
    private val props = Properties().apply { if (file.exists()) file.inputStream().use { load(it) } }

    actual fun get(key: String): String? = props.getProperty(key)
    actual fun put(key: String, value: String?) {
        if (value == null) props.remove(key) else props.setProperty(key, value)
        file.parentFile?.mkdirs()   // папку могли удалить на ходу (очистка, тесты с отдельной папкой)
        file.outputStream().use { props.store(it, "mailadmin") }
        runCatching { file.setReadable(false, false); file.setReadable(true, true) }
    }
}

actual object FileStore {
    private fun safe(name: String) = name.replace(Regex("[\\\\/:*?\"<>|\\u0000-\\u001f]"), "_").ifBlank { "file" }

    actual suspend fun save(name: String, mime: String?, channel: ByteReadChannel, total: Long?, progress: (Long) -> Unit, forOpen: Boolean): SavedFile =
        withContext(Dispatchers.IO) {
            val dir = if (forOpen) File(System.getProperty("java.io.tmpdir"), "mailadmin-open").apply { mkdirs() }
            else File(System.getProperty("user.home"), "Downloads").apply { mkdirs() }
            var f = File(dir, safe(name))
            if (!forOpen) {
                var i = 1
                val base = f.nameWithoutExtension; val ext = f.extension
                while (f.exists()) { f = File(dir, "$base ($i)" + if (ext.isNotEmpty()) ".$ext" else ""); i++ }
            }
            val buf = ByteArray(64 * 1024)
            var n = 0L
            f.outputStream().use { out ->
                while (true) {
                    val r = channel.readAvailable(buf, 0, buf.size)
                    if (r == -1) break
                    if (r > 0) { out.write(buf, 0, r); n += r; progress(n) }
                    if (r == 0 && channel.isClosedForRead) break
                }
            }
            SavedFile(f.name, f.absolutePath, mime, n)
        }

    actual fun open(file: SavedFile): Boolean = runCatching { Desktop.getDesktop().open(File(file.location)); true }.getOrDefault(false)
    actual fun share(file: SavedFile): Boolean = runCatching { Desktop.getDesktop().open(File(file.location).parentFile); true }.getOrDefault(false)
}

actual object Sys {
    actual fun openUrl(url: String): Boolean = runCatching { Desktop.getDesktop().browse(URI(url)); true }.getOrDefault(false)
    actual fun dial(phone: String): Boolean = openUrl("tel:" + phone.filter { it.isDigit() || it == '+' })
    actual fun copy(text: String) { Toolkit.getDefaultToolkit().systemClipboard.setContents(StringSelection(text), null) }
    actual fun shareText(text: String): Boolean { copy(text); return true }
}

@Composable
actual fun rememberFilePicker(multiple: Boolean, mimes: List<String>, onPicked: (List<LocalFile>) -> Unit): () -> Unit {
    val cb = rememberUpdatedState(onPicked)
    return remember(multiple) {
        {
            val d = FileDialog(null as Frame?, "Выберите файл", FileDialog.LOAD)
            d.isMultipleMode = multiple
            d.isVisible = true
            val files = d.files?.toList().orEmpty()
            if (files.isNotEmpty()) cb.value(files.map { f ->
                LocalFile(f.name, f.length(), java.nio.file.Files.probeContentType(f.toPath()) ?: "application/octet-stream") { f.inputStream().asSource().buffered() }
            })
        }
    }
}

/**
 * На ПК встроенного браузера в Compose нет: письмо показывается текстом со ссылками
 * (жирное, абзацы, списки сохраняются), а «Открыть в браузере» — в меню письма.
 */
@Composable
actual fun HtmlView(
    html: String,
    dark: Boolean,
    modifier: Modifier,
    onLink: (String) -> Unit,
    loadResource: suspend (path: String) -> Pair<String, ByteArray>?,
) {
    val link = rememberUpdatedState(onLink)
    val text = remember(html) { htmlToAnnotated(html) { link.value(it) } }
    SelectionContainer(modifier) {
        Column(Modifier.padding(12.dp)) { Text(text, style = MaterialTheme.typography.bodyLarge) }
    }
}

internal fun htmlToAnnotated(html: String, onLink: (String) -> Unit): AnnotatedString = buildAnnotatedString {
    var s = html
    s = Regex("(?is)<(style|script|head)[^>]*>.*?</\\1>").replace(s, "")
    s = Regex("(?i)<br\\s*/?>").replace(s, "\n")
    s = Regex("(?i)</(p|div|h[1-6]|tr|blockquote|pre|table)>").replace(s, "\n")
    s = Regex("(?i)<li[^>]*>").replace(s, "\n• ")
    val token = Regex("(?is)<a\\s[^>]*href=\"([^\"]*)\"[^>]*>(.*?)</a>|<(b|strong)>(.*?)</\\3>|<[^>]+>|[^<]+")
    for (m in token.findAll(s)) {
        val v = m.value
        when {
            m.groupValues[1].isNotEmpty() -> {
                val url = m.groupValues[1]
                withLink(LinkAnnotation.Clickable(url, TextLinkStyles(SpanStyle(color = androidx.compose.ui.graphics.Color(0xFF2F6FEB), textDecoration = TextDecoration.Underline))) { onLink(url) }) {
                    append(decode(Regex("<[^>]+>").replace(m.groupValues[2], "")))
                }
            }
            m.groupValues[4].isNotEmpty() -> withStyle(SpanStyle(fontWeight = FontWeight.SemiBold)) { append(decode(Regex("<[^>]+>").replace(m.groupValues[4], ""))) }
            v.startsWith("<") -> {}
            else -> append(decode(v))
        }
    }
}

private fun decode(s: String): String = s.replace("&nbsp;", " ").replace("&lt;", "<").replace("&gt;", ">").replace("&quot;", "\"").replace("&#39;", "'").replace("&amp;", "&")
    .replace(Regex("[ \\t]*\n[ \\t]*\n[\\s]*"), "\n\n")

@Composable
actual fun SystemBarsTheme(dark: Boolean) {}

@Composable
actual fun BackHandler(enabled: Boolean, onBack: () -> Unit) {
    // Назад на ПК — Esc в окне (main.kt).
    DesktopBack.register(enabled, onBack)
}

object DesktopBack {
    private val stack = ArrayList<Pair<() -> Boolean, () -> Unit>>()

    @Composable
    fun register(enabled: Boolean, onBack: () -> Unit) {
        val cb = rememberUpdatedState(onBack)
        val en = rememberUpdatedState(enabled)
        androidx.compose.runtime.DisposableEffect(Unit) {
            val e: Pair<() -> Boolean, () -> Unit> = ({ en.value } to { cb.value() })
            stack.add(e)
            onDispose { stack.remove(e) }
        }
    }

    fun back(): Boolean {
        val e = stack.lastOrNull { it.first() } ?: return false
        e.second(); return true
    }
}

actual object Notifier {
    actual val fastAvailable: Boolean get() = false
    actual fun fast(enabled: Boolean) {}
    private var tray: TrayIcon? = null
    actual fun ensurePermission() {}
    actual fun schedule(enabled: Boolean) {}
    actual fun show(id: Int, title: String, text: String, folder: String, uid: Long) {
        runCatching {
            if (!SystemTray.isSupported()) return
            val t = tray ?: TrayIcon(Toolkit.getDefaultToolkit().createImage(ByteArray(0)), "Почта").also { it.isImageAutoSize = true; SystemTray.getSystemTray().add(it); tray = it }
            t.displayMessage(title, text, TrayIcon.MessageType.INFO)
        }
    }
}

actual object Updater {
    actual val canInstall: Boolean get() = false
    actual val allowed: Boolean get() = true
    actual fun askPermission() {}
    actual fun install(file: SavedFile): Boolean = FileStore.open(file)
}

/** На ПК печать — из «Показать оригинал» в браузере; своей печати пока нет. */
actual object Printer {
    actual val available: Boolean get() = false
    actual fun print(title: String, html: String, loadResource: suspend (path: String) -> Pair<String, ByteArray>?): Boolean = false
}

actual fun decodeImage(bytes: ByteArray, maxSide: Int): androidx.compose.ui.graphics.ImageBitmap? =
    runCatching { org.jetbrains.skia.Image.makeFromEncoded(bytes).toComposeImageBitmap() }.getOrNull()

/** На ПК PDF открывается программой системы. */
actual fun openPdf(bytes: ByteArray): PdfDoc? = null

actual object DiskCache {
    private val dir: File get() = File(appHome, "cache").apply { mkdirs() }
    actual fun read(name: String): String? = runCatching { File(dir, name).takeIf { it.exists() }?.readText() }.getOrNull()
    actual fun write(name: String, text: String) { runCatching { File(dir, name).writeText(text) } }
    actual fun clear() { runCatching { dir.deleteRecursively() } }
}

actual fun shrinkToJpeg(bytes: ByteArray, maxSide: Int): ByteArray? = runCatching {
    val img = org.jetbrains.skia.Image.makeFromEncoded(bytes)
    val k = minOf(1f, maxSide.toFloat() / maxOf(img.width, img.height))
    val w = (img.width * k).toInt().coerceAtLeast(1); val h = (img.height * k).toInt().coerceAtLeast(1)
    val surface = org.jetbrains.skia.Surface.makeRasterN32Premul(w, h)
    surface.canvas.drawImageRect(img, org.jetbrains.skia.Rect.makeWH(w.toFloat(), h.toFloat()))
    surface.makeImageSnapshot().encodeToData(org.jetbrains.skia.EncodedImageFormat.JPEG, 85)!!.bytes
}.getOrNull()

actual fun sha256Of(file: SavedFile): String? = runCatching {
    val md = java.security.MessageDigest.getInstance("SHA-256")
    java.io.File(file.location).inputStream().use { input ->
        val buf = ByteArray(64 * 1024)
        while (true) { val n = input.read(buf); if (n <= 0) break; md.update(buf, 0, n) }
    }
    md.digest().joinToString("") { "%02x".format(it) }
}.getOrNull()
