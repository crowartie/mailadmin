package su.innotec.mail

import su.innotec.mail.platform.RichEditorState
import su.innotec.mail.platform.editorDocument
import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertFalse
import kotlin.test.assertTrue

/** Редактор письма: обмен со страницей редактора и сама страница (без браузера). */
class EditorTest {
    @Test
    fun messagesFromPageUpdateState() {
        val s = RichEditorState("<p>было</p>")
        var edits = 0
        var caret: Pair<Float, Float>? = null
        var note: String? = null
        s.onEdit = { edits++ }
        s.onCaret = { t, b -> caret = t to b }
        s.onNote = { note = it }
        s.onMessage("""{"t":"html","v":"<p><b>стало</b></p>"}""")
        assertEquals("<p><b>стало</b></p>", s.html)
        assertEquals(1, edits)
        s.onMessage("""{"t":"html","v":"<p><b>стало</b></p>"}""")   // то же самое — не правка
        assertEquals(1, edits)
        s.onMessage("""{"t":"state","b":true,"i":false,"u":true,"ul":false,"ol":true,"q":false}""")
        assertTrue(s.bold); assertFalse(s.italic); assertTrue(s.underline); assertTrue(s.numbers); assertFalse(s.bullets)
        s.onMessage("""{"t":"height","v":312}""")
        assertEquals(312f, s.contentHeight)
        s.onMessage("""{"t":"focus","v":true}""")
        assertTrue(s.focused)
        s.onMessage("""{"t":"caret","top":40.5,"bottom":60}""")
        assertEquals(40.5f to 60f, caret)
        s.onMessage("""{"t":"note","v":"Картинка большая"}""")
        assertEquals("Картинка большая", note)
        s.onMessage("не JSON")   // мусор не роняет
    }

    @Test
    fun commandsWaitForPageAndStartWithText() {
        val s = RichEditorState("<p>текст \"в кавычках\"</p>")
        s.cmd("bold")
        s.link("https://example.org/?a=1&b=2")
        val sent = mutableListOf<String>()
        s.attach { sent += it }
        assertEquals("ed.set(\"<p>текст \\\"в кавычках\\\"</p>\")", sent[0])
        assertEquals("ed.cmd(\"bold\",null)", sent[1])
        assertEquals("ed.link(\"https://example.org/?a=1&b=2\")", sent[2])
        s.cmd("formatBlock", "blockquote")
        assertEquals("ed.cmd(\"formatBlock\",\"blockquote\")", sent[3])
        s.detach()
        s.focus()
        assertEquals(4, sent.size)   // после detach в страницу ничего не уходит
    }

    @Test
    fun jsStringsAreSafe() {
        val v = RichEditorState.jsStr("строка\n</script> '\"\\")
        assertFalse(v.contains('\n')); assertFalse(v.contains(' '))
        assertTrue(v.startsWith("\"") && v.endsWith("\""))
    }

    @Test
    fun documentAllowsOnlyOwnScript() {
        val doc = editorDocument(dark = true, placeholder = "Текст \"письма\"", bridge = "window.__post=function(s){};", nonce = "abc123")
        assertTrue(doc.contains("script-src 'nonce-abc123'"))
        assertTrue(doc.contains("<script nonce=\"abc123\">"))
        assertFalse(doc.contains("unsafe-eval"))
        assertTrue(doc.contains("content:\"Текст \\\"письма\\\"\""))
        assertTrue(doc.contains("window.__post=function(s){};"))
    }
}
