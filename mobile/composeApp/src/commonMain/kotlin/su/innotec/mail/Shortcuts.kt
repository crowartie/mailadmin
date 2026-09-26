package su.innotec.mail

import su.innotec.mail.ui.mail.ComposeScreen
import su.innotec.mail.ui.mail.ComposeStart
import su.innotec.mail.ui.mail.MailStore

/**
 * Горячие клавиши версии для ПК. Только с Ctrl и функциональные: одиночные буквы (как j/k/r в веб-почте)
 * перехватывались бы и при наборе текста — окно не знает, в каком поле курсор.
 */
object Shortcuts {
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
}
