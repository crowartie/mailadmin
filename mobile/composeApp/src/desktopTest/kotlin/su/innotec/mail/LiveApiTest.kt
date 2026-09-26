package su.innotec.mail

import io.ktor.utils.io.readAvailable
import kotlinx.coroutines.runBlocking
import su.innotec.mail.api.ActionRequest
import su.innotec.mail.api.Api
import su.innotec.mail.api.ApiException
import su.innotec.mail.api.ComposeForm
import su.innotec.mail.api.DeviceInfo
import su.innotec.mail.api.LoginRequest
import su.innotec.mail.platform.createHttpClient
import su.innotec.mail.ui.login.compareVersions
import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertTrue
import kotlin.test.fail

/**
 * Проверка против живого сервера. Вход берётся из окружения (ux/_mtest.py передаёт тестовый ящик),
 * без него тест пропускается. В ящике только обратимые изменения: флажок ставится и снимается,
 * черновик создаётся и удаляется навсегда, в конце — выход (токен отзывается).
 */
class LiveApiTest {
    private val server = System.getenv("MAILADMIN_TEST_SERVER") ?: "https://mail.innotec.su"
    private val login = System.getenv("MAILADMIN_TEST_LOGIN")
    private val password = System.getenv("MAILADMIN_TEST_PASSWORD")
    private val hosts = System.getenv("MAILADMIN_TEST_IP")?.let { mapOf(server.substringAfter("://").substringBefore('/') to it) } ?: emptyMap()

    private fun log(s: String) = println("  · $s")

    @Test
    fun fullReadAndReversibleWrites() = runBlocking {
        if (login.isNullOrBlank() || password.isNullOrBlank()) { println("LiveApiTest: вход не задан — пропуск"); return@runBlocking }
        val http = createHttpClient(hosts)
        val anon = Api(http, server) { null }
        val d = anon.discover()
        log("сервер: ${d.name} ${d.version}, minApp ${d.minApp}, ${d.features}")
        assertTrue(d.api.endsWith("/api/v1"), d.api)
        assertTrue(compareVersions(AppInfo.VERSION, d.minApp) >= 0, "приложение старше minApp")

        val r = anon.login(LoginRequest(login, password, DeviceInfo("Тест приложения", "desktop", AppInfo.VERSION)))
        if (r.token == null) { println("LiveApiTest: включена 2FA — дальше без кода нельзя"); return@runBlocking }
        var token: String? = r.token
        val api = Api(http, server) { token }
        try {
            val me = api.me()
            assertEquals(login.lowercase(), me.user.lowercase())
            log("вход: ${me.user}, устройство ${me.device.id}, срок ${me.tokenDays} дн.")

            val meta = api.composeMeta()
            assertTrue(meta.identities.any { it.primary && it.mail.equals(login, true) }, "нет основного адреса в identities")
            assertTrue(meta.limits.messageMb > 0)
            log("compose-meta: отправителей ${meta.identities.size}, предел ${meta.limits.messageMb} МБ, облако ${meta.cloud}")

            val folders = api.folders()
            val inbox = folders.first { it.role == "inbox" && !it.isShared }
            val drafts = folders.first { it.role == "drafts" }
            val trash = folders.first { it.role == "trash" }
            log("папок ${folders.size}, общих ${folders.count { it.isShared }}")

            val st = api.status(inbox.path)
            assertTrue(st.folder.uidnext > 0)
            val list = api.list(inbox.path, 0, 20)
            assertTrue(list.total >= list.messages.size)
            log("входящие: всего ${list.total}, получено ${list.messages.size}")
            val unread = api.list(inbox.path, 0, 5, "unread")
            assertTrue(unread.messages.all { !it.seen }, "фильтр «непрочитанные» вернул прочитанные")

            if (list.messages.isNotEmpty()) {
                val m0 = list.messages.first()
                val msg = api.message(inbox.path, m0.uid, peek = true)
                assertEquals(m0.uid, msg.uid)
                assertTrue(msg.html != null || msg.text != null, "письмо без текста: subject=${msg.subject.length} size=${msg.size} att=${msg.attachments.size} uid=${msg.uid}")
                val t = api.thread(inbox.path, m0.uid)
                // /thread отдаёт остальные письма переписки, без открытого.
                assertTrue(t.messages.none { it.uid == m0.uid && it.folder == inbox.path }, "цепочка содержит само письмо")
                log("письмо ${m0.uid}: html ${msg.html?.length ?: 0}, цепочка ${t.messages.size}")

                val withAtt = list.messages.firstOrNull { it.hasAttachments }
                if (withAtt != null) {
                    val full = api.message(inbox.path, withAtt.uid, peek = true)
                    val a = full.attachments.firstOrNull()
                    if (a != null) {
                        val n = api.download(api.attachmentPath(inbox.path, withAtt.uid, a.index)) { len, type, name, ch ->
                            var total = 0L; val buf = ByteArray(65536)
                            while (true) { val k = ch.readAvailable(buf, 0, buf.size); if (k == -1) break; total += k; if (k == 0 && ch.isClosedForRead) break }
                            log("вложение «${name ?: a.name}» $type: $total байт (заявлено ${a.size})")
                            total
                        }
                        assertTrue(n > 0)
                    }
                }

                // Флажок: поставить и вернуть как было.
                val was = m0.flagged
                api.action(ActionRequest(inbox.path, listOf(m0.uid), if (was) "unflag" else "flag"))
                assertEquals(!was, api.message(inbox.path, m0.uid, peek = true).flagged)
                api.action(ActionRequest(inbox.path, listOf(m0.uid), if (was) "flag" else "unflag"))
                assertEquals(was, api.message(inbox.path, m0.uid, peek = true).flagged)
                log("флажок: поставлен и снят")
            }

            val found = api.list(inbox.path, 0, 5, q = "есть:вложение", scope = "all")
            log("поиск по всей почте «есть:вложение»: ${found.total}")

            // Прочие разделы — читаются и разбираются.
            val s = api.settings(); log("настройки: тема ${s.theme}, отмена ${s.undoSeconds} с")
            log("метки: ${api.labels().size}, подсказки «ко»: ${api.suggest("ко").size}")
            val books = api.books(); log("адресные книги: ${books.map { it.name to it.count }}")
            val contacts = api.contacts(); log("контакты: ${contacts.size}")
            contacts.firstOrNull { it.book.isNotBlank() }?.let { c -> assertEquals(c.uri, api.contact(c.book, c.uri).uri) }
            val cals = api.calendars(); log("календари: ${cals.map { it.name }}")
            log("события за 60 дней: ${api.events("2026-09-01", "2026-10-31").size}, задачи: ${api.tasks().size}")
            val cloud = runCatching { api.cloudList("") }.getOrNull(); log("облако: ${cloud?.items?.size ?: "выключено"}, занято ${cloud?.used}")
            log("файлы по ссылке: ${api.files().files.size}")
            val sec = api.security(); log("безопасность: 2FA ${sec.totp}, сеансов ${sec.sessions.size}, «приложение» среди них: ${sec.sessions.any { it.kind == "app" }}")
            assertTrue(api.devices().any { it.me }, "текущее устройство не отмечено me")
            log("правила: ${api.rules().rules.size}, карантин: ${api.quarantine().size}, обращения: ${api.tickets().tickets.size}")

            // Черновик самому себе: сохранить, открыть, удалить навсегда.
            val subject = "Проба приложения ${System.currentTimeMillis()}"
            val saved = api.saveDraft(ComposeForm(to = login, subject = subject, html = "<p>Проверка черновика из приложения</p>", draftKeepFiles = true))
            val uid = saved.draftUid ?: fail("черновик не сохранился")
            val draft = api.openDraft(uid)
            assertEquals(subject, draft.subject)
            assertTrue("Проверка черновика" in draft.html)
            api.action(ActionRequest(drafts.path, listOf(uid), "delete"))
            val inTrash = api.list(trash.path, 0, 10, q = "тема:\"$subject\"").messages
            if (inTrash.isNotEmpty()) api.action(ActionRequest(trash.path, inTrash.map { it.uid }, "delete"))
            assertTrue(api.list(drafts.path, 0, 50).messages.none { it.uid == uid }, "черновик не удалился")
            log("черновик: сохранён, открыт, удалён")
        } finally {
            runCatching { api.logout() }
            val after = runCatching { api.me() }.exceptionOrNull()
            assertTrue(after is ApiException && after.isAuth, "после выхода токен всё ещё действует")
            log("выход: токен отозван")
            token = null
        }
    }
}
