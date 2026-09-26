package su.innotec.mail.platform

/**
 * Документ для WebView из очищенного сервером HTML письма (MailHtml::clean).
 * Сервер уже вырезал скрипты и спрятал внешние картинки в data-blocked-*; здесь — только оформление:
 * своё CSP (никаких скриптов и сетевых запросов, кроме встроенных картинок), подгонка под ширину
 * экрана и тот же класс-обёртка .msg__body-inner, к которому сервер привязал стили письма.
 */
fun wrapHtml(html: String, dark: Boolean): String {
    // Письмо всегда на светлой «бумаге»: рассылки задают свои цвета и в тёмной теме иначе нечитаемы.
    val paper = if (dark) "#F3F5F8" else "#FFFFFF"
    return """<!DOCTYPE html><html><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="Content-Security-Policy" content="default-src 'none'; img-src https: http: data: cid:; style-src 'unsafe-inline'; font-src data: https:; media-src https: data:">
<style>
html,body{margin:0;padding:0;background:$paper;color:#1B2430;}
body{font:15px/1.5 -apple-system,Roboto,"Segoe UI",Arial,sans-serif;word-wrap:break-word;overflow-wrap:anywhere;}
.msg__body-inner{padding:12px 14px 20px;}
img{max-width:100%;height:auto;}
table{max-width:100%;}
pre{white-space:pre-wrap;}
blockquote{margin:8px 0;padding-left:10px;border-left:3px solid #CBD3DE;color:#5A6472;}
a{color:#1B4FC4;}
</style></head><body><div class="msg__body-inner">$html</div></body></html>"""
}

/** «Показать картинки»: вернуть внешним картинкам их адреса (как MessageView.vue). */
fun unblockImages(html: String): String = html.replace(Regex("\\sdata-blocked-(src|srcset|background)=", RegexOption.IGNORE_CASE)) { " " + it.groupValues[1] + "=" }

fun hasBlockedImages(html: String?): Boolean = html != null && Regex("\\sdata-blocked-(src|srcset|background)=", RegexOption.IGNORE_CASE).containsMatchIn(html)

/** Простой текст письма → HTML для показа, если HTML-части нет. */
fun textToHtml(text: String): String {
    val esc = text.replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;")
    val linked = Regex("(https?://[^\\s<]+)").replace(esc) { "<a href=\"${it.value}\">${it.value}</a>" }
    return "<div style=\"white-space:pre-wrap\">$linked</div>"
}
