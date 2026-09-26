package su.innotec.mail

import su.innotec.mail.ui.mail.encoded
import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertTrue

/** Размер вложений в письме считается так же, как на сервере: с кодированием (3 байта → 4 знака). */
class EncodedSizeTest {
    @Test
    fun roundsUpLikeBase64() {
        assertEquals(0, encoded(0))
        assertEquals(2, encoded(1))
        assertEquals(4, encoded(3))
        assertEquals(8, encoded(6))
    }

    @Test
    fun fiveOfSevenMbDoNotFitFortyMb() {
        val mb = 1024L * 1024
        val limit = 40 * mb
        assertTrue(encoded(4 * 7 * mb) <= limit)   // 4 файла по 7 МБ — 37,3 МБ в письме, влезают
        assertTrue(encoded(5 * 7 * mb) > limit)    // 5 — уже 46,7 МБ: пятый и дальше уходят ссылкой
    }
}
