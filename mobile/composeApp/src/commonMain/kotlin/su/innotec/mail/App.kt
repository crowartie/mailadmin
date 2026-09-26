package su.innotec.mail

import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.foundation.gestures.detectTapGestures
import androidx.compose.ui.Alignment
import androidx.compose.ui.zIndex
import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.togetherWith
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.BoxWithConstraints
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.WindowInsets
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.NavigationBar
import androidx.compose.material3.NavigationBarItem
import androidx.compose.material3.NavigationBarItemDefaults
import androidx.compose.material3.NavigationRail
import androidx.compose.material3.NavigationRailItem
import androidx.compose.material3.NavigationRailItemDefaults
import androidx.compose.material3.Scaffold
import androidx.compose.material3.SnackbarHost
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.runtime.staticCompositionLocalOf
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.unit.dp
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.compose.LifecycleEventEffect
import su.innotec.mail.data.Session
import su.innotec.mail.platform.BackHandler
import su.innotec.mail.platform.Notifier
import su.innotec.mail.ui.Badge
import su.innotec.mail.ui.Ico
import su.innotec.mail.ui.MailTheme
import su.innotec.mail.ui.P
import su.innotec.mail.ui.Toasts
import su.innotec.mail.ui.calendar.CalStore
import su.innotec.mail.ui.calendar.CalendarHome
import su.innotec.mail.ui.cloud.CloudHome
import su.innotec.mail.ui.cloud.CloudStore
import su.innotec.mail.ui.cloud.CloudUploads
import su.innotec.mail.ui.contacts.ContactsHome
import su.innotec.mail.ui.contacts.ContactsStore
import su.innotec.mail.ui.login.LoginScreen
import su.innotec.mail.ui.mail.MailHome
import su.innotec.mail.ui.mail.MailStore
import su.innotec.mail.ui.more.MoreHome

enum class Section(val title: String, val icon: String) {
    MAIL("Почта", "mail"),
    CALENDAR("Календарь", "cal"),
    CONTACTS("Контакты", "users"),
    CLOUD("Облако", "cloud"),
    MORE("Ещё", "menu"),
}

/** Экран поверх раздела (письмо, окно «Написать», карточка контакта…). */
abstract class Screen {
    /** Во весь экран даже на планшете (окно «Написать»). */
    open val fullScreen: Boolean get() = false
    /** Такой же экран сверху заменяется, а не копится: «Назад» из письма ведёт в папку, а не по истории переходов. */
    open val replacesSame: Boolean get() = false
    @Composable abstract fun Content()
}

/** Навигация: раздел и стопка экранов поверх него. */
object Nav {
    var section by mutableStateOf(Section.MAIL)
    val stack = mutableStateListOf<Screen>()

    fun push(s: Screen) {
        val top = stack.lastOrNull()
        if (s.replacesSame && top != null && top::class == s::class) stack[stack.lastIndex] = s else stack.add(s)
    }
    fun pop(): Boolean = if (stack.isNotEmpty()) { stack.removeAt(stack.lastIndex); true } else false
    fun replace(s: Screen) { if (stack.isNotEmpty()) stack.removeAt(stack.lastIndex); stack.add(s) }
    fun go(section: Section) { stack.clear(); this.section = section }
    fun reset() { stack.clear(); section = Section.MAIL }
}

/** Ширина окна: телефон / планшет (две панели) / широкий (три панели). */
enum class WindowKind { PHONE, TABLET, WIDE }

val LocalWindow = staticCompositionLocalOf { WindowKind.PHONE }

/** Открыть письмо по уведомлению: MainActivity кладёт сюда папку и uid. */
object DeepLink {
    var pending by mutableStateOf<Pair<String, Long>?>(null)
    /** mailto: из других программ или «Поделиться» → новое письмо. */
    var mailto by mutableStateOf<String?>(null)
}

/**
 * Полный сброс при выходе — одно место на все разделы. Session зовёт его при любом выходе: кнопка «Выйти»,
 * отозванный токен (401 в любом запросе), фоновая проверка почты. Здесь, а не в Session: разделы — часть UI.
 */
private fun resetOnSignOut() {
    Nav.reset()
    MailStore.reset()
    ContactsStore.reset()
    CalStore.reset()
    CloudStore.reset()
    // Список загрузок облака чистим; сами корутины CloudUploads снаружи не отменить — с отозванным токеном они
    // упрутся в 401 и остановятся сами.
    CloudUploads.jobs.clear()
    Notifier.fast(false)
}

@Composable
fun App() {
    // Регистрация один раз: выход из любого места проходит через resetOnSignOut.
    DisposableEffect(Unit) {
        Session.onSignOut = ::resetOnSignOut
        onDispose { Session.onSignOut = {} }
    }
    // Приложение ушло в фон (Android: экран закрыт или свернули; ПК: окно свернули): окна «Отменить» закрываем,
    // отложенные удаления и отправку шлём на сервер сейчас — процесс могут убить, а действие уже показано сделанным.
    LifecycleEventEffect(Lifecycle.Event.ON_STOP) {
        MailStore.flushPending()
        Toasts.expireAll()
    }
    MailTheme(Session.prefs.theme, Session.prefs.scheme) {
        Box(Modifier.fillMaxSize().background(P.bg)) {
            val acc = Session.account
            if (acc == null) {
                // Вход мог отозваться, пока приложение не было на экране (фоновая служба) — сброс повторяем здесь.
                LaunchedEffect(Unit) { resetOnSignOut() }
                LoginScreen()
            } else {
                LaunchedEffect(acc.origin, acc.user) {
                    Notifier.ensurePermission()
                    Notifier.schedule(Session.prefs.notify)
                    Notifier.fast(Session.prefs.fastNotify)
                    su.innotec.mail.ui.more.Updates.checkQuietly(offer = true)
                    // Имя и адрес — с сервера, если вход сохранён без них (и заодно проверка, что токен жив).
                    runCatching { Session.api!!.me() }.onSuccess { me ->
                        if (me.user != acc.user || me.name != acc.name) Session.signIn(acc.copy(user = me.user, name = me.name))
                    }.onFailure { if (it is su.innotec.mail.api.ApiException && it.isAuth) Session.signOut("Вход устарел или отозван — войдите заново.") }
                    // Тема — настройка ящика (как в веб-почте): при входе берём её с сервера, при смене пишем туда (Settings.kt).
                    runCatching { Session.api!!.settings() }.onSuccess { s ->
                        if (s.theme in setOf("light", "dark", "system") && s.theme != Session.prefs.theme) Session.updatePrefs { it.copy(theme = s.theme) }
                        if (s.scheme in setOf("brand", "classic") && s.scheme != Session.prefs.scheme) Session.updatePrefs { it.copy(scheme = s.scheme) }
                    }
                }
                // Напоминания о встречах, пока приложение открыто: раз в минуту статус «Входящих» (в нём же reminders),
                // системное уведомление и подсказка внизу. В фоне то же делает MailCheck (Android) и опрос main.kt (ПК).
                LaunchedEffect(acc.origin, acc.user) {
                    while (true) {
                        kotlinx.coroutines.delay(60_000)
                        try {
                            val fresh = su.innotec.mail.data.Reminders.poll()
                            if (fresh > 0) Toasts.show(if (fresh == 1) "Напоминание о встрече — смотрите уведомление" else "Напоминания о встречах: $fresh")
                        } catch (e: kotlinx.coroutines.CancellationException) {
                            throw e
                        } catch (_: Throwable) {
                            // Нет связи — попробуем через минуту; отозванный вход обработает список писем.
                        }
                    }
                }
                Main()
                if (Shortcuts.help) HotkeysHelp { Shortcuts.help = false }
            }
        }
    }
}

@Composable
private fun Main() {
    val focus = androidx.compose.ui.platform.LocalFocusManager.current
    val keyboard = androidx.compose.ui.platform.LocalSoftwareKeyboardController.current
    // Касание пустого места (не кнопки и не поля) убирает клавиатуру, как в почтовых приложениях:
    // на планшете без кнопок иначе её не убрать, не выходя с экрана.
    BoxWithConstraints(Modifier.fillMaxSize().pointerInput(Unit) { detectTapGestures { focus.clearFocus(); keyboard?.hide() } }) {
        val kind = when {
            maxWidth >= 1100.dp -> WindowKind.WIDE
            // Планшет вертикально (~740 dp) — как телефон: две колонки там слишком узкие для письма.
            maxWidth >= 900.dp -> WindowKind.TABLET
            else -> WindowKind.PHONE
        }
        CompositionLocalProvider(LocalWindow provides kind) {
            BackHandler(Nav.stack.isNotEmpty()) { Nav.pop() }
            BackHandler(Nav.stack.isEmpty() && Nav.section != Section.MAIL) { Nav.go(Section.MAIL) }
            val top = Nav.stack.lastOrNull()
            su.innotec.mail.ui.mail.SenderRuleHost()
            Scaffold(
                snackbarHost = { SnackbarHost(Toasts.host, Modifier.navigationBarsPadding()) },
                containerColor = P.bg,
                contentWindowInsets = WindowInsets(0),
                bottomBar = {
                    if (kind == WindowKind.PHONE && top == null) BottomBar()
                },
            ) { pad ->
                Row(Modifier.fillMaxSize().padding(pad)) {
                    if (kind != WindowKind.PHONE && top?.fullScreen != true) Rail()
                    // Вложенным экранам и планшету (нет нижней панели) — отступ под системную полоску жестов.
                    Box(Modifier.weight(1f).fillMaxHeight().then(if (top != null || kind != WindowKind.PHONE) Modifier.navigationBarsPadding() else Modifier)) {
                        // Ход отправки тяжёлого письма — поверх любого экрана, над кнопкой «Написать».
                        su.innotec.mail.ui.mail.SendProgressBar(Modifier.align(Alignment.BottomCenter).padding(bottom = 96.dp).zIndex(5f))
                        AnimatedContent(targetState = top ?: Nav.section, transitionSpec = { fadeIn() togetherWith fadeOut() }, label = "nav") { s ->
                            when (s) {
                                is Screen -> s.Content()
                                Section.MAIL -> MailHome()
                                Section.CALENDAR -> CalendarHome()
                                Section.CONTACTS -> ContactsHome()
                                Section.CLOUD -> CloudHome()
                                Section.MORE -> MoreHome()
                                else -> {}
                            }
                        }
                    }
                }
            }
        }
    }
}

/** Подсказка по горячим клавишам ПК («?» — как в веб-почте). */
@Composable
private fun HotkeysHelp(onDismiss: () -> Unit) {
    androidx.compose.material3.AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text("Горячие клавиши") },
        text = {
            Column(Modifier.verticalScroll(rememberScrollState())) {
                Shortcuts.HELP.forEach { (keys, what) ->
                    Row(Modifier.padding(vertical = 3.dp)) {
                        Text(keys, Modifier.width(96.dp), style = MaterialTheme.typography.bodyMedium, color = P.accentInk)
                        Text(what, style = MaterialTheme.typography.bodyMedium)
                    }
                }
            }
        },
        confirmButton = { androidx.compose.material3.TextButton(onClick = onDismiss) { Text("Закрыть") } },
    )
}

@Composable
private fun BottomBar() {
    NavigationBar(containerColor = P.surface, tonalElevation = 0.dp, modifier = Modifier.testTag("bottom-bar")) {
        Section.entries.forEach { s ->
            NavigationBarItem(
                selected = Nav.section == s,
                onClick = { if (Nav.section == s) MailStore.scrollTopSignal++ ; Nav.go(s) },
                icon = {
                    Box {
                        Ico(s.icon, size = 22.dp)
                        if (s == Section.MAIL) Badge(MailStore.inboxUnread, Modifier.padding(start = 14.dp))
                    }
                },
                label = { Text(s.title, style = MaterialTheme.typography.labelSmall) },
                colors = NavigationBarItemDefaults.colors(indicatorColor = P.accentSoft, selectedIconColor = P.accentInk, selectedTextColor = P.accentInk, unselectedIconColor = P.muted, unselectedTextColor = P.muted),
            )
        }
    }
}

@Composable
private fun Rail() {
    NavigationRail(containerColor = P.surface, modifier = Modifier.statusBarsPadding().width(84.dp)) {
        Section.entries.forEach { s ->
            NavigationRailItem(
                selected = Nav.section == s && Nav.stack.isEmpty(),
                onClick = { Nav.go(s) },
                icon = {
                    Box {
                        Ico(s.icon, size = 22.dp)
                        if (s == Section.MAIL) Badge(MailStore.inboxUnread, Modifier.padding(start = 14.dp))
                    }
                },
                label = { Text(s.title, style = MaterialTheme.typography.labelSmall) },
                colors = NavigationRailItemDefaults.colors(indicatorColor = P.accentSoft, selectedIconColor = P.accentInk, selectedTextColor = P.accentInk, unselectedIconColor = P.muted, unselectedTextColor = P.muted),
            )
        }
    }
}
