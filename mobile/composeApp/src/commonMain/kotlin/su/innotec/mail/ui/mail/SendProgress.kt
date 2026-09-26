package su.innotec.mail.ui.mail

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import su.innotec.mail.ui.Fmt
import su.innotec.mail.ui.Ico
import su.innotec.mail.ui.P

/**
 * Что сейчас уходит на сервер: письмо или черновик с файлами. Тяжёлое письмо грузится минуты,
 * и без этой плашки казалось, что «Отправить» ничего не сделала (обращение по приложению 26.09).
 */
object SendProgress {
    var title by mutableStateOf<String?>(null); private set
    var sent by mutableStateOf(0L); private set
    var total by mutableStateOf<Long?>(null); private set
    /** Файлы загружены, сервер собирает письмо и кладёт крупные вложения в облако. */
    var serverWork by mutableStateOf(false); private set
    private var draft = false

    /** Показывать плашку, только если есть что грузить: текстовое письмо уходит за доли секунды. */
    fun start(subject: String, isDraft: Boolean, heavy: Boolean) {
        if (!heavy) return
        title = subject.ifBlank { "(без темы)" }; draft = isDraft; sent = 0; total = null; serverWork = false
    }

    fun upload(bytes: Long, length: Long?) {
        if (title == null) return
        sent = bytes; total = length
        if (length != null && length > 0 && bytes >= length) serverWork = true
    }

    fun done() { title = null; serverWork = false }

    fun label(): String {
        val what = if (draft) "Сохраняется черновик" else "Отправляется"
        val t = total
        return when {
            serverWork -> "$what «$title» — сервер кладёт крупные файлы в облако…"
            t != null && t > 0 -> "$what «$title» — ${Fmt.size(sent)} из ${Fmt.size(t)}"
            else -> "$what «$title»…"
        }
    }
}

/** Плашка внизу экрана над кнопкой «Написать»: видна на любом экране, пока письмо уходит. */
@Composable
fun SendProgressBar(modifier: Modifier = Modifier) {
    val s = SendProgress
    if (s.title == null) return
    Surface(modifier.widthIn(max = 560.dp).fillMaxWidth().padding(horizontal = 12.dp).testTag("send-progress"), shape = RoundedCornerShape(12.dp), color = P.surface, shadowElevation = 6.dp) {
        Column(Modifier.padding(horizontal = 14.dp, vertical = 10.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                if (s.serverWork) CircularProgressIndicator(Modifier.size(16.dp), strokeWidth = 2.dp) else Ico("upload", size = 16.dp, tint = P.accent)
                Spacer(Modifier.width(10.dp))
                Text(s.label(), style = MaterialTheme.typography.bodyMedium, maxLines = 2, overflow = TextOverflow.Ellipsis)
            }
            val t = s.total
            Spacer(Modifier.width(1.dp))
            if (s.serverWork || t == null || t <= 0) LinearProgressIndicator(Modifier.fillMaxWidth().padding(top = 8.dp).clip(RoundedCornerShape(2.dp)), color = P.accent, trackColor = P.border)
            else LinearProgressIndicator(progress = { (s.sent.toFloat() / t).coerceIn(0f, 1f) }, modifier = Modifier.fillMaxWidth().padding(top = 8.dp).clip(RoundedCornerShape(2.dp)).background(P.border), color = P.accent, trackColor = P.border)
        }
    }
}
