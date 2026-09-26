package su.innotec.mail

import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import su.innotec.mail.ui.mail.ComposeScreen
import su.innotec.mail.ui.mail.ComposeStart
import su.innotec.mail.ui.mail.MailStore
import su.innotec.mail.ui.mail.MessageScreen

/**
 * Горячие клавиши версии для ПК — набор веб-почты (useHotkeys.js): j/k, r/a/f, e, #, s, u, g i…
 *
 * Сочетания с Ctrl приходят из onPreviewKeyEvent окна и работают всегда. Одиночные буквы — из onKeyEvent:
 * туда клавиша доходит, только если её не съело поле ввода, поэтому при наборе текста они молчат.
 * Клавиши определяются по физической клавише (Key.J), а не по букве — в русской раскладке «о» вместо j
 * иначе ничего бы не делала, как и в веб-почте.
 */
object Shortcuts {
    /** Открыта подсказка «?» (рисует App). */
    var help by mutableStateOf(false)

    /** Для подсказки: клавиша — действие. */
    val HELP: List<Pair<String, String>> = listOf(
        "j / k" to "Следующее / предыдущее письмо",
        "r" to "Ответить",
        "a" to "Ответить всем",
        "f" to "Переслать",
        "c, Ctrl+N" to "Написать",
        "e" to "В архив",
        "#" to "Удалить",
        "s" to "Флажок",
        "u" to "Отметить непрочитанным",
        "g i / g d" to "Входящие / черновики",
        "g s / g t / g a" to "Отправленные / корзина / архив",
        "/ , Ctrl+F" to "Поиск",
        "Esc" to "Закрыть письмо",
        "F5" to "Обновить",
        "?" to "Эта подсказка",
    )

    /** Нажали «g» — следующая буква выбирает папку, если успеть за это время. */
    private const val PREFIX_MS = 1200L
    private var goAt: kotlin.time.TimeSource.Monotonic.ValueTimeMark? = null

    /** Какая клавиша: "N", "R", "F", "F5"…; возвращает, обработано ли. */
    fun handle(key: String, ctrl: Boolean, shift: Boolean): Boolean {
        val uid = MailStore.openUid
        val folder = uid?.let { MailStore.folderOf(it) }
        // Открыто окно «Написать»: второе поверх него не нужно, а Ctrl+F там — не поиск по папке
        // (Nav.go сбросил бы стопку экранов вместе с недописанным письмом).
        val composing = Nav.stack.lastOrNull() is ComposeScreen
        return when {
            ctrl && !shift && key == "N" -> { if (!composing) Nav.push(ComposeScreen(ComposeStart.New())); true }
            ctrl && key == "R" && uid != null && folder != null -> { Nav.push(ComposeScreen(ComposeStart.Reply(folder, uid, all = shift))); true }
            ctrl && shift && key == "F" && uid != null && folder != null -> { Nav.push(ComposeScreen(ComposeStart.Forward(folder, uid))); true }
            ctrl && !shift && key == "F" -> if (composing) false else { Nav.go(Section.MAIL); MailStore.searchSignal++; true }
            key == "F5" -> { MailStore.load(); MailStore.refreshFolders(); true }
            else -> false
        }
    }

    /**
     * Одиночная клавиша без Ctrl (уже известно, что не в поле ввода): "J", "K", "#", "/", "?", "ESCAPE"…
     * В окне «Написать» и вне раздела «Почта» — не наши (кроме подсказки и Esc).
     */
    fun handlePlain(key: String): Boolean {
        if (key == "?") { help = !help; return true }
        if (help) { if (key == "ESCAPE") { help = false; return true }; return false }
        if (Nav.stack.lastOrNull() is ComposeScreen) return false
        if (key == "ESCAPE") {
            // Письмо в правой панели (планшетный вид на ПК): закрыть его. Экраны в стопке закрывает DesktopBack.
            if (Nav.stack.isEmpty() && MailStore.openUid != null) { MailStore.openUid = null; return true }
            return false
        }
        if (Nav.section != Section.MAIL) return false
        val cur = openUid()
        val folder = cur?.let { MailStore.folderOf(it) }
        // Приставка «g»: следующая буква — папка.
        goAt?.let { at ->
            goAt = null
            if (at.elapsedNow().inWholeMilliseconds <= PREFIX_MS) {
                val role = when (key) { "I" -> "inbox"; "D" -> "drafts"; "S" -> "sent"; "T" -> "trash"; "A" -> "archive"; else -> null }
                val path = role?.let { r -> MailStore.folders.firstOrNull { it.role == r && !it.isShared }?.path }
                if (path != null) { Nav.stack.clear(); MailStore.go(path); return true }
            }
        }
        return when (key) {
            "G" -> { goAt = kotlin.time.TimeSource.Monotonic.markNow(); true }
            "J" -> step(cur, 1)
            "K" -> step(cur, -1)
            "R" -> if (cur != null && folder != null) { Nav.push(ComposeScreen(ComposeStart.Reply(folder, cur, all = MailStore.settings.replyAll))); true } else false
            "A" -> if (cur != null && folder != null) { Nav.push(ComposeScreen(ComposeStart.Reply(folder, cur, all = true))); true } else false
            "F" -> if (cur != null && folder != null) { Nav.push(ComposeScreen(ComposeStart.Forward(folder, cur))); true } else false
            "C" -> { Nav.push(ComposeScreen(ComposeStart.New())); true }
            "E" -> act("archive", cur)
            "#" -> act("delete", cur)
            "S" -> cur?.let { u -> MailStore.messages.firstOrNull { it.uid == u }?.let { m -> act(if (m.flagged) "unflag" else "flag", u) } } ?: false
            "U" -> act("unseen", cur)
            "/" -> { MailStore.searchSignal++; true }
            else -> false
        }
    }

    /** Какое письмо открыли экраном клавишей j/k (у MessageScreen uid закрытый): пока этот экран сверху, оно и есть текущее. */
    private var pushed: Long? = null

    /** Открытое письмо: в панели (openUid) или экраном поверх списка. */
    private fun openUid(): Long? = MailStore.openUid ?: pushed?.takeIf { Nav.stack.lastOrNull() is MessageScreen }

    /** j/k: соседнее письмо списка; без открытого — первое. Черновики открывать нечем (это окно «Написать») — пропускаем. */
    private fun step(cur: Long?, delta: Int): Boolean {
        val ids = MailStore.messages.map { it.uid }
        if (ids.isEmpty()) return false
        val idx = ids.indexOf(cur)
        val next = if (idx < 0) ids.first() else ids[(idx + delta).coerceIn(0, ids.lastIndex)]
        if (next == cur) return true
        val m = MailStore.messages.firstOrNull { it.uid == next } ?: return false
        val folder = m.folder ?: MailStore.query.folder
        if (MailStore.folders.firstOrNull { it.path == folder }?.role == "drafts" || m.folderRole == "drafts") return true
        MailStore.markOpened(next)
        // Письмо открыто экраном (узкое окно) — заменяем его; иначе — правая панель.
        if (Nav.stack.lastOrNull() is MessageScreen) { pushed = next; Nav.push(MessageScreen(folder, next)) } else MailStore.openUid = next
        return true
    }

    private fun act(op: String, uid: Long?): Boolean {
        uid ?: return false
        // Письмо уходит из списка — открытая панель с ним закрывается, как при действии из самого письма.
        if (op in setOf("archive", "delete") && MailStore.openUid == uid) MailStore.openUid = null
        MailStore.act(op, listOf(uid))
        return true
    }
}
