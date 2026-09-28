package su.innotec.mail

import android.content.Context
import androidx.work.CoroutineWorker
import androidx.work.WorkerParameters
import su.innotec.mail.api.ApiException
import su.innotec.mail.data.MailCheck
import su.innotec.mail.data.Session
import su.innotec.mail.platform.AndroidCtx

/**
 * Фоновая проверка почты раз в 15 минут (пока нет push через Firebase — docs/mobile-api.md, раздел 5).
 * Берёт статус «Входящих»; если там есть письма новее последнего показанного — уведомление
 * «отправитель — тема» на каждое (не больше пяти), текст писем через чужие серверы не ходит.
 */
class MailCheckWorker(ctx: Context, params: WorkerParameters) : CoroutineWorker(ctx, params) {
    override suspend fun doWork(): Result {
        AndroidCtx.app = applicationContext
        if (Session.account == null) Session.load()
        if (Session.account == null || !Session.prefs.notify) return Result.success()
        return try {
            // Отозванный вход одного ящика MailCheck убирает сам; сюда доходит только «не ответил ни один» — повторить позже.
            MailCheck.run()
            Result.success()
        } catch (_: ApiException) {
            Result.retry()
        }
    }
}
