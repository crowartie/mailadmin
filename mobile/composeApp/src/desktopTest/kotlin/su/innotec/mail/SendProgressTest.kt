package su.innotec.mail

import su.innotec.mail.ui.mail.SendProgress
import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertNull
import kotlin.test.assertTrue

/** Плашка хода отправки: этапы называются словами — связь, загрузка, работа сервера. */
class SendProgressTest {
    @Test
    fun stages() {
        SendProgress.start("Отчёт", isDraft = false, heavy = false)
        assertNull(SendProgress.title, "текстовое письмо уходит мгновенно — плашка не нужна")

        SendProgress.start("Отчёт", isDraft = false, heavy = true, viaCloud = true)
        assertEquals("Письмо «Отчёт»: связь с сервером…", SendProgress.label())
        SendProgress.upload(10L * 1048576, 40L * 1048576)
        assertTrue(SendProgress.label().startsWith("Письмо «Отчёт»: связь установлена, загрузка 10"), SendProgress.label())
        SendProgress.upload(40L * 1048576, 40L * 1048576)
        assertEquals("Письмо «Отчёт»: загружено, сервер отправляет и кладёт крупные файлы в облако…", SendProgress.label())
        SendProgress.done()
        assertNull(SendProgress.title)

        SendProgress.start("", isDraft = true, heavy = true)
        assertEquals("Черновик «(без темы)»: связь с сервером…", SendProgress.label())
        SendProgress.done()
    }
}
