package su.innotec.mail.platform

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.BasicTextField
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.text.selection.SelectionContainer
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.TextRange
import androidx.compose.ui.text.input.KeyboardCapitalization
import androidx.compose.ui.text.input.TextFieldValue
import androidx.compose.ui.unit.dp
import su.innotec.mail.ui.Html
import su.innotec.mail.ui.Ico
import su.innotec.mail.ui.InputDialog
import su.innotec.mail.ui.P

/**
 * Письмо с оформлением на ПК без встроенного браузера: поле держит «разметку» — обычный текст, в котором
 * жирный, курсив, подчёркнутый и ссылки записаны тегами (<b>…</b>, <a href="…">…</a>), а список — строками
 * с «- ». Кнопки оборачивают выделение тегами, «Предпросмотр» показывает, как это будет выглядеть.
 * Черновик с таким оформлением при открытии не теряет его (раньше поле хранило голый текст); таблицы
 * и картинки в самом тексте на ПК не поддерживаются (уходят как текст), цитата и подпись — отдельно (ComposeModel.tail).
 */
object EditorSource {
    private val INLINE = Regex("(?i)</?(b|strong|i|em|u|s)>|<a\\s+href=\"[^\"<>]*\">|</a>")

    /** HTML тела письма → разметка для поля. */
    fun fromHtml(html: String): String {
        var s = html
        s = Regex("(?is)<(style|script|head)[^>]*>.*?</\\1>").replace(s, "")
        s = Regex("(?i)<br\\s*/?>").replace(s, "\n")
        s = Regex("(?i)</p>").replace(s, "\n\n")
        s = Regex("(?i)</(div|h[1-6]|tr|blockquote|pre|ul|ol)>").replace(s, "\n")
        s = Regex("(?i)</li>").replace(s, "\n")
        s = Regex("(?i)<li[^>]*>").replace(s, "- ")
        // Ссылку без адреса (mailto без текста и т. п.) оставляем как есть: атрибуты, кроме href, не нужны.
        s = Regex("(?i)<a\\s[^>]*href=\"([^\"]*)\"[^>]*>").replace(s) { "<a href=\"" + it.groupValues[1] + "\">" }
        s = Regex("(?i)<(strong)>").replace(s, "<b>"); s = Regex("(?i)</(strong)>").replace(s, "</b>")
        s = Regex("(?i)<(em)>").replace(s, "<i>"); s = Regex("(?i)</(em)>").replace(s, "</i>")
        // Остальные теги — вон; свои (INLINE) — оставить.
        s = Regex("<[^>]+>").replace(s) { m -> if (INLINE.matches(m.value)) m.value else "" }
        s = s.replace("&nbsp;", " ").replace("\u00a0", " ")
        // Сущности раскрываем только те, что не образуют тег: «&lt;b&gt;» в тексте письма так и останется текстом.
        s = s.replace("&amp;", "&").replace("&quot;", "\"").replace("&#39;", "'").replace("&laquo;", "«").replace("&raquo;", "»").replace("&mdash;", "—").replace("&ndash;", "–")
        s = Regex("&#(\\d+);").replace(s) { m -> m.groupValues[1].toIntOrNull()?.let { c -> if (c in 1..0xFFFF && c != '<'.code && c != '>'.code) c.toChar().toString() else m.value } ?: m.value }
        s = Regex("[ \\t]+\n").replace(s, "\n")
        s = Regex("\n{3,}").replace(s, "\n\n")
        return s.trim()
    }

    /** Разметка из поля → HTML тела письма (как Html.fromText, но с тегами оформления и списками). */
    fun toHtml(src: String): String {
        val t = src.replace("\r\n", "\n").trimEnd()
        if (t.isEmpty()) return "<p><br></p>"
        return t.split(Regex("\n{2,}")).joinToString("") { para -> paragraph(para) }
    }

    private fun paragraph(para: String): String {
        val lines = para.split('\n')
        val sb = StringBuilder()
        var inList = false
        var plain = ArrayList<String>()
        fun flushPlain() { if (plain.isNotEmpty()) { sb.append("<p>").append(plain.joinToString("<br>")).append("</p>"); plain = ArrayList() } }
        for (line in lines) {
            if (line.startsWith("- ") || line.startsWith("• ")) {
                flushPlain()
                if (!inList) { sb.append("<ul>"); inList = true }
                sb.append("<li>").append(inline(line.substring(2))).append("</li>")
            } else {
                if (inList) { sb.append("</ul>"); inList = false }
                plain.add(inline(line))
            }
        }
        if (inList) sb.append("</ul>")
        flushPlain()
        return sb.toString()
    }

    /** Свои теги проходят, всё остальное экранируется, голые адреса становятся ссылками (вне тегов). */
    fun inline(line: String): String {
        val sb = StringBuilder()
        var i = 0
        var inA = false
        for (m in INLINE.findAll(line)) {
            sb.append(text(line.substring(i, m.range.first), link = !inA))
            val tag = m.value
            val lower = tag.lowercase()
            when {
                lower.startsWith("<a ") -> {
                    val href = Regex("(?i)href=\"([^\"]*)\"").find(tag)?.groupValues?.get(1) ?: ""
                    inA = true; sb.append("<a href=\"").append(Html.escape(href)).append("\">")
                }
                // Закрывающий тег без открывающего (ссылка с одинарными кавычками отброшена в fromHtml) — просто пропускаем.
                lower == "</a>" -> { if (inA) sb.append("</a>"); inA = false }
                lower == "<strong>" -> sb.append("<b>")
                lower == "</strong>" -> sb.append("</b>")
                lower == "<em>" -> sb.append("<i>")
                lower == "</em>" -> sb.append("</i>")
                else -> sb.append(lower)
            }
            i = m.range.last + 1
        }
        sb.append(text(line.substring(i), link = !inA))
        return sb.toString()
    }

    private fun text(s: String, link: Boolean): String {
        val esc = Html.escape(s)
        return if (link) Regex("(https?://[^\\s<]+)").replace(esc) { "<a href=\"${it.value}\">${it.value}</a>" } else esc
    }

    /** Обернуть выделение тегами; без выделения — вставить пару тегов и поставить курсор между ними. */
    fun wrap(v: TextFieldValue, open: String, close: String): TextFieldValue {
        val a = v.selection.min; val b = v.selection.max
        val text = v.text.substring(0, a) + open + v.text.substring(a, b) + close + v.text.substring(b)
        return if (a == b) v.copy(text = text, selection = TextRange(a + open.length))
        else v.copy(text = text, selection = TextRange(a + open.length, b + open.length))
    }

    /** Список: строки выделения (или текущая) получают «- » в начале; если уже есть — убираем. */
    fun toggleList(v: TextFieldValue): TextFieldValue {
        val t = v.text
        val start = t.lastIndexOf('\n', (v.selection.min - 1).coerceAtLeast(0)).let { if (it < 0) 0 else it + 1 }
        val endNl = t.indexOf('\n', v.selection.max)
        val end = if (endNl < 0) t.length else endNl
        val block = t.substring(start, end)
        val lines = block.split('\n')
        val allMarked = lines.all { it.startsWith("- ") }
        val changed = lines.joinToString("\n") { if (allMarked) it.removePrefix("- ") else if (it.startsWith("- ")) it else "- $it" }
        val text = t.substring(0, start) + changed + t.substring(end)
        return v.copy(text = text, selection = TextRange(start, start + changed.length))
    }
}

@Composable
actual fun RichEditor(
    state: RichEditorState,
    dark: Boolean,
    placeholder: String,
    modifier: Modifier,
    loadResource: suspend (path: String) -> Pair<String, ByteArray>?,
) {
    // Панель оформления у ComposeScreen завязана на встроенный браузер; на ПК своя, внутри поля.
    LaunchedEffect(Unit) { state.rich = false }
    var value by remember { mutableStateOf(TextFieldValue(EditorSource.fromHtml(state.html))) }
    var preview by remember { mutableStateOf(false) }
    var linkAsk by remember { mutableStateOf(false) }
    val color = if (dark) Color(0xFFE6EAF0) else Color(0xFF1B2430)
    val density = androidx.compose.ui.platform.LocalDensity.current.density
    fun set(v: TextFieldValue) { value = v; state.setFromPlain(EditorSource.toHtml(v.text)) }
    Column(modifier.fillMaxWidth()) {
        Row(Modifier.fillMaxWidth().padding(horizontal = 8.dp, vertical = 2.dp), verticalAlignment = Alignment.CenterVertically) {
            Btn("bold", "Жирный") { set(EditorSource.wrap(value, "<b>", "</b>")) }
            Btn("italic", "Курсив") { set(EditorSource.wrap(value, "<i>", "</i>")) }
            Btn("underline", "Подчёркнутый") { set(EditorSource.wrap(value, "<u>", "</u>")) }
            Btn("ul", "Список") { set(EditorSource.toggleList(value)) }
            Btn("link", "Ссылка") { linkAsk = true }
            Spacer(Modifier.width(8.dp))
            Btn("eye", if (preview) "Правка" else "Предпросмотр", on = preview) { preview = !preview }
            Text(if (preview) "Предпросмотр" else "Оформление: выделите текст и нажмите кнопку", Modifier.padding(start = 6.dp), style = MaterialTheme.typography.labelSmall, color = P.faint)
        }
        Box(Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 8.dp)) {
            if (preview) SelectionContainer {
                Text(htmlToAnnotated(EditorSource.toHtml(value.text)) { Sys.openUrl(it) }, style = MaterialTheme.typography.bodyLarge.copy(color = color), modifier = Modifier.semantics { contentDescription = "Предпросмотр письма" })
            } else {
                if (value.text.isEmpty()) Text(placeholder, color = color.copy(alpha = .45f), style = MaterialTheme.typography.bodyLarge)
                BasicTextField(
                    value, { v -> set(v) }, Modifier.fillMaxWidth(),
                    textStyle = MaterialTheme.typography.bodyLarge.copy(color = color),
                    cursorBrush = SolidColor(color),
                    keyboardOptions = KeyboardOptions(capitalization = KeyboardCapitalization.Sentences),
                    // Высоту текста сообщаем так же, как страница редактора на телефоне: поле растёт вместе с письмом,
                    // а не обрезается на 220 dp (панель кнопок и отступы — сверху).
                    onTextLayout = { layout -> state.onMessage("{\"t\":\"height\",\"v\":${layout.size.height / density + 64}}") },
                )
            }
        }
    }
    if (linkAsk) InputDialog("Ссылка", "Адрес", initial = "https://", confirm = "Вставить", onDismiss = { linkAsk = false }) { raw ->
        var url = raw.trim()
        if (url.isNotEmpty() && url != "https://") {
            if (!Regex("^[a-z][a-z0-9+.-]*:", RegexOption.IGNORE_CASE).containsMatchIn(url)) url = "https://" + url.trimStart('/')
            val open = "<a href=\"" + url.replace("\"", "%22") + "\">"
            // Без выделения ссылкой становится сам адрес.
            set(if (value.selection.collapsed) EditorSource.wrap(value, open + url, "</a>") else EditorSource.wrap(value, open, "</a>"))
        }
    }
}

@Composable
private fun Btn(icon: String, label: String, on: Boolean = false, onClick: () -> Unit) {
    Box(
        Modifier.padding(1.dp).size(32.dp).clip(RoundedCornerShape(6.dp)).background(if (on) P.accentSoft else Color.Transparent)
            .clickable(onClickLabel = label) { onClick() }.semantics { contentDescription = label },
        contentAlignment = Alignment.Center,
    ) { Ico(icon, size = 18.dp, tint = if (on) P.accentInk else P.text) }
}
