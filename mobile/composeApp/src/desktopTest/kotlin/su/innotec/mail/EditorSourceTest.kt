package su.innotec.mail

import androidx.compose.ui.text.TextRange
import androidx.compose.ui.text.input.TextFieldValue
import su.innotec.mail.platform.EditorSource
import su.innotec.mail.platform.Printer
import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertFalse
import kotlin.test.assertTrue

/** Редактор письма на ПК: разметка ↔ HTML, кнопки оформления, страница для печати. */
class EditorSourceTest {
    @Test
    fun formattingSurvivesRoundTrip() {
        val html = "<p>Привет, <b>Иван</b>!<br>См. <a href=\"https://example.org/?a=1&amp;b=2\">сайт</a></p><ul><li>раз</li><li><i>два</i></li></ul><p>Конец</p>"
        val src = EditorSource.fromHtml(html)
        assertEquals("Привет, <b>Иван</b>!\nСм. <a href=\"https://example.org/?a=1&b=2\">сайт</a>\n\n- раз\n- <i>два</i>\n\nКонец", src)
        assertEquals(html, EditorSource.toHtml(src))
    }

    @Test
    fun strangeTextIsEscapedAndLinked() {
        // Знаки «<» в тексте не становятся тегами, а адреса — ссылками; внутри ссылки адрес второй раз не оборачивается.
        assertEquals("<p>a &lt; b &amp; c</p>", EditorSource.toHtml("a < b & c"))
        assertEquals("<p>см. <a href=\"https://x.ru\">https://x.ru</a></p>", EditorSource.toHtml("см. https://x.ru"))
        assertEquals("<p><a href=\"https://x.ru\">https://x.ru</a></p>", EditorSource.toHtml("<a href=\"https://x.ru\">https://x.ru</a>"))
        assertEquals("<p><br></p>", EditorSource.toHtml("  \n"))
        // Чужие теги из черновика (таблицы, картинки) уходят, свои остаются; <strong> становится <b>.
        assertEquals("<b>жирно</b> текст", EditorSource.fromHtml("<div><strong>жирно</strong> <span style=\"color:red\">текст</span><img src=\"x\"></div>"))
        // Закрывающий </a> без открывающего (ссылка с одинарными кавычками отброшена) не ломает HTML.
        assertEquals("<p>текст</p>", EditorSource.toHtml("текст</a>"))
    }

    @Test
    fun buttonsWrapSelectionAndToggleList() {
        val v = TextFieldValue("один два три", TextRange(5, 8))
        val b = EditorSource.wrap(v, "<b>", "</b>")
        assertEquals("один <b>два</b> три", b.text)
        assertEquals(TextRange(8, 11), b.selection)   // выделение остаётся на слове
        val empty = EditorSource.wrap(TextFieldValue("ab", TextRange(1)), "<i>", "</i>")
        assertEquals("a<i></i>b", empty.text); assertEquals(TextRange(4), empty.selection)   // курсор между тегами
        val list = EditorSource.toggleList(TextFieldValue("раз\nдва\nтри", TextRange(1, 6)))
        assertEquals("- раз\n- два\nтри", list.text)
        assertEquals("раз\nдва\nтри", EditorSource.toggleList(list.copy(selection = TextRange(0, 11))).text)
    }

    @Test
    fun printablePageIsPlain() {
        val p = Printer.printable("Тема <1>", "<html><head><style>x{}</style></head><body><script>alert(1)</script><p>Текст</p><img src=\"cid:1\"></body></html>")
        assertTrue(p.contains("<h3>Тема &lt;1&gt;</h3>"))
        assertTrue(p.contains("<p>Текст</p>"))
        assertFalse(p.contains("script")); assertFalse(p.contains("<img")); assertFalse(p.contains("<style"))
    }
}
