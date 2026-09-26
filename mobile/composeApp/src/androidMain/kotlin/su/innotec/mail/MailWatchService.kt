package su.innotec.mail

import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.app.Service
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.pm.ServiceInfo
import android.net.ConnectivityManager
import android.net.NetworkCapabilities
import android.os.Build
import android.os.IBinder
import androidx.core.app.NotificationCompat
import androidx.core.app.ServiceCompat
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.delay
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch
import su.innotec.mail.api.ApiException
import su.innotec.mail.data.MailCheck
import su.innotec.mail.data.Session
import su.innotec.mail.platform.AndroidCtx

/**
 * «Мгновенные уведомления» без Firebase: служба с тихим постоянным значком проверяет «Входящие» раз в минуту
 * (запрос статуса — сотни байт) и показывает уведомление о новом письме. Без сети не ходит, при ошибке сети
 * ждёт дольше. WorkManager раз в 15 минут остаётся запасным путём, если система службу всё же остановит.
 */
class MailWatchService : Service() {
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private var loop: Job? = null

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        AndroidCtx.app = applicationContext
        if (Session.account == null) Session.load()
        if (Session.account == null || !Session.prefs.fastNotify || !Session.prefs.notify) { stopSelf(); return START_NOT_STICKY }
        ServiceCompat.startForeground(this, ID, ongoing(), if (Build.VERSION.SDK_INT >= 34) ServiceInfo.FOREGROUND_SERVICE_TYPE_SPECIAL_USE else 0)
        if (loop?.isActive != true) loop = scope.launch {
            var wait = 60_000L
            while (isActive) {
                if (online()) {
                    wait = try {
                        MailCheck.run(); 60_000L
                    } catch (e: ApiException) {
                        if (e.isAuth) { Session.signOut("Вход устарел или отозван — войдите заново."); stopSelf(); return@launch }
                        (wait * 2).coerceAtMost(600_000L)   // сервер недоступен — реже, до 10 минут
                    } catch (_: Throwable) {
                        (wait * 2).coerceAtMost(600_000L)
                    }
                }
                delay(wait)
            }
        }
        return START_STICKY
    }

    override fun onDestroy() {
        scope.coroutineContext[Job]?.cancel()
        super.onDestroy()
    }

    private fun online(): Boolean {
        val cm = getSystemService(ConnectivityManager::class.java) ?: return true
        return cm.getNetworkCapabilities(cm.activeNetwork)?.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET) == true
    }

    /** Тихий постоянный значок — без него система не даст службе работать; по нажатию — открыть приложение. */
    private fun ongoing(): Notification {
        val nm = getSystemService(NotificationManager::class.java)
        if (Build.VERSION.SDK_INT >= 26 && nm.getNotificationChannel(CHANNEL) == null) {
            nm.createNotificationChannel(NotificationChannel(CHANNEL, "Слежение за почтой", NotificationManager.IMPORTANCE_MIN).apply {
                description = "Постоянный значок мгновенных уведомлений; его можно скрыть в настройках уведомлений"
                setShowBadge(false)
            })
        }
        val open = PendingIntent.getActivity(this, 0, Intent(this, MainActivity::class.java), PendingIntent.FLAG_IMMUTABLE)
        return NotificationCompat.Builder(this, CHANNEL)
            .setSmallIcon(R.drawable.ic_stat_mail)
            .setContentTitle("Почта следит за новыми письмами")
            .setContentText("Уведомления приходят в течение минуты")
            .setOngoing(true).setSilent(true).setPriority(NotificationCompat.PRIORITY_MIN)
            .setContentIntent(open)
            .build()
    }

    companion object {
        private const val ID = 4201
        private const val CHANNEL = "watch"

        fun sync(ctx: Context) {
            val on = Session.account != null && Session.prefs.notify && Session.prefs.fastNotify
            val i = Intent(ctx, MailWatchService::class.java)
            if (on) runCatching { androidx.core.content.ContextCompat.startForegroundService(ctx, i) } else ctx.stopService(i)
        }
    }
}

/** После перезагрузки и обновления приложения — снова следить, если включено. */
class WatchBootReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        AndroidCtx.app = context.applicationContext
        if (Session.account == null) Session.load()
        MailWatchService.sync(context)
    }
}
