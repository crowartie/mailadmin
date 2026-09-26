package su.innotec.mail.platform

import android.annotation.SuppressLint
import android.os.Handler
import android.os.Looper
import android.view.View
import android.view.ViewGroup
import android.webkit.JavascriptInterface
import android.webkit.WebResourceRequest
import android.webkit.WebResourceResponse
import android.webkit.WebView
import android.webkit.WebViewClient
import androidx.compose.runtime.Composable
import androidx.compose.runtime.rememberUpdatedState
import androidx.compose.ui.Modifier
import androidx.compose.ui.viewinterop.AndroidView
import kotlinx.coroutines.runBlocking

/** Мостик из страницы редактора: вызовы приходят в фоновом потоке — передаём в главный. */
private class EditorBridge(private val state: RichEditorState) {
    private val main = Handler(Looper.getMainLooper())

    @JavascriptInterface
    fun post(s: String) { main.post { state.onMessage(s) } }
}

@SuppressLint("SetJavaScriptEnabled", "JavascriptInterface")
@Composable
actual fun RichEditor(
    state: RichEditorState,
    dark: Boolean,
    placeholder: String,
    modifier: Modifier,
    loadResource: suspend (path: String) -> Pair<String, ByteArray>?,
) {
    val loader = rememberUpdatedState(loadResource)
    AndroidView(
        modifier = modifier,
        factory = { ctx ->
            WebView(ctx).apply {
                layoutParams = ViewGroup.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT)
                settings.javaScriptEnabled = true
                settings.allowFileAccess = false
                settings.allowContentAccess = false
                settings.textZoom = (ctx.resources.configuration.fontScale * 100).toInt()
                setBackgroundColor(android.graphics.Color.TRANSPARENT)
                isVerticalScrollBarEnabled = false
                isHorizontalScrollBarEnabled = false
                overScrollMode = View.OVER_SCROLL_NEVER
                addJavascriptInterface(EditorBridge(state), "MailEditor")
                val imm = ctx.getSystemService(android.view.inputmethod.InputMethodManager::class.java)
                state.finishInput = { if (hasFocus()) imm?.restartInput(this) }
                // Ошибки скрипта редактора — в системный журнал (adb logcat -s MailEditor).
                webChromeClient = object : android.webkit.WebChromeClient() {
                    override fun onConsoleMessage(m: android.webkit.ConsoleMessage): Boolean {
                        if (m.messageLevel() == android.webkit.ConsoleMessage.MessageLevel.ERROR) android.util.Log.w("MailEditor", "${m.message()} @${m.lineNumber()}")
                        return true
                    }
                }
                webViewClient = object : WebViewClient() {
                    // Ссылки в тексте не открываем: это поле ввода, касание ставит курсор.
                    override fun shouldOverrideUrlLoading(view: WebView, request: WebResourceRequest): Boolean = true

                    override fun shouldInterceptRequest(view: WebView, request: WebResourceRequest): WebResourceResponse? {
                        val u = request.url
                        if (u.host == EDITOR_HOST) {
                            val path = (u.encodedPath ?: "") + (u.encodedQuery?.let { "?$it" } ?: "")
                            val res = runCatching { runBlocking { loader.value(path) } }.getOrNull()
                                ?: return WebResourceResponse("text/plain", "utf-8", 404, "Not found", emptyMap(), "".byteInputStream())
                            return WebResourceResponse(res.first.substringBefore(';'), null, res.second.inputStream())
                        }
                        // Внешние картинки черновика не грузим (адресат их всё равно увидит), в сеть поле не ходит.
                        // Только http(s): сам документ WebView грузит через data: — его не трогаем.
                        if (u.scheme != "http" && u.scheme != "https") return null
                        return WebResourceResponse("text/plain", "utf-8", 403, "Blocked", emptyMap(), "".byteInputStream())
                    }

                    override fun onPageFinished(view: WebView, url: String?) {
                        state.attach { js -> view.evaluateJavascript(js, null) }
                    }
                }
                val doc = editorDocument(dark, placeholder, "window.__post=function(s){MailEditor.post(s)};", editorNonce())
                loadDataWithBaseURL("https://$EDITOR_HOST/", doc, "text/html", "utf-8", null)
            }
        },
        onRelease = { w -> state.detach(); state.finishInput = null; w.removeJavascriptInterface("MailEditor"); w.destroy() },
    )
}

private const val EDITOR_HOST = "app.local"
