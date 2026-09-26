package su.innotec.mail.ui.mail

import kotlinx.serialization.Serializable
import kotlinx.serialization.builtins.ListSerializer
import su.innotec.mail.api.ApiJson
import su.innotec.mail.api.Folder
import su.innotec.mail.api.Message
import su.innotec.mail.api.MessageSummary
import su.innotec.mail.data.Session
import su.innotec.mail.platform.DiskCache

@Serializable
private data class CachedList(val folder: String, val filter: String, val sort: String, val total: Int, val messages: List<MessageSummary>)

@Serializable
private data class CachedMessage(val folder: String, val uid: Long, val message: Message)

/**
 * Что показать сразу, пока сервер отвечает, и без сети: папки, первая страница списков, последние открытые письма.
 * Писем — до [SLOTS] (место по хэшу: новое вытесняет старое с тем же номером), при чтении сверяются папка и номер.
 * Имена файлов — хэш от адреса ящика и ключа: у двух ящиков на одном устройстве кэши не смешиваются.
 */
object MailCache {
    private const val SLOTS = 100

    /** FNV-1a — стабильный между запусками (hashCode() строки для имени файла подходит хуже). */
    private fun name(key: String): String {
        var h = -0x340d631b7bdddcdbL
        for (c in ((Session.account?.user ?: "") + "|" + key)) { h = h xor c.code.toLong(); h *= 0x100000001b3L }
        return h.toULong().toString(16)
    }

    private fun listKey(folder: String, filter: String, sort: String) = "list|$folder|$filter|$sort"

    fun folders(): List<Folder>? = DiskCache.read(name("folders"))?.let { runCatching { ApiJson.decodeFromString(ListSerializer(Folder.serializer()), it) }.getOrNull() }
    fun saveFolders(list: List<Folder>) = DiskCache.write(name("folders"), ApiJson.encodeToString(ListSerializer(Folder.serializer()), list))

    fun list(folder: String, filter: String, sort: String): Pair<List<MessageSummary>, Int>? =
        DiskCache.read(name(listKey(folder, filter, sort)))?.let { t ->
            runCatching { ApiJson.decodeFromString(CachedList.serializer(), t) }.getOrNull()
                ?.takeIf { it.folder == folder && it.filter == filter && it.sort == sort }?.let { it.messages to it.total }
        }

    fun saveList(folder: String, filter: String, sort: String, messages: List<MessageSummary>, total: Int) =
        DiskCache.write(name(listKey(folder, filter, sort)), ApiJson.encodeToString(CachedList.serializer(), CachedList(folder, filter, sort, total, messages.take(100))))

    private fun slot(folder: String, uid: Long) = name("msg|" + ((folder + "|" + uid).hashCode().toUInt() % SLOTS.toUInt()))

    fun message(folder: String, uid: Long): Message? = DiskCache.read(slot(folder, uid))?.let { t ->
        runCatching { ApiJson.decodeFromString(CachedMessage.serializer(), t) }.getOrNull()?.takeIf { it.folder == folder && it.uid == uid }?.message
    }

    fun saveMessage(folder: String, uid: Long, m: Message) =
        DiskCache.write(slot(folder, uid), ApiJson.encodeToString(CachedMessage.serializer(), CachedMessage(folder, uid, m)))
}
