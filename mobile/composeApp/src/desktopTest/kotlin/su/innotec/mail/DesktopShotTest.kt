package su.innotec.mail

import androidx.compose.ui.graphics.toAwtImage
import androidx.compose.ui.test.ExperimentalTestApi
import androidx.compose.ui.test.onNodeWithTag
import androidx.compose.ui.test.onRoot
import androidx.compose.ui.test.captureToImage
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.runDesktopComposeUiTest
import su.innotec.mail.api.ApiJson
import su.innotec.mail.data.Account
import su.innotec.mail.data.Session
import su.innotec.mail.platform.KeyValueStore
import su.innotec.mail.ui.mail.MailStore
import java.io.File
import javax.imageio.ImageIO
import kotlin.test.Test
import kotlin.test.assertTrue

/**
 * Версия для ПК «вживую»: окно 1400×860 с тестовым токеном, снимки в mobile/build/shots/.
 * Без токена (MAILADMIN_TEST_TOKEN) — только экран входа.
 */
@OptIn(ExperimentalTestApi::class)
class DesktopShotTest {
    private val out = File("build/shots").apply { mkdirs() }

    @Test
    fun screens() = runDesktopComposeUiTest(1400, 860) {
        val home = File(System.getProperty("java.io.tmpdir"), "mailadmin-shot-" + System.nanoTime()).apply { mkdirs() }
        System.setProperty("mailadmin.home", home.absolutePath)
        val token = System.getenv("MAILADMIN_TEST_TOKEN")
        if (!token.isNullOrBlank()) {
            KeyValueStore("account").put("account", ApiJson.encodeToString(Account.serializer(),
                Account(origin = System.getenv("MAILADMIN_TEST_SERVER") ?: "https://mail.innotec.su", token = token, user = "", name = "")))
        }
        Session.load()
        setContent { App() }
        fun shot(name: String) {
            waitForIdle()
            ImageIO.write(onRoot().captureToImage().toAwtImage(), "png", File(out, "$name.png"))
            println("  · снимок ${File(out, "$name.png").absolutePath}")
        }
        if (token.isNullOrBlank()) { shot("desktop-login"); return@runDesktopComposeUiTest }
        waitUntil(timeoutMillis = 30_000) { MailStore.messages.isNotEmpty() }
        shot("desktop-inbox")
        val first = MailStore.messages.first().uid
        onNodeWithTag("row-$first").performClick()
        waitUntil(timeoutMillis = 30_000) { runCatching { onNodeWithTag("msg-subject").fetchSemanticsNode(); true }.getOrDefault(false) }
        shot("desktop-message")
        assertTrue(MailStore.openUid == first)
        home.deleteRecursively()
    }
}
