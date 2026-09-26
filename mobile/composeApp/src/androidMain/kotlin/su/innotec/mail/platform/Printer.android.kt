package su.innotec.mail.platform

import android.print.PrintAttributes
import android.print.PrintManager
import android.webkit.WebResourceRequest
import android.webkit.WebResourceResponse
import android.webkit.WebView
import android.webkit.WebViewClient
import kotlinx.coroutines.runBlocking

/**
 * Системная печать Android: письмо в невидимом WebView, затем PrintManager — оттуда же «Сохранить как PDF».
 * WebView держим, пока идёт печать: иначе сборщик мусора убьёт его посреди подготовки страниц.
 */
actual object Printer {
    private var holder: WebView? = null

    actual val available: Boolean get() = true

    actual fun print(title: String, html: String, loadResource: suspend (path: String) -> Pair<String, ByteArray>?): Boolean {
        val a = AndroidCtx.activity?.get() ?: return false
        val w = WebView(a)
        holder = w
        w.settings.javaScriptEnabled = false
        w.webViewClient = object : WebViewClient() {
            override fun shouldOverrideUrlLoading(view: WebView, request: WebResourceRequest): Boolean = true

            override fun shouldInterceptRequest(view: WebView, request: WebResourceRequest): WebResourceResponse? {
                val u = request.url
                if (u.host != "app.local") return null
                val path = (u.encodedPath ?: "") + (u.encodedQuery?.let { "?$it" } ?: "")
                val res = runCatching { runBlocking { loadResource(path) } }.getOrNull()
                    ?: return WebResourceResponse("text/plain", "utf-8", 404, "Not found", emptyMap(), "".byteInputStream())
                return WebResourceResponse(res.first.substringBefore(';'), null, res.second.inputStream())
            }

            override fun onPageFinished(view: WebView, url: String?) {
                val pm = a.getSystemService(PrintManager::class.java) ?: return
                val name = title.ifBlank { "Письмо" }.take(60)
                pm.print(name, view.createPrintDocumentAdapter(name), PrintAttributes.Builder().setMediaSize(PrintAttributes.MediaSize.ISO_A4).build())
            }
        }
        w.loadDataWithBaseURL("https://app.local/", html, "text/html", "utf-8", null)
        return true
    }
}
