package su.innotec.mail.ui

import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.gestures.detectTapGestures
import androidx.compose.foundation.gestures.awaitEachGesture
import androidx.compose.foundation.gestures.awaitFirstDown
import androidx.compose.foundation.gestures.calculatePan
import androidx.compose.foundation.gestures.calculateZoom
import androidx.compose.ui.input.pointer.PointerEventPass
import androidx.compose.ui.input.pointer.positionChanged
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.BoxWithConstraints
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.pager.HorizontalPager
import androidx.compose.foundation.pager.rememberPagerState
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.produceState
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clipToBounds
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.ImageBitmap
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import io.ktor.utils.io.readAvailable
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import kotlinx.io.readByteArray
import su.innotec.mail.Nav
import su.innotec.mail.Screen
import su.innotec.mail.api.ApiException
import su.innotec.mail.data.Session
import su.innotec.mail.platform.BackHandler
import su.innotec.mail.platform.PdfDoc
import su.innotec.mail.platform.decodeImage
import su.innotec.mail.platform.openPdf
import su.innotec.mail.ui.mail.IconBtn

/**
 * Что можно смотреть прямо в приложении: [path] — сам файл (для «Сохранить», «Поделиться»),
 * [preview] — PDF, который сервер собирает из документа Office (как предпросмотр в веб-почте).
 */
data class ViewItem(val name: String, val path: String, val kind: String, val preview: String? = null)

object Viewable {
    private val IMAGES = setOf("jpg", "jpeg", "png", "gif", "webp", "bmp", "heic", "heif")
    private val OFFICE = setOf("doc", "docx", "xls", "xlsx", "ppt", "pptx", "odt", "ods", "odp", "rtf")

    /** image | pdf | office | null — смотреть другой программой. */
    fun kind(name: String, type: String? = null): String? {
        val ext = name.substringAfterLast('.', "").lowercase()
        return when {
            ext in IMAGES || (type ?: "").startsWith("image/") && !type!!.contains("svg") -> "image"
            ext == "pdf" || type == "application/pdf" -> "pdf"
            ext in OFFICE -> "office"
            else -> null
        }
    }
}

/** Просмотр вложений и файлов облака: листание между ними, увеличение пальцами и двойным касанием. */
class ViewerScreen(private val items: List<ViewItem>, private val start: Int) : Screen() {
    override val fullScreen: Boolean get() = true

    @Composable
    override fun Content() {
        val pager = rememberPagerState(initialPage = start.coerceIn(0, (items.size - 1).coerceAtLeast(0))) { items.size }
        var zoomed by remember { mutableStateOf(false) }
        BackHandler(true) { Nav.pop() }
        val cur = items.getOrNull(pager.currentPage) ?: return
        Column(Modifier.fillMaxSize().background(Color(0xFF111418))) {
            Row(Modifier.fillMaxWidth().statusBarsPadding().height(56.dp).padding(horizontal = 4.dp), verticalAlignment = Alignment.CenterVertically) {
                IconBtn("back", "Назад", tint = Color.White) { Nav.pop() }
                Column(Modifier.weight(1f).padding(horizontal = 4.dp)) {
                    Text(cur.name, color = Color.White, style = MaterialTheme.typography.titleSmall, maxLines = 1, overflow = TextOverflow.Ellipsis)
                    if (items.size > 1) Text("${pager.currentPage + 1} из ${items.size}", color = Color.White.copy(alpha = .6f), style = MaterialTheme.typography.bodySmall)
                }
                IconBtn("download", "Сохранить", tint = Color.White) { Transfers.fetch(cur.path, cur.name, Transfers.Then.SAVE) }
                IconBtn("share", "Поделиться", tint = Color.White) { Transfers.fetch(cur.path, cur.name, Transfers.Then.SHARE) }
                IconBtn("external", "Открыть в другой программе", tint = Color.White) { Transfers.fetch(cur.path, cur.name, Transfers.Then.OPEN) }
            }
            // Увеличенную картинку двигают пальцем — листание между файлами в это время выключено.
            HorizontalPager(pager, Modifier.weight(1f).fillMaxWidth(), userScrollEnabled = !zoomed, key = { items[it].path }) { i ->
                ViewPage(items[i], onZoom = { zoomed = it })
            }
        }
    }
}

private suspend fun bytesOf(path: String, progress: (Long, Long?) -> Unit = { _, _ -> }): ByteArray {
    val api = Session.api ?: throw IllegalStateException("нет входа")
    return api.download(path) { len, _, _, ch ->
        val out = kotlinx.io.Buffer()
        val buf = ByteArray(64 * 1024)
        var got = 0L
        while (true) {
            val r = ch.readAvailable(buf, 0, buf.size)
            if (r == -1) break
            if (r > 0) { out.write(buf, 0, r); got += r; progress(got, len) }
            if (r == 0 && ch.isClosedForRead) break
        }
        out.readByteArray()
    }
}

private sealed class Loaded {
    data object Wait : Loaded()
    data class Img(val bmp: ImageBitmap) : Loaded()
    data class Pdf(val doc: PdfDoc) : Loaded()
    data class Fail(val text: String) : Loaded()
}

@Composable
private fun ViewPage(item: ViewItem, onZoom: (Boolean) -> Unit) {
    // Сколько скачано: крупный файл грузится заметное время, и одна крутилка выглядела как зависание.
    var got by remember(item.path) { mutableStateOf(0L to (null as Long?)) }
    val state by produceState<Loaded>(Loaded.Wait, item.path) {
        // Открытый PDF, который не успели отдать на экран (ушли со страницы, пока шла отрисовка), закрываем сами:
        // иначе временный файл и дескриптор PdfRenderer остались бы висеть.
        var opened: PdfDoc? = null
        value = try {
            withContext(Dispatchers.Default) {
                when (item.kind) {
                    "image" -> decodeImage(bytesOf(item.path) { a, b -> got = a to b })?.let { Loaded.Img(it) } ?: Loaded.Fail("Картинку не удалось показать")
                    else -> {
                        val b = bytesOf(if (item.kind == "office") item.preview ?: item.path else item.path) { a, t -> got = a to t }
                        openPdf(b)?.also { opened = it }?.let { Loaded.Pdf(it) } ?: Loaded.Fail("Здесь документ не показать")
                    }
                }
            }
        } catch (e: kotlinx.coroutines.CancellationException) {
            opened?.close()
            throw e
        } catch (e: ApiException) {
            Loaded.Fail(if (item.kind == "office") "Сервер не смог подготовить просмотр: ${e.message}" else e.message ?: "Ошибка")
        } catch (e: Throwable) {
            opened?.close()
            Loaded.Fail("Не удалось открыть: ${e.message ?: e::class.simpleName}")
        }
    }
    val doc = (state as? Loaded.Pdf)?.doc
    DisposableEffect(doc) { onDispose { doc?.close() } }
    when (val s = state) {
        Loaded.Wait -> Column(Modifier.fillMaxSize(), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = androidx.compose.foundation.layout.Arrangement.Center) {
            val (a, t) = got
            if (t != null && t > 0) CircularProgressIndicator(progress = { (a.toFloat() / t).coerceIn(0f, 1f) }, color = Color.White, trackColor = Color.White.copy(alpha = .2f))
            else CircularProgressIndicator(color = Color.White)
            Spacer(Modifier.height(12.dp))
            Text(when {
                a == 0L && item.kind == "office" -> "Сервер готовит просмотр документа…"
                a == 0L -> "Загрузка…"
                t != null && t > 0 -> "${Fmt.size(a)} из ${Fmt.size(t)}"
                else -> Fmt.size(a)
            }, color = Color.White.copy(alpha = .8f))
        }
        is Loaded.Fail -> Column(Modifier.fillMaxSize().padding(24.dp), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = androidx.compose.foundation.layout.Arrangement.Center) {
            Text(s.text, color = Color.White.copy(alpha = .8f))
            Spacer(Modifier.height(16.dp))
            Button(onClick = { Transfers.fetch(item.path, item.name, Transfers.Then.OPEN) }) { Text("Открыть в другой программе") }
        }
        is Loaded.Img -> Zoomable(onZoom) { Image(s.bmp, item.name, Modifier.fillMaxSize().testTag("viewer-image"), contentScale = ContentScale.Fit) }
        is Loaded.Pdf -> Zoomable(onZoom) { PdfPages(s.doc) }
    }
}

/** Увеличение двумя пальцами (до ×5) и двойным касанием (×2.5 ↔ ×1), сдвиг увеличенного пальцем. */
@Composable
private fun Zoomable(onZoom: (Boolean) -> Unit, content: @Composable () -> Unit) {
    var scale by remember { mutableStateOf(1f) }
    var offset by remember { mutableStateOf(Offset.Zero) }
    LaunchedEffect(scale > 1.01f) { onZoom(scale > 1.01f) }
    // clipToBounds: увеличенное не залезает на верхнюю панель с кнопками.
    BoxWithConstraints(Modifier.fillMaxSize().clipToBounds()) {
        val w = constraints.maxWidth.toFloat(); val h = constraints.maxHeight.toFloat()
        fun clamp(o: Offset, s: Float): Offset {
            val mx = (w * (s - 1)) / 2; val my = (h * (s - 1)) / 2
            return Offset(o.x.coerceIn(-mx, mx), o.y.coerceIn(-my, my))
        }
        Box(
            Modifier.fillMaxSize()
                .pointerInput(Unit) {
                    detectTapGestures(onDoubleTap = { p ->
                        if (scale > 1.01f) { scale = 1f; offset = Offset.Zero }
                        else { scale = 2.5f; offset = clamp(Offset((w / 2 - p.x) * 1.5f, (h / 2 - p.y) * 1.5f), 2.5f) }
                    })
                }
                .pointerInput(Unit) {
                    // Свой разбор жеста, а не detectTransformGestures: тот забирает и одиночный палец, и тогда не листались
                    // бы ни страницы PDF, ни файлы. Здесь: два пальца — масштаб, один палец — сдвиг только увеличенного.
                    // Проход Initial — раньше вложенного списка, иначе он принял бы щипок за прокрутку.
                    awaitEachGesture {
                        awaitFirstDown(requireUnconsumed = false, pass = PointerEventPass.Initial)
                        do {
                            val event = awaitPointerEvent(PointerEventPass.Initial)
                            val fingers = event.changes.count { it.pressed }
                            if (fingers >= 2 || scale > 1.01f) {
                                val s = (scale * event.calculateZoom()).coerceIn(1f, 5f)
                                scale = s
                                offset = if (s <= 1.01f) Offset.Zero else clamp(offset + event.calculatePan(), s)
                                event.changes.forEach { if (it.positionChanged()) it.consume() }
                            }
                        } while (event.changes.any { it.pressed })
                    }
                }
                .graphicsLayer { scaleX = scale; scaleY = scale; translationX = offset.x; translationY = offset.y },
        ) { content() }
    }
}

@Composable
private fun PdfPages(doc: PdfDoc) {
    BoxWithConstraints(Modifier.fillMaxSize()) {
        // Рисуем с запасом ×2 к ширине экрана: при увеличении текст остаётся чётким. Потолок 1600 px:
        // страница A4 в ARGB — уже ~14 МБ, при 2400 px было ~33 МБ на страницу и нехватка памяти на телефонах.
        val px = (constraints.maxWidth * 2).coerceAtMost(1600)
        LazyColumn(Modifier.fillMaxSize().testTag("viewer-pdf")) {
            items(doc.pages) { i ->
                val bmp by produceState<ImageBitmap?>(null, i, px) { value = withContext(Dispatchers.Default) { doc.render(i, px) } }
                val b = bmp
                if (b == null) Box(Modifier.fillMaxWidth().aspectRatio(0.707f).padding(8.dp).background(Color.White.copy(alpha = .08f)))
                else Image(b, "Страница ${i + 1}", Modifier.fillMaxWidth().padding(horizontal = 8.dp, vertical = 6.dp).aspectRatio(b.width.toFloat() / b.height), contentScale = ContentScale.FillWidth)
            }
            item { Text("${doc.pages} ${Fmt.plural(doc.pages, "страница", "страницы", "страниц")}", Modifier.fillMaxWidth().padding(16.dp), color = Color.White.copy(alpha = .5f)) }
        }
    }
}
