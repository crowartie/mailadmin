package su.innotec.mail

import su.innotec.mail.ui.mail.ComposeScreen
import su.innotec.mail.ui.mail.MailStore
import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertFalse
import kotlin.test.assertTrue

/** Горячие клавиши ПК: что открывают и что пропускают дальше (набор текста не перехватывается). */
class ShortcutsTest {
    @Test
    fun keys() {
        Nav.reset()
        assertTrue(Shortcuts.handle("N", ctrl = true, shift = false))
        assertTrue(Nav.stack.lastOrNull() is ComposeScreen, "Ctrl+N — новое письмо")
        Nav.reset()
        // Без открытого письма ответить некому — клавиша уходит дальше.
        MailStore.openUid = null
        assertFalse(Shortcuts.handle("R", ctrl = true, shift = false))
        // Буква без Ctrl — это набор текста.
        assertFalse(Shortcuts.handle("N", ctrl = false, shift = false))
        val before = MailStore.searchSignal
        assertTrue(Shortcuts.handle("F", ctrl = true, shift = false))
        assertEquals(before + 1, MailStore.searchSignal, "Ctrl+F — поиск")
        assertEquals(Section.MAIL, Nav.section)
    }

    @Test
    fun composeWindowStaysSingle() {
        Nav.reset()
        assertTrue(Shortcuts.handle("N", ctrl = true, shift = false))
        // Второе Ctrl+N поверх открытого письма — клавиша съедается, второго окна нет.
        assertTrue(Shortcuts.handle("N", ctrl = true, shift = false))
        assertEquals(1, Nav.stack.size, "одно окно «Написать»")
        // Ctrl+F в окне письма — не поиск по папке: стопка экранов (и недописанное письмо) остаётся.
        val before = MailStore.searchSignal
        assertFalse(Shortcuts.handle("F", ctrl = true, shift = false))
        assertEquals(before, MailStore.searchSignal)
        assertEquals(1, Nav.stack.size)
        Nav.reset()
    }
}
