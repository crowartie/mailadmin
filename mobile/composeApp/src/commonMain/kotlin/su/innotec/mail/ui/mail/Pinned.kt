package su.innotec.mail.ui.mail

import androidx.compose.runtime.mutableStateListOf
import kotlinx.serialization.Serializable
import kotlinx.serialization.builtins.ListSerializer
import su.innotec.mail.api.ApiJson
import su.innotec.mail.data.Session
import su.innotec.mail.platform.KeyValueStore

/** Письмо «под рукой»: папка и номер — чтобы открыть, тема и отправитель — чтобы узнать в панели папок. */
@Serializable
data class PinnedMessage(val folder: String, val uid: Long, val subject: String = "", val from: String = "")

/**
 * «Держать под рукой» — как вкладки внизу веб-почты (Inbox.vue, holdMessage): письмо с номером счёта
 * или реквизитами, которое нужно перед глазами, пока пишешь другое. Список хранится на устройстве
 * (KeyValueStore «pinned», отдельно для каждого ящика), не больше [MAX] писем; показывается разделом
 * «Под рукой» вверху панели папок.
 */
object Pinned {
    const val MAX = 5
    private val store by lazy { KeyValueStore("pinned") }
    val items = mutableStateListOf<PinnedMessage>()
    private var loadedFor: String? = null

    private val key get() = "list:" + (Session.account?.user ?: "")

    /** Список того ящика, в который вошли (при смене ящика перечитывается). */
    fun load() {
        val user = Session.account?.user ?: return
        if (loadedFor == user) return
        loadedFor = user
        items.clear()
        store.get(key)?.let { runCatching { ApiJson.decodeFromString(ListSerializer(PinnedMessage.serializer()), it) }.getOrNull() }?.let { items.addAll(it) }
    }

    private fun save() { store.put(key, ApiJson.encodeToString(ListSerializer(PinnedMessage.serializer()), items.toList())) }

    fun has(folder: String, uid: Long): Boolean { load(); return items.any { it.folder == folder && it.uid == uid } }

    /** Закрепить или снять. null — места нет (уже [MAX]); иначе — закреплено ли письмо теперь. */
    fun toggle(p: PinnedMessage): Boolean? {
        load()
        val next = pinToggle(items.toList(), p) ?: return null
        val pinned = next.any { it.folder == p.folder && it.uid == p.uid }
        items.clear(); items.addAll(next); save()
        return pinned
    }

    fun remove(folder: String, uid: Long) { load(); items.removeAll { it.folder == folder && it.uid == uid }; save() }
}

/**
 * Чистая часть [Pinned.toggle]: то же письмо — убрать, новое — в начало, не больше [max]
 * (null — места нет: закреплённое надо снять руками, а не вытеснять молча).
 */
fun pinToggle(list: List<PinnedMessage>, p: PinnedMessage, max: Int = Pinned.MAX): List<PinnedMessage>? {
    val rest = list.filterNot { it.folder == p.folder && it.uid == p.uid }
    if (rest.size != list.size) return rest
    if (list.size >= max) return null
    return listOf(p) + list
}
