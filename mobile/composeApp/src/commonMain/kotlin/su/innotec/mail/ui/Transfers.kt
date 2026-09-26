package su.innotec.mail.ui

import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import io.ktor.utils.io.readAvailable
import kotlinx.io.readByteArray
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.launch
import su.innotec.mail.api.ApiException
import su.innotec.mail.data.Session
import su.innotec.mail.platform.FileStore
import su.innotec.mail.platform.SavedFile

/** Скачивания: вложения, файлы облака, архивы. Прогресс — в полосе над нижней панелью. */
object Transfers {
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.Main)

    var active by mutableStateOf<String?>(null); private set
    var progress by mutableStateOf(0f); private set

    enum class Then { OPEN, SAVE, SHARE }

    /** path — путь API (/message/…/attachment/1, /cloud/file?path=…). */
    fun fetch(path: String, name: String, then: Then, onDone: (SavedFile) -> Unit = {}) {
        val api = Session.api ?: return
        if (active != null) { Toasts.show("Дождитесь окончания загрузки «$active»"); return }
        active = name
        progress = 0f
        scope.launch {
            try {
                val saved = api.download(path) { len, type, serverName, ch ->
                    FileStore.save(serverName ?: name, type, ch, len, { n -> if (len != null && len > 0) progress = (n.toFloat() / len).coerceIn(0f, 1f) }, forOpen = then != Then.SAVE)
                }
                when (then) {
                    Then.OPEN -> if (!FileStore.open(saved)) Toasts.show("Нет программы, чтобы открыть «${saved.name}»")
                    Then.SHARE -> FileStore.share(saved)
                    Then.SAVE -> Toasts.action("«${saved.name}» сохранён в «Загрузки»", "Открыть", 6) { FileStore.open(saved) }
                }
                onDone(saved)
            } catch (e: ApiException) {
                Toasts.error(e)
            } catch (e: Throwable) {
                Toasts.show("Не удалось сохранить файл: ${e.message ?: e::class.simpleName}")
            } finally {
                active = null
            }
        }
    }

    /** Картинка письма для WebView: /mail/api/… из HTML → /api/v1/… с токеном. */
    suspend fun inlineResource(path: String): Pair<String, ByteArray>? {
        val api = Session.api ?: return null
        val p = when {
            path.startsWith("/mail/api/") -> path.removePrefix("/mail/api")
            path.startsWith("/api/v1/") -> path.removePrefix("/api/v1")
            else -> return null
        }
        return runCatching {
            api.download(p) { _, type, _, ch ->
                val out = kotlinx.io.Buffer()
                val buf = ByteArray(32 * 1024)
                while (true) {
                    val r = ch.readAvailable(buf, 0, buf.size)
                    if (r == -1) break
                    if (r > 0) out.write(buf, 0, r)
                    if (r == 0 && ch.isClosedForRead) break
                }
                (type ?: "application/octet-stream") to out.readByteArray()
            }
        }.getOrNull()
    }
}
