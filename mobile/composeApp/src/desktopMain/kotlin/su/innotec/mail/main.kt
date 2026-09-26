package su.innotec.mail

import androidx.compose.runtime.LaunchedEffect
import androidx.compose.ui.input.key.Key
import androidx.compose.ui.input.key.KeyEvent
import androidx.compose.ui.input.key.KeyEventType
import androidx.compose.ui.input.key.isAltPressed
import androidx.compose.ui.input.key.isCtrlPressed
import androidx.compose.ui.input.key.isMetaPressed
import androidx.compose.ui.input.key.isShiftPressed
import androidx.compose.ui.input.key.key
import androidx.compose.ui.input.key.type
import androidx.compose.ui.unit.dp
import androidx.compose.ui.window.Window
import androidx.compose.ui.window.application
import androidx.compose.ui.window.rememberWindowState
import kotlinx.coroutines.delay
import su.innotec.mail.data.MailCheck
import su.innotec.mail.data.Session
import su.innotec.mail.platform.DesktopBack

/** Имя одиночной клавиши для Shortcuts.handlePlain — по физической клавише, «#» и «?» — с Shift. */
private fun plainName(e: KeyEvent): String = when (e.key) {
    Key.J -> "J"; Key.K -> "K"; Key.R -> "R"; Key.A -> "A"; Key.F -> "F"; Key.C -> "C"; Key.E -> "E"; Key.S -> "S"; Key.U -> "U"
    Key.G -> "G"; Key.I -> "I"; Key.D -> "D"; Key.T -> "T"
    Key.Three -> if (e.isShiftPressed) "#" else ""
    Key.Slash -> if (e.isShiftPressed) "?" else "/"
    Key.Delete -> "#"
    Key.Escape -> "ESCAPE"
    else -> ""
}

fun main() = application {
    Session.load()
    // Уведомления на ПК: пока программа запущена, раз в минуту проверяем «Входящие» (на Android это делают
    // служба и WorkManager). Показ — через значок в области уведомлений (Notifier на ПК).
    LaunchedEffect(Unit) {
        while (true) {
            delay(60_000)
            if (Session.account == null || !Session.prefs.notify) continue
            try {
                MailCheck.run()
            } catch (e: kotlinx.coroutines.CancellationException) {
                throw e
            } catch (_: Throwable) {
                // Нет связи или сервер не ответил — попробуем через минуту; отозванный вход обработает сам список писем.
            }
        }
    }
    Window(
        onCloseRequest = ::exitApplication,
        title = "Почта",
        state = rememberWindowState(width = 1280.dp, height = 820.dp),
        onPreviewKeyEvent = { e ->
            if (e.type != KeyEventType.KeyDown) false
            else if (e.key == Key.Escape) DesktopBack.back() || Shortcuts.handlePlain("ESCAPE")
            else {
                val name = when (e.key) { Key.N -> "N"; Key.R -> "R"; Key.F -> "F"; Key.F5 -> "F5"; else -> "" }
                name.isNotEmpty() && (e.isCtrlPressed || e.key == Key.F5) && Shortcuts.handle(name, e.isCtrlPressed, e.isShiftPressed)
            }
        },
        // Не «preview»: сюда клавиша доходит, только если поле ввода её не съело — так j/k/r не срабатывают при наборе текста.
        onKeyEvent = { e ->
            if (e.type != KeyEventType.KeyDown || e.isCtrlPressed || e.isMetaPressed || e.isAltPressed) false
            else plainName(e).let { it.isNotEmpty() && Shortcuts.handlePlain(it) }
        },
    ) {
        App()
    }
}
