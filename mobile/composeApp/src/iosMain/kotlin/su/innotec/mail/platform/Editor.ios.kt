package su.innotec.mail.platform

import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberUpdatedState
import androidx.compose.ui.Modifier
import androidx.compose.ui.viewinterop.UIKitView
import kotlinx.cinterop.ExperimentalForeignApi
import kotlinx.cinterop.ObjCSignatureOverride
import kotlinx.cinterop.readValue
import platform.Foundation.base64EncodedStringWithOptions
import platform.WebKit.WKNavigation
import platform.WebKit.WKNavigationDelegateProtocol
import platform.WebKit.WKScriptMessage
import platform.WebKit.WKScriptMessageHandlerProtocol
import platform.WebKit.WKUserContentController
import platform.WebKit.WKWebView
import platform.WebKit.WKWebViewConfiguration
import platform.darwin.NSObject

private class EditorMessages(private val state: RichEditorState) : NSObject(), WKScriptMessageHandlerProtocol {
    override fun userContentController(userContentController: WKUserContentController, didReceiveScriptMessage: WKScriptMessage) {
        (didReceiveScriptMessage.body as? String)?.let { state.onMessage(it) }
    }
}

private class EditorLoaded(private val state: RichEditorState) : NSObject(), WKNavigationDelegateProtocol {
    @ObjCSignatureOverride
    override fun webView(webView: WKWebView, didFinishNavigation: WKNavigation?) {
        state.attach { js -> webView.evaluateJavaScript(js, null) }
    }
}

/**
 * WKWebView с тем же документом, что и на Android. Картинки черновика (/mail/api/…) заранее
 * подставляются как data: — перехватить запрос с токеном WKWebView без своей схемы не даёт.
 * Не проверено на устройстве: первая сборка — на Mac.
 */
@OptIn(ExperimentalForeignApi::class)
@Composable
actual fun RichEditor(
    state: RichEditorState,
    dark: Boolean,
    placeholder: String,
    modifier: Modifier,
    loadResource: suspend (path: String) -> Pair<String, ByteArray>?,
) {
    val loader = rememberUpdatedState(loadResource)
    val messages = remember(state) { EditorMessages(state) }
    val loaded = remember(state) { EditorLoaded(state) }
    LaunchedEffect(state) {
        var h = state.html
        Regex("src=\"(/mail/api/[^\"]+)\"").findAll(h).map { it.groupValues[1] }.distinct().forEach { p ->
            loader.value(p.replace("&amp;", "&"))?.let { (type, bytes) ->
                h = h.replace("src=\"$p\"", "src=\"data:${type.substringBefore(';')};base64,${bytes.toNSData().base64EncodedStringWithOptions(0u)}\"")
            }
        }
        if (h != state.html) state.replaceHtml(h)
    }
    UIKitView(
        factory = {
            val cfg = WKWebViewConfiguration()
            cfg.userContentController.addScriptMessageHandler(messages, "ed")
            WKWebView(frame = platform.CoreGraphics.CGRectZero.readValue(), configuration = cfg).apply {
                navigationDelegate = loaded
                scrollView.scrollEnabled = false
                setOpaque(false)
                loadHTMLString(editorDocument(dark, placeholder, "window.__post=function(s){webkit.messageHandlers.ed.postMessage(s)};", editorNonce()), null)
            }
        },
        modifier = modifier,
        onRelease = { w -> state.detach(); w.configuration.userContentController.removeScriptMessageHandlerForName("ed") },
    )
}
