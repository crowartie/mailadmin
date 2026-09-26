package su.innotec.mail

import su.innotec.mail.platform.editorDocument
import java.io.File
import kotlin.test.Test

/** Страница редактора в файл — для проверки её скрипта в настоящем браузере (ux/_editorjs). */
class EditorPageDump {
    @Test
    fun dump() {
        val doc = editorDocument(false, "Текст письма", "window.__log=[];window.__post=function(s){window.__log.push(JSON.parse(s))};", "testnonce")
        File("build/editor-page.html").apply { parentFile.mkdirs() }.writeText(doc)
    }
}
