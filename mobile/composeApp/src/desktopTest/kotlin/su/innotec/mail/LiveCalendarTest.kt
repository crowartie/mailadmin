package su.innotec.mail

import kotlinx.io.readByteArray
import io.ktor.utils.io.readAvailable
import kotlinx.coroutines.runBlocking
import kotlinx.serialization.json.jsonObject
import su.innotec.mail.api.Api
import su.innotec.mail.api.EventInput
import su.innotec.mail.api.RRule
import su.innotec.mail.platform.createHttpClient
import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertTrue

/**
 * Календарь вживую, как его использует форма события приложения: серия по дням недели с интервалом и числом
 * повторов, правка одной встречи (вхождение убирается из серии, создаётся отдельное событие), занятость.
 * Всё создаётся в личном календаре на 2027 год и удаляется в конце.
 */
class LiveCalendarTest {
    private val server = System.getenv("MAILADMIN_TEST_SERVER") ?: "https://mail.innotec.su"
    private val token = System.getenv("MAILADMIN_TEST_TOKEN")
    private val stamp = System.currentTimeMillis().toString().takeLast(6)
    private fun log(s: String) = println("  · $s")

    @Test
    fun seriesOneOccurrenceAndFreeBusy() = runBlocking {
        if (token.isNullOrBlank()) { println("LiveCalendarTest: токен не задан — пропуск"); return@runBlocking }
        val api = Api(createHttpClient(), server) { token }
        val cal = api.calendars().first { it.kind == "personal" }
        val title = "Серия приложения $stamp"
        // Пн и Ср раз в две недели, 4 раза, с понедельника 4 января 2027.
        api.createEvent(EventInput(calendar = cal.uri, title = title, start = "2027-01-04T10:00:00+08:00", end = "2027-01-04T11:00:00+08:00",
            rrule = RRule(freq = "WEEKLY", interval = 2, count = 4, byday = listOf("MO", "WE"))))
        val made = mutableListOf<Pair<String, String>>()
        try {
            val occ = api.events("2027-01-01", "2027-02-28").filter { it.title == title }.sortedBy { it.start }
            made += occ.first().calendar to occ.first().id
            val days = occ.map { it.start.take(10) }
            log("вхождения: $days")
            // Раз в две недели по Пн и Ср: 4, 6, 18, 20 января.
            assertEquals(4, occ.size, "ожидалось 4 вхождения, пришло $days")
            assertTrue(days.all { it in setOf("2027-01-04", "2027-01-06", "2027-01-18", "2027-01-20") }, "не те дни: $days")
            val rule = api.event(occ[0].calendar, occ[0].id).rrule!!
            assertEquals(2, rule.interval); assertEquals(4, rule.count); assertEquals(setOf("MO", "WE"), rule.byday.toSet())

            // «Только эту встречу»: второе вхождение переносим на час позже.
            val second = occ[1]
            api.deleteEvent(second.calendar, second.id, second.recurrenceId ?: second.start)
            api.createEvent(EventInput(calendar = cal.uri, title = "$title (перенос)", start = "2027-01-06T11:00:00+08:00", end = "2027-01-06T12:00:00+08:00"))
            val after = api.events("2027-01-01", "2027-02-28").filter { it.title.startsWith(title) }
            after.filter { it.title.endsWith("(перенос)") }.forEach { made += it.calendar to it.id }
            val seriesDays = after.filter { it.title == title }.map { it.start.take(10) }
            assertEquals(3, seriesDays.size, "в серии должно остаться 3: $seriesDays")
            assertTrue("2027-01-06" !in seriesDays, "вхождение 6 января не убрано из серии")
            assertEquals(1, after.count { it.title.endsWith("(перенос)") })
            log("одна встреча серии: убрана из серии и создана отдельно")

            // Занятость: в 10:00 4 января пользователь занят (своя серия).
            val me = api.me().user
            val fb = api.freebusy(listOf(me), "2027-01-04T00:00:00+08:00", "2027-01-05T00:00:00+08:00").jsonObject
            val mine = fb.entries.first { it.key.equals(me, true) }.value.toString()
            assertTrue(mine.contains("2027-01-04") || mine.contains("2027-01-03"), "занятость не видит встречу: $mine")
            log("занятость: встреча видна")
        } finally {
            made.distinct().forEach { (c, id) -> runCatching { api.deleteEvent(c, id) } }
        }
        assertTrue(api.events("2027-01-01", "2027-02-28").none { it.title.startsWith(title) }, "пробные события не удалились")
        log("пробные события удалены")

        // «Скачать .ics»: календарь целиком одним файлом, который разбирается как iCalendar.
        val ics = api.download(api.calendarExportPath(cal.uri)) { _, type, _, ch ->
            assertTrue(type?.startsWith("text/calendar") == true, "тип $type")
            val out = kotlinx.io.Buffer(); val buf = ByteArray(8192)
            while (true) { val r = ch.readAvailable(buf, 0, buf.size); if (r == -1) break; if (r > 0) out.write(buf, 0, r); if (r == 0 && ch.isClosedForRead) break }
            out.readByteArray().decodeToString()
        }
        assertTrue(ics.startsWith("BEGIN:VCALENDAR") && ics.trimEnd().endsWith("END:VCALENDAR"), "не iCalendar: ${ics.take(80)}")
        log("выгрузка .ics: ${ics.length} байт, событий ${Regex("BEGIN:VEVENT").findAll(ics).count()}")
    }
}
