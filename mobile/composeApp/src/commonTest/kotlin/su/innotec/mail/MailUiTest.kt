package su.innotec.mail

import io.ktor.client.HttpClient
import io.ktor.client.engine.mock.MockEngine
import io.ktor.client.engine.mock.respond
import io.ktor.client.plugins.contentnegotiation.ContentNegotiation
import io.ktor.client.request.HttpRequestData
import io.ktor.http.HttpHeaders
import io.ktor.http.HttpMethod
import io.ktor.http.HttpStatusCode
import io.ktor.http.headersOf
import io.ktor.serialization.kotlinx.json.json
import kotlinx.coroutines.test.runTest
import su.innotec.mail.api.Api
import su.innotec.mail.api.ApiException
import su.innotec.mail.api.ApiJson
import su.innotec.mail.api.CloudConfig
import su.innotec.mail.api.CloudFileRef
import su.innotec.mail.api.Draft
import su.innotec.mail.api.LocalFile
import su.innotec.mail.api.Person
import su.innotec.mail.api.Rule
import su.innotec.mail.api.RuleAction
import su.innotec.mail.api.RuleCondition
import su.innotec.mail.api.Thread
import su.innotec.mail.api.ThreadMessage
import su.innotec.mail.ui.mail.PinnedMessage
import su.innotec.mail.ui.mail.pinToggle
import su.innotec.mail.ui.mail.quickReplyHint
import su.innotec.mail.ui.mail.quoteTitleOf
import su.innotec.mail.ui.mail.ruleCoversSender
import su.innotec.mail.ui.mail.searchHint
import su.innotec.mail.ui.mail.senderMatchOf
import su.innotec.mail.ui.mail.stageLabel
import su.innotec.mail.ui.mail.threadPosition
import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertFailsWith
import kotlin.test.assertFalse
import kotlin.test.assertNull
import kotlin.test.assertTrue

/** Чистая логика экранов почты после макетов 27.09: хранилище файлов, «под рукой», правила, подписи. */
class MailUiTest {
    // ---------- compose/stage ----------

    private fun api(handler: suspend io.ktor.client.engine.mock.MockRequestHandleScope.(HttpRequestData) -> io.ktor.client.request.HttpResponseData): Pair<Api, MutableList<HttpRequestData>> {
        val seen = mutableListOf<HttpRequestData>()
        val http = HttpClient(MockEngine { req -> seen.add(req); handler(req) }) {
            expectSuccess = false
            install(ContentNegotiation) { json(ApiJson) }
        }
        return Api(http, "https://mail.test") { "TOKEN" } to seen
    }

    private val jsonHeaders = headersOf(HttpHeaders.ContentType, "application/json")
    private fun file(name: String, bytes: ByteArray) = LocalFile(name, bytes.size.toLong(), "application/octet-stream") { kotlinx.io.Buffer().apply { write(bytes) } }

    @Test
    fun stageParsesTokenAndPostsMultipart() = runTest {
        val (a, seen) = api {
            respond("""{"token":"AbCdEfGhIjKlMnOpQrStUvWx","name":"Отчёт.pdf","size":3,"url":"https://files.test/AbCdEfGhIjKlMnOpQrStUvWx","expires":"2026-10-27"}""", HttpStatusCode.OK, jsonHeaders)
        }
        val r = a.stageFile(file("Отчёт.pdf", byteArrayOf(1, 2, 3)))
        assertEquals("AbCdEfGhIjKlMnOpQrStUvWx", r.token)
        assertEquals("Отчёт.pdf", r.name)
        assertEquals(3L, r.size)
        assertEquals("2026-10-27", r.expires)
        assertEquals(HttpMethod.Post, seen[0].method)
        assertEquals("https://mail.test/api/v1/compose/stage", seen[0].url.toString())
        assertTrue(seen[0].body.contentType?.contentType == "multipart", seen[0].body.contentType.toString())
    }

    @Test
    fun stageRefusalIsReadable() = runTest {
        // Не своё хранилище: 409 с текстом — приложение вернётся к пути через черновик.
        val (a, _) = api { respond("""{"message":"Заранее класть файлы можно только в своё хранилище"}""", HttpStatusCode.Conflict, jsonHeaders) }
        val e = assertFailsWith<ApiException> { a.stageFile(file("x.bin", byteArrayOf(1))) }
        assertEquals(409, e.status)
    }

    @Test
    fun unstageDeletesByToken() = runTest {
        val (a, seen) = api { respond("""{"removed":1}""", HttpStatusCode.OK, jsonHeaders) }
        a.unstage("AbCdEfGhIjKlMnOpQrStUvWx")
        assertEquals(HttpMethod.Delete, seen[0].method)
        assertEquals("https://mail.test/api/v1/compose/stage/AbCdEfGhIjKlMnOpQrStUvWx", seen[0].url.toString())
    }

    @Test
    fun draftCarriesStagedFiles() {
        val d = ApiJson.decodeFromString(Draft.serializer(), """{"draftUid":5,"subject":"т","html":"<p>x</p>","staged":[{"token":"AbCdEfGhIjKlMnOpQrStUvWx","name":"big.zip","size":50000000}]}""")
        assertEquals(1, d.staged.size)
        assertEquals("big.zip", d.staged[0].name)
        assertEquals(50000000L, d.staged[0].size)
        // Старый сервер без поля — пустой список, а не ошибка.
        assertTrue(ApiJson.decodeFromString(Draft.serializer(), """{"draftUid":5}""").staged.isEmpty())
    }

    @Test
    fun stageAllowedWhenNoPersonalCloud() {
        assertTrue(CloudConfig(enabled = true, personal = false).canStage)
        assertFalse(CloudConfig(enabled = true, personal = true).canStage)
        assertFalse(CloudConfig(enabled = false).canStage)
        // Сервер сказал прямо — верим ему.
        assertTrue(CloudConfig(enabled = true, personal = true, stage = true).canStage)
        assertFalse(CloudConfig(enabled = true, personal = false, stage = false).canStage)
    }

    @Test
    fun stageLabels() {
        val mb48 = 48L * 1024 * 1024
        assertEquals("48 МБ · ссылкой · 72%", stageLabel("upload", mb48, 72))
        assertEquals("48 МБ · ссылкой · проверка антивирусом…", stageLabel("check", mb48, 99))
        assertEquals("48 МБ · ссылкой", stageLabel("ready", mb48, 100))
        assertEquals("48 МБ · не загрузился: нет связи", stageLabel("error", mb48, 10, "нет связи"))
    }

    // ---------- файлы по ссылке в письме ----------

    @Test
    fun cloudFileCardFromServer() {
        val c = ApiJson.decodeFromString(CloudFileRef.serializer(), """{"token":"AbCdEfGhIjKlMnOpQrStUvWx","name":"Смета.xlsx","size":120,"type":"application/vnd.ms-excel","url":"https://files.test/AbCdEfGhIjKlMnOpQrStUvWx","expires":"2026-10-01","expired":false,"mine":true,"downloads":2,"subject":"","preview":true,"cloud":false}""")
        assertTrue(c.mine); assertTrue(c.preview); assertFalse(c.cloud); assertEquals("2026-10-01", c.expires)
        // В окне «Написать» файл облака — только путь и имя: остальные поля со значениями по умолчанию.
        val plain = ApiJson.decodeFromString(CloudFileRef.serializer(), """{"path":"/Вложения из почты/a.pdf","name":"a.pdf","size":1}""")
        assertEquals("", plain.token); assertFalse(plain.mine)
    }

    // ---------- «под рукой» ----------

    @Test
    fun pinToggleAddsRemovesAndCaps() {
        val a = PinnedMessage("INBOX", 1, "Счёт")
        val b = PinnedMessage("INBOX", 2, "Договор")
        assertEquals(listOf(a), pinToggle(emptyList(), a))
        assertEquals(listOf(b, a), pinToggle(listOf(a), b))          // новое — сверху
        assertEquals(listOf(b), pinToggle(listOf(b, a), a))          // повтор — убрать
        val five = (1..5).map { PinnedMessage("INBOX", it.toLong()) }
        assertNull(pinToggle(five, PinnedMessage("INBOX", 9)))       // места нет
        assertEquals(4, pinToggle(five, five[2])!!.size)             // но снять можно
        // То же письмо в другой папке — другое письмо.
        assertEquals(2, pinToggle(listOf(a), PinnedMessage("Archive", 1))!!.size)
    }

    // ---------- правило для отправителя уже есть ----------

    @Test
    fun ruleCoversSenderByAddressDomainAndAutoFolder() {
        val byAddress = Rule(id = "1", conditions = listOf(RuleCondition("from", "is", "ivanov@polyus.ru")), actions = listOf(RuleAction("move", "INBOX/Полюс")))
        val byDomain = Rule(id = "2", conditions = listOf(RuleCondition("from", "ends", "@polyus.ru")), actions = listOf(RuleAction("move", "INBOX/Полюс")))
        val auto = Rule(id = "3", conditions = listOf(RuleCondition("from", "contains", "@polyus.ru")), actions = listOf(RuleAction("move_by_sender", "INBOX/Клиенты")))
        val off = byAddress.copy(id = "4", enabled = false)
        assertTrue(ruleCoversSender(listOf(byAddress), "Ivanov@Polyus.ru", "INBOX/Полюс"))
        assertFalse(ruleCoversSender(listOf(byAddress), "ivanov@polyus.ru", "INBOX/Другая"))   // в другую папку — правила нет
        assertFalse(ruleCoversSender(listOf(byAddress), "petrov@polyus.ru", "INBOX/Полюс"))
        assertTrue(ruleCoversSender(listOf(byDomain), "petrov@polyus.ru", "INBOX/Полюс"))
        assertTrue(ruleCoversSender(listOf(auto), "petrov@polyus.ru", "INBOX/Что угодно"))     // раскладывает само
        assertFalse(ruleCoversSender(listOf(off), "ivanov@polyus.ru", "INBOX/Полюс"))
        assertFalse(ruleCoversSender(emptyList(), "ivanov@polyus.ru", "INBOX/Полюс"))
    }

    @Test
    fun senderMatchFromFolderRuleInput() {
        assertEquals("address" to "ivanov@polyus.ru", senderMatchOf(" Ivanov@Polyus.ru "))
        assertEquals("domain" to "polyus.ru", senderMatchOf("@polyus.ru"))
        assertEquals("domain" to "polyus.ru", senderMatchOf("polyus.ru"))
        assertEquals("address" to "a@b.ru", senderMatchOf("<a@b.ru>"))
        assertNull(senderMatchOf(""))
        assertNull(senderMatchOf("ivanov"))
        assertNull(senderMatchOf("иван петров"))
        assertNull(senderMatchOf("a@b"))
    }

    // ---------- подписи на экранах ----------

    @Test
    fun threadPositionCountsNewestFirst() {
        val t = Thread(messages = listOf(
            ThreadMessage(uid = 10, folder = "INBOX", date = "2026-09-27T10:00:00+03:00"),
            ThreadMessage(uid = 12, folder = "INBOX", date = "2026-09-27T12:00:00+03:00"),
        ))
        assertEquals("2 из 3 в переписке", threadPosition(t, "INBOX", 11, "2026-09-27T11:00:00+03:00"))
        assertEquals("1 из 3 в переписке", threadPosition(t, "INBOX", 13, "2026-09-27T13:00:00+03:00"))
        assertEquals("", threadPosition(null, "INBOX", 11, "2026-09-27T11:00:00+03:00"))
        assertEquals("", threadPosition(Thread(), "INBOX", 11, "2026-09-27T11:00:00+03:00"))
    }

    @Test
    fun quickReplyHintUsesFirstName() {
        assertEquals("Быстрый ответ Марии…", quickReplyHint(Person("Мария Иванова", "m@x.ru")))
        assertEquals("Быстрый ответ Ивану…", quickReplyHint(Person("Иван Петров", "i@x.ru")))
        assertEquals("Быстрый ответ Андрею…", quickReplyHint(Person("Андрей", "a@x.ru")))
        assertEquals("Быстрый ответ Игорю…", quickReplyHint(Person("Игорь", "a@x.ru")))
        assertEquals("Быстрый ответ…", quickReplyHint(Person("", "noname@x.ru")))
        assertEquals("Быстрый ответ: ООО…", quickReplyHint(Person("ООО Ромашка", "info@x.ru")))
    }

    @Test
    fun searchHintAndQuoteTitle() {
        assertEquals("Поиск во «Входящих»", searchHint("Входящие"))
        assertEquals("Поиск в «Отчёты»", searchHint("Отчёты"))
        assertEquals("Поиск по всей почте", searchHint("Поиск по всей почте"))
        // Дата — как в списке писем (сегодня время, иначе день): проверяем только, что она приписана через запятую.
        val q = quoteTitleOf("Иванова Мария", "2026-09-25T09:17:00+03:00")
        assertTrue(q.startsWith("Иванова Мария, ") && q.length > "Иванова Мария, ".length, q)
        assertEquals("Иванова Мария", quoteTitleOf("Иванова Мария", "не дата"))
        assertEquals("", quoteTitleOf("", ""))
    }
}
