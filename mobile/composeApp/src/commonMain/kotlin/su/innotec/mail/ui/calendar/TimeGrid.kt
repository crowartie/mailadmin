package su.innotec.mail.ui.calendar

import su.innotec.mail.ui.launchSafe
import su.innotec.mail.ui.Toasts
import su.innotec.mail.data.Session
import su.innotec.mail.api.EventInput
import kotlin.math.roundToInt
import androidx.compose.ui.zIndex
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.geometry.Offset
import androidx.compose.foundation.gestures.detectDragGesturesAfterLongPress
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.gestures.Orientation
import androidx.compose.foundation.gestures.detectTapGestures
import androidx.compose.foundation.gestures.draggable
import androidx.compose.foundation.gestures.rememberDraggableState
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.BoxWithConstraints
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextDecoration
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import kotlinx.coroutines.delay
import kotlinx.datetime.DatePeriod
import kotlinx.datetime.LocalDate
import kotlinx.datetime.LocalDateTime
import kotlinx.datetime.LocalTime
import kotlinx.datetime.atTime
import kotlinx.datetime.plus
import kotlinx.datetime.toLocalDateTime
import kotlin.time.Clock
import su.innotec.mail.Nav
import su.innotec.mail.api.CalEvent
import su.innotec.mail.ui.Fmt
import su.innotec.mail.ui.P
import su.innotec.mail.ui.hexColor

/** Высота часа в сетке «День» и «Неделя». */
private val HOUR: Dp = 56.dp

/** Событие в пределах одного дня: минуты от начала суток и колонка при наложении. */
data class Placed(val e: CalEvent, val from: Int, val to: Int, val col: Int, val cols: Int)

/**
 * Раскладка событий дня по колонкам, как в календарях Google и Outlook: пересекающиеся встают рядом,
 * ширина делится на число колонок в группе пересечений. Короткие события считаются не короче 20 минут,
 * иначе их было бы не разглядеть и не нажать.
 */
fun layoutDay(day: LocalDate, events: List<CalEvent>): List<Placed> {
    val items = events.filter { !it.allDay }.mapNotNull { e ->
        val s = Fmt.local(e.start) ?: return@mapNotNull null
        val en = Fmt.local(e.end) ?: s
        if (s.date > day || en.date < day) return@mapNotNull null
        val from = if (s.date < day) 0 else s.hour * 60 + s.minute
        val to = if (en.date > day) 1440 else en.hour * 60 + en.minute
        if (to <= from && s.date != en.date) return@mapNotNull null   // закончилось ровно в полночь
        Triple(e, from, maxOf(to, from + 20).coerceAtMost(1440))
    }.sortedWith(compareBy({ it.second }, { -it.third }))
    val out = mutableListOf<Placed>()
    var group = mutableListOf<Triple<CalEvent, Int, Int>>()
    val cols = mutableListOf<Int>()   // конец последнего события в каждой колонке
    val colOf = mutableMapOf<Triple<CalEvent, Int, Int>, Int>()
    var groupEnd = -1
    fun flush() {
        group.forEach { t -> out += Placed(t.first, t.second, t.third, colOf.getValue(t), cols.size) }
        group = mutableListOf(); cols.clear(); colOf.clear()
    }
    items.forEach { t ->
        if (t.second >= groupEnd && group.isNotEmpty()) flush()
        val c = cols.indexOfFirst { it <= t.second }
        if (c >= 0) { cols[c] = t.third; colOf[t] = c } else { cols += t.third; colOf[t] = cols.lastIndex }
        group += t
        groupEnd = maxOf(if (group.size == 1) t.third else groupEnd, t.third)
    }
    flush()
    return out
}

/** События «весь день» и многодневные — полосой над сеткой. */
private fun topBand(day: LocalDate, events: List<CalEvent>): List<CalEvent> = events.filter { e ->
    e.allDay && CalStore.startDate(e) <= day && CalStore.endDate(e) >= day
}

/**
 * Сетка по часам на один день или неделю. Пустое место — новое событие на это время (с шагом полчаса),
 * жест влево-вправо листает на [days].size дней.
 */
@Composable
fun TimeGrid(days: List<LocalDate>, onNew: (LocalDateTime) -> Unit, onPage: (Int) -> Unit, onDay: (LocalDate) -> Unit) {
    val scroll = rememberScrollState()
    val density = LocalDensity.current
    val hourPx = with(density) { HOUR.toPx() }
    val today = Fmt.today()
    var now by remember { mutableStateOf(Clock.System.now().toLocalDateTime(CalStore.tz)) }
    LaunchedEffect(Unit) { while (true) { delay(60_000); now = Clock.System.now().toLocalDateTime(CalStore.tz) } }
    // Открываемся на рабочем времени: с 8 утра, а если сегодня позже — за час до «сейчас».
    LaunchedEffect(days.first()) {
        val h = if (today in days) (now.hour - 1).coerceIn(0, 16) else 8
        scroll.scrollTo((h * hourPx).toInt())
    }
    var drag by remember { mutableStateOf(0f) }
    val swipe = rememberDraggableState { d -> drag += d }
    val events = CalStore.visible()
    Column(
        Modifier.fillMaxSize().background(P.surface).draggable(
            swipe, Orientation.Horizontal,
            onDragStarted = { drag = 0f },
            onDragStopped = { v ->
                val limit = with(density) { 72.dp.toPx() }
                if (drag < -limit || v < -1500f) onPage(1) else if (drag > limit || v > 1500f) onPage(-1)
            },
        ),
    ) {
        // Заголовки дней (для недели) и полоса «весь день».
        val bands = days.map { topBand(it, events) }
        if (days.size > 1 || bands.any { it.isNotEmpty() }) {
            Row(Modifier.fillMaxWidth().padding(vertical = 4.dp)) {
                Spacer(Modifier.width(44.dp))
                days.forEachIndexed { i, d ->
                    Column(Modifier.weight(1f).padding(horizontal = 1.dp), horizontalAlignment = Alignment.CenterHorizontally) {
                        if (days.size > 1) Column(
                            Modifier.clip(RoundedCornerShape(8.dp)).clickable { onDay(d) }.padding(horizontal = 4.dp, vertical = 2.dp),
                            horizontalAlignment = Alignment.CenterHorizontally,
                        ) {
                            Text(Fmt.weekdaysShort[d.dayOfWeek.ordinal], fontSize = 11.sp, color = if (d.dayOfWeek.ordinal >= 5) P.no.copy(alpha = .8f) else P.muted)
                            Box(Modifier.size(26.dp).clip(CircleShape).background(if (d == today) P.accent else Color.Transparent), contentAlignment = Alignment.Center) {
                                Text(d.day.toString(), fontSize = 14.sp, color = if (d == today) P.accentOn else P.text, fontWeight = if (d == today) FontWeight.Bold else FontWeight.Normal)
                            }
                        }
                        bands[i].take(2).forEach { e ->
                            Text(e.title.ifBlank { "(без названия)" }, Modifier.fillMaxWidth().padding(top = 2.dp).clip(RoundedCornerShape(4.dp))
                                .background(hexColor(e.color).copy(alpha = .22f)).clickable { Nav.push(EventScreen(e)) }.padding(horizontal = 4.dp, vertical = 2.dp),
                                fontSize = 11.sp, maxLines = 1, overflow = TextOverflow.Ellipsis, color = P.text)
                        }
                        if (bands[i].size > 2) Text("ещё ${bands[i].size - 2}", fontSize = 10.sp, color = P.muted, modifier = Modifier.clickable { onDay(d) })
                    }
                }
            }
            Box(Modifier.fillMaxWidth().height(1.dp).background(P.border))
        }
        Row(Modifier.fillMaxWidth().weight(1f).verticalScroll(scroll)) {
            // Часы
            Column(Modifier.width(44.dp)) {
                for (h in 0 until 24) Box(Modifier.height(HOUR).fillMaxWidth()) {
                    if (h > 0) Text(h.toString().padStart(2, '0') + ":00", Modifier.align(Alignment.TopEnd).offset(y = (-7).dp).padding(end = 6.dp), fontSize = 10.sp, color = P.faint)
                }
            }
            days.forEach { d ->
                BoxWithConstraints(
                    Modifier.weight(1f).height(HOUR * 24).background(if (d == today && days.size > 1) P.accent.copy(alpha = .04f) else Color.Transparent)
                        .pointerInput(d) {
                            detectTapGestures { o ->
                                val minutes = ((o.y / hourPx) * 60).toInt().coerceIn(0, 1410) / 30 * 30
                                onNew(d.atTime(LocalTime(minutes / 60, minutes % 60)))
                            }
                        }.testTag("grid-$d"),
                ) {
                    val colW = maxWidth
                    for (h in 1 until 24) Box(Modifier.offset(y = HOUR * h).fillMaxWidth().height(1.dp).background(P.border.copy(alpha = .6f)))
                    Box(Modifier.fillMaxHeight().width(1.dp).background(P.border.copy(alpha = .6f)))
                    val colPx = constraints.maxWidth.toFloat()
                    layoutDay(d, events).forEach { p ->
                        val top = HOUR * (p.from / 60f)
                        val height = HOUR * ((p.to - p.from) / 60f)
                        val w = colW / p.cols
                        EventBlock(p.e, Modifier.offset(x = w * p.col, y = top).width(w).height(height).padding(1.dp), compact = days.size > 1,
                            hourPx = hourPx, colPx = if (days.size > 1) colPx else 0f, onMoved = { minutes, daysShift -> moveEvent(p.e, minutes, daysShift) })
                    }
                    if (d == today) {
                        val y = HOUR * ((now.hour * 60 + now.minute) / 60f)
                        Box(Modifier.offset(y = y - 1.dp).fillMaxWidth().height(2.dp).background(P.no))
                        Box(Modifier.offset(x = (-4).dp, y = y - 5.dp).size(10.dp).clip(CircleShape).background(P.no))
                    }
                }
            }
        }
    }
}

/**
 * Перенос события пальцем, как в веб-почте (useEventDrag): долгое нажатие и тянуть — время с шагом 15 минут,
 * в «Неделе» и на другой день. Повторяющиеся и чужие (только чтение) — не переносятся, об этом говорим.
 */
private fun moveEvent(e: CalEvent, minutes: Int, daysShift: Int) {
    if (e.readonly) { Toasts.show("Календарь «${e.calendarName}» только для чтения"); return }
    if (e.rrule != null || e.recurrenceId != null) { Toasts.show("У повторяющегося события время меняется в правке — откройте его"); return }
    val tz = CalStore.tz
    val shift = kotlin.time.Duration.parse("${minutes + daysShift * 1440}m")
    val s = Fmt.parse(e.start) ?: return
    val en = Fmt.parse(e.end) ?: s
    val ns = (s + shift).toString(); val ne = (en + shift).toString()
    // Новое время — сразу, иначе событие прыгнет на старое место и обратно, пока сервер отвечает.
    CalStore.events = CalStore.events.map { if (it.id == e.id && it.calendar == e.calendar) it.copy(start = ns, end = ne) else it }
    moveScope.launchSafe {
        Session.api!!.updateEvent(e.calendar, e.id, EventInput(
            calendar = e.calendar, title = e.title, start = ns, end = ne, allDay = false, location = e.location, description = e.description,
            url = e.url, status = e.status, transparent = e.transparent, attendees = e.attendees, alarm = e.alarm,
        ))
        val at = (s + shift).toLocalDateTime(tz)
        Toasts.show((if (e.attendees.isNotEmpty()) "Время изменено, участники извещены" else "Перенесено") + ": " + Fmt.dateShort(at.date) + ", " + Fmt.time(at))
        CalStore.load()
    }
}

private val moveScope = kotlinx.coroutines.CoroutineScope(kotlinx.coroutines.SupervisorJob() + kotlinx.coroutines.Dispatchers.Main)

@Composable
private fun EventBlock(e: CalEvent, modifier: Modifier, compact: Boolean, hourPx: Float, colPx: Float, onMoved: (Int, Int) -> Unit) {
    val c = hexColor(e.color)
    val declined = myStatus(e) == "DECLINED"
    var drag by remember { mutableStateOf(Offset.Zero) }
    var dragging by remember { mutableStateOf(false) }
    val haptic = androidx.compose.ui.platform.LocalHapticFeedback.current
    fun minutes() = (drag.y / hourPx * 4).roundToInt() * 15
    fun daysShift() = if (colPx > 0f) (drag.x / colPx).roundToInt() else 0
    Box(
        modifier.zIndex(if (dragging) 2f else 0f)
            .graphicsLayer { translationX = if (colPx > 0f) drag.x else 0f; translationY = drag.y; if (dragging) { shadowElevation = 12f; scaleX = 1.03f; scaleY = 1.03f } }
            .clip(RoundedCornerShape(6.dp)).background(if (dragging) c.copy(alpha = .45f) else if (e.transparent || declined) c.copy(alpha = .10f) else c.copy(alpha = .22f))
            .pointerInput(e.id, e.start) {
                detectDragGesturesAfterLongPress(
                    onDragStart = { dragging = true; haptic.performHapticFeedback(androidx.compose.ui.hapticfeedback.HapticFeedbackType.LongPress) },
                    onDrag = { ch, d -> ch.consume(); drag += d },
                    onDragEnd = { val m = minutes(); val dd = daysShift(); dragging = false; drag = Offset.Zero; if (m != 0 || dd != 0) onMoved(m, dd) },
                    onDragCancel = { dragging = false; drag = Offset.Zero },
                )
            }
            .clickable { Nav.push(EventScreen(e)) },
    ) {
        if (dragging) Fmt.parse(e.start)?.let { st ->
            val at = (st + kotlin.time.Duration.parse("${minutes() + daysShift() * 1440}m")).toLocalDateTime(CalStore.tz)
            Text((if (daysShift() != 0) Fmt.weekdaysShort[at.date.dayOfWeek.ordinal] + " " else "") + Fmt.time(at), Modifier.align(Alignment.BottomEnd).padding(3.dp)
                .clip(RoundedCornerShape(4.dp)).background(P.surface).padding(horizontal = 4.dp), fontSize = 11.sp, color = P.text, fontWeight = FontWeight.SemiBold)
        }
        Box(Modifier.width(3.dp).fillMaxHeight().background(c))
        Column(Modifier.padding(start = 6.dp, end = 3.dp, top = 2.dp)) {
            Text(e.title.ifBlank { "(без названия)" }, fontSize = if (compact) 11.sp else 13.sp, fontWeight = FontWeight.Medium, color = P.text,
                maxLines = if (compact) 3 else 2, overflow = TextOverflow.Ellipsis, lineHeight = if (compact) 13.sp else 16.sp,
                textDecoration = if (e.status == "CANCELLED" || declined) TextDecoration.LineThrough else null)
            if (!compact) Text(listOf(timeRange(e), e.location).filter { it.isNotBlank() }.joinToString(" · "), fontSize = 11.sp, color = P.muted, maxLines = 1, overflow = TextOverflow.Ellipsis)
        }
    }
}

/** Неделя с понедельника, в которой [d]. */
fun weekOf(d: LocalDate): List<LocalDate> {
    val mon = d.plus(DatePeriod(days = -d.dayOfWeek.ordinal))
    return (0 until 7).map { mon.plus(DatePeriod(days = it)) }
}

/** «21–27 сентября 2026», «29 сентября – 5 октября 2026». */
fun weekTitle(days: List<LocalDate>): String {
    val a = days.first(); val b = days.last()
    return if (a.month == b.month) "${a.day}–${b.day} ${Fmt.monthsGen[a.month.ordinal]} ${a.year}"
    else "${a.day} ${Fmt.monthsGen[a.month.ordinal]} – ${b.day} ${Fmt.monthsGen[b.month.ordinal]} ${b.year}"
}
