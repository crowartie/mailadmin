package su.innotec.mail

import android.app.Application
import android.content.Intent
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.SystemBarStyle
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import su.innotec.mail.data.Session
import su.innotec.mail.platform.AndroidCtx
import java.lang.ref.WeakReference

class MailApp : Application() {
    override fun onCreate() {
        super.onCreate()
        AndroidCtx.app = this
        Session.load()
    }
}

class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        enableEdgeToEdge(
            statusBarStyle = SystemBarStyle.auto(android.graphics.Color.TRANSPARENT, android.graphics.Color.TRANSPARENT),
            navigationBarStyle = SystemBarStyle.auto(android.graphics.Color.TRANSPARENT, android.graphics.Color.TRANSPARENT),
        )
        super.onCreate(savedInstanceState)
        AndroidCtx.activity = WeakReference(this)
        handle(intent)
        setContent { App() }
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        handle(intent)
    }

    override fun onResume() {
        super.onResume()
        AndroidCtx.activity = WeakReference(this)
    }

    /** Нажали на уведомление — открыть письмо; «Поделиться» / mailto: из других программ — новое письмо. */
    private fun handle(i: Intent?) {
        i ?: return
        when (i.action) {
            // Только отладочная сборка: автотест передаёт готовый токен (ux/_mdev.py), пароль в интерфейс не вводится.
            "su.innotec.mail.DEBUG_LOGIN" -> if (applicationInfo.flags and android.content.pm.ApplicationInfo.FLAG_DEBUGGABLE != 0) {
                val origin = i.getStringExtra("origin") ?: return
                val token = i.getStringExtra("token") ?: return
                Session.signIn(su.innotec.mail.data.Account(origin = origin, token = token, user = i.getStringExtra("user") ?: "", name = i.getStringExtra("name") ?: ""))
                i.getStringExtra("theme")?.let { t -> Session.updatePrefs { it.copy(theme = t) } }
            }
            "open-message" -> {
                val folder = i.getStringExtra("folder") ?: return
                val uid = i.getLongExtra("uid", 0)
                if (uid > 0) DeepLink.pending = folder to uid
            }
            Intent.ACTION_SENDTO, Intent.ACTION_VIEW -> i.data?.toString()?.takeIf { it.startsWith("mailto:", true) }?.let { DeepLink.mailto = it }
            Intent.ACTION_SEND -> {
                val text = i.getStringExtra(Intent.EXTRA_TEXT) ?: ""
                val subject = i.getStringExtra(Intent.EXTRA_SUBJECT) ?: ""
                DeepLink.mailto = "mailto:?subject=" + java.net.URLEncoder.encode(subject, "UTF-8").replace("+", "%20") +
                    "&body=" + java.net.URLEncoder.encode(text, "UTF-8").replace("+", "%20")
            }
        }
    }
}
