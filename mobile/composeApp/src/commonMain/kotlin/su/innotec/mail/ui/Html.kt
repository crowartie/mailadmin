package su.innotec.mail.ui

/** Небольшие преобразования HTML ↔ текст для окна «Написать» (редактор в приложении — текстовый). */
object Html {
    fun escape(s: String) = s.replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;").replace("\"", "&quot;")

    /** Текст → абзацы: пустая строка — новый абзац, перевод строки — <br>. Ссылки становятся ссылками. */
    fun fromText(text: String): String {
        val t = text.replace("\r\n", "\n").trimEnd()
        if (t.isEmpty()) return "<p><br></p>"
        return t.split(Regex("\n{2,}")).joinToString("") { para ->
            val body = para.split('\n').joinToString("<br>") { line ->
                Regex("(https?://[^\\s<]+)").replace(escape(line)) { "<a href=\"${it.value}\">${it.value}</a>" }
            }
            "<p>$body</p>"
        }
    }

    private val entities = mapOf("&nbsp;" to " ", "&amp;" to "&", "&lt;" to "<", "&gt;" to ">", "&quot;" to "\"", "&#39;" to "'", "&apos;" to "'", "&laquo;" to "«", "&raquo;" to "»", "&mdash;" to "—", "&ndash;" to "–")

    /** HTML → текст (для правки черновика и «Изменить как новое»). */
    fun toText(html: String): String {
        var s = html
        s = Regex("(?is)<(style|script|head)[^>]*>.*?</\\1>").replace(s, "")
        s = Regex("(?i)<br\\s*/?>").replace(s, "\n")
        // </p> — граница абзаца (пустая строка), остальные блоки — перевод строки: текст → HTML → текст сходится.
        s = Regex("(?i)</p>").replace(s, "\n\n")
        s = Regex("(?i)</(div|h[1-6]|li|tr|blockquote|pre)>").replace(s, "\n")
        s = Regex("(?i)<li[^>]*>").replace(s, "• ")
        s = Regex("(?i)<a[^>]+href=\"([^\"]+)\"[^>]*>(.*?)</a>").replace(s) { m ->
            val href = m.groupValues[1]; val txt = m.groupValues[2]
            if (Regex("<[^>]+>").replace(txt, "").trim() == href || href.startsWith("mailto:")) txt else "$txt ($href)"
        }
        s = Regex("<[^>]+>").replace(s, "")
        entities.forEach { (k, v) -> s = s.replace(k, v, ignoreCase = true) }
        s = Regex("&#(\\d+);").replace(s) { m -> m.groupValues[1].toIntOrNull()?.let { c -> if (c in 1..0xFFFF) c.toChar().toString() else "" } ?: "" }
        s = s.replace("\u00a0", " ")
        s = Regex("[ \\t]+\n").replace(s, "\n")
        s = Regex("\n{3,}").replace(s, "\n\n")
        return s.trim()
    }

    /**
     * Черновик из веб-почты: текст человека — до подписи (div.sig), цитаты (div.quote) или шапки
     * пересылки (div.fwd); всё с этого места сохраняется как есть и дописывается при сохранении.
     */
    fun splitTail(html: String): Pair<String, String> {
        val idx = listOf("<div class=\"sig\"", "<div class=\"quote\"", "<div class=\"fwd\"")
            .map { html.indexOf(it) }.filter { it >= 0 }.minOrNull() ?: return html to ""
        // Пустой абзац-отступ перед подписью/цитатой уходит в хвост вместе с ней.
        var cut = idx
        val before = html.substring(0, idx)
        if (before.endsWith("<p><br></p>")) cut = idx - "<p><br></p>".length
        return html.substring(0, cut) to html.substring(cut)
    }
}
