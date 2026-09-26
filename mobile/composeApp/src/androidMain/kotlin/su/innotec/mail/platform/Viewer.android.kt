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
    BitmapFactory.decodeByteArray(bytes, 0, bytes.size, BitmapFactory.Options().apply { inSampleSize = sample })?.let { upright(it, bytes) }?.asImageBitmap()
}.getOrNull()

/**
 * Повернуть картинку, как велит EXIF: камера телефона пишет пиксели «лёжа», а поворот — в теге Orientation,
 * и без него портрет выходит боком. Библиотеки androidx.exifinterface в проекте нет — хватает системной.
 */
private fun upright(src: Bitmap, bytes: ByteArray): Bitmap {
    val o = runCatching { android.media.ExifInterface(bytes.inputStream()).getAttributeInt(android.media.ExifInterface.TAG_ORIENTATION, android.media.ExifInterface.ORIENTATION_NORMAL) }
        .getOrDefault(android.media.ExifInterface.ORIENTATION_NORMAL)
    val m = android.graphics.Matrix()
    when (o) {
        android.media.ExifInterface.ORIENTATION_ROTATE_90 -> m.postRotate(90f)
        android.media.ExifInterface.ORIENTATION_ROTATE_180 -> m.postRotate(180f)
        android.media.ExifInterface.ORIENTATION_ROTATE_270 -> m.postRotate(270f)
        android.media.ExifInterface.ORIENTATION_FLIP_HORIZONTAL -> m.postScale(-1f, 1f)
        android.media.ExifInterface.ORIENTATION_FLIP_VERTICAL -> m.postScale(1f, -1f)
        android.media.ExifInterface.ORIENTATION_TRANSPOSE -> { m.postRotate(90f); m.postScale(-1f, 1f) }
        android.media.ExifInterface.ORIENTATION_TRANSVERSE -> { m.postRotate(270f); m.postScale(-1f, 1f) }
        else -> return src
    }
    return runCatching { Bitmap.createBitmap(src, 0, 0, src.width, src.height, m, true) }.getOrDefault(src)
}

/** PdfRenderer читает только файл: кладём PDF во временный файл приложения и удаляем при закрытии. */
actual fun openPdf(bytes: ByteArray): PdfDoc? {
    val f = try {
        File.createTempFile("view", ".pdf", AndroidCtx.app.cacheDir).apply { writeBytes(bytes) }
    } catch (e: kotlinx.coroutines.CancellationException) { throw e } catch (e: Exception) { return null }
    var fd: ParcelFileDescriptor? = null
    try {
        val pfd = ParcelFileDescriptor.open(f, ParcelFileDescriptor.MODE_READ_ONLY)
        fd = pfd
        val r = PdfRenderer(pfd)
        return object : PdfDoc {
            override val pages: Int get() = r.pageCount
            override fun render(page: Int, width: Int): ImageBitmap? = runCatching {
                synchronized(r) {
                    r.openPage(page).use { p ->
                        // PdfRenderer рисует только в ARGB_8888 (RGB_565 он отвергает), поэтому память бережём шириной (Viewer.kt).
                        val h = (width.toLong() * p.height / p.width).toInt().coerceAtLeast(1)
                        val bmp = Bitmap.createBitmap(width, h, Bitmap.Config.ARGB_8888)
                        bmp.eraseColor(Color.WHITE)
                        p.render(bmp, null, null, PdfRenderer.Page.RENDER_MODE_FOR_DISPLAY)
                        bmp.asImageBitmap()
                    }
                }
            }.getOrNull()
            override fun close() { runCatching { r.close(); pfd.close() }; f.delete() }
        }
    } catch (e: kotlinx.coroutines.CancellationException) {
        runCatching { fd?.close() }; f.delete()
        throw e
    } catch (e: Exception) {
        // Битый PDF (или не PDF вовсе): дескриптор и временный файл не должны остаться.
        runCatching { fd?.close() }; f.delete()
        return null
    }
}

actual object DiskCache {
    private val dir: File get() = File(AndroidCtx.app.cacheDir, "mail").apply { mkdirs() }
    actual fun read(name: String): String? = runCatching { File(dir, name).takeIf { it.exists() }?.readText() }.getOrNull()
    actual fun write(name: String, text: String) { runCatching { File(dir, "$name.tmp").apply { writeText(text) }.renameTo(File(dir, name)) } }
    actual fun clear() { runCatching { dir.deleteRecursively() } }
}

actual fun shrinkToJpeg(bytes: ByteArray, maxSide: Int): ByteArray? = runCatching {
    val bounds = BitmapFactory.Options().apply { inJustDecodeBounds = true }
    BitmapFactory.decodeByteArray(bytes, 0, bytes.size, bounds)
    var sample = 1
    while (maxOf(bounds.outWidth, bounds.outHeight) / (sample * 2) >= maxSide) sample *= 2
    // Поворот по EXIF — до сжатия: в JPEG без тега фото контакта иначе легло бы боком.
    val src = BitmapFactory.decodeByteArray(bytes, 0, bytes.size, BitmapFactory.Options().apply { inSampleSize = sample })?.let { upright(it, bytes) } ?: return null
    val k = maxSide.toFloat() / maxOf(src.width, src.height)
    val bmp = if (k < 1f) Bitmap.createScaledBitmap(src, (src.width * k).toInt().coerceAtLeast(1), (src.height * k).toInt().coerceAtLeast(1), true) else src
    java.io.ByteArrayOutputStream().also { bmp.compress(Bitmap.CompressFormat.JPEG, 85, it) }.toByteArray()
}.getOrNull()

actual fun sha256Of(file: SavedFile): String? = runCatching {
    val md = java.security.MessageDigest.getInstance("SHA-256")
    java.io.File(file.location).inputStream().use { input ->
        val buf = ByteArray(64 * 1024)
        while (true) { val n = input.read(buf); if (n <= 0) break; md.update(buf, 0, n) }
    }
    md.digest().joinToString("") { "%02x".format(it) }
}.getOrNull()
