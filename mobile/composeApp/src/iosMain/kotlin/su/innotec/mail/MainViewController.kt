package su.innotec.mail

import androidx.compose.ui.window.ComposeUIViewController
import su.innotec.mail.data.Session

/** Точка входа для iPhone: iosApp/iosApp/ContentView.swift вызывает MainViewControllerKt.MainViewController(). */
fun MainViewController() = ComposeUIViewController {
    Session.load()
    App()
}
