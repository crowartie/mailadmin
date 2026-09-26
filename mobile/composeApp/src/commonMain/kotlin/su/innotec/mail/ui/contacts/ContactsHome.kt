package su.innotec.mail.ui.contacts

import kotlinx.io.readByteArray
import su.innotec.mail.ui.Fmt
import kotlinx.datetime.plus
import kotlinx.datetime.atTime
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExtendedFloatingActionButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TextField
import androidx.compose.material3.TextFieldDefaults
import androidx.compose.material3.pulltorefresh.PullToRefreshBox
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.ImageBitmap
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.launch
import org.jetbrains.compose.resources.decodeToImageBitmap
import su.innotec.mail.LocalWindow
import su.innotec.mail.Nav
import su.innotec.mail.Screen
import su.innotec.mail.WindowKind
import su.innotec.mail.api.AddressBook
import su.innotec.mail.api.ApiException
import su.innotec.mail.api.Contact
import su.innotec.mail.api.ContactGroup
import su.innotec.mail.api.ContactInput
import su.innotec.mail.api.HistoryEntry
import su.innotec.mail.api.PostalAddress
import su.innotec.mail.api.TypedValue
import su.innotec.mail.api.toInput
import su.innotec.mail.data.Session
import su.innotec.mail.platform.BackHandler
import su.innotec.mail.platform.Sys
import su.innotec.mail.platform.rememberFilePicker
import su.innotec.mail.ui.Avatar
import su.innotec.mail.ui.Chip
import su.innotec.mail.ui.ConfirmDialog
import su.innotec.mail.ui.Divider
import su.innotec.mail.ui.Empty
import su.innotec.mail.ui.ErrorBox
import su.innotec.mail.ui.Ico
import su.innotec.mail.ui.InputDialog
import su.innotec.mail.ui.ListRow
import su.innotec.mail.ui.Loading
import su.innotec.mail.ui.P
import su.innotec.mail.ui.SectionTitle
import su.innotec.mail.ui.Toasts
import su.innotec.mail.ui.Transfers
import su.innotec.mail.ui.launchSafe
import su.innotec.mail.ui.mail.ComposeScreen
import su.innotec.mail.ui.mail.ComposeStart
import su.innotec.mail.ui.mail.IconBtn

/** Состояние раздела «Контакты». */
object ContactsStore {
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.Main)
    var books by mutableStateOf<List<AddressBook>>(emptyList())
    var groups by mutableStateOf<List<ContactGroup>>(emptyList())
    val all = mutableStateListOf<Contact>()
    var book by mutableStateOf<String?>(null)
    var group by mutableStateOf<String?>(null)
    var q by mutableStateOf("")
    var loading by mutableStateOf(false)
    var error by mutableStateOf<String?>(null)
    var selected by mutableStateOf<Contact?>(null)
    var loaded = false

    fun load() {
        val api = Session.api ?: return
        loading = true; error = null
        scope.launch {
            try {
                books = api.books()
                groups = runCatching { api.contactGroups() }.getOrDefault(emptyList())
                val list = api.contacts(book, q)
                all.clear(); all.addAll(list)
                loaded = true
            } catch (e: ApiException) {
                if (e.isAuth) Toasts.error(e) else error = e.message
            } finally { loading = false }
        }
    }

    fun visible(): List<Contact> = all.filter { c -> group == null || group in c.groups }

    fun reset() { all.clear(); books = emptyList(); groups = emptyList(); book = null; group = null; q = ""; selected = null; loaded = false }
}

fun Contact.displayName(): String = fn.ifBlank { listOf(last, first, middle).filter { it.isNotBlank() }.joinToString(" ") }.ifBlank { email.ifBlank { emails.firstOrNull()?.value ?: "(без имени)" } }

@Composable
fun ContactPhoto(c: Contact, size: Dp) {
    val img = remember(c.photo) { c.photo?.let { decodePhoto(it) } }
    if (img != null) Image(img, null, Modifier.size(size).clip(CircleShape), contentScale = ContentScale.Crop)
    else Avatar(c.displayName(), c.email.ifBlank { c.uid }, size)
}

private fun decodePhoto(s: String): ImageBitmap? = runCatching {
    val b64 = s.substringAfter("base64,", s)
    kotlin.io.encoding.Base64.Default.decode(b64).decodeToImageBitmap()
}.getOrNull()

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ContactsHome() {
    val s = ContactsStore
    LaunchedEffect(Unit) { if (!s.loaded) s.load() }
    val wide = LocalWindow.current != WindowKind.PHONE
    if (wide) Row(Modifier.fillMaxSize()) {
        Box(Modifier.width(400.dp).fillMaxHeight()) { ContactList(wide = true) }
        Box(Modifier.width(1.dp).fillMaxHeight().background(P.border))
        Box(Modifier.weight(1f).fillMaxHeight().background(P.surface)) {
            val c = s.selected
            if (c == null) Empty("users", "Выберите контакт") else androidx.compose.runtime.key(c.book, c.uri) { ContactDetail(c, onClose = { s.selected = null }) }
        }
    } else ContactList(wide = false)
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun ContactList(wide: Boolean) {
    val s = ContactsStore
    var menu by remember { mutableStateOf(false) }
    var history by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    val import = rememberFilePicker(multiple = false, mimes = listOf("text/vcard", "text/x-vcard", "text/directory", "*/*")) { files ->
        files.firstOrNull()?.let { f -> scope.launchSafe { Session.api!!.importContacts(f, "personal"); Toasts.show("Контакты загружены"); s.load() } }
    }
    Box(Modifier.fillMaxSize().background(P.bg)) {
        Column(Modifier.fillMaxSize()) {
            Column(Modifier.background(P.surface).statusBarsPadding()) {
                Row(Modifier.fillMaxWidth().height(56.dp).padding(start = 16.dp, end = 4.dp), verticalAlignment = Alignment.CenterVertically) {
                    Text("Контакты", Modifier.weight(1f), style = MaterialTheme.typography.titleMedium)
                    Box {
                        IconBtn("dots", "Ещё") { menu = true }
                        DropdownMenu(menu, { menu = false }) {
                            DropdownMenuItem({ Text("Недавние адресаты") }, { menu = false; history = true }, leadingIcon = { Ico("clock") })
                            DropdownMenuItem({ Text("Загрузить из файла (.vcf)") }, { menu = false; import() }, leadingIcon = { Ico("upload") })
                            DropdownMenuItem({ Text("Выгрузить в файл (.vcf)") }, {
                                menu = false; Transfers.fetch(Session.api!!.contactsExportPath(s.book), "Контакты.vcf", Transfers.Then.SAVE)
                            }, leadingIcon = { Ico("download") })
                        }
                    }
                }
                TextField(
                    s.q, { s.q = it; s.load() },
                    Modifier.fillMaxWidth().padding(horizontal = 12.dp).clip(RoundedCornerShape(10.dp)).testTag("contacts-search"),
                    placeholder = { Text("Имя, адрес, телефон, отдел") }, singleLine = true, leadingIcon = { Ico("search", tint = P.muted) },
                    colors = TextFieldDefaults.colors(focusedContainerColor = P.surface2, unfocusedContainerColor = P.surface2, focusedIndicatorColor = Color.Transparent, unfocusedIndicatorColor = Color.Transparent),
                )
                Row(Modifier.fillMaxWidth().horizontalScroll(rememberScrollState()).padding(horizontal = 12.dp, vertical = 8.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    Chip("Все", s.book == null && s.group == null, { s.book = null; s.group = null; s.load() })
                    s.books.forEach { b -> Chip(b.name + if (b.count > 0) " · ${b.count}" else "", s.book == b.uri, { s.book = b.uri; s.group = null; s.load() }) }
                    s.groups.forEach { g -> Chip(g.name, s.group == g.name, { s.group = if (s.group == g.name) null else g.name }, icon = "tag") }
                }
                Divider()
            }
            PullToRefreshBox(isRefreshing = s.loading && s.all.isNotEmpty(), onRefresh = { s.load() }, modifier = Modifier.weight(1f)) {
                val list = s.visible()
                when {
                    s.loading && s.all.isEmpty() -> Loading()
                    s.error != null && s.all.isEmpty() -> ErrorBox(s.error!!, { s.load() })
                    list.isEmpty() -> Empty("users", if (s.q.isBlank()) "Контактов нет" else "Никого не нашлось")
                    else -> {
                        val fav = list.filter { it.favorite }
                        val rest = list.sortedBy { it.displayName().lowercase() }
                        LazyColumn(Modifier.fillMaxSize().testTag("contacts-list")) {
                            if (fav.isNotEmpty() && s.q.isBlank()) {
                                item { SectionTitle("Избранные") }
                                items(fav.size) { i -> ContactRow(fav[i], wide) }
                                item { SectionTitle("Все") }
                            }
                            var letter = ""
                            rest.forEach { c ->
                                val l = c.displayName().take(1).uppercase()
                                if (l != letter) { letter = l; item(key = "L$l") { Text(l, Modifier.padding(start = 20.dp, top = 10.dp, bottom = 2.dp), color = P.accentInk, fontWeight = FontWeight.SemiBold) } }
                                item(key = "c:" + c.book + c.uri) { ContactRow(c, wide) }
                            }
                            item { Spacer(Modifier.height(96.dp)) }
                        }
                    }
                }
            }
        }
        ExtendedFloatingActionButton(
            onClick = { Nav.push(ContactEditScreen(null)) }, containerColor = P.accent, contentColor = P.accentOn,
            modifier = Modifier.align(Alignment.BottomEnd).padding(16.dp).testTag("new-contact"),
            icon = { Ico("plus") }, text = { Text("Контакт") },
        )
    }
    if (history) HistoryDialog(onDismiss = { history = false })
}

@Composable
private fun ContactRow(c: Contact, wide: Boolean) {
    val selected = wide && ContactsStore.selected?.let { it.uri == c.uri && it.book == c.book } == true
    Row(
        Modifier.fillMaxWidth().background(if (selected) P.accentSoft else P.surface)
            .clickable { if (wide) ContactsStore.selected = c else Nav.push(ContactScreen(c)) }.padding(horizontal = 16.dp, vertical = 10.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        ContactPhoto(c, 40.dp)
        Spacer(Modifier.width(14.dp))
        Column(Modifier.weight(1f)) {
            Text(c.displayName(), style = MaterialTheme.typography.bodyLarge, maxLines = 1, overflow = TextOverflow.Ellipsis)
            // «Организация» у сотрудников — просто домен (innotec.su); тогда полезнее адрес.
            val org = c.org.takeUnless { it.contains('.') && !it.contains(' ') } ?: ""
            val sub = listOf(c.title, c.department.ifBlank { org }).filter { it.isNotBlank() }.joinToString(" · ").ifBlank { c.email.ifBlank { c.emails.firstOrNull()?.value ?: "" } }
            if (sub.isNotBlank()) Text(sub, style = MaterialTheme.typography.bodySmall, color = P.muted, maxLines = 1, overflow = TextOverflow.Ellipsis)
        }
        if (c.favorite) Ico("star", size = 16.dp, tint = P.warn)
    }
}

@Composable
private fun HistoryDialog(onDismiss: () -> Unit) {
    var list by remember { mutableStateOf<List<HistoryEntry>?>(null) }
    val scope = rememberCoroutineScope()
    LaunchedEffect(Unit) { list = runCatching { Session.api!!.contactHistory() }.getOrDefault(emptyList()) }
    androidx.compose.material3.AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text("Недавние адресаты") },
        text = {
            val l = list
            if (l == null) Loading(Modifier.height(120.dp).fillMaxWidth())
            else if (l.isEmpty()) Text("Пока пусто", color = P.muted)
            else LazyColumn {
                items(l.size) { i ->
                    val h = l[i]
                    Row(Modifier.fillMaxWidth().clickable { onDismiss(); Nav.push(ComposeScreen(ComposeStart.New(to = h.email))) }.padding(vertical = 8.dp), verticalAlignment = Alignment.CenterVertically) {
                        Column(Modifier.weight(1f)) {
                            Text(h.name.ifBlank { h.email }, maxLines = 1, overflow = TextOverflow.Ellipsis)
                            Text("${h.email} · писем ${h.uses}", style = MaterialTheme.typography.bodySmall, color = P.muted, maxLines = 1)
                        }
                        IconBtn("plus", "В контакты", tint = P.muted) {
                            scope.launchSafe {
                                val parts = h.name.trim().split(' ', limit = 2)
                                Session.api!!.createContact(su.innotec.mail.api.ContactInput(book = "personal", first = parts.getOrElse(0) { "" }.ifBlank { h.email.substringBefore('@') },
                                    last = parts.getOrElse(1) { "" }.trim(), emails = listOf(su.innotec.mail.api.TypedValue(h.email, "work"))))
                                Toasts.show("${h.email} — в личных контактах")
                            }
                        }
                        IconBtn("x", "Забыть", tint = P.muted) { scope.launchSafe { Session.api!!.forgetHistory(h.email); list = list?.filter { it.email != h.email } } }
                    }
                }
            }
        },
        confirmButton = { TextButton(onClick = onDismiss) { Text("Закрыть") } },
    )
}

class ContactScreen(private val c: Contact) : Screen() {
    @Composable override fun Content() = ContactDetail(c, onClose = { Nav.pop() })
}

@Composable
fun ContactDetail(start: Contact, onClose: () -> Unit) {
    var c by remember { mutableStateOf(start) }
    var confirmDelete by remember { mutableStateOf(false) }
    var suggest by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    LaunchedEffect(start.uri) {
        runCatching { Session.api!!.contact(start.book, start.uri) }.onSuccess { c = it }
    }
    Column(Modifier.fillMaxSize().background(P.surface)) {
        Row(Modifier.fillMaxWidth().statusBarsPadding().height(56.dp).padding(horizontal = 4.dp), verticalAlignment = Alignment.CenterVertically) {
            IconBtn(if (LocalWindow.current == WindowKind.PHONE) "back" else "x", "Назад") { onClose() }
            Spacer(Modifier.weight(1f))
            if (!c.readonly) {
                IconBtn(if (c.favorite) "star" else "star", if (c.favorite) "Убрать из избранных" else "В избранные", tint = if (c.favorite) P.warn else P.muted) {
                    scope.launchSafe {
                        c = Session.api!!.updateContact(c.book, c.uri, c.toInput().copy(favorite = !c.favorite)); ContactsStore.load()
                    }
                }
                IconBtn("edit", "Изменить", Modifier.testTag("contact-edit")) { Nav.push(ContactEditScreen(c)) }
                IconBtn("trash", "Удалить") { confirmDelete = true }
            } else {
                IconBtn("copy", "Копировать в мои контакты") { scope.launchSafe { Session.api!!.copyContact(c.book, c.uri); Toasts.show("Скопировано в «Мои контакты»"); ContactsStore.load() } }
                if (c.employee) IconBtn("edit", "Предложить правку") { suggest = true }
            }
        }
        Divider()
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(bottom = 24.dp)) {
            Column(Modifier.fillMaxWidth().padding(20.dp), horizontalAlignment = Alignment.CenterHorizontally) {
                ContactPhoto(c, 88.dp)
                Spacer(Modifier.height(12.dp))
                Text(c.displayName(), style = MaterialTheme.typography.titleLarge)
                val sub = listOf(c.title, c.department, c.org).filter { it.isNotBlank() }.joinToString(" · ")
                if (sub.isNotBlank()) Text(sub, style = MaterialTheme.typography.bodyMedium, color = P.muted)
                if (c.bookName.isNotBlank()) Text(c.bookName, style = MaterialTheme.typography.bodySmall, color = P.faint)
                Spacer(Modifier.height(14.dp))
                Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                    val mail = c.email.ifBlank { c.emails.firstOrNull()?.value ?: "" }
                    if (mail.isNotBlank()) Action("mail", "Написать") { Nav.push(ComposeScreen(ComposeStart.New(to = if (c.displayName() != mail) "\"${c.displayName()}\" <$mail>" else mail))) }
                    c.phones.firstOrNull()?.let { p -> Action("phone", "Позвонить") { Sys.dial(p.value) } }
                    if (mail.isNotBlank()) Action("search", "Переписка") {
                        su.innotec.mail.ui.mail.MailStore.search("переписка:$mail", everywhere = true)
                        Nav.go(su.innotec.mail.Section.MAIL)
                    }
                    // Встреча с человеком — как «Встреча» в карточке контакта веб-почты: завтра в 10, он — участник.
                    if (mail.isNotBlank()) Action("cal", "Встреча") {
                        val start = Fmt.today().plus(kotlinx.datetime.DatePeriod(days = 1)).atTime(kotlinx.datetime.LocalTime(10, 0))
                        Nav.push(su.innotec.mail.ui.calendar.EventEditScreen(null, prefill = su.innotec.mail.api.EventInput(
                            title = "Встреча: " + c.displayName(), start = start.toString(), end = start.date.atTime(kotlinx.datetime.LocalTime(11, 0)).toString(),
                            attendees = listOf(su.innotec.mail.api.Attendee(mail = mail, name = c.displayName())),
                        )))
                    }
                }
            }
            Divider()
            c.emails.forEach { e -> Field("mail", e.value, typeName(e.type, "почта")) { Nav.push(ComposeScreen(ComposeStart.New(to = e.value))) } }
            c.phones.forEach { p -> Field("phone", p.value, typeName(p.type, "телефон")) { Sys.dial(p.value) } }
            c.addresses.forEach { a -> Field("map", a.oneLine, typeName(a.type, "адрес")) { Sys.openUrl("geo:0,0?q=" + su.innotec.mail.api.enc(a.oneLine)) } }
            if (c.birthday.isNotBlank()) Field("cake", c.birthday, "день рождения")
            if (c.url.isNotBlank()) Field("globe", c.url, "сайт") { Sys.openUrl(if (c.url.startsWith("http")) c.url else "https://" + c.url) }
            if (c.groups.isNotEmpty()) Field("tag", c.groups.joinToString(", "), "группы")
            if (c.nick.isNotBlank()) Field("user", c.nick, "псевдоним")
            if (c.note.isNotBlank()) Field("log", c.note, "заметка")
        }
    }
    if (confirmDelete) ConfirmDialog("Удалить контакт «${c.displayName()}»?", confirm = "Удалить", danger = true, onDismiss = { confirmDelete = false }) {
        scope.launchSafe { Session.api!!.deleteContact(c.book, c.uri); ContactsStore.selected = null; ContactsStore.load(); onClose() }
    }
    if (suggest) InputDialog("Предложить правку", "Что исправить", confirm = "Отправить", hint = "Карточку сотрудника правит администратор — он получит ваше сообщение.", onDismiss = { suggest = false }) { note ->
        scope.launchSafe { Session.api!!.suggestContact(c.book, c.uri, note); Toasts.show("Отправлено администратору") }
    }
}

private fun typeName(t: String, default: String) = when (t.lowercase()) {
    "work" -> "рабочий"; "home" -> "домашний"; "cell", "mobile" -> "мобильный"; "fax" -> "факс"; "other" -> "другой"; "" -> default; else -> t
}

@Composable
private fun Action(icon: String, text: String, onClick: () -> Unit) {
    Column(Modifier.clip(RoundedCornerShape(12.dp)).clickable(onClick = onClick).padding(horizontal = 14.dp, vertical = 8.dp), horizontalAlignment = Alignment.CenterHorizontally) {
        Box(Modifier.size(44.dp).clip(CircleShape).background(P.accentSoft), contentAlignment = Alignment.Center) { Ico(icon, tint = P.accentInk) }
        Spacer(Modifier.height(4.dp))
        Text(text, style = MaterialTheme.typography.labelMedium, color = P.accentInk)
    }
}

@Composable
private fun Field(icon: String, value: String, label: String, onClick: (() -> Unit)? = null) {
    Row(Modifier.fillMaxWidth().let { if (onClick != null) it.clickable(onClick = onClick) else it }.padding(horizontal = 20.dp, vertical = 12.dp), verticalAlignment = Alignment.CenterVertically) {
        Ico(icon, tint = P.muted)
        Spacer(Modifier.width(18.dp))
        Column(Modifier.weight(1f)) {
            Text(value, style = MaterialTheme.typography.bodyLarge)
            Text(label, style = MaterialTheme.typography.bodySmall, color = P.faint)
        }
        if (onClick != null) IconBtn("copy", "Копировать", tint = P.faint) { Sys.copy(value); Toasts.show("Скопировано") }
    }
}

/** Новый контакт (existing == null) или правка. */
class ContactEditScreen(private val existing: Contact?) : Screen() {
    override val fullScreen: Boolean get() = true

    @Composable
    override fun Content() {
        var f by remember { mutableStateOf(existing?.toInput() ?: ContactInput(book = "personal", emails = listOf(TypedValue("", "work")), phones = listOf(TypedValue("", "cell")))) }
        var saving by remember { mutableStateOf(false) }
        val scope = rememberCoroutineScope()
        val books = ContactsStore.books.filter { !it.readonly }
        // Фото: сжимаем до 256 точек — снимок с телефона весит мегабайты, а сервер берёт до ~1,5 МБ.
        val pickPhoto = su.innotec.mail.platform.rememberFilePicker(multiple = false, mimes = listOf("image/*")) { list ->
            val file = list.firstOrNull() ?: return@rememberFilePicker
            scope.launch {
                val jpeg = kotlinx.coroutines.withContext(kotlinx.coroutines.Dispatchers.Default) {
                    su.innotec.mail.platform.shrinkToJpeg(file.open().use { it.readByteArray() }, 256)
                }
                if (jpeg == null) Toasts.show("Картинку не удалось прочитать")
                else f = f.copy(photo = "data:image/jpeg;base64," + kotlin.io.encoding.Base64.Default.encode(jpeg))
            }
        }
        BackHandler(true) { Nav.pop() }
        Column(Modifier.fillMaxSize().background(P.surface)) {
            Row(Modifier.fillMaxWidth().statusBarsPadding().height(56.dp).padding(horizontal = 4.dp), verticalAlignment = Alignment.CenterVertically) {
                IconBtn("x", "Закрыть") { Nav.pop() }
                Text(if (existing == null) "Новый контакт" else "Изменить контакт", Modifier.weight(1f), style = MaterialTheme.typography.titleMedium)
                TextButton(
                    enabled = !saving && (f.fn.isNotBlank() || f.first.isNotBlank() || f.last.isNotBlank() || f.emails.any { it.value.isNotBlank() }),
                    onClick = {
                        saving = true
                        scope.launch {
                            try {
                                val clean = f.copy(
                                    emails = f.emails.filter { it.value.isNotBlank() }, phones = f.phones.filter { it.value.isNotBlank() },
                                    addresses = f.addresses.filter { it.oneLine.isNotBlank() },
                                    fn = f.fn.ifBlank { listOf(f.last, f.first, f.middle).filter { it.isNotBlank() }.joinToString(" ") },
                                )
                                val api = Session.api!!
                                val saved = if (existing == null) api.createContact(clean) else api.updateContact(existing.book, existing.uri, clean)
                                ContactsStore.load()
                                if (ContactsStore.selected != null) ContactsStore.selected = saved
                                Nav.pop()
                                Toasts.show("Сохранено")
                            } catch (e: ApiException) { Toasts.error(e) } finally { saving = false }
                        }
                    },
                    modifier = Modifier.testTag("contact-save"),
                ) { Text("Сохранить", fontWeight = FontWeight.SemiBold) }
            }
            Divider()
            Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                if (existing == null && books.size > 1) {
                    var open by remember { mutableStateOf(false) }
                    Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(8.dp)).clickable { open = true }.padding(8.dp), verticalAlignment = Alignment.CenterVertically) {
                        Text("Книга: ", color = P.muted); Text(books.firstOrNull { it.uri == f.book }?.name ?: "Мои контакты", Modifier.weight(1f)); Ico("down", size = 16.dp)
                    }
                    if (open) su.innotec.mail.ui.ChoiceDialog("Адресная книга", books, { it.name }, books.firstOrNull { it.uri == f.book }, onDismiss = { open = false }) { f = f.copy(book = it.uri) }
                }
                val shown = if (f.photo != null) f.photo!!.ifBlank { null } else existing?.photo
                val img = remember(shown) { shown?.let { decodePhoto(it) } }
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                    Box(Modifier.size(72.dp).clip(CircleShape).background(P.surface2).clickable { pickPhoto() }, contentAlignment = Alignment.Center) {
                        if (img != null) androidx.compose.foundation.Image(img, "Фото", Modifier.fillMaxSize(), contentScale = androidx.compose.ui.layout.ContentScale.Crop)
                        else Ico("img", tint = P.muted)
                    }
                    Column {
                        TextButton(onClick = { pickPhoto() }) { Text(if (img == null) "Добавить фото" else "Сменить фото") }
                        if (img != null) TextButton(onClick = { f = f.copy(photo = "") }) { Text("Убрать фото", color = P.no) }
                    }
                }
                Tf("Фамилия", f.last) { f = f.copy(last = it) }
                Tf("Имя", f.first) { f = f.copy(first = it) }
                Tf("Отчество", f.middle) { f = f.copy(middle = it) }
                Tf("Организация", f.org) { f = f.copy(org = it) }
                Tf("Отдел", f.department) { f = f.copy(department = it) }
                Tf("Должность", f.title) { f = f.copy(title = it) }
                SectionTitle("Почта", Modifier.padding(0.dp))
                f.emails.forEachIndexed { i, e ->
                    Tf("Адрес", e.value, KeyboardType.Email, onRemove = { f = f.copy(emails = f.emails.filterIndexed { j, _ -> j != i }) }) { v -> f = f.copy(emails = f.emails.mapIndexed { j, x -> if (j == i) x.copy(value = v) else x }) }
                }
                TextButton(onClick = { f = f.copy(emails = f.emails + TypedValue("", "work")) }) { Text("+ адрес") }
                SectionTitle("Телефоны", Modifier.padding(0.dp))
                f.phones.forEachIndexed { i, p ->
                    Tf("Телефон", p.value, KeyboardType.Phone, onRemove = { f = f.copy(phones = f.phones.filterIndexed { j, _ -> j != i }) }) { v -> f = f.copy(phones = f.phones.mapIndexed { j, x -> if (j == i) x.copy(value = v) else x }) }
                }
                TextButton(onClick = { f = f.copy(phones = f.phones + TypedValue("", "cell")) }) { Text("+ телефон") }
                SectionTitle("Адрес", Modifier.padding(0.dp))
                val a = f.addresses.firstOrNull() ?: PostalAddress()
                fun setA(n: PostalAddress) { f = f.copy(addresses = listOf(n) + f.addresses.drop(1)) }
                Tf("Улица, дом", a.street) { setA(a.copy(street = it)) }
                Tf("Город", a.city) { setA(a.copy(city = it)) }
                Tf("Индекс", a.postal) { setA(a.copy(postal = it)) }
                Tf("Страна", a.country) { setA(a.copy(country = it)) }
                SectionTitle("Прочее", Modifier.padding(0.dp))
                Tf("День рождения (ГГГГ-ММ-ДД)", f.birthday) { f = f.copy(birthday = it.take(10)) }
                Tf("Сайт", f.url, KeyboardType.Uri) { f = f.copy(url = it) }
                Tf("Группы через запятую", f.groups.joinToString(", ")) { v -> f = f.copy(groups = v.split(',').map { it.trim() }.filter { it.isNotEmpty() }) }
                Tf("Заметка", f.note, single = false) { f = f.copy(note = it) }
                Spacer(Modifier.height(40.dp))
            }
        }
    }
}

@Composable
private fun Tf(label: String, value: String, kb: KeyboardType = KeyboardType.Text, single: Boolean = true, onRemove: (() -> Unit)? = null, onChange: (String) -> Unit) {
    OutlinedTextField(
        value, onChange, Modifier.fillMaxWidth(), label = { Text(label) }, singleLine = single,
        keyboardOptions = KeyboardOptions(keyboardType = kb),
        trailingIcon = onRemove?.let { { IconBtn("x", "Убрать", tint = P.muted) { it() } } },
    )
}
