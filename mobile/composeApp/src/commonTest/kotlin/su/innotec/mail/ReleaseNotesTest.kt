package su.innotec.mail

import su.innotec.mail.ui.more.releaseNotes
import kotlin.test.Test
import kotlin.test.assertEquals

/** «Что нового» в «О приложении»: разметка CHANGELOG превращается в читаемый список. */
class ReleaseNotesTest {
    @Test
    fun bulletsJoinedAndBoldStripped() {
        val md = "- **Облако:** файлы уходят ссылкой,\n  отправка за секунды.\n\n- Планшет горизонтально"
        assertEquals("• Облако: файлы уходят ссылкой, отправка за секунды.\n• Планшет горизонтально", releaseNotes(md))
    }

    @Test
    fun singleItemWithoutBullet() {
        assertEquals("Обновление из приложения: установка продолжится сама.",
            releaseNotes("- Обновление из приложения:\n  установка продолжится сама."))
    }
}
