package su.innotec.mail

import su.innotec.mail.api.ApiJson
import su.innotec.mail.api.Reminder
import su.innotec.mail.api.Status
import su.innotec.mail.data.Reminders
import su.innotec.mail.ui.contacts.importSummary
import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertTrue

/** Напоминания о встречах из GET status: разбор, дедупликация показанных, текст уведомления. */
class RemindersTest {
    @Test
    fun statusCarriesReminders() {
        val st = ApiJson.decodeFromString(Status.serializer(),
            """{"folder":{"messages":3,"unseen":1,"uidnext":10},"inboxUnseen":1,"at":"2026-09-28T09:45:00+03:00",
               "reminders":[{"key":"u1@2026-09-28T10:00:00+03:00","title":"Планёрка","start":"2026-09-28T10:00:00+03:00","allDay":false,"location":"переговорная"}]}""")
        assertEquals(1, st.reminders.size)
        assertEquals("Планёрка", st.reminders[0].title)
        // Старый сервер без поля — пустой список, а не ошибка разбора.
        assertTrue(ApiJson.decodeFromString(Status.serializer(), """{"folder":{},"inboxUnseen":0,"at":""}""").reminders.isEmpty())
    }

    @Test
    fun shownRemindersAreNotRepeated() {
        val a = Reminder(key = "a@1", title = "A"); val b = Reminder(key = "b@1", title = "B")
        // Сервер отдаёт то же напоминание ещё пару минут — второй раз не показываем; дубликаты в одном ответе — один раз.
        assertEquals(listOf(b), Reminders.newOnes(listOf(a, b, b), setOf("a@1")))
        assertTrue(Reminders.newOnes(listOf(a, b), setOf("a@1", "b@1")).isEmpty())
        assertTrue(Reminders.newOnes(listOf(Reminder(key = "", title = "без ключа")), emptySet()).isEmpty())
        // Память ограничена: остаются последние, старые вытесняются.
        val kept = Reminders.trim((1..250).map { "k$it" }, keep = 200)
        assertEquals(200, kept.size); assertEquals("k51", kept.first()); assertEquals("k250", kept.last())
    }

    @Test
    fun notificationText() {
        val (title, text) = Reminders.describe(Reminder(key = "x", title = "Планёрка", start = "2026-09-28", allDay = true, location = "офис"))
        assertEquals("Напоминание: Планёрка", title)
        assertEquals("Весь день · офис", text)
        assertEquals("Напоминание: (без названия)", Reminders.describe(Reminder(key = "y", allDay = true)).first)
    }

    @Test
    fun contactsImportSummary() {
        assertEquals("Загружено 12 в «Мои контакты», пропущено 3 (уже есть)", importSummary(12, 3, "Мои контакты"))
        assertEquals("Загружено 1", importSummary(1, 0, null))
        assertEquals("Новых контактов в файле нет, пропущено 5 (уже есть)", importSummary(0, 5, "Личные"))
    }
}
