package su.innotec.mail

import su.innotec.mail.ui.more.isNewerRelease
import su.innotec.mail.ui.more.releaseNotes
import su.innotec.mail.ui.more.verifyDownload
import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertFalse
import kotlin.test.assertNull
import kotlin.test.assertTrue

/** «О приложении»: «что нового» из CHANGELOG, сравнение выпусков, проверка скачанного файла. */
class ReleaseNotesTest {
    @Test
    fun newerByVersionThenByBuildCode() {
        assertTrue(isNewerRelease("1.2.7", 1, ownVersion = "1.2.6", ownCode = 11))
        assertFalse(isNewerRelease("1.2.5", 99, ownVersion = "1.2.6", ownCode = 11))
        // Та же версия, пересобрали с большим номером сборки — обновление; с тем же или меньшим — нет.
        assertTrue(isNewerRelease("1.2.6", 12, ownVersion = "1.2.6", ownCode = 11))
        assertFalse(isNewerRelease("1.2.6", 11, ownVersion = "1.2.6", ownCode = 11))
        assertFalse(isNewerRelease("1.2.6", 0, ownVersion = "1.2.6", ownCode = 11))
    }

    @Test
    fun downloadIsVerifiedByHashOrSize() {
        assertNull(verifyDownload("ABCD", "abcd", 10, 10))
        assertEquals("Файл обновления повреждён — попробуйте ещё раз", verifyDownload("abce", "abcd", 10, 10))
        // Хеш не прочитался — это ошибка, а не «сойдёт».
        assertEquals("Не удалось проверить файл обновления", verifyDownload(null, "abcd", 10, 10))
        // Сервер хеш не дал — сверяем размер; размера тоже нет — верим.
        assertNull(verifyDownload(null, "", 10, 10))
        assertEquals("Файл обновления скачался не полностью — попробуйте ещё раз", verifyDownload(null, "", 9, 10))
        assertNull(verifyDownload(null, "", 9, 0))
    }

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
