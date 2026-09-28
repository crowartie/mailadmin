package su.innotec.mail

import su.innotec.mail.data.Account
import su.innotec.mail.data.AccountList
import su.innotec.mail.ui.mail.MailCache
import su.innotec.mail.ui.mail.Pinned
import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertNotEquals
import kotlin.test.assertNull
import kotlin.test.assertTrue

/** Несколько ящиков на устройстве: список, перенос со старого формата, выбор активного, ключи хранилищ, уведомления. */
class AccountsTest {
    private val inno = Account(origin = "https://mail.innotec.su", token = "t1", user = "ivanov@innotec.su", name = "Иванов", serverName = "Иннотек")
    private val delta = Account(origin = "https://mail.deltaservices.ru", token = "t2", user = "ivanov@deltaservices.ru", name = "Иванов")
    private val third = Account(origin = "https://mail.innotec.su", token = "t3", user = "petrov@innotec.su", name = "Петров")

    @Test
    fun migratesSingleAccountWithCounters() {
        val legacy = """{"origin":"https://mail.innotec.su","token":"t1","user":"ivanov@innotec.su","name":"Иванов"}"""
        val prefs = """{"theme":"dark","lastNotifiedUid":120,"lastNotifiedUidNext":121,"notify":true}"""
        val list = AccountList.migrate(legacy, prefs)
        assertEquals(1, list.size)
        assertEquals("https://mail.innotec.su ivanov@innotec.su", list[0].key)
        // Счётчики уведомлений переезжают в ящик — иначе старые непрочитанные показались бы новыми.
        assertEquals(120L, list[0].lastNotifiedUid); assertEquals(121L, list[0].lastNotifiedUidNext)
        // Настроек нет или они битые — счётчики нулевые, а не ошибка.
        assertEquals(0L, AccountList.migrate(legacy, null)[0].lastNotifiedUid)
        assertEquals(0L, AccountList.migrate(legacy, "{oops")[0].lastNotifiedUid)
        assertTrue(AccountList.migrate(null, prefs).isEmpty())
        assertTrue(AccountList.migrate("{oops", prefs).isEmpty())
    }

    @Test
    fun listRoundTripAndPick() {
        val json = AccountList.encode(listOf(inno, delta))
        val back = AccountList.parse(json)
        assertEquals(listOf(inno, delta), back)
        assertTrue(AccountList.parse(null).isEmpty()); assertTrue(AccountList.parse("[oops").isEmpty())
        // Активный — по сохранённому ключу; ключ потерян — первый.
        assertEquals(delta, AccountList.pick(back, delta.key))
        assertEquals(inno, AccountList.pick(back, "нет такого"))
        assertNull(AccountList.pick(emptyList(), inno.key))
    }

    @Test
    fun addReplacesByKey() {
        val list = AccountList.add(AccountList.add(emptyList(), inno), delta)
        assertEquals(listOf(inno, delta), list)
        // Повторный вход — новый токен на том же месте, а не второй такой же ящик.
        val again = AccountList.add(list, inno.copy(token = "fresh"))
        assertEquals(2, again.size); assertEquals("fresh", again[0].token); assertEquals(delta, again[1])
        // Тот же логин на другом сервере — другой ящик.
        val other = AccountList.add(list, inno.copy(origin = "https://mail.deltaservices.ru"))
        assertEquals(3, other.size)
    }

    @Test
    fun nextAfterSignOut() {
        val list = listOf(inno, delta, third)
        assertEquals(delta, AccountList.next(list, inno.key), "следующий по списку")
        assertEquals(delta, AccountList.next(list, third.key), "для последнего — предыдущий")
        assertEquals(third, AccountList.next(list, delta.key))
        assertNull(AccountList.next(listOf(inno), inno.key), "никого не осталось")
        assertEquals(inno, AccountList.next(list, "нет такого"))
        assertEquals(listOf(inno, third), AccountList.remove(list, delta.key))
    }

    @Test
    fun notificationPrefixOnlyWithSeveralAccounts() {
        assertNull(AccountList.notifyPrefix(listOf(inno), inno))
        assertEquals("Иванов", AccountList.notifyTitle(null, "Иванов"))
        val two = listOf(inno, delta)
        assertEquals("deltaservices", AccountList.notifyPrefix(two, delta))
        assertEquals("deltaservices: Иванов", AccountList.notifyTitle(AccountList.notifyPrefix(two, delta), "Иванов"))
        assertEquals("innotec", AccountList.notifyPrefix(two, inno))
        // Два ящика на одном домене — коротким именем не различить, берём адрес.
        val same = listOf(inno, third)
        assertEquals("ivanov@innotec.su", AccountList.notifyPrefix(same, inno))
        assertEquals("petrov@innotec.su", AccountList.notifyPrefix(same, third))
        // Номера уведомлений у разных ящиков для одного uid не совпадают и неотрицательны.
        assertNotEquals(AccountList.notifyId(inno.key, 7), AccountList.notifyId(delta.key, 7))
        assertTrue(AccountList.notifyId(inno.key, Long.MAX_VALUE) >= 0)
    }

    @Test
    fun cacheAndPinnedKeysIncludeServer() {
        val a = MailCache.fileName("https://mail.innotec.su", "ivanov@innotec.su", "folders")
        val b = MailCache.fileName("https://mail.deltaservices.ru", "ivanov@innotec.su", "folders")
        assertNotEquals(a, b, "один логин на двух серверах — разные кэши")
        assertTrue(a.startsWith(MailCache.prefix("https://mail.innotec.su", "ivanov@innotec.su")), "файлы ящика находятся по префиксу")
        assertNotEquals(MailCache.prefix("https://mail.innotec.su", "ivanov@innotec.su"), MailCache.prefix("https://mail.deltaservices.ru", "ivanov@innotec.su"))
        assertNotEquals(Pinned.storeKey(inno.key), Pinned.storeKey(inno.copy(origin = "https://mail.deltaservices.ru").key))
    }
}
