package su.innotec.mail

import androidx.compose.ui.input.key.Key
import androidx.compose.ui.input.key.KeyEventType
import androidx.compose.ui.input.key.isCtrlPressed
import androidx.compose.ui.input.key.isShiftPressed
import androidx.compose.ui.input.key.key
import androidx.compose.ui.input.key.type
import androidx.compose.ui.unit.dp
import androidx.compose.ui.window.Window
import androidx.compose.ui.window.application
import androidx.compose.ui.window.rememberWindowState
import su.innotec.mail.data.Session
import su.innotec.mail.platform.DesktopBack

fun main() = application {
    Session.load()
    Window(
        onCloseRequest = ::exitApplication,
        title = "Почта",
        state = rememberWindowState(width = 1280.dp, height = 820.dp),
        onPreviewKeyEvent = { e ->
            if (e.type != KeyEventType.KeyDown) false
            else if (e.key == Key.Escape) DesktopBack.back()
            else {
                val name = when (e.key) { Key.N -> "N"; Key.R -> "R"; Key.F -> "F"; Key.F5 -> "F5"; else -> "" }
                name.isNotEmpty() && Shortcuts.handle(name, e.isCtrlPressed, e.isShiftPressed)
            }
        },
    ) {
        App()
    }
}
