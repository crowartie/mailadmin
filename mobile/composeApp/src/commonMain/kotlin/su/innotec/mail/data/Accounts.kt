package su.innotec.mail.data

import kotlinx.serialization.builtins.ListSerializer
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import kotlinx.serialization.json.longOrNull
import su.innotec.mail.api.ApiJson

/**
 * Чистая часть работы со списком ящиков ([Session.accounts]): без хранилища и Compose, чтобы проверять тестами.
 * Ящик узнаётся по [Account.key] — «сервер + адрес»: один и тот же логин на двух серверах — два разных ящика.
 */
object AccountList {
    private val serializer = ListSerializer(Account.serializer())

    fun parse(json: String?): List<Account> =
        json?.let { runCatching { ApiJson.decodeFromString(serializer, it) }.getOrNull() } ?: emptyList()

    fun encode(list: List<Account>): String = ApiJson.encodeToString(serializer, list)

    /**
     * Перенос со старого формата (до 1.4: один ящик под ключом «account», счётчики уведомлений — в общих настройках).
     * Счётчики переезжают в ящик: иначе после обновления первая проверка сочла бы все непрочитанные новыми.
     */
    fun migrate(legacyAccountJson: String?, legacyPrefsJson: String?): List<Account> {
        val a = legacyAccountJson?.let { runCatching { ApiJson.decodeFromString(Account.serializer(), it) }.getOrNull() } ?: return emptyList()
        val prefs = legacyPrefsJson?.let { runCatching { ApiJson.parseToJsonElement(it).jsonObject }.getOrNull() }
        fun num(name: String) = prefs?.get(name)?.jsonPrimitive?.longOrNull ?: 0L
        return listOf(a.copy(lastNotifiedUid = num("lastNotifiedUid"), lastNotifiedUidNext = num("lastNotifiedUidNext")))
    }

    /** Активный по сохранённому ключу; ключ потерян или ящик удалён — первый в списке. */
    fun pick(list: List<Account>, activeKey: String?): Account? = list.firstOrNull { it.key == activeKey } ?: list.firstOrNull()

    /** Повторный вход в тот же ящик заменяет запись на месте (новый токен), новый ящик — в конец. */
    fun add(list: List<Account>, a: Account): List<Account> =
        if (list.any { it.key == a.key }) list.map { if (it.key == a.key) a else it } else list + a

    fun remove(list: List<Account>, key: String): List<Account> = list.filterNot { it.key == key }

    /** Кто станет активным после выхода из [key]: следующий по списку, для последнего — предыдущий; null — никого не осталось. */
    fun next(list: List<Account>, key: String): Account? {
        val i = list.indexOfFirst { it.key == key }
        val rest = remove(list, key)
        if (rest.isEmpty()) return null
        if (i < 0) return rest.first()
        return rest.getOrNull(i) ?: rest.last()
    }

    /**
     * Короткое имя ящика для уведомлений и меню: домен адреса без зоны («deltaservices» для ivanov@deltaservices.ru).
     * Если у двух ящиков домен совпадает (два логина на одном сервере), различать нечем — берём весь адрес.
     */
    fun label(list: List<Account>, a: Account): String {
        val mine = shortLabel(a)
        return if (list.any { it.key != a.key && shortLabel(it) == mine }) a.user else mine
    }

    private fun shortLabel(a: Account): String {
        val domain = a.user.substringAfter('@', "").ifBlank { a.origin.substringAfter("://").substringBefore('/').substringBefore(':') }.lowercase()
        val parts = domain.split('.').filter { it.isNotBlank() }
        return when {
            parts.size >= 2 -> parts[parts.size - 2]
            parts.isNotEmpty() -> parts[0]
            else -> a.user
        }
    }

    /** Префикс уведомления: при одном ящике не нужен — как раньше; при нескольких — чей это ящик. */
    fun notifyPrefix(list: List<Account>, a: Account): String? = if (list.size > 1) label(list, a) else null

    fun notifyTitle(prefix: String?, from: String): String = if (prefix == null) from else "$prefix: $from"

    /** Номер системного уведомления: у двух ящиков письма с одним uid не должны затирать друг друга. */
    fun notifyId(key: String, uid: Long): Int = ((uid * 31 + key.hashCode()) and 0x7fffffffL).toInt()
}
