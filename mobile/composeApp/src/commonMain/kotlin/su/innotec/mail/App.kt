package su.innotec.mail

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
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.runtime.staticCompositionLocalOf
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.unit.dp
import su.innotec.mail.data.Session
import su.innotec.mail.platform.BackHandler
import su.innotec.mail.platform.Notifier
import su.innotec.mail.ui.Badge
import su.innotec.mail.ui.Ico
import su.innotec.mail.ui.MailTheme
import su.innotec.mail.ui.P
import su.innotec.mail.ui.Toasts
import su.innotec.mail.ui.calendar.CalendarHome
import su.innotec.mail.ui.cloud.CloudHome
import su.innotec.mail.ui.contacts.ContactsHome
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

@Composable
fun App() {
    MailTheme(Session.prefs.theme) {
        Box(Modifier.fillMaxSize().background(P.bg)) {
            val acc = Session.account
            if (acc == null) {
                LaunchedEffect(Unit) { Nav.reset(); MailStore.reset(); Notifier.fast(false) }
                LoginScreen()
            } else {
                LaunchedEffect(acc.origin, acc.user) {
                    Notifier.ensurePermission()
                    Notifier.schedule(Session.prefs.notify)
                    Notifier.fast(Session.prefs.fastNotify)
                    // Имя и адрес — с сервера, если вход сохранён без них (и заодно проверка, что токен жив).
                    runCatching { Session.api!!.me() }.onSuccess { me ->
                        if (me.user != acc.user || me.name != acc.name) Session.signIn(acc.copy(user = me.user, name = me.name))
                    }.onFailure { if (it is su.innotec.mail.api.ApiException && it.isAuth) Session.signOut("Вход устарел или отозван — войдите заново.") }
                }
                Main()
            }
        }
    }
}

@Composable
private fun Main() {
    BoxWithConstraints(Modifier.fillMaxSize()) {
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
                        su.innotec.mail.ui.mail.SendProgressBar(Modifier.align(Alignment.BottomCenter).padding(bottom = 88.dp).zIndex(5f))
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
