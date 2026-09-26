package su.innotec.mail.ui.calendar

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Checkbox
import androidx.compose.material3.DatePicker
import androidx.compose.material3.DatePickerDialog
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.ExtendedFloatingActionButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TimePicker
import androidx.compose.material3.pulltorefresh.PullToRefreshBox
import androidx.compose.material3.rememberDatePickerState
import androidx.compose.material3.rememberTimePickerState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextDecoration
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.launch
import kotlinx.datetime.DatePeriod
import kotlinx.datetime.LocalDate
import kotlinx.datetime.LocalDateTime
import kotlinx.datetime.LocalTime
import kotlinx.datetime.TimeZone
import kotlinx.datetime.atTime
import kotlinx.datetime.minus
import kotlinx.datetime.plus
import kotlinx.datetime.toInstant
import kotlinx.datetime.toLocalDateTime
import kotlin.time.Clock
import kotlin.time.Instant
import su.innotec.mail.LocalWindow
import su.innotec.mail.Nav
import su.innotec.mail.Screen
import su.innotec.mail.WindowKind
import su.innotec.mail.api.ApiException
import su.innotec.mail.api.Attendee
import su.innotec.mail.api.CalEvent
import su.innotec.mail.api.Calendar
import su.innotec.mail.api.EventInput
import su.innotec.mail.api.Message
import su.innotec.mail.api.Person
import su.innotec.mail.api.RRule
import su.innotec.mail.api.TaskInput
import su.innotec.mail.api.TaskItem
import su.innotec.mail.data.Session
import su.innotec.mail.platform.BackHandler
import su.innotec.mail.platform.Sys
import su.innotec.mail.ui.Avatar
import su.innotec.mail.ui.Chip
import su.innotec.mail.ui.ChoiceDialog
import su.innotec.mail.ui.ConfirmDialog
import su.innotec.mail.ui.Divider
import su.innotec.mail.ui.Empty
import su.innotec.mail.ui.ErrorBox
import su.innotec.mail.ui.Fmt
import su.innotec.mail.ui.Html
import su.innotec.mail.ui.Ico
import su.innotec.mail.ui.InputDialog
import su.innotec.mail.ui.Loading
import su.innotec.mail.ui.P
import su.innotec.mail.ui.SectionTitle
import su.innotec.mail.ui.Toasts
import su.innotec.mail.ui.hexColor
import su.innotec.mail.ui.launchSafe
import su.innotec.mail.ui.mail.IconBtn
import su.innotec.mail.ui.mail.RecipientsField
import su.innotec.mail.ui.mail.SubBar

// ---------- состояние ----------

object CalStore {
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.Main)
    val tz: TimeZone get() = TimeZone.currentSystemDefault()
    var calendars by mutableStateOf<List<Calendar>>(emptyList())
    val hidden = mutableStateListOf<String>()
    var month by mutableStateOf(Fmt.today().let { LocalDate(it.year, it.month, 1) })
    var day by mutableStateOf(Fmt.today())
    var events by mutableStateOf<List<CalEvent>>(emptyList())
    var tasks by mutableStateOf<List<TaskItem>>(emptyList())
    var loading by mutableStateOf(false)
    var error by mutableStateOf<String?>(null)
    var tab by mutableStateOf("month")
    private var loaded = false

    fun start() { if (!loaded) { loaded = true; loadCalendars(); load(); loadTasks() } }

    fun loadCalendars() { scope.launch { runCatching { calendars = Session.api!!.calendars() }.onFailure { if (it is ApiException) Toasts.error(it) } } }

    /** События месяца с запасом на соседние недели сетки и на повестку вперёд. */
    fun load() {
        val api = Session.api ?: return
        loading = true; error = null
        val from = month.minus(DatePeriod(days = 7))
        val to = month.plus(DatePeriod(months = 1)).plus(DatePeriod(days = 45))
        scope.launch {
            try { events = api.events(from.toString(), to.toString()) }
            catch (e: ApiException) { if (e.isAuth) Toasts.error(e) else error = e.message }
            finally { loading = false }
        }
    }

    fun loadTasks() { scope.launch { runCatching { tasks = Session.api!!.tasks() } } }

    fun visible(): List<CalEvent> = events.filter { it.calendar !in hidden }

    fun onDay(d: LocalDate): List<CalEvent> = visible().filter { e ->
        val s = startDate(e); val en = endDate(e)
        d >= s && d <= en
    }.sortedWith(compareBy({ !it.allDay }, { it.start }))

    fun startDate(e: CalEvent): LocalDate = Fmt.local(e.start)?.date ?: LocalDate(1970, 1, 1)
    /** Последний день события (у целодневных конец исключающий). */
    fun endDate(e: CalEvent): LocalDate {
        val end = Fmt.local(e.end) ?: return startDate(e)
        return if (e.allDay || (end.hour == 0 && end.minute == 0 && end.date > startDate(e))) end.date.minus(DatePeriod(days = 1)) else end.date
    }

    fun reset() { calendars = emptyList(); events = emptyList(); tasks = emptyList(); hidden.clear(); loaded = false }
}

fun timeRange(e: CalEvent): String {
    if (e.allDay) return "весь день"
    val s = Fmt.local(e.start) ?: return ""
    val en = Fmt.local(e.end) ?: return Fmt.time(s)
    return if (s.date == en.date) "${Fmt.time(s)}–${Fmt.time(en)}" else "${Fmt.dateShort(s.date)}, ${Fmt.time(s)} – ${Fmt.dateShort(en.date)}, ${Fmt.time(en)}"
}

// ---------- главный экран ----------

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun CalendarHome() {
    val s = CalStore
    LaunchedEffect(Unit) { s.start() }
    var menu by remember { mutableStateOf(false) }
    val wide = LocalWindow.current != WindowKind.PHONE
    Box(Modifier.fillMaxSize().background(P.bg)) {
        Column(Modifier.fillMaxSize()) {
            Column(Modifier.background(P.surface).statusBarsPadding()) {
                Row(Modifier.fillMaxWidth().height(56.dp).padding(start = 4.dp, end = 4.dp), verticalAlignment = Alignment.CenterVertically) {
                    if (s.tab != "tasks") {
                        IconBtn("left", "Предыдущий месяц") { s.month = s.month.minus(DatePeriod(months = 1)); s.load() }
                        Text("${Fmt.monthsNom[s.month.month.ordinal]} ${s.month.year}", Modifier.weight(1f), style = MaterialTheme.typography.titleMedium, textAlign = TextAlign.Center)
                        IconBtn("right", "Следующий месяц") { s.month = s.month.plus(DatePeriod(months = 1)); s.load() }
                        IconBtn("today", "Сегодня") { val t = Fmt.today(); s.day = t; s.month = LocalDate(t.year, t.month, 1); s.load() }
                    } else Text("Задачи", Modifier.weight(1f).padding(start = 12.dp), style = MaterialTheme.typography.titleMedium)
                    Box {
                        IconBtn("dots", "Ещё") { menu = true }
                        DropdownMenu(menu, { menu = false }) {
                            DropdownMenuItem({ Text("Календари…") }, { menu = false; Nav.push(CalendarsScreen()) }, leadingIcon = { Ico("cal") })
                            DropdownMenuItem({ Text("Обновить") }, { menu = false; s.loadCalendars(); s.load(); s.loadTasks() }, leadingIcon = { Ico("refresh") })
                        }
                    }
                }
                Row(Modifier.fillMaxWidth().padding(horizontal = 12.dp, vertical = 6.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    Chip("Месяц", s.tab == "month", { s.tab = "month" })
                    Chip("Повестка", s.tab == "agenda", { s.tab = "agenda" })
                    Chip("Задачи" + if (s.tasks.count { !it.done } > 0) " · ${s.tasks.count { !it.done }}" else "", s.tab == "tasks", { s.tab = "tasks"; s.loadTasks() })
                }
                Divider()
            }
            PullToRefreshBox(isRefreshing = s.loading && s.events.isNotEmpty(), onRefresh = { s.load(); s.loadTasks() }, modifier = Modifier.weight(1f)) {
                when {
                    s.tab == "tasks" -> TasksView()
                    s.error != null && s.events.isEmpty() -> ErrorBox(s.error!!, { s.load() })
                    s.tab == "agenda" -> Agenda(s.day)
                    wide -> Row(Modifier.fillMaxSize()) {
                        Box(Modifier.weight(1.3f)) { MonthGrid(big = true) }
                        Box(Modifier.width(1.dp).fillMaxHeight().background(P.border))
                        Box(Modifier.weight(1f)) { DayList(s.day) }
                    }
                    else -> Column(Modifier.fillMaxSize()) {
                        MonthGrid(big = false)
                        Divider()
                        Box(Modifier.weight(1f)) { DayList(s.day) }
                    }
                }
            }
        }
        ExtendedFloatingActionButton(
            onClick = { if (s.tab == "tasks") Nav.push(TaskEditScreen(null)) else Nav.push(EventEditScreen(null, day = s.day)) },
            containerColor = P.accent, contentColor = P.accentOn,
            modifier = Modifier.align(Alignment.BottomEnd).padding(16.dp).testTag("new-event"),
            icon = { Ico("plus") }, text = { Text(if (s.tab == "tasks") "Задача" else "Событие") },
        )
    }
}

@Composable
private fun MonthGrid(big: Boolean) {
    val s = CalStore
    val first = s.month
    val offset = first.dayOfWeek.ordinal
    val start = first.minus(DatePeriod(days = offset))
    val today = Fmt.today()
    Column(Modifier.fillMaxWidth().background(P.surface).padding(horizontal = 6.dp, vertical = 4.dp)) {
        Row(Modifier.fillMaxWidth()) {
            Fmt.weekdaysShort.forEach { Text(it, Modifier.weight(1f), textAlign = TextAlign.Center, style = MaterialTheme.typography.labelSmall, color = P.faint) }
        }
        for (w in 0 until 6) {
            Row(Modifier.fillMaxWidth()) {
                for (d in 0 until 7) {
                    val date = start.plus(DatePeriod(days = w * 7 + d))
                    val inMonth = date.month == first.month
                    val evs = s.onDay(date)
                    val sel = date == s.day
                    Column(
                        Modifier.weight(1f).let { if (big) it.height(92.dp) else it.aspectRatio(1.1f) }.padding(1.dp).clip(RoundedCornerShape(8.dp))
                            .background(if (sel) P.accentSoft else Color.Transparent).clickable { s.day = date; if (big.not() && s.tab == "agenda") s.tab = "month" }
                            .padding(top = 3.dp),
                        horizontalAlignment = Alignment.CenterHorizontally,
                    ) {
                        Box(Modifier.size(24.dp).clip(CircleShape).background(if (date == today) P.accent else Color.Transparent), contentAlignment = Alignment.Center) {
                            Text(date.day.toString(), fontSize = 13.sp, color = when { date == today -> P.accentOn; !inMonth -> P.border2; d >= 5 -> P.no.copy(alpha = .8f); else -> P.text },
                                fontWeight = if (date == today) FontWeight.Bold else FontWeight.Normal)
                        }
                        if (big) {
                            evs.take(3).forEach { e ->
                                Text(e.title, Modifier.fillMaxWidth().padding(horizontal = 2.dp, vertical = 1.dp).clip(RoundedCornerShape(3.dp)).background(hexColor(e.color).copy(alpha = .18f)).padding(horizontal = 3.dp),
                                    fontSize = 10.sp, maxLines = 1, overflow = TextOverflow.Ellipsis, color = P.text)
                            }
                            if (evs.size > 3) Text("ещё ${evs.size - 3}", fontSize = 10.sp, color = P.muted)
                        } else if (evs.isNotEmpty()) {
                            Row(Modifier.padding(top = 2.dp), horizontalArrangement = Arrangement.spacedBy(2.dp)) {
                                evs.take(3).forEach { e -> Box(Modifier.size(5.dp).clip(CircleShape).background(hexColor(e.color))) }
                            }
                        }
                    }
                }
            }
        }
    }
}

@Composable
private fun DayList(day: LocalDate) {
    val evs = CalStore.onDay(day)
    val tasks = CalStore.tasks.filter { !it.done && Fmt.local(it.due)?.date == day }
    Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState())) {
        Text(Fmt.dayTitle(day), Modifier.padding(start = 16.dp, top = 12.dp, bottom = 6.dp), style = MaterialTheme.typography.titleSmall)
        if (evs.isEmpty() && tasks.isEmpty()) Text("Ничего не запланировано", Modifier.padding(horizontal = 16.dp, vertical = 8.dp), color = P.muted)
        evs.forEach { EventRow(it) }
        tasks.forEach { TaskRow(it) }
        Spacer(Modifier.height(96.dp))
    }
}

@Composable
private fun Agenda(from: LocalDate) {
    val s = CalStore
    val days = (0 until 45).map { from.plus(DatePeriod(days = it)) }.map { it to s.onDay(it) }.filter { it.second.isNotEmpty() }
    if (days.isEmpty()) { Empty("cal", "Событий нет", "В ближайшие полтора месяца ничего не запланировано"); return }
    LazyColumn(Modifier.fillMaxSize()) {
        days.forEach { (d, evs) ->
            item(key = d.toString()) { Text(Fmt.dayTitle(d), Modifier.fillMaxWidth().background(P.bg).padding(start = 16.dp, top = 14.dp, bottom = 4.dp), style = MaterialTheme.typography.titleSmall, color = if (d == Fmt.today()) P.accentInk else P.text) }
            items(evs.size, key = { i -> d.toString() + evs[i].calendar + evs[i].id + evs[i].start }) { i -> EventRow(evs[i]) }
        }
        item { Spacer(Modifier.height(96.dp)) }
    }
}

@Composable
private fun EventRow(e: CalEvent) {
    Row(
        Modifier.fillMaxWidth().padding(horizontal = 12.dp, vertical = 3.dp).clip(RoundedCornerShape(10.dp)).background(P.surface)
            .clickable { Nav.push(EventScreen(e)) }.padding(10.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(Modifier.width(4.dp).height(38.dp).clip(RoundedCornerShape(2.dp)).background(hexColor(e.color)))
        Spacer(Modifier.width(10.dp))
        Column(Modifier.weight(1f)) {
            Text(e.title, style = MaterialTheme.typography.bodyLarge, maxLines = 1, overflow = TextOverflow.Ellipsis,
                textDecoration = if (e.status == "CANCELLED") TextDecoration.LineThrough else null)
            Text(listOf(timeRange(e), e.location).filter { it.isNotBlank() }.joinToString(" · "), style = MaterialTheme.typography.bodySmall, color = P.muted, maxLines = 1, overflow = TextOverflow.Ellipsis)
        }
        if (e.rrule != null) Ico("repeat", size = 15.dp, tint = P.faint)
        val me = myStatus(e)
        if (me == "NEEDS-ACTION") Box(Modifier.padding(start = 6.dp).size(8.dp).clip(CircleShape).background(P.warn))
    }
}

fun myStatus(e: CalEvent): String? {
    val me = Session.account?.user?.lowercase() ?: return null
    if (e.organizer?.mail?.lowercase() == me) return null
    return e.attendees.firstOrNull { it.mail.lowercase() == me }?.status
}

// ---------- событие ----------

class EventScreen(private val start: CalEvent) : Screen() {
    @Composable
    override fun Content() {
        var e by remember { mutableStateOf(start) }
        var confirm by remember { mutableStateOf(false) }
        val scope = rememberCoroutineScope()
        LaunchedEffect(Unit) { runCatching { Session.api!!.event(start.calendar, start.id) }.onSuccess { full -> e = full.copy(start = if (start.recurrenceId != null) start.start else full.start, end = if (start.recurrenceId != null) start.end else full.end) } }
        Column(Modifier.fillMaxSize().background(P.surface)) {
            SubBar("Событие") {
                if (!e.readonly) {
                    IconBtn("edit", "Изменить") { Nav.push(EventEditScreen(e)) }
                    IconBtn("trash", "Удалить") { confirm = true }
                }
            }
            Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(16.dp)) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Box(Modifier.size(14.dp).clip(RoundedCornerShape(4.dp)).background(hexColor(e.color)))
                    Spacer(Modifier.width(10.dp))
                    Text(e.title, style = MaterialTheme.typography.titleLarge)
                }
                Spacer(Modifier.height(10.dp))
                Line("clock", Fmt.local(e.start)?.let { Fmt.dayTitle(it.date) + ", " + timeRange(e) } ?: timeRange(e))
                e.rrule?.let { Line("repeat", repeatText(it)) }
                if (e.location.isNotBlank()) Line("map", e.location) { Sys.openUrl("geo:0,0?q=" + su.innotec.mail.api.enc(e.location)) }
                if (e.url.isNotBlank()) Line("link", e.url) { Sys.openUrl(e.url) }
                Line("cal", e.calendarName)
                e.alarm?.let { Line("bell", "Напомнить за " + alarmText(it)) }
                if (e.transparent) Line("eye", "Время не занято")
                if (e.description.isNotBlank()) { Spacer(Modifier.height(8.dp)); Text(e.description, style = MaterialTheme.typography.bodyLarge) }
                if (e.organizer != null || e.attendees.isNotEmpty()) {
                    SectionTitle("Участники", Modifier.padding(0.dp))
                    e.organizer?.let { o -> PersonLine(o.name, o.mail, "организатор") }
                    e.attendees.forEach { a -> PersonLine(a.name, a.mail, statusText(a.status)) }
                }
                val me = myStatus(e)
                if (me != null) {
                    SectionTitle("Ваш ответ", Modifier.padding(0.dp))
                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        listOf("ACCEPTED" to "Пойду", "TENTATIVE" to "Возможно", "DECLINED" to "Не пойду").forEach { (st, t) ->
                            Chip(t, me == st, {
                                scope.launchSafe {
                                    Session.api!!.respond(e.calendar, e.id, st)
                                    val mine = Session.account!!.user.lowercase()
                                    e = e.copy(attendees = e.attendees.map { if (it.mail.lowercase() == mine) it.copy(status = st) else it })
                                    Toasts.show("Ответ отправлен организатору"); CalStore.load()
                                }
                            })
                        }
                    }
                }
            }
        }
        if (confirm) {
            if (e.rrule != null || e.recurrenceId != null) AlertDialog(
                onDismissRequest = { confirm = false },
                title = { Text("Удалить повторяющееся событие") },
                confirmButton = {
                    TextButton(onClick = { confirm = false; scope.launchSafe { Session.api!!.deleteEvent(e.calendar, e.id); CalStore.load(); Nav.pop() } }) { Text("Все повторы", color = P.no) }
                },
                dismissButton = {
                    TextButton(onClick = { confirm = false; scope.launchSafe { Session.api!!.deleteEvent(e.calendar, e.id, e.recurrenceId ?: e.start); CalStore.load(); Nav.pop() } }) { Text("Только это") }
                },
            ) else ConfirmDialog("Удалить «${e.title}»?", if (e.attendees.isNotEmpty()) "Участники получат отмену." else null, "Удалить", danger = true, onDismiss = { confirm = false }) {
                scope.launchSafe { Session.api!!.deleteEvent(e.calendar, e.id); CalStore.load(); Nav.pop() }
            }
        }
    }
}

private fun statusText(s: String) = when (s) { "ACCEPTED" -> "придёт"; "DECLINED" -> "не придёт"; "TENTATIVE" -> "возможно"; else -> "не ответил(а)" }
fun alarmText(m: Int) = when { m == 0 -> "в момент начала"; m < 60 -> "$m мин"; m < 1440 -> "${m / 60} ч"; else -> "${m / 1440} ${Fmt.plural(m / 1440, "день", "дня", "дней")}" }
private fun repeatText(r: RRule): String {
    val base = when (r.freq) { "DAILY" -> "Каждый день"; "WEEKLY" -> "Каждую неделю"; "MONTHLY" -> "Каждый месяц"; "YEARLY" -> "Каждый год"; else -> "Повторяется" }
    val every = if (r.interval > 1) " (раз в ${r.interval})" else ""
    val until = r.until?.let { ", до $it" } ?: r.count?.let { ", $it раз" } ?: ""
    return base + every + until
}

@Composable
private fun Line(icon: String, text: String, onClick: (() -> Unit)? = null) {
    Row(Modifier.fillMaxWidth().let { if (onClick != null) it.clickable(onClick = onClick) else it }.padding(vertical = 8.dp), verticalAlignment = Alignment.CenterVertically) {
        Ico(icon, tint = P.muted); Spacer(Modifier.width(14.dp)); Text(text, style = MaterialTheme.typography.bodyLarge, color = if (onClick != null) P.accentInk else P.text)
    }
}

@Composable
private fun PersonLine(name: String, mail: String, note: String) {
    Row(Modifier.fillMaxWidth().padding(vertical = 6.dp), verticalAlignment = Alignment.CenterVertically) {
        Avatar(name.ifBlank { mail }, mail, 32.dp); Spacer(Modifier.width(10.dp))
        Column(Modifier.weight(1f)) { Text(name.ifBlank { mail }, maxLines = 1, overflow = TextOverflow.Ellipsis); Text(mail, style = MaterialTheme.typography.bodySmall, color = P.muted, maxLines = 1) }
        Text(note, style = MaterialTheme.typography.bodySmall, color = P.faint)
    }
}

// ---------- правка события ----------

class EventEditScreen(private val existing: CalEvent?, private val day: LocalDate? = null, private val prefill: EventInput? = null) : Screen() {
    override val fullScreen: Boolean get() = true

    companion object {
        /** «Назначить встречу» из письма: тема, участники — все из переписки. */
        fun fromMessage(m: Message): EventEditScreen {
            val me = Session.account?.user?.lowercase()
            val people = (listOf(m.from) + m.to + m.cc).filter { it.mail.isNotBlank() && it.mail.lowercase() != me }.distinctBy { it.mail.lowercase() }
            val now = Clock.System.now().toLocalDateTime(TimeZone.currentSystemDefault())
            val start = now.date.plus(DatePeriod(days = 1)).atTime(LocalTime(10, 0))
            return EventEditScreen(null, prefill = EventInput(
                title = m.subject.removePrefix("Re: ").removePrefix("RE: "), start = start.toString(),
                end = start.date.atTime(LocalTime(11, 0)).toString(), attendees = people.map { Attendee(mail = it.mail, name = it.name) },
                description = Html.toText(m.html ?: m.text ?: "").take(1500),
            ))
        }
    }

    @OptIn(ExperimentalMaterial3Api::class, ExperimentalLayoutApi::class)
    @Composable
    override fun Content() {
        val tz = CalStore.tz
        val initStart: LocalDateTime = existing?.let { Fmt.local(it.start) } ?: prefill?.let { LocalDateTime.parse(it.start.take(16)) }
            ?: (day ?: Fmt.today()).atTime(LocalTime((Clock.System.now().toLocalDateTime(tz).hour + 1).coerceAtMost(22), 0))
        val initEnd: LocalDateTime = existing?.let { e -> Fmt.local(e.end)?.let { if (e.allDay) it.date.minus(DatePeriod(days = 1)).atTime(LocalTime(0, 0)) else it } }
            ?: prefill?.end?.let { LocalDateTime.parse(it.take(16)) } ?: initStart.date.atTime(LocalTime((initStart.hour + 1).coerceAtMost(23), initStart.minute))
        var title by remember { mutableStateOf(existing?.title ?: prefill?.title ?: "") }
        var allDay by remember { mutableStateOf(existing?.allDay ?: false) }
        var start by remember { mutableStateOf(initStart) }
        var end by remember { mutableStateOf(initEnd) }
        var location by remember { mutableStateOf(existing?.location ?: "") }
        var description by remember { mutableStateOf(existing?.description ?: prefill?.description ?: "") }
        var calendar by remember { mutableStateOf(existing?.calendar ?: CalStore.calendars.firstOrNull { it.kind == "personal" }?.uri ?: CalStore.calendars.firstOrNull { !it.readonly }?.uri) }
        var repeat by remember { mutableStateOf(existing?.rrule?.freq ?: "") }
        var alarm by remember { mutableStateOf(existing?.alarm ?: 15) }
        var busy by remember { mutableStateOf(!(existing?.transparent ?: false)) }
        val people = remember { mutableStateListOf<Person>().apply { addAll((existing?.attendees ?: prefill?.attendees ?: emptyList()).map { Person(it.name, it.mail) }) } }
        var pick by remember { mutableStateOf<String?>(null) }
        var saving by remember { mutableStateOf(false) }
        val scope = rememberCoroutineScope()
        BackHandler(true) { Nav.pop() }

        fun save() {
            if (saving) return
            saving = true
            scope.launch {
                try {
                    val fmt = { d: LocalDateTime -> if (allDay) d.date.toString() else d.toInstant(tz).toString() }
                    val oldAtt = existing?.attendees?.associateBy { it.mail.lowercase() } ?: emptyMap()
                    val input = EventInput(
                        calendar = calendar, title = title, start = fmt(start), end = fmt(if (end < start) start else end), allDay = allDay,
                        location = location, description = description, url = existing?.url ?: "", status = existing?.status,
                        transparent = !busy, rrule = if (repeat.isBlank()) null else (existing?.rrule?.takeIf { it.freq == repeat } ?: RRule(freq = repeat)),
                        attendees = people.map { p -> oldAtt[p.mail.lowercase()] ?: Attendee(mail = p.mail, name = p.name) },
                        alarm = alarm.takeIf { it >= 0 },
                    )
                    val api = Session.api!!
                    if (existing == null) api.createEvent(input) else api.updateEvent(existing.calendar, existing.id, input)
                    CalStore.load()
                    Nav.pop()
                    if (existing != null) Nav.pop()
                    Toasts.show(if (people.isNotEmpty()) "Сохранено, участникам ушли приглашения" else "Сохранено")
                } catch (e: ApiException) { Toasts.error(e) } finally { saving = false }
            }
        }

        Column(Modifier.fillMaxSize().background(P.surface)) {
            Row(Modifier.fillMaxWidth().statusBarsPadding().height(56.dp).padding(horizontal = 4.dp), verticalAlignment = Alignment.CenterVertically) {
                IconBtn("x", "Закрыть") { Nav.pop() }
                Text(if (existing == null) "Новое событие" else "Изменить событие", Modifier.weight(1f), style = MaterialTheme.typography.titleMedium)
                TextButton(onClick = { save() }, enabled = !saving && calendar != null, modifier = Modifier.testTag("event-save")) { Text("Сохранить", fontWeight = FontWeight.SemiBold) }
            }
            Divider()
            Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                OutlinedTextField(title, { title = it }, Modifier.fillMaxWidth().testTag("event-title"), label = { Text("Название") }, singleLine = true)
                Row(verticalAlignment = Alignment.CenterVertically) { Text("Весь день", Modifier.weight(1f)); Switch(allDay, { allDay = it }) }
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    Field("Начало", Fmt.dateShort(start.date) + if (allDay) "" else ", " + Fmt.time(start), Modifier.weight(1f)) { pick = "start" }
                    Field("Конец", Fmt.dateShort(end.date) + if (allDay) "" else ", " + Fmt.time(end), Modifier.weight(1f)) { pick = "end" }
                }
                val writable = CalStore.calendars.filter { !it.readonly }
                Field("Календарь", writable.firstOrNull { it.uri == calendar }?.name ?: "—") { if (existing == null) pick = "calendar" }
                Field("Повтор", when (repeat) { "DAILY" -> "Каждый день"; "WEEKLY" -> "Каждую неделю"; "MONTHLY" -> "Каждый месяц"; "YEARLY" -> "Каждый год"; else -> "Не повторять" }) { pick = "repeat" }
                Field("Напоминание", if (alarm < 0) "Без напоминания" else "За " + alarmText(alarm)) { pick = "alarm" }
                Row(verticalAlignment = Alignment.CenterVertically) { Text("Время занято", Modifier.weight(1f)); Switch(busy, { busy = it }) }
                OutlinedTextField(location, { location = it }, Modifier.fillMaxWidth(), label = { Text("Место") }, singleLine = true)
                Text("Участники", style = MaterialTheme.typography.labelLarge, color = P.muted)
                Box(Modifier.fillMaxWidth().clip(RoundedCornerShape(8.dp)).border(1.dp, P.border2, RoundedCornerShape(8.dp))) {
                    RecipientsField("Кто", people, onChange = {})
                }
                OutlinedTextField(description, { description = it }, Modifier.fillMaxWidth(), label = { Text("Описание") }, minLines = 3)
                Spacer(Modifier.height(40.dp))
            }
        }

        when (pick) {
            "start", "end" -> {
                val isStart = pick == "start"
                DateTimePick(if (isStart) start else end, withTime = !allDay, onDismiss = { pick = null }) { v ->
                    if (isStart) {
                        val dur = end.toInstant(tz) - start.toInstant(tz)
                        start = v
                        end = (v.toInstant(tz) + dur).toLocalDateTime(tz)
                    } else end = v
                }
            }
            "calendar" -> ChoiceDialog("Календарь", CalStore.calendars.filter { !it.readonly }, { it.name }, CalStore.calendars.firstOrNull { it.uri == calendar }, onDismiss = { pick = null }) { calendar = it.uri }
            "repeat" -> ChoiceDialog("Повтор", listOf("", "DAILY", "WEEKLY", "MONTHLY", "YEARLY"),
                { when (it) { "DAILY" -> "Каждый день"; "WEEKLY" -> "Каждую неделю"; "MONTHLY" -> "Каждый месяц"; "YEARLY" -> "Каждый год"; else -> "Не повторять" } }, repeat, onDismiss = { pick = null }) { repeat = it }
            "alarm" -> ChoiceDialog("Напоминание", listOf(-1, 0, 5, 15, 30, 60, 120, 1440), { if (it < 0) "Без напоминания" else "За " + alarmText(it) }, alarm, onDismiss = { pick = null }) { alarm = it }
        }
    }
}

@Composable
private fun Field(label: String, value: String, modifier: Modifier = Modifier.fillMaxWidth(), onClick: () -> Unit) {
    Column(modifier.clip(RoundedCornerShape(8.dp)).border(1.dp, P.border2, RoundedCornerShape(8.dp)).clickable(onClick = onClick).padding(horizontal = 14.dp, vertical = 8.dp)) {
        Text(label, style = MaterialTheme.typography.labelSmall, color = P.muted)
        Text(value, style = MaterialTheme.typography.bodyLarge)
    }
}

/** Дата (и время) одним шагом за другим. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun DateTimePick(initial: LocalDateTime, withTime: Boolean, onDismiss: () -> Unit, onPick: (LocalDateTime) -> Unit) {
    var date by remember { mutableStateOf<LocalDate?>(null) }
    if (date == null) {
        val st = rememberDatePickerState(initialSelectedDateMillis = initial.date.atTime(LocalTime(12, 0)).toInstant(TimeZone.UTC).toEpochMilliseconds())
        DatePickerDialog(
            onDismissRequest = onDismiss,
            confirmButton = {
                TextButton(onClick = {
                    val d = st.selectedDateMillis?.let { Instant.fromEpochMilliseconds(it).toLocalDateTime(TimeZone.UTC).date } ?: initial.date
                    if (withTime) date = d else { onDismiss(); onPick(d.atTime(LocalTime(0, 0))) }
                }) { Text(if (withTime) "Далее" else "Готово") }
            },
            dismissButton = { TextButton(onClick = onDismiss) { Text("Отмена") } },
        ) { DatePicker(st) }
    } else {
        val tp = rememberTimePickerState(initialHour = initial.hour, initialMinute = initial.minute, is24Hour = true)
        AlertDialog(
            onDismissRequest = onDismiss,
            title = { Text(Fmt.dateShort(date!!)) },
            text = { TimePicker(tp) },
            confirmButton = { TextButton(onClick = { onDismiss(); onPick(date!!.atTime(LocalTime(tp.hour, tp.minute))) }) { Text("Готово") } },
            dismissButton = { TextButton(onClick = { date = null }) { Text("Назад") } },
        )
    }
}

// ---------- задачи ----------

@Composable
private fun TasksView() {
    val s = CalStore
    var showDone by remember { mutableStateOf(false) }
    val open = s.tasks.filter { !it.done }.sortedWith(compareBy({ it.due == null }, { it.due ?: "" }, { -it.priority }))
    val done = s.tasks.filter { it.done }
    if (s.tasks.isEmpty()) { Empty("check", "Задач нет", "Добавьте первую — кнопка внизу"); return }
    LazyColumn(Modifier.fillMaxSize()) {
        items(open.size, key = { "o" + open[it].calendar + open[it].id }) { i -> TaskRow(open[i]) }
        if (done.isNotEmpty()) {
            item { TextButton(onClick = { showDone = !showDone }, modifier = Modifier.padding(start = 8.dp)) { Text(if (showDone) "Скрыть выполненные" else "Выполненные · ${done.size}") } }
            if (showDone) items(done.size, key = { "d" + done[it].calendar + done[it].id }) { i -> TaskRow(done[i]) }
        }
        item { Spacer(Modifier.height(96.dp)) }
    }
}

@Composable
private fun TaskRow(t: TaskItem) {
    val scope = rememberCoroutineScope()
    val overdue = !t.done && Fmt.local(t.due)?.date?.let { it < Fmt.today() } == true
    Row(Modifier.fillMaxWidth().background(P.surface).clickable { Nav.push(TaskEditScreen(t)) }.padding(horizontal = 8.dp, vertical = 4.dp), verticalAlignment = Alignment.CenterVertically) {
        Checkbox(t.done, { v ->
            scope.launchSafe {
                Session.api!!.updateTask(t.calendar, t.id, TaskInput(done = v))
                CalStore.tasks = CalStore.tasks.map { if (it.id == t.id && it.calendar == t.calendar) it.copy(done = v) else it }
            }
        })
        Column(Modifier.weight(1f)) {
            Text(t.title.ifBlank { "(без названия)" }, style = MaterialTheme.typography.bodyLarge, color = if (t.done) P.faint else P.text,
                textDecoration = if (t.done) TextDecoration.LineThrough else null, maxLines = 2, overflow = TextOverflow.Ellipsis)
            val due = Fmt.local(t.due)
            if (due != null) Text((if (t.allDay) Fmt.dayTitle(due.date) else Fmt.full(t.due)), style = MaterialTheme.typography.bodySmall, color = if (overdue) P.no else P.muted)
        }
        if (t.priority in 1..4) Ico("flag", size = 16.dp, tint = P.no)
        Box(Modifier.padding(start = 6.dp, end = 6.dp).size(8.dp).clip(CircleShape).background(hexColor(t.color)))
    }
    Divider()
}

class TaskEditScreen(private val existing: TaskItem?) : Screen() {
    override val fullScreen: Boolean get() = true

    @Composable
    override fun Content() {
        var title by remember { mutableStateOf(existing?.title ?: "") }
        var description by remember { mutableStateOf(existing?.description ?: "") }
        var due by remember { mutableStateOf(existing?.due?.let { Fmt.local(it) }) }
        var important by remember { mutableStateOf((existing?.priority ?: 0) in 1..4) }
        var calendar by remember { mutableStateOf(existing?.calendar ?: CalStore.calendars.firstOrNull { it.kind == "personal" }?.uri) }
        var pick by remember { mutableStateOf<String?>(null) }
        var confirm by remember { mutableStateOf(false) }
        val scope = rememberCoroutineScope()
        val tz = CalStore.tz
        BackHandler(true) { Nav.pop() }
        Column(Modifier.fillMaxSize().background(P.surface)) {
            Row(Modifier.fillMaxWidth().statusBarsPadding().height(56.dp).padding(horizontal = 4.dp), verticalAlignment = Alignment.CenterVertically) {
                IconBtn("x", "Закрыть") { Nav.pop() }
                Text(if (existing == null) "Новая задача" else "Задача", Modifier.weight(1f), style = MaterialTheme.typography.titleMedium)
                if (existing != null) IconBtn("trash", "Удалить") { confirm = true }
                TextButton(enabled = title.isNotBlank(), onClick = {
                    scope.launchSafe {
                        val input = TaskInput(calendar = calendar, title = title, description = description, due = due?.toInstant(tz)?.toString() ?: "", priority = if (important) 1 else 0)
                        val api = Session.api!!
                        if (existing == null) api.createTask(input) else api.updateTask(existing.calendar, existing.id, input)
                        CalStore.loadTasks(); Nav.pop()
                    }
                }, modifier = Modifier.testTag("task-save")) { Text("Сохранить", fontWeight = FontWeight.SemiBold) }
            }
            Divider()
            Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                OutlinedTextField(title, { title = it }, Modifier.fillMaxWidth().testTag("task-title"), label = { Text("Что сделать") })
                Field("Срок", due?.let { Fmt.dateShort(it.date) + ", " + Fmt.time(it) } ?: "Без срока") { pick = "due" }
                if (due != null) TextButton(onClick = { due = null }) { Text("Убрать срок") }
                Row(verticalAlignment = Alignment.CenterVertically) { Text("Важная", Modifier.weight(1f)); Switch(important, { important = it }) }
                if (existing == null && CalStore.calendars.count { !it.readonly } > 1)
                    Field("Список", CalStore.calendars.firstOrNull { it.uri == calendar }?.name ?: "—") { pick = "calendar" }
                OutlinedTextField(description, { description = it }, Modifier.fillMaxWidth(), label = { Text("Заметка") }, minLines = 3)
            }
        }
        when (pick) {
            "due" -> DateTimePick(due ?: Fmt.today().atTime(LocalTime(18, 0)), withTime = true, onDismiss = { pick = null }) { due = it }
            "calendar" -> ChoiceDialog("Список", CalStore.calendars.filter { !it.readonly }, { it.name }, CalStore.calendars.firstOrNull { it.uri == calendar }, onDismiss = { pick = null }) { calendar = it.uri }
        }
        if (confirm && existing != null) ConfirmDialog("Удалить задачу?", existing.title, "Удалить", danger = true, onDismiss = { confirm = false }) {
            scope.launchSafe { Session.api!!.deleteTask(existing.calendar, existing.id); CalStore.loadTasks(); Nav.pop() }
        }
    }
}

// ---------- календари ----------

private val COLORS = listOf("#2F6FEB", "#16A05C", "#D9791F", "#8E44AD", "#C0392B", "#0E8A9E", "#6D4C41", "#AD1457")

class CalendarsScreen : Screen() {
    @Composable
    override fun Content() {
        var creating by remember { mutableStateOf(false) }
        var edit by remember { mutableStateOf<Calendar?>(null) }
        val scope = rememberCoroutineScope()
        Column(Modifier.fillMaxSize().background(P.bg)) {
            SubBar("Календари") { IconBtn("plus", "Новый календарь") { creating = true } }
            LazyColumn(Modifier.fillMaxSize()) {
                val cals = CalStore.calendars
                items(cals.size) { i ->
                    val c = cals[i]
                    val shown = c.uri !in CalStore.hidden
                    Row(Modifier.fillMaxWidth().background(P.surface).clickable { if (shown) CalStore.hidden.add(c.uri) else CalStore.hidden.remove(c.uri) }.padding(horizontal = 8.dp, vertical = 4.dp),
                        verticalAlignment = Alignment.CenterVertically) {
                        Checkbox(shown, { v -> if (v) CalStore.hidden.remove(c.uri) else CalStore.hidden.add(c.uri) })
                        Box(Modifier.size(12.dp).clip(CircleShape).background(hexColor(c.color)))
                        Spacer(Modifier.width(12.dp))
                        Column(Modifier.weight(1f)) {
                            Text(c.name, maxLines = 1, overflow = TextOverflow.Ellipsis)
                            Text(when (c.kind) { "personal" -> "личный"; "own" -> "мой"; "company" -> "компании"; else -> "общий · " + c.owner.name } + if (c.readonly) " · только чтение" else "",
                                style = MaterialTheme.typography.bodySmall, color = P.muted)
                        }
                        if (c.kind == "own" || c.kind == "personal") IconBtn("gear", "Настроить", tint = P.muted) { edit = c }
                    }
                    Divider()
                }
            }
        }
        if (creating) InputDialog("Новый календарь", "Название", confirm = "Создать", onDismiss = { creating = false }) { name ->
            scope.launchSafe { Session.api!!.createCalendar(name, COLORS.random()); CalStore.loadCalendars() }
        }
        edit?.let { c -> CalendarSettings(c, onDismiss = { edit = null }) }
    }
}

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun CalendarSettings(c: Calendar, onDismiss: () -> Unit) {
    var name by remember { mutableStateOf(c.name) }
    var color by remember { mutableStateOf(c.color) }
    var shares by remember { mutableStateOf<List<su.innotec.mail.api.CalendarShare>?>(null) }
    var add by remember { mutableStateOf("") }
    var confirm by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    val api = Session.api!!
    LaunchedEffect(c.uri) { shares = runCatching { api.calendarShares(c.uri) }.getOrDefault(emptyList()) }
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text("Календарь") },
        text = {
            Column(Modifier.verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                OutlinedTextField(name, { name = it }, Modifier.fillMaxWidth(), label = { Text("Название") }, singleLine = true, enabled = c.kind != "personal")
                FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    COLORS.forEach { h ->
                        Box(Modifier.size(30.dp).clip(CircleShape).background(hexColor(h)).clickable { color = h }, contentAlignment = Alignment.Center) {
                            if (color.equals(h, true)) Ico("check", size = 16.dp, tint = Color.White)
                        }
                    }
                }
                Text("Доступ", style = MaterialTheme.typography.labelLarge, color = P.muted)
                shares?.forEach { s ->
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Column(Modifier.weight(1f)) { Text(s.name.ifBlank { s.mail }, maxLines = 1); Text(if (s.level == "write") "может изменять" else "только смотрит", style = MaterialTheme.typography.bodySmall, color = P.muted) }
                        IconBtn("x", "Убрать", tint = P.muted) { scope.launchSafe { api.unshareCalendar(c.uri, s.mail); shares = api.calendarShares(c.uri) } }
                    }
                }
                Row(verticalAlignment = Alignment.CenterVertically) {
                    OutlinedTextField(add, { add = it.trim() }, Modifier.weight(1f), label = { Text("Адрес коллеги") }, singleLine = true)
                    IconBtn("plus", "Дать доступ на чтение") {
                        if (add.contains('@')) scope.launchSafe { api.shareCalendar(c.uri, add, "read"); add = ""; shares = api.calendarShares(c.uri) }
                    }
                }
                if (c.kind == "own") TextButton(onClick = { confirm = true }) { Text("Удалить календарь", color = P.no) }
            }
        },
        confirmButton = {
            TextButton(onClick = {
                onDismiss()
                scope.launchSafe { api.updateCalendar(c.uri, name.takeIf { it != c.name }, color.takeIf { it != c.color }); CalStore.loadCalendars(); CalStore.load() }
            }) { Text("Сохранить") }
        },
        dismissButton = { TextButton(onClick = onDismiss) { Text("Отмена") } },
    )
    if (confirm) ConfirmDialog("Удалить календарь «${c.name}»?", "Все события в нём пропадут.", "Удалить", danger = true, onDismiss = { confirm = false }) {
        onDismiss(); scope.launchSafe { api.deleteCalendar(c.uri); CalStore.loadCalendars(); CalStore.load() }
    }
}
