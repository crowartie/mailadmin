package su.innotec.mail.data

import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import io.ktor.client.HttpClient
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
)

/** Настройки самого приложения (не ящика): хранятся на устройстве. */
@Serializable
data class LocalPrefs(
    val theme: String = "system",
    val notify: Boolean = true,
    val notifyShared: Boolean = false,
    /** Последний uid во «Входящих», о котором уже сообщили. */
    val lastNotifiedUid: Long = 0,
    val lastNotifiedUidNext: Long = 0,
    val swipeLeft: String = "delete",
    val swipeRight: String = "archive",
    val lastUpdateCheck: Long = 0,
    /** Мгновенные уведомления: проверка раз в минуту фоновой службой (Android), без Firebase. */
    val fastNotify: Boolean = false,
)

object Session {
    private val store by lazy { KeyValueStore("account") }

    var account by mutableStateOf<Account?>(null)
        private set
    var prefs by mutableStateOf(LocalPrefs())
        private set

    /** Причина последнего выхода («вход устарел») — показывается на экране входа. */
    var signedOutReason by mutableStateOf<String?>(null)

    /**
     * Что сделать при выходе, кроме стирания входа: сброс всех разделов и их задач. Регистрирует App —
     * выход случается и из кнопки «Выйти», и из любого ответа 401 (Toasts.error, фоновая проверка почты),
     * и всё это должно проходить через одно место.
     */
    var onSignOut: () -> Unit = {}

    private var http: HttpClient? = null
    private var httpHosts: Map<String, String>? = null
    private var cachedApi: Api? = null

    fun load() {
        account = store.get("account")?.let { runCatching { ApiJson.decodeFromString(Account.serializer(), it) }.getOrNull() }
        prefs = store.get("prefs")?.let { runCatching { ApiJson.decodeFromString(LocalPrefs.serializer(), it) }.getOrNull() } ?: LocalPrefs()
    }

    fun signIn(a: Account) {
        store.put("account", ApiJson.encodeToString(Account.serializer(), a))
        account = a
        cachedApi = null
        signedOutReason = null
    }

    fun signOut(reason: String? = null) {
        val had = account != null
        // Письма на устройстве — только пока вход действует.
        su.innotec.mail.platform.DiskCache.clear()
        store.put("account", null)
        account = null
        cachedApi = null
        // Повторный выход (запоздалый 401 от уже отменённого запроса) причину не перезаписывает и разделы не трогает.
        if (had) signedOutReason = reason
        prefs = prefs.copy(lastNotifiedUid = 0, lastNotifiedUidNext = 0)
        savePrefs()
        if (had) onSignOut()
    }

    fun updatePrefs(f: (LocalPrefs) -> LocalPrefs) {
        prefs = f(prefs)
        savePrefs()
    }

    private fun savePrefs() = store.put("prefs", ApiJson.encodeToString(LocalPrefs.serializer(), prefs))

    fun client(hosts: Map<String, String>): HttpClient {
        if (http == null || httpHosts != hosts) {
            http?.close()
            http = createHttpClient(hosts)
            httpHosts = hosts
        }
        return http!!
    }

    /** API текущего входа; null — не вошли. */
    val api: Api?
        get() {
            val a = account ?: return null
            cachedApi?.let { if (it.origin == a.origin) return it }
            return Api(client(a.hosts), a.origin) { account?.token }.also { cachedApi = it }
        }

    /** API без входа — для обнаружения сервера и самого входа. */
    fun anonymous(origin: String, hosts: Map<String, String>): Api = Api(client(hosts), origin) { null }
}
