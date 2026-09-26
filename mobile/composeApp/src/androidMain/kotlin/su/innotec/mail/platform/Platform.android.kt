package su.innotec.mail.platform

import android.Manifest
import android.annotation.SuppressLint
import android.app.Activity
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.ClipData
import android.content.ClipboardManager
import android.content.ContentValues
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Build
import android.os.Environment
import android.provider.MediaStore
import android.provider.OpenableColumns
import android.provider.Settings
import android.util.Base64
import android.view.ViewGroup
import android.webkit.WebResourceRequest
import android.webkit.WebResourceResponse
import android.webkit.WebView
import android.webkit.WebViewClient
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.runtime.Composable
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberUpdatedState
import androidx.compose.ui.Modifier
import androidx.compose.ui.viewinterop.AndroidView
import androidx.core.app.ActivityCompat
import androidx.core.app.NotificationCompat
import androidx.core.app.NotificationManagerCompat
import androidx.core.content.ContextCompat
import androidx.core.content.FileProvider
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.Constraints
import androidx.work.NetworkType
import io.ktor.client.HttpClient
import io.ktor.client.HttpClientConfig
import io.ktor.client.engine.okhttp.OkHttp
import io.ktor.utils.io.ByteReadChannel
import io.ktor.utils.io.readAvailable
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.runBlocking
import kotlinx.coroutines.withContext
import kotlinx.io.asSource
import kotlinx.io.buffered
import okhttp3.Dns
import su.innotec.mail.MainActivity
import su.innotec.mail.MailCheckWorker
import su.innotec.mail.R
import su.innotec.mail.api.LocalFile
import java.io.File
import java.io.OutputStream
import java.lang.ref.WeakReference
import java.net.InetAddress
import java.security.KeyStore
import java.util.concurrent.TimeUnit
import javax.crypto.Cipher
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey
import javax.crypto.spec.GCMParameterSpec
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties

/** Контекст приложения и текущая активность — ставит App.onCreate / MainActivity. */
object AndroidCtx {
    lateinit var app: Context
    var activity: WeakReference<Activity>? = null
}

actual object PlatformInfo {
    actual val kind: String = "android"
    actual val os: String get() = "Android ${Build.VERSION.RELEASE}"
    actual val model: String
        get() {
            val m = Build.MODEL ?: "Android"
            val maker = Build.MANUFACTURER ?: ""
            return if (maker.isNotBlank() && !m.startsWith(maker, ignoreCase = true)) "${maker.replaceFirstChar { it.uppercase() }} $m" else m
        }
}

actual fun platformHttpClient(hosts: Map<String, String>, block: HttpClientConfig<*>.() -> Unit): HttpClient = HttpClient(OkHttp) {
    engine {
        config {
            retryOnConnectionFailure(true)
            if (hosts.isNotEmpty()) dns(object : Dns {
                override fun lookup(hostname: String): List<InetAddress> =
                    hosts[hostname]?.let { listOf(InetAddress.getByName(it)) } ?: Dns.SYSTEM.lookup(hostname)
            })
        }
    }
    block()
}

actual class KeyValueStore actual constructor(name: String) {
    private val prefs = AndroidCtx.app.getSharedPreferences("mailadmin_$name", Context.MODE_PRIVATE)

    private fun key(): SecretKey {
        val ks = KeyStore.getInstance("AndroidKeyStore").apply { load(null) }
        (ks.getEntry(ALIAS, null) as? KeyStore.SecretKeyEntry)?.let { return it.secretKey }
        val gen = KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES, "AndroidKeyStore")
        gen.init(
            KeyGenParameterSpec.Builder(ALIAS, KeyProperties.PURPOSE_ENCRYPT or KeyProperties.PURPOSE_DECRYPT)
                .setBlockModes(KeyProperties.BLOCK_MODE_GCM)
                .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
                .setKeySize(256)
                .build()
        )
        return gen.generateKey()
    }

    actual fun get(key: String): String? {
        val v = prefs.getString(key, null) ?: return null
        return runCatching {
            val all = Base64.decode(v, Base64.NO_WRAP)
            val c = Cipher.getInstance("AES/GCM/NoPadding")
            c.init(Cipher.DECRYPT_MODE, key(), GCMParameterSpec(128, all.copyOfRange(0, 12)))
            String(c.doFinal(all.copyOfRange(12, all.size)), Charsets.UTF_8)
        }.getOrNull()
    }

    actual fun put(key: String, value: String?) {
        if (value == null) { prefs.edit().remove(key).apply(); return }
        val c = Cipher.getInstance("AES/GCM/NoPadding")
        c.init(Cipher.ENCRYPT_MODE, key())
        val ct = c.doFinal(value.toByteArray(Charsets.UTF_8))
        prefs.edit().putString(key, Base64.encodeToString(c.iv + ct, Base64.NO_WRAP)).apply()
    }

    private companion object { const val ALIAS = "mailadmin" }
}

actual object FileStore {
    private fun safe(name: String) = name.replace(Regex("[\\\\/:*?\"<>|\\u0000-\\u001f]"), "_").ifBlank { "file" }

    actual suspend fun save(name: String, mime: String?, channel: ByteReadChannel, total: Long?, progress: (Long) -> Unit, forOpen: Boolean): SavedFile =
        withContext(Dispatchers.IO) {
            val ctx = AndroidCtx.app
            val clean = safe(name)
            val type = mime?.substringBefore(';')?.trim()?.ifBlank { null } ?: "application/octet-stream"
            if (forOpen || Build.VERSION.SDK_INT < Build.VERSION_CODES.Q) {
                val dir = if (forOpen) File(ctx.cacheDir, "open").apply { mkdirs() }
                else (ctx.getExternalFilesDir(Environment.DIRECTORY_DOWNLOADS) ?: File(ctx.filesDir, "downloads")).apply { mkdirs() }
                val f = File(dir, clean)
                val n = f.outputStream().use { copy(channel, it, progress) }
                SavedFile(clean, f.absolutePath, type, n)
            } else {
                val values = ContentValues().apply {
                    put(MediaStore.Downloads.DISPLAY_NAME, clean)
                    put(MediaStore.Downloads.MIME_TYPE, type)
                    put(MediaStore.Downloads.IS_PENDING, 1)
                }
                val uri = ctx.contentResolver.insert(MediaStore.Downloads.EXTERNAL_CONTENT_URI, values)
                    ?: error("Не удалось создать файл в «Загрузках»")
                val n = ctx.contentResolver.openOutputStream(uri)!!.use { copy(channel, it, progress) }
                ctx.contentResolver.update(uri, ContentValues().apply { put(MediaStore.Downloads.IS_PENDING, 0) }, null, null)
                SavedFile(clean, uri.toString(), type, n)
            }
        }

    private suspend fun copy(channel: ByteReadChannel, out: OutputStream, progress: (Long) -> Unit): Long {
        val buf = ByteArray(64 * 1024)
        var n = 0L
        while (true) {
            val r = channel.readAvailable(buf, 0, buf.size)
            if (r == -1) break
            if (r > 0) { out.write(buf, 0, r); n += r; progress(n) }
            if (r == 0 && channel.isClosedForRead) break
        }
        return n
    }

    fun uriOf(file: SavedFile): Uri =
        if (file.location.startsWith("content:")) Uri.parse(file.location)
        else FileProvider.getUriForFile(AndroidCtx.app, AndroidCtx.app.packageName + ".files", File(file.location))

    actual fun open(file: SavedFile): Boolean = runCatching {
        val i = Intent(Intent.ACTION_VIEW).setDataAndType(uriOf(file), file.mime ?: "*/*")
            .addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION or Intent.FLAG_ACTIVITY_NEW_TASK)
        AndroidCtx.app.startActivity(Intent.createChooser(i, "Открыть").addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)); true
    }.getOrDefault(false)

    actual fun share(file: SavedFile): Boolean = runCatching {
        val i = Intent(Intent.ACTION_SEND).setType(file.mime ?: "*/*").putExtra(Intent.EXTRA_STREAM, uriOf(file))
            .addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
        AndroidCtx.app.startActivity(Intent.createChooser(i, "Поделиться").addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)); true
    }.getOrDefault(false)
}

actual object Sys {
    private fun start(i: Intent) = runCatching { AndroidCtx.app.startActivity(i.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)); true }.getOrDefault(false)
    actual fun openUrl(url: String): Boolean = start(Intent(Intent.ACTION_VIEW, Uri.parse(url)))
    actual fun dial(phone: String): Boolean = start(Intent(Intent.ACTION_DIAL, Uri.parse("tel:" + phone.filter { it.isDigit() || it == '+' })))
    actual fun copy(text: String) {
        (AndroidCtx.app.getSystemService(Context.CLIPBOARD_SERVICE) as ClipboardManager).setPrimaryClip(ClipData.newPlainText("text", text))
    }
    actual fun shareText(text: String): Boolean = start(Intent.createChooser(Intent(Intent.ACTION_SEND).setType("text/plain").putExtra(Intent.EXTRA_TEXT, text), "Поделиться"))
}

@Composable
actual fun rememberFilePicker(multiple: Boolean, mimes: List<String>, onPicked: (List<LocalFile>) -> Unit): () -> Unit {
    val cb = rememberUpdatedState(onPicked)
    fun toFiles(uris: List<Uri>): List<LocalFile> {
        val cr = AndroidCtx.app.contentResolver
        return uris.mapNotNull { uri ->
            runCatching { cr.takePersistableUriPermission(uri, Intent.FLAG_GRANT_READ_URI_PERMISSION) }
            var name = "file"; var size = -1L
            cr.query(uri, arrayOf(OpenableColumns.DISPLAY_NAME, OpenableColumns.SIZE), null, null, null)?.use { c ->
                if (c.moveToFirst()) {
                    name = c.getString(0) ?: name
                    if (!c.isNull(1)) size = c.getLong(1)
                }
            }
            if (size < 0) size = runCatching { cr.openAssetFileDescriptor(uri, "r")?.use { it.length } ?: -1L }.getOrDefault(-1L)
            if (size < 0) return@mapNotNull null
            LocalFile(name, size, cr.getType(uri) ?: "application/octet-stream") { cr.openInputStream(uri)!!.asSource().buffered() }
        }
    }
    val many = rememberLauncherForActivityResult(ActivityResultContracts.OpenMultipleDocuments()) { uris -> if (uris.isNotEmpty()) cb.value(toFiles(uris)) }
    val one = rememberLauncherForActivityResult(ActivityResultContracts.OpenDocument()) { uri -> if (uri != null) cb.value(toFiles(listOf(uri))) }
    val types = mimes.toTypedArray()
    return remember(multiple) { { if (multiple) many.launch(types) else one.launch(types) } }
}

@SuppressLint("SetJavaScriptEnabled")
@Composable
actual fun HtmlView(
    html: String,
    dark: Boolean,
    modifier: Modifier,
    onLink: (String) -> Unit,
    loadResource: suspend (path: String) -> Pair<String, ByteArray>?,
) {
    val link = rememberUpdatedState(onLink)
    val loader = rememberUpdatedState(loadResource)
    AndroidView(
        modifier = modifier,
        factory = { ctx ->
            WebView(ctx).apply {
                layoutParams = ViewGroup.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT)
                settings.javaScriptEnabled = false
                settings.allowFileAccess = false
                settings.allowContentAccess = false
                settings.loadWithOverviewMode = true
                settings.useWideViewPort = true
                settings.builtInZoomControls = true
                settings.displayZoomControls = false
                settings.blockNetworkImage = false
                setBackgroundColor(android.graphics.Color.TRANSPARENT)
                isVerticalScrollBarEnabled = false
                webViewClient = object : WebViewClient() {
                    override fun shouldOverrideUrlLoading(view: WebView, request: WebResourceRequest): Boolean {
                        link.value(request.url.toString()); return true
                    }

                    override fun shouldInterceptRequest(view: WebView, request: WebResourceRequest): WebResourceResponse? {
                        val u = request.url
                        if (u.host == BASE_HOST) {
                            val path = (u.encodedPath ?: "") + (u.encodedQuery?.let { "?$it" } ?: "")
                            val res = runCatching { runBlocking { loader.value(path) } }.getOrNull()
                                ?: return WebResourceResponse("text/plain", "utf-8", 404, "Not found", emptyMap(), "".byteInputStream())
                            return WebResourceResponse(res.first.substringBefore(';'), null, res.second.inputStream())
                        }
                        return null
                    }
                }
            }
        },
        update = { w ->
            val doc = wrapHtml(html, dark)
            if (w.tag != doc) {
                w.tag = doc
                w.loadDataWithBaseURL("https://$BASE_HOST/", doc, "text/html", "utf-8", null)
            }
        },
    )
}

private const val BASE_HOST = "app.local"

@Composable
actual fun SystemBarsTheme(dark: Boolean) {
    val view = androidx.compose.ui.platform.LocalView.current
    androidx.compose.runtime.SideEffect {
        val window = (view.context as? Activity)?.window ?: return@SideEffect
        val c = androidx.core.view.WindowCompat.getInsetsController(window, view)
        c.isAppearanceLightStatusBars = !dark
        c.isAppearanceLightNavigationBars = !dark
    }
}

@Composable
actual fun BackHandler(enabled: Boolean, onBack: () -> Unit) = androidx.activity.compose.BackHandler(enabled, onBack)

actual object Notifier {
    private const val CHANNEL = "mail"

    private fun channel() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            val nm = AndroidCtx.app.getSystemService(NotificationManager::class.java)
            if (nm.getNotificationChannel(CHANNEL) == null) {
                nm.createNotificationChannel(NotificationChannel(CHANNEL, "Новые письма", NotificationManager.IMPORTANCE_DEFAULT))
            }
        }
    }

    actual fun ensurePermission() {
        channel()
        if (Build.VERSION.SDK_INT >= 33) {
            val a = AndroidCtx.activity?.get() ?: return
            if (ContextCompat.checkSelfPermission(a, Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED) {
                ActivityCompat.requestPermissions(a, arrayOf(Manifest.permission.POST_NOTIFICATIONS), 7)
            }
        }
    }

    actual val fastAvailable: Boolean get() = true
    actual fun fast(enabled: Boolean) { su.innotec.mail.MailWatchService.sync(AndroidCtx.app) }

    actual fun schedule(enabled: Boolean) {
        val wm = WorkManager.getInstance(AndroidCtx.app)
        if (!enabled) { wm.cancelUniqueWork("mail-check"); return }
        val req = PeriodicWorkRequestBuilder<MailCheckWorker>(15, TimeUnit.MINUTES)
            .setConstraints(Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build())
            .build()
        wm.enqueueUniquePeriodicWork("mail-check", ExistingPeriodicWorkPolicy.KEEP, req)
    }

    @SuppressLint("MissingPermission")
    actual fun show(id: Int, title: String, text: String, folder: String, uid: Long) {
        channel()
        if (Build.VERSION.SDK_INT >= 33 &&
            ContextCompat.checkSelfPermission(AndroidCtx.app, Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED
        ) return
        val open = Intent(AndroidCtx.app, MainActivity::class.java)
            .setAction("open-message").putExtra("folder", folder).putExtra("uid", uid)
            .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_SINGLE_TOP)
        val pi = PendingIntent.getActivity(AndroidCtx.app, id, open, PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT)
        val n = NotificationCompat.Builder(AndroidCtx.app, CHANNEL)
            .setSmallIcon(R.drawable.ic_stat_mail)
            .setContentTitle(title)
            .setContentText(text)
            .setStyle(NotificationCompat.BigTextStyle().bigText(text))
            .setAutoCancel(true)
            .setContentIntent(pi)
            .setGroup("mail")
            .build()
        NotificationManagerCompat.from(AndroidCtx.app).notify(id, n)
    }
}

actual object Updater {
    actual val canInstall: Boolean get() = true
    actual fun install(file: SavedFile): Boolean {
        val ctx = AndroidCtx.app
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O && !ctx.packageManager.canRequestPackageInstalls()) {
            return runCatching {
                ctx.startActivity(Intent(Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES, Uri.parse("package:" + ctx.packageName)).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)); false
            }.getOrDefault(false)
        }
        return runCatching {
            val i = Intent(Intent.ACTION_VIEW).setDataAndType(FileStore.uriOf(file), "application/vnd.android.package-archive")
                .addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION or Intent.FLAG_ACTIVITY_NEW_TASK)
            ctx.startActivity(i); true
        }.getOrDefault(false)
    }
}
