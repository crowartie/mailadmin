package su.innotec.mail

import io.ktor.utils.io.readAvailable
import kotlinx.coroutines.runBlocking
import kotlinx.io.Buffer
import kotlinx.io.readByteArray
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.put
import su.innotec.mail.api.Api
import su.innotec.mail.api.ContactInput
import su.innotec.mail.api.EventInput
import su.innotec.mail.api.LocalFile
import su.innotec.mail.api.TaskInput
import su.innotec.mail.api.TypedValue
import su.innotec.mail.platform.createHttpClient
import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertTrue

/**
 * Запись во всех разделах против живого сервера — всё создаётся и тут же удаляется.
 * Вход — готовым токеном тестового ящика (MAILADMIN_TEST_TOKEN, его выдаёт ux/_mtest.py):
 * так не рождается письмо «вход с нового устройства» на каждый прогон.
 */
class LiveWriteTest {
    private val server = System.getenv("MAILADMIN_TEST_SERVER") ?: "https://mail.innotec.su"
    private val token = System.getenv("MAILADMIN_TEST_TOKEN")
    private fun log(s: String) = println("  · $s")
    private val stamp = System.currentTimeMillis().toString().takeLast(6)

    private fun api() = Api(createHttpClient(), server) { token }

    @Test
    fun contactsCalendarTasksLabels() = runBlocking {
        if (token.isNullOrBlank()) { println("LiveWriteTest: токен не задан — пропуск"); return@runBlocking }
        val api = api()
        // Контакт в личной книге
        val c = api.createContact(ContactInput(book = "personal", first = "Проба", last = "Приложения $stamp", emails = listOf(TypedValue("proba$stamp@example.ru", "work")), phones = listOf(TypedValue("+7 900 000-00-00", "cell"))))
        try {
            assertTrue(c.uri.isNotBlank())
            val got = api.contact(c.book, c.uri)
            assertEquals("proba$stamp@example.ru", got.emails.first().value)
            val upd = api.updateContact(c.book, c.uri, ContactInput(book = c.book, first = "Проба", last = "Изменено $stamp", emails = got.emails, favorite = true))
            assertTrue(upd.favorite)
            assertTrue(api.contacts(q = "Изменено $stamp").any { it.uri == c.uri }, "поиск не нашёл контакт")
            log("контакт: создан, найден, изменён")
        } finally { api.deleteContact(c.book, c.uri) }
        assertTrue(api.contacts(q = "Изменено $stamp").none { it.uri == c.uri })
        log("контакт: удалён")

        // Событие в личном календаре
        val cal = api.calendars().first { it.kind == "personal" }
        api.createEvent(EventInput(calendar = cal.uri, title = "Проба приложения $stamp", start = "2027-01-15T10:00:00+08:00", end = "2027-01-15T11:00:00+08:00", location = "Кабинет", alarm = 15))
        val ev = api.events("2027-01-14", "2027-01-16").first { it.title == "Проба приложения $stamp" }
        try {
            assertEquals(15, ev.alarm)
            assertEquals(cal.uri, ev.calendar)
            api.updateEvent(ev.calendar, ev.id, EventInput(calendar = cal.uri, title = "Проба изменена $stamp", start = ev.start, end = ev.end, allDay = false))
            assertEquals("Проба изменена $stamp", api.event(ev.calendar, ev.id).title)
            log("событие: создано, изменено")
        } finally { api.deleteEvent(ev.calendar, ev.id) }
        assertTrue(api.events("2027-01-14", "2027-01-16").none { it.id == ev.id })
        log("событие: удалено")

        // Задача
        val t = api.createTask(TaskInput(title = "Проба задачи $stamp", due = "2027-01-20T18:00:00+08:00", priority = 1))
        try {
            val done = api.updateTask(t.calendar, t.id, TaskInput(done = true))
            assertTrue(done.done)
            log("задача: создана, выполнена")
        } finally { api.deleteTask(t.calendar, t.id) }
        assertTrue(api.tasks().none { it.id == t.id })
        log("задача: удалена")

        // Метка
        api.createLabel("Проба $stamp", "#16A05C")
        val l = api.labels().first { it.name == "Проба $stamp" }
        api.updateLabel(l.id, "Проба2 $stamp", "#C0392B")
        assertEquals("Проба2 $stamp", api.labels().first { it.id == l.id }.name)
        api.deleteLabel(l.id)
        assertTrue(api.labels().none { it.id == l.id })
        log("метка: создана, переименована, удалена")

        // Настройки и правила: сохранить то же самое — проверка записи (для правил — ManageSieve служебным входом).
        val s = api.settings()
        api.saveSettings(buildJsonObject { put("undo_seconds", s.undoSeconds) })
        assertEquals(s.undoSeconds, api.settings().undoSeconds)
        val rules = api.rules()
        api.saveRules(rules.rules, rules.autoreply)
        assertEquals(rules.rules.size, api.rules().rules.size)
        log("настройки и правила: перезаписаны без изменений (${rules.rules.size} правил)")

        // Доступ к папке — только чтение списка.
        val custom = api.folders().firstOrNull { it.role == "custom" && !it.isShared }
        if (custom != null) log("доступ к «${custom.name}»: ${api.folderShares(custom.path).shares.size}, кандидатов ${api.folderShares(custom.path).candidates.size}")
    }

    @Test
    fun sendToSelfWithAttachmentAndCleanup() = runBlocking {
        if (token.isNullOrBlank()) { println("LiveWriteTest: токен не задан — пропуск"); return@runBlocking }
        val api = api()
        val me = api.me().user
        val subject = "Проба отправки из приложения $stamp"
        val body = "Проверка: письмо самому себе с вложением."
        val att = "вложение-$stamp.txt".let { n -> val b = "Привет из приложения $stamp".encodeToByteArray(); LocalFile(n, b.size.toLong(), "text/plain") { Buffer().apply { write(b) } } }
        val folders = api.folders()
        val inbox = folders.first { it.role == "inbox" && !it.isShared }.path
        val sent = folders.first { it.role == "sent" }.path
        val trash = folders.first { it.role == "trash" }.path
        val r = api.send(su.innotec.mail.api.ComposeForm(to = me, subject = subject, html = "<p>$body</p>", files = listOf(att)))
        assertTrue(r.sent, "сервер не подтвердил отправку")
        log("отправлено: ${r.messageId}")
        var got: su.innotec.mail.api.MessageSummary? = null
        for (i in 1..20) {
            got = api.list(inbox, 0, 20).messages.firstOrNull { it.subject == subject }
            if (got != null) break
            kotlinx.coroutines.delay(1500)
        }
        try {
            val m = got ?: error("письмо не пришло за 30 секунд")
            val full = api.message(inbox, m.uid, peek = true)
            assertTrue(body in (full.html ?: full.text ?: ""), "текст не совпал")
            assertEquals(att.name, full.attachments.single().name)
            log("пришло во «Входящие»: вложение «${full.attachments.single().name}», ${full.attachments.single().size} байт")
        } finally {
            got?.let { api.action(su.innotec.mail.api.ActionRequest(inbox, listOf(it.uid), "delete")) }
            api.list(sent, 0, 20, q = "тема:\"$subject\"").messages.takeIf { it.isNotEmpty() }?.let { api.action(su.innotec.mail.api.ActionRequest(sent, it.map { m -> m.uid }, "delete")) }
            kotlinx.coroutines.delay(1000)
            api.list(trash, 0, 20, q = "тема:\"$subject\"").messages.takeIf { it.isNotEmpty() }?.let { api.action(su.innotec.mail.api.ActionRequest(trash, it.map { m -> m.uid }, "delete")) }
        }
        assertTrue(api.list(inbox, 0, 20).messages.none { it.subject == subject })
        log("входящее, отправленное и копии в корзине удалены")
    }

    @Test
    fun cloudUploadLinkAndCleanup() = runBlocking {
        if (token.isNullOrBlank()) { println("LiveWriteTest: токен не задан — пропуск"); return@runBlocking }
        val api = api()
        val folder = "Проба приложения $stamp"
        api.cloudMkdir("", folder)
        try {
            // 2,5 МБ — несколько частей, если сервер режет мельче; иначе одна.
            val data = ByteArray(2_500_000) { (it * 31 % 251).toByte() }
            val f = LocalFile("проба.bin", data.size.toLong()) { Buffer().apply { write(data) } }
            var st = api.cloudUploadStart(folder, f.name, f.size)
            for (n in 1..st.chunks) {
                val from = ((n - 1) * st.chunkSize).toInt()
                val to = minOf(data.size, from + st.chunkSize.toInt())
                api.cloudUploadChunk(st.id, n, data.copyOfRange(from, to))
            }
            val item = api.cloudUploadFinish(st.id).item
            assertEquals(data.size.toLong(), item.size)
            log("облако: загружено ${st.chunks} частями по ${st.chunkSize} байт")

            val back = api.download(api.cloudFilePath(item.path)) { _, _, _, ch ->
                val out = Buffer(); val buf = ByteArray(65536)
                while (true) { val k = ch.readAvailable(buf, 0, buf.size); if (k == -1) break; out.write(buf, 0, k); if (k == 0 && ch.isClosedForRead) break }
                out.readByteArray()
            }
            assertTrue(back.contentEquals(data), "скачанный файл не совпал")
            log("облако: скачано байт в байт")

            val link = api.cloudLink(item.path, 7).link
            assertTrue(link.url.startsWith("https://"), link.url)
            api.cloudUnlink(item.path)
            log("облако: ссылка создана и снята")

            val renamed = api.cloudRename(item.path, "проба-2.bin").path
            assertTrue(api.cloudList(folder).items.any { it.path == renamed })
            log("облако: переименовано")
        } finally {
            api.cloudDelete(listOf(folder))
            api.cloudTrash().items.filter { it.path == folder || it.name == folder }.forEach { api.cloudPurge(it.id ?: 0) }
        }
        assertTrue(api.cloudList("").items.none { it.name == folder })
        log("облако: папка удалена навсегда")
    }
}

