package su.innotec.mail.ui

import kotlinx.datetime.DatePeriod
import kotlinx.datetime.LocalDate
import kotlinx.datetime.LocalDateTime
import kotlinx.datetime.TimeZone
import kotlinx.datetime.minus
import kotlinx.datetime.toLocalDateTime
import kotlinx.datetime.todayIn
import kotlin.time.Clock
import kotlin.time.Instant

object Fmt {
    val tz: TimeZone get() = TimeZone.currentSystemDefault()

    private val monthsShort = listOf("янв", "фев", "мар", "апр", "мая", "июн", "июл", "авг", "сен", "окт", "ноя", "дек")
    val monthsGen = listOf("января", "февраля", "марта", "апреля", "мая", "июня", "июля", "августа", "сентября", "октября", "ноября", "декабря")
    val monthsNom = listOf("Январь", "Февраль", "Март", "Апрель", "Май", "Июнь", "Июль", "Август", "Сентябрь", "Октябрь", "Ноябрь", "Декабрь")
    val weekdaysShort = listOf("пн", "вт", "ср", "чт", "пт", "сб", "вс")
    private val weekdaysFull = listOf("понедельник", "вторник", "среда", "четверг", "пятница", "суббота", "воскресенье")

    fun parse(iso: String?): Instant? {
        if (iso.isNullOrBlank()) return null
        return runCatching { Instant.parse(iso) }.getOrNull()
            ?: runCatching { Instant.parse(iso.replace(" ", "T") + if (iso.length <= 19) "Z" else "") }.getOrNull()
    }

    fun local(iso: String?): LocalDateTime? = parse(iso)?.toLocalDateTime(tz)

    fun today(): LocalDate = Clock.System.todayIn(tz)

    private fun two(n: Int) = n.toString().padStart(2, '0')

    fun time(d: LocalDateTime) = "${two(d.hour)}:${two(d.minute)}"

    /** Дата в списке писем: сегодня — время, на этой неделе — день недели, в этом году — «25 сен». */
    fun listDate(iso: String?): String {
        val d = local(iso) ?: return ""
        val t = today()
        return when {
            d.date == t -> time(d)
            d.date > t.minus(DatePeriod(days = 6)) -> weekdaysShort[d.date.dayOfWeek.ordinal]
            d.date.year == t.year -> "${d.date.day} ${monthsShort[d.date.month.ordinal]}"
            else -> "${two(d.date.day)}.${two(d.date.month.ordinal + 1)}.${d.date.year}"
        }
    }

    /** «25 сентября, 18:29» / «25 сентября 2024, 18:29». */
    fun full(iso: String?): String {
        val d = local(iso) ?: return ""
        val y = if (d.date.year != today().year) " ${d.date.year}" else ""
        return "${d.date.day} ${monthsGen[d.date.month.ordinal]}$y, ${time(d)}"
    }

    fun dayTitle(date: LocalDate): String {
        val t = today()
        val base = "${date.day} ${monthsGen[date.month.ordinal]}" + if (date.year != t.year) " ${date.year}" else ""
        return when (date) {
            t -> "Сегодня, $base"
            t.minus(DatePeriod(days = 1)) -> "Вчера, $base"
            else -> "${weekdaysFull[date.dayOfWeek.ordinal].replaceFirstChar { it.uppercase() }}, $base"
        }
    }

    fun dateShort(date: LocalDate) = "${date.day} ${monthsGen[date.month.ordinal]}"

    /** Группа в списке писем: «Сегодня», «Вчера», «На этой неделе»… — как в веб-почте. */
    fun group(iso: String?): String {
        val d = local(iso)?.date ?: return ""
        val t = today()
        return when {
            d == t -> "Сегодня"
            d == t.minus(DatePeriod(days = 1)) -> "Вчера"
            d > t.minus(DatePeriod(days = t.dayOfWeek.ordinal + 1)) -> "На этой неделе"
            d > t.minus(DatePeriod(days = t.dayOfWeek.ordinal + 8)) -> "На прошлой неделе"
            d.year == t.year && d.month == t.month -> "В этом месяце"
            d.year == t.year -> monthsNom[d.month.ordinal]
            else -> "${monthsNom[d.month.ordinal]} ${d.year}"
        }
    }

    fun size(bytes: Long?): String {
        val b = bytes ?: return ""
        return when {
            b < 1024 -> "$b Б"
            b < 1024 * 1024 -> "${(b + 512) / 1024} КБ"
            b < 1024L * 1024 * 1024 -> one(b / 1048576.0) + " МБ"
            else -> one(b / 1073741824.0) + " ГБ"
        }
    }

    private fun one(v: Double): String {
        val r = kotlin.math.round(v * 10) / 10
        return if (r >= 100 || r == kotlin.math.floor(r)) r.toLong().toString() else r.toString().replace('.', ',')
    }

    /** Склонение: plural(5, "письмо", "письма", "писем"). */
    fun plural(n: Int, one: String, few: String, many: String): String {
        val a = n % 100; val b = n % 10
        return when {
            a in 11..14 -> many
            b == 1 -> one
            b in 2..4 -> few
            else -> many
        }
    }

    fun initials(name: String): String {
        // У адреса — только имя ящика: «ivanov@innotec.su» → «IV», а не «II» из домена.
        val base = name.trim().let { if ('@' in it && ' ' !in it) it.substringBefore('@') else it }
        val parts = base.split(Regex("[\\s._@-]+")).filter { it.isNotBlank() }
        return when {
            parts.isEmpty() -> "?"
            parts.size == 1 -> parts[0].take(2).uppercase()
            else -> (parts[0].take(1) + parts[1].take(1)).uppercase()
        }
    }
}
