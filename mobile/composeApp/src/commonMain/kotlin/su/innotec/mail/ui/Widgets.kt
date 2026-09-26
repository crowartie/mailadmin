package su.innotec.mail.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.SnackbarDuration
import androidx.compose.material3.SnackbarHostState
import androidx.compose.material3.SnackbarResult
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.focus.FocusRequester
import androidx.compose.ui.focus.focusRequester
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.launch
import su.innotec.mail.api.ApiException
import su.innotec.mail.data.Session

// ---------- Сообщения внизу экрана (как «тосты» веб-почты), с «Отменить» ----------

object Toasts {
    val host = SnackbarHostState()
    val scope = CoroutineScope(SupervisorJob() + Dispatchers.Main)

    /**
     * Сообщение с действием («Отменить», «Открыть»), которое ждёт своего времени. Пока оно на экране,
     * обычные сообщения его не сбивают; [expire] — закончить ожидание сразу (как будто время вышло),
     * [dismiss] — снять молча, не выполняя ни действия, ни onTimeout.
     */
    class ActionToast internal constructor(val text: String, val action: String, seconds: Int, val onTimeout: () -> Unit, val onAction: () -> Unit) {
        private val started = kotlin.time.TimeSource.Monotonic.markNow()
        private var limit = seconds * 1000L
        internal var dropped = false
        internal var shown = false
        internal val leftMs: Long get() = (limit - started.elapsedNow().inWholeMilliseconds).coerceAtLeast(0)
        /** Время вышло досрочно: действие уходит на сервер сейчас (приложение уходит в фон). */
        fun expire() { limit = 0; if (shown) host.currentSnackbarData?.dismiss() }
        /** Снять без последствий (выход из аккаунта). */
        fun dismiss() { dropped = true; expire() }
    }

    /** Очередь сообщений с действием: показываются по одному, чтобы окно «Отменить» никто не схлопнул. */
    private val queue = ArrayDeque<ActionToast>()
    private var showing = false
    private var current: ActionToast? = null
    /** Обычные сообщения, пришедшие во время окна «Отменить»: показываются после него, по одному. */
    private val deferred = ArrayList<String>()

    fun show(text: String) {
        scope.launch {
            // Окно «Отменить» на экране: не сбиваем его, сообщение покажем следом.
            if (showing) { if (text !in deferred) deferred.add(text); return@launch }
            host.currentSnackbarData?.dismiss()
            host.showSnackbar(text, withDismissAction = true, duration = SnackbarDuration.Short)
        }
    }

    /** Сообщение с действием; onAction — если нажали, onTimeout — если время вышло. */
    fun action(text: String, action: String, seconds: Int = 5, onTimeout: () -> Unit = {}, onAction: () -> Unit): ActionToast {
        val t = ActionToast(text, action, seconds, onTimeout, onAction)
        scope.launch {
            queue.addLast(t)
            if (showing) return@launch
            showing = true
            try {
                while (true) showOne(queue.removeFirstOrNull() ?: break)
            } finally {
                showing = false
                // Что накопилось за время ожидания — теперь по очереди (каждое само дождётся своего места).
                val rest = deferred.toList(); deferred.clear()
                rest.forEach { show(it) }
            }
        }
        return t
    }

    private suspend fun showOne(t: ActionToast) {
        try {
            host.currentSnackbarData?.dismiss()   // здесь может быть только обычное сообщение
            current = t
            t.shown = true
            while (!t.dropped) {
                val left = t.leftMs
                if (left <= 0L) break
                val r = kotlinx.coroutines.withTimeoutOrNull(left) { host.showSnackbar(t.text, actionLabel = t.action, duration = SnackbarDuration.Indefinite) }
                if (r == SnackbarResult.ActionPerformed) { t.onAction(); return }
                // r == null — время вышло; Dismissed — кто-то снял снэкбар раньше срока: показываем снова на остаток времени.
                if (r == null) break
            }
            if (!t.dropped) t.onTimeout()
        } finally {
            t.shown = false
            current = null
        }
    }

    /** Все ожидающие действия — выполнить сейчас (приложение уходит в фон, процесс могут убить). */
    fun expireAll() { scope.launch { current?.expire(); queue.toList().forEach { it.expire() } } }

    fun error(e: Throwable) {
        if (e is ApiException && e.isAuth) { Session.signOut("Вход устарел или отозван — войдите заново."); return }
        show(e.message ?: "Что-то пошло не так")
    }
}

/** Выполнить запрос с показом ошибки. */
fun CoroutineScope.launchSafe(block: suspend () -> Unit) = launch {
    try { block() } catch (e: kotlinx.coroutines.CancellationException) { throw e } catch (e: Throwable) { Toasts.error(e) }
}

// ---------- Мелкие детали ----------

/** Аватар-инициалы: глубокий тон из палитры (по адресу — один и тот же везде) и белые буквы. */
@Composable
fun Avatar(name: String, key: String = name, size: Dp = 40.dp) {
    val c = P.avatarColor(key)
    Box(Modifier.size(size).clip(CircleShape).background(c), contentAlignment = Alignment.Center) {
        Text(Fmt.initials(name), color = Color.White, fontSize = (size.value * 0.36f).sp, fontWeight = FontWeight.SemiBold)
    }
}

@Composable
fun Loading(modifier: Modifier = Modifier.fillMaxSize()) {
    Box(modifier, contentAlignment = Alignment.Center) { CircularProgressIndicator(strokeWidth = 2.5.dp) }
}

@Composable
fun Empty(icon: String, title: String, text: String? = null, modifier: Modifier = Modifier.fillMaxSize(), action: (@Composable () -> Unit)? = null) {
    Column(modifier.padding(32.dp), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.Center) {
        Ico(icon, size = 40.dp, tint = P.faint)
        Spacer(Modifier.height(12.dp))
        Text(title, style = MaterialTheme.typography.titleMedium, textAlign = TextAlign.Center)
        if (text != null) {
            Spacer(Modifier.height(6.dp))
            Text(text, style = MaterialTheme.typography.bodyMedium, color = P.muted, textAlign = TextAlign.Center)
        }
        if (action != null) { Spacer(Modifier.height(16.dp)); action() }
    }
}

@Composable
fun ErrorBox(message: String, onRetry: () -> Unit, modifier: Modifier = Modifier.fillMaxSize()) {
    Empty("warn", "Не удалось загрузить", message, modifier) { Button(onClick = onRetry) { Text("Повторить") } }
}

/** Фильтр-чип: выбранный — акцентный (оранжевый), остальные — на приглушённой подложке; [count] — счётчик справа. */
@Composable
fun Chip(text: String, selected: Boolean, onClick: () -> Unit, icon: String? = null, modifier: Modifier = Modifier, count: Int = 0) {
    Row(
        modifier.clip(RoundedCornerShape(50)).background(if (selected) P.accent else P.chipOff).clickable(onClick = onClick)
            .padding(horizontal = if (text.isEmpty()) 8.dp else 12.dp, vertical = 6.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        if (icon != null) { Ico(icon, size = 16.dp, tint = if (selected) P.accentOn else P.muted); if (text.isNotEmpty()) Spacer(Modifier.width(6.dp)) }
        if (text.isNotEmpty()) Text(text, style = MaterialTheme.typography.labelLarge, color = if (selected) P.accentOn else P.text, maxLines = 1)
        if (count > 0) {
            Spacer(Modifier.width(6.dp))
            Text(if (count > 999) "999+" else count.toString(), style = MaterialTheme.typography.labelMedium, fontWeight = FontWeight.SemiBold,
                color = if (selected) P.accentOn else P.accentInk)
        }
    }
}

@Composable
fun Badge(n: Int, modifier: Modifier = Modifier, strong: Boolean = true) {
    if (n <= 0) return
    Box(
        modifier.clip(RoundedCornerShape(50)).background(if (strong) P.accent else P.chipOff).padding(horizontal = 7.dp, vertical = 1.dp),
        contentAlignment = Alignment.Center,
    ) { Text(if (n > 999) "999+" else n.toString(), color = if (strong) P.accentOn else P.muted, fontSize = 11.sp, fontWeight = FontWeight.SemiBold) }
}

@Composable
fun SectionTitle(text: String, modifier: Modifier = Modifier) {
    Text(text.uppercase(), modifier.padding(start = 16.dp, end = 16.dp, top = 18.dp, bottom = 6.dp), style = MaterialTheme.typography.labelMedium, color = P.faint, letterSpacing = 0.6.sp)
}

@Composable
fun Divider(modifier: Modifier = Modifier) = HorizontalDivider(modifier, color = P.border, thickness = 1.dp)

/** Строка списка настроек/действий. */
@Composable
fun ListRow(
    title: String,
    subtitle: String? = null,
    icon: String? = null,
    iconTint: Color? = null,
    danger: Boolean = false,
    trailing: (@Composable () -> Unit)? = null,
    onClick: (() -> Unit)? = null,
) {
    Row(
        Modifier.fillMaxWidth().let { if (onClick != null) it.clickable(onClick = onClick) else it }.padding(horizontal = 16.dp, vertical = 12.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        if (icon != null) { Ico(icon, tint = iconTint ?: if (danger) P.no else P.muted); Spacer(Modifier.width(16.dp)) }
        Column(Modifier.weight(1f)) {
            Text(title, style = MaterialTheme.typography.bodyLarge, color = if (danger) P.no else P.text, maxLines = 2, overflow = TextOverflow.Ellipsis)
            if (!subtitle.isNullOrBlank()) Text(subtitle, style = MaterialTheme.typography.bodySmall, color = P.muted, maxLines = 3, overflow = TextOverflow.Ellipsis)
        }
        if (trailing != null) { Spacer(Modifier.width(12.dp)); trailing() }
    }
}

@Composable
fun SwitchRow(title: String, subtitle: String? = null, checked: Boolean, onChange: (Boolean) -> Unit) {
    ListRow(title, subtitle, trailing = { Switch(checked, onChange) }, onClick = { onChange(!checked) })
}

@Composable
fun Card(modifier: Modifier = Modifier, padding: PaddingValues = PaddingValues(0.dp), content: @Composable () -> Unit) {
    Box(modifier.clip(RoundedCornerShape(12.dp)).background(P.surface).border(1.dp, P.border, RoundedCornerShape(12.dp)).padding(padding)) { content() }
}

// ---------- Диалоги ----------

@Composable
fun ConfirmDialog(
    title: String,
    text: String? = null,
    confirm: String = "Да",
    danger: Boolean = false,
    onDismiss: () -> Unit,
    onConfirm: () -> Unit,
) {
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(title) },
        text = text?.let { { Text(it) } },
        confirmButton = {
            Button(
                onClick = { onDismiss(); onConfirm() },
                colors = if (danger) ButtonDefaults.buttonColors(containerColor = P.no, contentColor = Color.White) else ButtonDefaults.buttonColors(),
            ) { Text(confirm) }
        },
        dismissButton = { TextButton(onClick = onDismiss) { Text("Отмена") } },
    )
}

@Composable
fun InputDialog(
    title: String,
    label: String,
    initial: String = "",
    confirm: String = "Сохранить",
    password: Boolean = false,
    keyboard: KeyboardType = KeyboardType.Text,
    hint: String? = null,
    onDismiss: () -> Unit,
    onConfirm: (String) -> Unit,
) {
    var v by remember { mutableStateOf(initial) }
    val focus = remember { FocusRequester() }
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(title) },
        text = {
            Column {
                if (hint != null) { Text(hint, style = MaterialTheme.typography.bodyMedium, color = P.muted); Spacer(Modifier.height(10.dp)) }
                OutlinedTextField(
                    v, { v = it }, label = { Text(label) }, singleLine = true, modifier = Modifier.fillMaxWidth().focusRequester(focus),
                    visualTransformation = if (password) androidx.compose.ui.text.input.PasswordVisualTransformation() else androidx.compose.ui.text.input.VisualTransformation.None,
                    keyboardOptions = KeyboardOptions(keyboardType = if (password) KeyboardType.Password else keyboard),
                )
            }
        },
        confirmButton = { Button(onClick = { onDismiss(); onConfirm(v.trim()) }, enabled = v.isNotBlank()) { Text(confirm) } },
        dismissButton = { TextButton(onClick = onDismiss) { Text("Отмена") } },
    )
    LaunchedEffect(Unit) { runCatching { focus.requestFocus() } }
}

/** Выбор одного варианта из списка. */
@Composable
fun <T> ChoiceDialog(title: String, items: List<T>, label: (T) -> String, selected: T? = null, onDismiss: () -> Unit, onPick: (T) -> Unit) {
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(title) },
        text = {
            androidx.compose.foundation.lazy.LazyColumn(Modifier.widthIn(max = 480.dp)) {
                items(items.size) { i ->
                    val it = items[i]
                    Row(
                        Modifier.fillMaxWidth().clip(RoundedCornerShape(8.dp)).clickable { onDismiss(); onPick(it) }.padding(horizontal = 8.dp, vertical = 12.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Text(label(it), Modifier.weight(1f), fontWeight = if (it == selected) FontWeight.SemiBold else FontWeight.Normal, color = if (it == selected) P.accentInk else P.text)
                        if (it == selected) Ico("check", tint = P.accent)
                    }
                }
            }
        },
        confirmButton = {},
        dismissButton = { TextButton(onClick = onDismiss) { Text("Отмена") } },
    )
}
