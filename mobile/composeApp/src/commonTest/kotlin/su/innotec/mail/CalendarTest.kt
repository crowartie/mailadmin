package su.innotec.mail

import kotlinx.datetime.LocalDate
import kotlinx.datetime.LocalDateTime
import kotlinx.datetime.TimeZone
import kotlinx.datetime.toInstant
import su.innotec.mail.api.CalEvent
import su.innotec.mail.api.RRule
import su.innotec.mail.ui.calendar.buildRule
import su.innotec.mail.ui.calendar.eventEndDate
import su.innotec.mail.ui.calendar.eventStartDate
import su.innotec.mail.ui.calendar.layoutDay
import su.innotec.mail.ui.calendar.repeatText
import su.innotec.mail.ui.calendar.weekOf
import su.innotec.mail.ui.calendar.weekTitle
import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertNull
import kotlin.test.assertTrue

/** Сетка «День/Неделя»: раскладка пересечений, неделя с понедельника, подписи повторов. */
class CalendarTest {
    private val d = LocalDate(2027, 1, 4)
    // Сервер отдаёт моменты времени; строим их из местного времени машины, где идут тесты.
    private fun at(day: Int, hm: String) = LocalDateTime(2027, 1, day, hm.take(2).toInt(), hm.takeLast(2).toInt()).toInstant(TimeZone.currentSystemDefault()).toString()
    private fun ev(id: String, s: String, e: String) = CalEvent(id = id, title = id, start = at(4, s), end = at(4, e))

    @Test
    fun overlappingEventsShareWidth() {
        val placed = layoutDay(d, listOf(ev("a", "10:00", "11:00"), ev("b", "10:30", "12:00"), ev("c", "11:00", "11:30"), ev("d", "13:00", "14:00")))
            .associateBy { it.e.id }
        // a и b пересекаются — две колонки; c встаёт в освободившуюся колонку a; d — отдельно, на всю ширину.
        assertEquals(2, placed.getValue("a").cols); assertEquals(0, placed.getValue("a").col)
        assertEquals(1, placed.getValue("b").col)
        assertEquals(0, placed.getValue("c").col); assertEquals(2, placed.getValue("c").cols)
        assertEquals(1, placed.getValue("d").cols)
        assertEquals(600, placed.getValue("a").from); assertEquals(660, placed.getValue("a").to)
    }

    @Test
    fun shortAndMultiDayEvents() {
        val short = layoutDay(d, listOf(ev("s", "09:00", "09:05"))).single()
        assertEquals(20, short.to - short.from)   // короче 20 минут не рисуем — не нажать
        val long = CalEvent(id = "l", start = at(3, "22:00"), end = at(5, "02:00"))
        val p = layoutDay(d, listOf(long)).single()
        assertEquals(0, p.from); assertEquals(1440, p.to)
        assertTrue(layoutDay(d, listOf(CalEvent(id = "x", allDay = true, start = "2027-01-04", end = "2027-01-05"))).isEmpty())
    }

    @Test
    fun weekStartsOnMonday() {
        val w = weekOf(LocalDate(2026, 10, 1))   // четверг
        assertEquals(LocalDate(2026, 9, 28), w.first()); assertEquals(LocalDate(2026, 10, 4), w.last())
        assertEquals("28 сентября – 4 октября 2026", weekTitle(w))
        assertEquals("4–10 января 2027", weekTitle(weekOf(d)))
    }

    @Test
    fun repeatWording() {
        assertEquals("Каждую неделю", repeatText(RRule(freq = "WEEKLY")))
        assertEquals("Раз в 2 недели: пн, ср, 4 раза", repeatText(RRule(freq = "WEEKLY", interval = 2, count = 4, byday = listOf("WE", "MO"))))
        assertEquals("Каждый месяц, до 31 марта", repeatText(RRule(freq = "MONTHLY", until = "2027-03-31")))
    }

    @Test
    fun ruleFromForm() {
        assertNull(buildRule("", 1, null, null, emptyList(), d))
        // Неделя без выбранных дней — день начала (4 января 2027 — понедельник); с выбранными — они.
        assertEquals(listOf("MO"), buildRule("WEEKLY", 1, null, null, emptyList(), d)!!.byday)
        assertEquals(listOf("WE", "FR"), buildRule("WEEKLY", 1, null, null, listOf("WE", "FR"), d)!!.byday)
        assertEquals(emptyList(), buildRule("MONTHLY", 1, null, null, listOf("WE"), d)!!.byday)
        // «До даты» и «сколько раз» вместе не уходят: дата побеждает; без даты — счётчик.
        val untilRule = buildRule("DAILY", 2, LocalDate(2027, 2, 1), 10, emptyList(), d)!!
        assertEquals("2027-02-01", untilRule.until); assertNull(untilRule.count); assertEquals(2, untilRule.interval)
        val countRule = buildRule("DAILY", 0, null, 10, emptyList(), d)!!
        assertNull(countRule.until); assertEquals(10, countRule.count); assertEquals(1, countRule.interval)   // interval < 1 поправлен
    }

    @Test
    fun allDayDatesDoNotShiftWithTimeZone() {
        // Сервер отдаёт полночь своего пояса (+03:00): на устройстве в UTC-8 это ещё 3 января, а в +12 — уже 5-е.
        // Целодневное событие 4–5 января должно оставаться 4–5 января везде, DTEND у него исключающий.
        val e = CalEvent(id = "x", allDay = true, start = "2027-01-04T00:00:00+03:00", end = "2027-01-06T00:00:00+03:00")
        assertEquals(LocalDate(2027, 1, 4), eventStartDate(e)); assertEquals(LocalDate(2027, 1, 5), eventEndDate(e))
        // Тот же ответ, если пояс сервера «на другой стороне» от устройства.
        val far = e.copy(start = "2027-01-04T00:00:00+14:00", end = "2027-01-06T00:00:00+14:00")
        assertEquals(LocalDate(2027, 1, 4), eventStartDate(far)); assertEquals(LocalDate(2027, 1, 5), eventEndDate(far))
        val west = e.copy(start = "2027-01-04T00:00:00-11:00", end = "2027-01-06T00:00:00-11:00")
        assertEquals(LocalDate(2027, 1, 4), eventStartDate(west)); assertEquals(LocalDate(2027, 1, 5), eventEndDate(west))
        // Голые даты и однодневное (конец = начало + 1 день) — тоже один день.
        val one = CalEvent(id = "y", allDay = true, start = "2027-01-04", end = "2027-01-05")
        assertEquals(LocalDate(2027, 1, 4), eventStartDate(one)); assertEquals(LocalDate(2027, 1, 4), eventEndDate(one))
        // Испорченный конец (раньше начала) не даёт «последний день раньше первого».
        assertEquals(LocalDate(2027, 1, 4), eventEndDate(one.copy(end = "2027-01-04")))
    }
}
