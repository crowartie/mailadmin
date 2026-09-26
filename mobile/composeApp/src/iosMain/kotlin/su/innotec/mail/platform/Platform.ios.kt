@file:OptIn(kotlinx.cinterop.ExperimentalForeignApi::class, kotlinx.cinterop.BetaInteropApi::class)

package su.innotec.mail.platform

import androidx.compose.ui.graphics.toComposeImageBitmap
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberUpdatedState
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.viewinterop.UIKitView
import io.ktor.client.HttpClient
import io.ktor.client.HttpClientConfig
import io.ktor.client.engine.darwin.Darwin
import io.ktor.utils.io.ByteReadChannel
import io.ktor.utils.io.readAvailable
import kotlinx.cinterop.addressOf
import kotlinx.cinterop.usePinned
import kotlinx.io.Buffer
import platform.Foundation.NSData
import platform.Foundation.NSFileManager
import platform.Foundation.NSTemporaryDirectory
import platform.Foundation.NSURL
import platform.Foundation.NSUserDefaults
import platform.Foundation.create
import platform.Foundation.dataWithBytes
import platform.Foundation.base64EncodedStringWithOptions
import platform.UIKit.UIActivityViewController
import platform.UIKit.UIApplication
import platform.UIKit.UIDevice
import platform.UIKit.UIPasteboard
import platform.UserNotifications.UNAuthorizationOptionAlert
import platform.UserNotifications.UNAuthorizationOptionBadge
import platform.UserNotifications.UNAuthorizationOptionSound
import platform.UserNotifications.UNMutableNotificationContent
import platform.UserNotifications.UNNotificationRequest
import platform.UserNotifications.UNUserNotificationCenter
import platform.WebKit.WKWebView
import platform.WebKit.WKWebViewConfiguration
import su.innotec.mail.api.LocalFile

// iPhone: собирается только на Mac (Xcode). Код написан на Windows без проверки компилятором —
// см. mobile/README.md, раздел «iPhone».

actual object PlatformInfo {
    actual val kind: String = "ios"
    actual val os: String get() = "iOS ${UIDevice.currentDevice.systemVersion}"
    actual val model: String get() = UIDevice.currentDevice.model
}

/** Подмена адреса сервера (hosts) на iOS не поддерживается: NSURLSession не даёт задать DNS. */
actual fun platformHttpClient(hosts: Map<String, String>, block: HttpClientConfig<*>.() -> Unit): HttpClient = HttpClient(Darwin) { block() }

/**
 * Хранилище: NSUserDefaults. Токен стоит перенести в Keychain при первой сборке на Mac
 * (в README — задача номер один для iPhone).
 */
actual class KeyValueStore actual constructor(private val name: String) {
    private val d = NSUserDefaults.standardUserDefaults
    actual fun get(key: String): String? = d.stringForKey("$name.$key")
    actual fun put(key: String, value: String?) {
        if (value == null) d.removeObjectForKey("$name.$key") else d.setObject(value, "$name.$key")
    }
}

internal fun ByteArray.toNSData(): NSData = if (isEmpty()) NSData() else usePinned { NSData.dataWithBytes(it.addressOf(0), size.toULong()) }

actual object FileStore {
    actual suspend fun save(name: String, mime: String?, channel: ByteReadChannel, total: Long?, progress: (Long) -> Unit, forOpen: Boolean): SavedFile {
        val buf = Buffer()
        val chunk = ByteArray(64 * 1024)
        var n = 0L
        while (true) {
            val r = channel.readAvailable(chunk, 0, chunk.size)
            if (r == -1) break
            if (r > 0) { buf.write(chunk, 0, r); n += r; progress(n) }
            if (r == 0 && channel.isClosedForRead) break
        }
        val path = NSTemporaryDirectory() + name.replace("/", "_")
        NSFileManager.defaultManager.createFileAtPath(path, kotlinx.io.readByteArray(buf).toNSData(), null)
        return SavedFile(name, path, mime, n)
    }

    private fun present(items: List<Any>): Boolean {
        val root = UIApplication.sharedApplication.keyWindow?.rootViewController ?: return false
        root.presentViewController(UIActivityViewController(items, null), true, null)
        return true
    }

    /** На iPhone «открыть» и «поделиться» — одно системное окно (оттуда «Открыть в…», «Сохранить в Файлы»). */
    actual fun open(file: SavedFile): Boolean = present(listOf(NSURL.fileURLWithPath(file.location)))
    actual fun share(file: SavedFile): Boolean = open(file)
}

actual object Sys {
    actual fun openUrl(url: String): Boolean {
        val u = NSURL.URLWithString(url) ?: return false
        UIApplication.sharedApplication.openURL(u, emptyMap<Any?, Any>(), null)
        return true
    }
    actual fun dial(phone: String): Boolean = openUrl("tel:" + phone.filter { it.isDigit() || it == '+' })
    actual fun copy(text: String) { UIPasteboard.generalPasteboard.string = text }
    actual fun shareText(text: String): Boolean {
        val root = UIApplication.sharedApplication.keyWindow?.rootViewController ?: return false
        root.presentViewController(UIActivityViewController(listOf(text), null), true, null)
        return true
    }
}

/** Выбор файлов (UIDocumentPicker) — на первой сборке на Mac; пока кнопка ничего не делает. */
@Composable
actual fun rememberFilePicker(multiple: Boolean, mimes: List<String>, onPicked: (List<LocalFile>) -> Unit): () -> Unit = {}

/**
 * WKWebView. Встроенные картинки письма (/mail/api/…) подставляются заранее как data:,
 * потому что WKWebView не пускает перехватить запрос с токеном без своей схемы.
 */
@Composable
actual fun HtmlView(
    html: String,
    dark: Boolean,
    modifier: Modifier,
    onLink: (String) -> Unit,
    loadResource: suspend (path: String) -> Pair<String, ByteArray>?,
) {
    var doc by remember(html) { mutableStateOf<String?>(null) }
    val loader = rememberUpdatedState(loadResource)
    LaunchedEffect(html) {
        var h = html
        Regex("src=\"(/mail/api/[^\"]+)\"").findAll(html).map { it.groupValues[1] }.distinct().forEach { p ->
            loader.value(p.replace("&amp;", "&"))?.let { (type, bytes) ->
                h = h.replace("src=\"$p\"", "src=\"data:${type.substringBefore(';')};base64,${bytes.toNSData().base64EncodedStringWithOptions(0u)}\"")
            }
        }
        doc = wrapHtml(h, dark)
    }
    UIKitView(
        factory = { WKWebView(frame = platform.CoreGraphics.CGRectZero.readValue(), configuration = WKWebViewConfiguration()) },
        modifier = modifier,
        update = { w -> doc?.let { d -> if (w.tag.toInt() != d.hashCode()) { w.tag = d.hashCode().toLong(); w.loadHTMLString(d, null) } } },
    )
}

@Composable
actual fun SystemBarsTheme(dark: Boolean) {}

/** На iPhone «назад» — жест от края экрана; своя обработка появится вместе с навигацией UIKit. */
@Composable
actual fun BackHandler(enabled: Boolean, onBack: () -> Unit) {}

actual object Notifier {
    actual val fastAvailable: Boolean get() = false
    actual fun fast(enabled: Boolean) {}
    actual fun ensurePermission() {
        UNUserNotificationCenter.currentNotificationCenter().requestAuthorizationWithOptions(
            UNAuthorizationOptionAlert or UNAuthorizationOptionSound or UNAuthorizationOptionBadge,
        ) { _, _ -> }
    }

    /** Фоновая проверка на iPhone — через push (APNs), см. docs/mobile-api.md, раздел 5. */
    actual fun schedule(enabled: Boolean) {}

    actual fun show(id: Int, title: String, text: String, folder: String, uid: Long) {
        val c = UNMutableNotificationContent().apply { setTitle(title); setBody(text) }
        UNUserNotificationCenter.currentNotificationCenter().addNotificationRequest(UNNotificationRequest.requestWithIdentifier("mail-$id", c, null), null)
    }
}

actual object Updater {
    actual val canInstall: Boolean get() = false
    actual val allowed: Boolean get() = false
    actual fun askPermission() {}
    actual fun install(file: SavedFile): Boolean = false
}

/** Печать на iPhone — UIPrintInteractionController, появится вместе с первой сборкой на Mac. */
actual object Printer {
    actual val available: Boolean get() = false
    actual fun print(title: String, html: String, loadResource: suspend (path: String) -> Pair<String, ByteArray>?): Boolean = false
}

actual fun decodeImage(bytes: ByteArray, maxSide: Int): androidx.compose.ui.graphics.ImageBitmap? =
    runCatching { org.jetbrains.skia.Image.makeFromEncoded(bytes).toComposeImageBitmap() }.getOrNull()

/** PDF на iPhone — через PDFKit, появится с первой сборкой на Mac; пока открывается другой программой. */
actual fun openPdf(bytes: ByteArray): PdfDoc? = null

/** Кэш на iPhone — в NSUserDefaults не годится (объём); файловый появится с первой сборкой на Mac. */
actual object DiskCache {
    actual fun read(name: String): String? = null
    actual fun write(name: String, text: String) {}
    actual fun clear() {}
}

/** На iPhone — с первой сборкой на Mac (UIImage); пока фото контакта меняется в веб-почте. */
actual fun shrinkToJpeg(bytes: ByteArray, maxSide: Int): ByteArray? = null

/** На iPhone обновления идут через App Store / TestFlight — сверка файла не нужна. */
actual fun sha256Of(file: SavedFile): String? = null
