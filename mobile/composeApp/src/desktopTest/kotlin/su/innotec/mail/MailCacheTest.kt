package su.innotec.mail

import su.innotec.mail.api.Folder
import su.innotec.mail.api.Message
import su.innotec.mail.api.MessageSummary
import su.innotec.mail.api.Person
import su.innotec.mail.data.Account
import su.innotec.mail.data.Session
import su.innotec.mail.platform.DiskCache
import su.innotec.mail.ui.mail.MailCache
import java.io.File
import kotlin.test.AfterTest
import kotlin.test.BeforeTest
import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertNull

/** Письма на устройстве: список и письмо возвращаются как сохранены, чужой ящик их не видит, выход стирает всё. */
class MailCacheTest {
    private val home = File(System.getProperty("java.io.tmpdir"), "mailadmin-cache-" + System.nanoTime())

    @BeforeTest fun setUp() { home.mkdirs(); System.setProperty("mailadmin.home", home.absolutePath) }
    @AfterTest fun tearDown() { home.deleteRecursively() }

    @Test
    fun roundTripAndIsolation() {
        Session.signIn(Account(origin = "https://example.test", token = "t", user = "a@example.test", name = "A"))
        val list = listOf(MessageSummary(uid = 7, subject = "Привет", from = Person("Иван", "ivan@example.test")), MessageSummary(uid = 5, subject = "Второе"))
        MailCache.saveList("INBOX", "all", "date", list, 42)
        MailCache.saveFolders(listOf(Folder(path = "INBOX", name = "Входящие", role = "inbox")))
        MailCache.saveMessage("INBOX", 7, Message(uid = 7, subject = "Привет", html = "<p>текст</p>"))

        val (got, total) = MailCache.list("INBOX", "all", "date")!!
        assertEquals(listOf(7L, 5L), got.map { it.uid }); assertEquals(42, total); assertEquals("Иван", got[0].from.name)
        assertEquals("<p>текст</p>", MailCache.message("INBOX", 7)?.html)
        assertNull(MailCache.message("INBOX", 8), "чужой номер письма не должен подменяться")
        assertNull(MailCache.list("INBOX", "unread", "date"), "другой отбор — другой список")
        assertEquals("Входящие", MailCache.folders()?.single()?.name)

        // Другой ящик на том же устройстве не видит писем первого.
        Session.signIn(Account(origin = "https://example.test", token = "t", user = "b@example.test", name = "B"))
        assertNull(MailCache.list("INBOX", "all", "date"))
        assertNull(MailCache.message("INBOX", 7))

        // Выход стирает кэш.
        Session.signIn(Account(origin = "https://example.test", token = "t", user = "a@example.test", name = "A"))
        Session.signOut()
        Session.signIn(Account(origin = "https://example.test", token = "t", user = "a@example.test", name = "A"))
        assertNull(MailCache.list("INBOX", "all", "date"))
        Session.signOut()
        DiskCache.clear()
    }
}
