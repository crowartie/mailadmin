package su.innotec.mail

import kotlinx.coroutines.runBlocking
import su.innotec.mail.api.Api
import su.innotec.mail.api.ContactInput
import su.innotec.mail.platform.createHttpClient
import su.innotec.mail.platform.shrinkToJpeg
import kotlin.test.Test
import kotlin.test.assertNotNull
import kotlin.test.assertNull
import kotlin.test.assertTrue

/** Фото контакта: большая картинка сжимается, ставится, читается, убирается; пробный контакт удаляется. */
class LiveContactPhotoTest {
    private val server = System.getenv("MAILADMIN_TEST_SERVER") ?: "https://mail.innotec.su"
    private val token = System.getenv("MAILADMIN_TEST_TOKEN")

    @Test
    fun setAndRemovePhoto() = runBlocking {
        // Картинка 1600×1200 → не больше 256 точек и десятков килобайт.
        val surface = org.jetbrains.skia.Surface.makeRasterN32Premul(1600, 1200)
        surface.canvas.clear(0xFF2F6FEB.toInt())
        val png = surface.makeImageSnapshot().encodeToData(org.jetbrains.skia.EncodedImageFormat.PNG)!!.bytes
        val jpeg = shrinkToJpeg(png, 256)!!
        val img = org.jetbrains.skia.Image.makeFromEncoded(jpeg)
        assertTrue(maxOf(img.width, img.height) <= 256 && jpeg.size < 60_000, "сжато до ${img.width}×${img.height}, ${jpeg.size} байт")
        if (token.isNullOrBlank()) { println("LiveContactPhotoTest: токен не задан — только сжатие"); return@runBlocking }

        val api = Api(createHttpClient(), server) { token }
        val stamp = System.currentTimeMillis().toString().takeLast(6)
        val c = api.createContact(ContactInput(book = "personal", first = "Фото", last = "Проба $stamp",
            photo = "data:image/jpeg;base64," + kotlin.io.encoding.Base64.Default.encode(jpeg)))
        try {
            assertNotNull(api.contact(c.book, c.uri).photo, "фото не сохранилось")
            // Правка без поля photo фото не трогает, "" — убирает.
            api.updateContact(c.book, c.uri, api.contact(c.book, c.uri).let { ContactInput(book = it.book, first = it.first, last = it.last + "!") })
            assertNotNull(api.contact(c.book, c.uri).photo, "правка без фото стёрла фото")
            api.updateContact(c.book, c.uri, ContactInput(book = c.book, first = "Фото", last = "Проба $stamp", photo = ""))
            assertNull(api.contact(c.book, c.uri).photo, "фото не убралось")
            println("  · фото контакта: поставлено, пережило правку, убрано")
        } finally { api.deleteContact(c.book, c.uri) }
    }
}
