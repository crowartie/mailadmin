package su.innotec.mail.platform

import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.graphics.Color
import android.graphics.pdf.PdfRenderer
import android.os.ParcelFileDescriptor
import androidx.compose.ui.graphics.ImageBitmap
import androidx.compose.ui.graphics.asImageBitmap
import java.io.File

actual fun decodeImage(bytes: ByteArray, maxSide: Int): ImageBitmap? = runCatching {
    // Снимок с телефона — 4000×3000 и больше: полный размер в памяти не нужен и может её не хватить.
    val bounds = BitmapFactory.Options().apply { inJustDecodeBounds = true }
    BitmapFactory.decodeByteArray(bytes, 0, bytes.size, bounds)
    var sample = 1
    while (maxOf(bounds.outWidth, bounds.outHeight) / sample > maxSide) sample *= 2
    BitmapFactory.decodeByteArray(bytes, 0, bytes.size, BitmapFactory.Options().apply { inSampleSize = sample })?.asImageBitmap()
}.getOrNull()

/** PdfRenderer читает только файл: кладём PDF во временный файл приложения и удаляем при закрытии. */
actual fun openPdf(bytes: ByteArray): PdfDoc? = runCatching {
    val f = File.createTempFile("view", ".pdf", AndroidCtx.app.cacheDir).apply { writeBytes(bytes) }
    val fd = ParcelFileDescriptor.open(f, ParcelFileDescriptor.MODE_READ_ONLY)
    val r = PdfRenderer(fd)
    object : PdfDoc {
        override val pages: Int get() = r.pageCount
        override fun render(page: Int, width: Int): ImageBitmap? = runCatching {
            synchronized(r) {
                r.openPage(page).use { p ->
                    val h = (width.toLong() * p.height / p.width).toInt().coerceAtLeast(1)
                    val bmp = Bitmap.createBitmap(width, h, Bitmap.Config.ARGB_8888)
                    bmp.eraseColor(Color.WHITE)
                    p.render(bmp, null, null, PdfRenderer.Page.RENDER_MODE_FOR_DISPLAY)
                    bmp.asImageBitmap()
                }
            }
        }.getOrNull()
        override fun close() { runCatching { r.close(); fd.close() }; f.delete() }
    }
}.getOrNull()

actual object DiskCache {
    private val dir: File get() = File(AndroidCtx.app.cacheDir, "mail").apply { mkdirs() }
    actual fun read(name: String): String? = runCatching { File(dir, name).takeIf { it.exists() }?.readText() }.getOrNull()
    actual fun write(name: String, text: String) { runCatching { File(dir, "$name.tmp").apply { writeText(text) }.renameTo(File(dir, name)) } }
    actual fun clear() { runCatching { dir.deleteRecursively() } }
}
