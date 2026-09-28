package su.innotec.mail.data

import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import io.ktor.client.HttpClient
import kotlinx.coroutines.launch
import kotlinx.serialization.Serializable
import su.innotec.mail.api.Api
import su.innotec.mail.api.ApiJson
import su.innotec.mail.platform.KeyValueStore
import su.innotec.mail.platform.createHttpClient

/** Вход на этом устройстве: сервер и токен. Пароль не хранится (docs/mobile-api.md, раздел 1). */
@Serializable
data class Account(
    val origin: String,
    val token: String,
    val user: String,
    val name: String,
    val serverName: String = "",
    val features: List<String> = emptyList(),
    /** «имя → IP», если имя сервера в этой сети не разрешается (дополнительно при входе). */
    val hosts: Map<String, String> = emptyMap(),
    val deviceId: Long = 0,
    /** Последний uid во «Входящих» этого ящика, о котором уже сообщили (у каждого ящика свой счётчик). */
    val lastNotifiedUid: Long = 0,
    val lastNotifiedUidNext: Long = 0,
) {
    /** Чем ящик отличается от других на устройстве: тот же адрес на другом сервере — другой ящик. */
    val key: String get() = "$origin $user"

    /** Как показать сервер: имя с сервера, иначе хост. */
    val serverLabel: String get() = serverName.ifBlank { origin.substringAfter("://").substringBefore('/') }
}

/** Настройки самого приложения (не ящика): хранятся на устройстве и общие для всех ящиков. */
@Serializable
data class LocalPrefs(
    val theme: String = "system",
    /** Цветовая схема: brand — фирменная оранжевая, classic — синяя; общая с веб-почтой (settings.scheme). */
    val scheme: String = "brand",
    val notify: Boolean = true,
    val notifyShared: Boolean = false,
    val swipeLeft: String = "delete",
    val swipeRight: String = "archive",
    val lastUpdateCheck: Long = 0,
    /** Мгновенные уведомления: проверка раз в минуту фоновой службой (Android), без Firebase. */
    val fastNotify: Boolean = false,
    /** Второй ряд панели оформления письма раскрыт (кнопка «A»); помнится, как в веб-почте (mail.fmt). */
    val fmtOpen: Boolean = false,
    /** Ящики, у которых тема и схема уже взяты с сервера при первом появлении (App.kt). */
    val themeSynced: List<String> = emptyList(),
)

/**
 * Ящики на устройстве. Их может быть несколько (в том числе на разных серверах); интерфейс показывает
 * активный ([account]), фоновая проверка почты обходит все ([accounts]).
 */
object Session {
    private val store by lazy { KeyValueStore("account") }

    var accounts by mutableStateOf<List<Account>>(emptyList())
        private set
    /** Активный ящик; null — ни одного входа нет. */
    var account by mutableStateOf<Account?>(null)
        private set
    var prefs by mutableStateOf(LocalPrefs())
        private set

    /** Причина последнего выхода («вход устарел») — показывается на экране входа. */
    var signedOutReason by mutableStateOf<String?>(null)

    /**
     * Что сделать при выходе из последнего ящика, кроме стирания входа: сброс всех разделов и их задач. Регистрирует App —
     * выход случается и из кнопки «Выйти», и из любого ответа 401 (Toasts.error, фоновая проверка почты),
     * и всё это должно проходить через одно место.
     */
    var onSignOut: () -> Unit = {}

    /**
     * Что сделать перед сменой активного ящика: сбросить разделы, чтобы они загрузились заново уже для нового.
     * Тоже регистрирует App; зовётся и при переключении руками, и когда активный ящик отозван, а другие остались.
     */
    var onSwitch: () -> Unit = {}

    /**
     * Клиент на каждый набор «имя → IP» и API на каждый ящик — неизменяемые словари с копированием при записи:
     * к ним ходят и интерфейс, и фоновая служба из другого потока, а гонка тут в худшем случае создаст лишний клиент.
     */
    private var clients: Map<Map<String, String>, HttpClient> = emptyMap()
    private var apis: Map<String, Api> = emptyMap()

    /** Повторный вызов безопасен (службы зовут при старте): перенос со старого формата делается один раз. */
    fun load() {
        prefs = store.get("prefs")?.let { runCatching { ApiJson.decodeFromString(LocalPrefs.serializer(), it) }.getOrNull() } ?: LocalPrefs()
        val listJson = store.get("accounts")
        val legacy = store.get("account")
        if (listJson == null && legacy != null) {
            accounts = AccountList.migrate(legacy, store.get("prefs"))
            account = accounts.firstOrNull()
            saveAccounts()
        } else {
            accounts = AccountList.parse(listJson)
            account = AccountList.pick(accounts, store.get("active"))
        }
        // Старый ключ больше не читается — стираем, чтобы токен не лежал в двух местах.
        if (legacy != null) store.put("account", null)
    }

    private fun saveAccounts() {
        store.put("accounts", if (accounts.isEmpty()) null else AccountList.encode(accounts))
        store.put("active", account?.key)
    }

    /** Первый вход или вход в ещё один ящик: он становится активным. */
    fun signIn(a: Account) = addAccount(a)

    /**
     * Добавить ящик или обновить токен уже добавленного (повторный вход). Счётчики уведомлений при повторном
     * входе сохраняются — иначе старые непрочитанные показались бы новыми.
     */
    fun addAccount(a: Account) {
        val existing = accounts.firstOrNull { it.key == a.key }
        val merged = if (existing != null) a.copy(lastNotifiedUid = existing.lastNotifiedUid, lastNotifiedUidNext = existing.lastNotifiedUidNext) else a
        // Повторный вход в тот же ящик: старый токен на сервере иначе жил бы «устройством» ещё 90 дней.
        val old = apis[a.key]
        if (existing != null && existing.token != a.token && old != null) {
            kotlinx.coroutines.CoroutineScope(kotlinx.coroutines.Dispatchers.Default).launch { runCatching { old.logout() } }
        }
        accounts = AccountList.add(accounts, merged)
        // Токен или IP сменились — старый клиент этого ящика не годится.
        apis = apis - a.key
        signedOutReason = null
        if (account?.key == a.key) { account = merged; saveAccounts() } else switchTo(a.key)
    }

    /** Сделать ящик активным. Разделы сбрасываются через [onSwitch], интерфейс перезагружается сам (App: key по ящику). */
    fun switchTo(key: String) {
        val next = accounts.firstOrNull { it.key == key } ?: return
        if (account?.key != key) {
            if (account != null) onSwitch()
            account = next
        }
        saveAccounts()
    }

    /** Изменить запись ящика (имя с сервера, счётчики уведомлений) с сохранением списка. */
    fun updateAccount(key: String, f: (Account) -> Account) {
        val cur = accounts.firstOrNull { it.key == key } ?: return
        val upd = f(cur)
        if (upd == cur) return
        accounts = accounts.map { if (it.key == key) upd else it }
        if (account?.key == key) account = upd
        if (upd.key != key) apis = apis - key
        saveAccounts()
    }

    /**
     * Выход из ящика [key] (по умолчанию — из активного). Отзыв токена на сервере делает вызывающий.
     * Остались другие — активным становится следующий, причину показываем тостом, экран входа не нужен;
     * это был последний — как раньше: экран входа с причиной. Запоздалый 401 от уже убранного ящика ничего не делает.
     */
    fun signOut(reason: String? = null, key: String? = account?.key) {
        val k = key ?: return
        val gone = accounts.firstOrNull { it.key == k } ?: return
        val wasActive = account?.key == k
        val rest = AccountList.remove(accounts, k)
        val next = AccountList.next(accounts, k)
        apis = apis - k
        // Следы ящика на устройстве: письма, закреплённые, показанные напоминания — только его, чужие остаются.
        su.innotec.mail.ui.mail.MailCache.forget(gone)
        su.innotec.mail.ui.mail.Pinned.forget(gone.key)
        Reminders.forget(gone.key)
        when {
            rest.isEmpty() -> {
                // Письма на устройстве — только пока есть хоть один вход.
                su.innotec.mail.platform.DiskCache.clear()
                accounts = rest; account = null
                saveAccounts()
                signedOutReason = reason
                onSignOut()
            }
            wasActive -> {
                onSwitch()
                accounts = rest; account = next
                saveAccounts()
                if (reason != null) su.innotec.mail.ui.Toasts.show("${gone.user}: $reason")
            }
            else -> { accounts = rest; saveAccounts() }
        }
    }

    fun updatePrefs(f: (LocalPrefs) -> LocalPrefs) {
        prefs = f(prefs)
        savePrefs()
    }

    private fun savePrefs() = store.put("prefs", ApiJson.encodeToString(LocalPrefs.serializer(), prefs))

    /** HTTP-клиент для набора «имя → IP»: у ящиков на разных серверах он может быть разным, живут все. */
    fun client(hosts: Map<String, String>): HttpClient =
        clients[hosts] ?: createHttpClient(hosts).also { clients = clients + (hosts to it) }

    /** API любого ящика (фоновая проверка обходит все). Токен читается из списка при каждом запросе — после повторного входа новый. */
    fun apiFor(a: Account): Api =
        apis[a.key] ?: Api(client(a.hosts), a.origin, { accounts.firstOrNull { it.key == a.key }?.token }, a.key).also { apis = apis + (a.key to it) }

    /** API активного ящика; null — не вошли. */
    val api: Api?
        get() = account?.let { apiFor(it) }

    /** API без входа — для обнаружения сервера и самого входа. */
    fun anonymous(origin: String, hosts: Map<String, String>): Api = Api(client(hosts), origin, { null })
}
