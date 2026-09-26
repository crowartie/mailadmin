package su.innotec.mail

import kotlinx.datetime.LocalDateTime
import kotlinx.datetime.TimeZone
import kotlinx.datetime.toInstant
import su.innotec.mail.api.CalEvent
import su.innotec.mail.ui.calendar.dayBars
import su.innotec.mail.ui.calendar.eventKey
import su.innotec.mail.ui.calendar.overlapping
import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertTrue

/** Экран месяца: полоски в клетке и предупреждение о пересечениях в повестке. */
class CalendarMonthTest {
    private fun at(hm: String) = LocalDateTime(2026, 9, 28, hm.take(2).toInt(), hm.takeLast(2).toInt()).toInstant(TimeZone.currentSystemDefault()).toString()
    private fun ev(id: String, s: String, e: String, allDay: Boolean = false, transparent: Boolean = false) =
        CalEvent(id = id, title = id, start = if (allDay) "2026-09-28" else at(s), end = if (allDay) "2026-09-29" else at(e), allDay = allDay, transparent = transparent)

    @Test
    fun barsShowAllDayFirstAndCountTheRest() {
        val (shown, more) = dayBars(listOf(ev("b", "14:00", "15:00"), ev("a", "09:00", "10:00"), ev("holiday", "", "", allDay = true), ev("c", "16:00", "17:00")), 2)
        // Целодневное — первой полоской, дальше по времени; лишние — числом.
        assertEquals(listOf("holiday", "a"), shown.map { it.id })
        assertEquals(2, more)
        // Помещается всё — «+N» нет.
        assertEquals(0, dayBars(listOf(ev("a", "09:00", "10:00")), 3).second)
        assertEquals(0, dayBars(emptyList(), 2).second)
    }

    @Test
    fun overlappingMeetingsAreFlagged() {
        val a = ev("a", "10:00", "11:00"); val b = ev("b", "10:30", "12:00"); val c = ev("c", "12:00", "13:00")
        val clash = overlapping(listOf(a, b, c))
        assertEquals(setOf(eventKey(a), eventKey(b)), clash)   // c начинается ровно когда b кончается — не пересечение
        // Целодневные и «время не занято» никому не мешают.
        assertTrue(overlapping(listOf(ev("d", "", "", allDay = true), a, ev("free", "10:00", "11:00", transparent = true))).isEmpty())
        // У повторяющихся один id на все вхождения — ключ различает их по началу.
        assertTrue(eventKey(a) != eventKey(a.copy(start = at("15:00"))))
    }
}
