package su.innotec.mail

import io.ktor.client.HttpClient
import io.ktor.client.engine.mock.MockEngine
import io.ktor.client.engine.mock.respond
import io.ktor.client.plugins.contentnegotiation.ContentNegotiation
import io.ktor.client.request.HttpRequestData
import io.ktor.http.HttpHeaders
import io.ktor.http.HttpStatusCode
import io.ktor.http.content.OutgoingContent
import io.ktor.http.headersOf
import io.ktor.serialization.kotlinx.json.json
import kotlinx.coroutines.test.runTest
import su.innotec.mail.api.ActionRequest
import su.innotec.mail.api.Api
import su.innotec.mail.api.ApiException
import su.innotec.mail.api.ApiJson
import su.innotec.mail.api.DeviceInfo
import su.innotec.mail.api.LoginRequest
import su.innotec.mail.api.enc
import su.innotec.mail.platform.hasBlockedImages
import su.innotec.mail.platform.unblockImages
import su.innotec.mail.ui.Fmt
import su.innotec.mail.ui.Html
import su.innotec.mail.ui.login.compareVersions
import su.innotec.mail.ui.login.serverCandidates
import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertFailsWith
import kotlin.test.assertFalse
import kotlin.test.assertNull
import kotlin.test.assertTrue

class CoreTest {
    @Test
    fun encodesLikeEncodeURIComponent() {
        assertEquals("INBOX", enc("INBOX"))
        assertEquals("INBOX%2F%D0%9E%D1%82%D1%87%D1%91%D1%82%D1%8B", enc("INBOX/Отчёты"))
        assertEquals("a%20b%26c%3Dd", enc("a b&c=d"))
        assertEquals("shared%2Finfo%40innotec.su%2FINBOX", enc("shared/info@innotec.su/INBOX"))
        assertEquals("-_.!~*'()", enc("-_.!~*'()"))
    }

    @Test
    fun versions() {
        assertTrue(compareVersions("1.0.0", "0.1.0") > 0)
        assertEquals(0, compareVersions("1.0", "1.0.0"))
        assertTrue(compareVersions("1.2.9", "1.10.0") < 0)
    }

    @Test
    fun serverFromAddress() {
        assertEquals(listOf("https://mail.innotec.su", "https://innotec.su"), serverCandidates("ivanov@innotec.su", ""))
        assertEquals(listOf("https://mail.example.ru"), serverCandidates("x@y.z", "mail.example.ru/"))
        assertEquals(listOf("http://10.0.0.1:8080"), serverCandidates("x@y.z", "http://10.0.0.1:8080"))
        assertEquals(emptyList(), serverCandidates("ivanov", ""))
    }

    @Test
    fun textToHtmlAndBack() {
        val h = Html.fromText("Привет, <мир>!\nВторая строка\n\nНовый абзац https://ya.ru")
        assertEquals("<p>Привет, &lt;мир&gt;!<br>Вторая строка</p><p>Новый абзац <a href=\"https://ya.ru\">https://ya.ru</a></p>", h)
        assertEquals("Привет, <мир>!\nВторая строка\n\nНовый абзац https://ya.ru", Html.toText(h))
        assertEquals("<p><br></p>", Html.fromText("   "))
    }

    @Test
    fun htmlToTextKeepsLinksAndLists() {
        val t = Html.toText("<div>Список:<ul><li>один</li><li>два</li></ul><a href=\"https://a.ru/x\">сайт</a>&nbsp;&laquo;ок&raquo;</div>")
        assertTrue("• один" in t && "• два" in t, t)
        assertTrue("сайт (https://a.ru/x)" in t, t)
        assertTrue("«ок»" in t, t)
    }

    @Test
    fun splitsDraftTail() {
        val (mine, tail) = Html.splitTail("<p>Текст</p><p><br></p><div class=\"sig\">Подпись</div><p><br></p><div class=\"quote\">q</div>")
        assertEquals("<p>Текст</p>", mine)
        assertTrue(tail.startsWith("<p><br></p><div class=\"sig\">"), tail)
        assertEquals("<p>x</p>" to "", Html.splitTail("<p>x</p>"))
    }

    @Test
    fun blockedImages() {
        val h = "<img data-blocked-src=\"https://t.ru/p.gif\"><td data-blocked-background='http://x/y.png'>"
        assertTrue(hasBlockedImages(h))
        val u = unblockImages(h)
        assertFalse(hasBlockedImages(u))
        assertTrue("src=\"https://t.ru/p.gif\"" in u && "background='http://x/y.png'" in u, u)
    }

    @Test
    fun formats() {
        assertEquals("письмо", Fmt.plural(1, "письмо", "письма", "писем"))
        assertEquals("письма", Fmt.plural(23, "письмо", "письма", "писем"))
        assertEquals("писем", Fmt.plural(11, "письмо", "письма", "писем"))
        assertEquals("писем", Fmt.plural(105, "письмо", "письма", "писем"))
        assertEquals("512 Б", Fmt.size(512))
        assertEquals("2 КБ", Fmt.size(2048))
        assertEquals("1,5 МБ", Fmt.size(1572864))
        assertEquals("ИП", Fmt.initials("Иван Петров"))
        assertEquals("IV", Fmt.initials("ivanov@innotec.su"))
        assertEquals("", Fmt.listDate("не дата"))
        assertEquals(2026, Fmt.local("2026-09-25T18:29:00+08:00")!!.year)
    }

    @Test
    fun fileNameFromContentDisposition() {
        assertEquals("Отчёт 1.pdf", Api.fileNameOf("attachment; filename*=UTF-8''%D0%9E%D1%82%D1%87%D1%91%D1%82%201.pdf"))
        assertEquals("a b.txt", Api.fileNameOf("inline; filename=\"a b.txt\""))
        assertNull(Api.fileNameOf(null))
    }

    // ---------- API на подставном сервере ----------

    private fun api(handler: suspend io.ktor.client.engine.mock.MockRequestHandleScope.(HttpRequestData) -> io.ktor.client.request.HttpResponseData): Pair<Api, MutableList<HttpRequestData>> {
        val seen = mutableListOf<HttpRequestData>()
        val http = HttpClient(MockEngine { req -> seen.add(req); handler(req) }) {
            expectSuccess = false
            install(ContentNegotiation) { json(ApiJson) }
        }
        return Api(http, "https://mail.test") { "TOKEN" } to seen
    }

    private val jsonHeaders = headersOf(HttpHeaders.ContentType, "application/json")

    @Test
    fun loginChallengeAndBearer() = runTest {
        val (a, seen) = api { respond("""{"challenge":"abc","message":"Введите код"}""", HttpStatusCode.Accepted, jsonHeaders) }
        val r = a.login(LoginRequest("u@x.ru", "p", DeviceInfo("Pixel", "android", "1.0.0")))
        assertEquals("abc", r.challenge)
        assertNull(r.token)
        assertEquals("https://mail.test/api/v1/login", seen[0].url.toString())
        assertEquals("Bearer TOKEN", seen[0].headers[HttpHeaders.Authorization])
        val body = (seen[0].body as OutgoingContent.ByteArrayContent).bytes().decodeToString()
        assertTrue("\"app_version\":\"1.0.0\"" in body, body)
    }

    @Test
    fun errorsBecomeReadable() = runTest {
        val (a, _) = api { respond("""{"message":"Письмо уже переложили","code":"conflict"}""", HttpStatusCode.Conflict, jsonHeaders) }
        val e = assertFailsWith<ApiException> { a.folders() }
        assertEquals(409, e.status); assertEquals("conflict", e.code); assertEquals("Письмо уже переложили", e.message)

        val (b, _) = api { respond("""{"message":"The given data was invalid.","errors":{"to":["Неверный адрес"],"subject":["Слишком длинная тема"]}}""", HttpStatusCode.UnprocessableEntity, jsonHeaders) }
        assertEquals("The given data was invalid.", assertFailsWith<ApiException> { b.folders() }.message)

        val (c, _) = api { respond("", HttpStatusCode.Unauthorized) }
        assertTrue(assertFailsWith<ApiException> { c.me() }.isAuth)
    }

    @Test
    fun listUrlAndParsing() = runTest {
        val (a, seen) = api {
            respond(
                """{"messages":[{"uid":7,"subject":"Тема","from":{"name":"Иван","mail":"i@x.ru"},"date":"2026-09-25T10:00:00+08:00","seen":false,"labels":[3],"unknownField":1}],"total":42,"offset":0,"limit":50}""",
                HttpStatusCode.OK, jsonHeaders,
            )
        }
        val r = a.list("INBOX/Отчёты", offset = 50, limit = 25, filter = "unread", q = "от:иван")
        assertEquals(42, r.total)
        assertEquals(7L, r.messages[0].uid)
        assertEquals(listOf(3L), r.messages[0].labels)
        val url = seen[0].url.toString()
        assertTrue(url.startsWith("https://mail.test/api/v1/list/INBOX%2F%D0%9E"), url)
        assertTrue("offset=50&limit=25&filter=unread&q=%D0%BE%D1%82%3A%D0%B8%D0%B2%D0%B0%D0%BD&folders=0" in url, url)
    }

    @Test
    fun actionBody() = runTest {
        val (a, seen) = api { respond("""{"ok":true,"done":2}""", HttpStatusCode.OK, jsonHeaders) }
        val r = a.action(ActionRequest(folder = "INBOX", uids = listOf(1, 2), op = "move", target = "Archive"))
        assertEquals(2, r.done)
        val body = (seen[0].body as OutgoingContent.ByteArrayContent).bytes().decodeToString()
        assertEquals("""{"folder":"INBOX","uids":[1,2],"op":"move","target":"Archive"}""", body)
    }
}
