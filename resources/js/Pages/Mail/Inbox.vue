<script setup>
// Веб-почта: папки · список · чтение. Страница отрисовывается с данными первой страницы,
// дальше всё живёт на /mail/api/* без перезагрузок.
import { computed, onBeforeUnmount, onMounted, ref, nextTick, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import MailLayout from '../../Layouts/MailLayout.vue';
import Icon from '../../Components/Icon.vue';
import FolderNav from '../../Components/Mail/FolderNav.vue';
import MessageList from '../../Components/Mail/MessageList.vue';
import MessageView from '../../Components/Mail/MessageView.vue';
import Compose from '../../Components/Mail/Compose.vue';
import Popover from '../../Components/Mail/Popover.vue';
import Toast from '../../Components/Mail/Toast.vue';
import Dialog from '../../Components/Mail/Dialog.vue';
import ShortcutsHelp from '../../Components/Mail/ShortcutsHelp.vue';
import { api, composeForm } from '../../mail/api';
import { addrString, escapeHtml, hotkey, plural, presets, when } from '../../mail/format';

const props = defineProps({
    user: String,
    settings: Object,
    identities: Array,
    folders: Array,
    labels: Array,
    folder: String,
    filter: { type: String, default: 'all' },
    query: { type: String, default: '' },
    list: Object,
    outbox: { type: Number, default: 0 },
    cloud: { type: Object, default: () => ({ enabled: false, thresholdMb: 10, maxMb: 50 }) },
    quarantine: { type: Number, default: 0 },
    // Занятое место в ящике: индикатор внизу панели папок (справка обещала его с самого начала).
    quota: { type: Object, default: null },
    openUid: { type: Number, default: null },
    composeTo: { type: String, default: null },
});

// ── Состояние ─────────────────────────────────────────────────
const folders = ref(props.folders);
const labels = ref(props.labels);
const settings = ref(props.settings);
const folder = ref(props.folder);
const filter = ref(props.filter);
const query = ref(props.query || '');
// 170: поиск шёл только по текущей папке, хотя справка обещает «по всем папкам»:
// письмо, разложенное правилом в проектную папку, найти было нельзя.
const everywhere = ref(false);
function setEverywhere(on) {
    everywhere.value = !!on;
    load(1);
}
const list = ref(props.list);
const selected = ref([]);
const cursor = ref(null);
const open = ref(null);
const loading = ref(false);
// Какое письмо сейчас открывается: подсвечиваем строку сразу, не дожидаясь ответа,
// и отбрасываем ответ, если человек успел кликнуть другое письмо.
const opening = ref(null);
// Порядок списка: по дате (как раньше), по отправителю, по теме, по размеру.
const sort = ref((() => {
    const fromUrl = new URLSearchParams(window.location.search).get('sort');
    if (fromUrl) return fromUrl;
    try { return localStorage.getItem('mail.sort') || 'date'; } catch { return 'date'; }
})());
function setSort(v) {
    sort.value = v;
    try { localStorage.setItem('mail.sort', v); } catch { /* приватное окно */ }
    load(1);
}
let openSeq = 0;
const compose = ref(null);
const menu = ref(null);      // { kind, x, y, uids, folder, label }
const toast = ref(null);
const dialog = ref(null);    // { kind, ... }
const help = ref(false);
// Фокус в окно «Это спам» и Escape для него: см. watch ниже.
const senderBox = ref(null);
const mobileRead = ref(false);
const navOpen = ref(false);
const outboxCount = ref(props.outbox);
const listRef = ref(null);
let toastTimer = null;
let pending = null;          // отложенная отправка с «Отменить»
let refreshTimer = null;

const folderInfo = computed(() => folders.value.find((f) => f.path === folder.value) || { name: folder.value, role: 'custom' });
const folderName = computed(() => (filter.value === 'flagged' ? 'Важное' : filter.value.startsWith('label:') ? (labels.value.find((l) => 'label:' + l.id === filter.value)?.name || 'Метка') : folderInfo.value.name));
const rolePath = (role) => folders.value.find((f) => f.role === role)?.path;

function showToast(t, ms = 4000) {
    clearTimeout(toastTimer);
    toast.value = t;
    if (ms) toastTimer = setTimeout(() => { toast.value = null; }, ms);
}
function fail(e) {
    showToast({ text: e?.message || 'Что-то пошло не так', error: true }, 6000);
}

/** Адрес страницы = папка + отбор + поиск + номер страницы. */
function currentUrl() {
    const p = new URLSearchParams();
    if (filter.value !== 'all') p.set('filter', filter.value);
    if (query.value) p.set('q', query.value);
    // Номер страницы в адресе: без него обновление на седьмой странице возвращало на первую.
    if ((list.value.page || 1) > 1) p.set('page', String(list.value.page));
    if (sort.value !== 'date') p.set('sort', sort.value);
    const qs = p.toString();
    return `/mail/folder/${encodeURIComponent(folder.value)}${qs ? '?' + qs : ''}`;
}
function syncUrl() {
    const url = currentUrl();
    if (url !== window.location.pathname + window.location.search) {
        window.history.replaceState({ mail: true }, '', url);
    }
}
// Переход в другую папку добавляет запись в историю: раньше всё писалось поверх одной,
// и кнопка «Назад» уводила из почты целиком вместо возврата в предыдущую папку.
function pushUrl() {
    const url = currentUrl();
    if (url !== window.location.pathname + window.location.search) {
        window.history.pushState({ mail: true }, '', url);
    }
}
function onPopState() {
    const m = window.location.pathname.match(/^\/mail\/folder\/(.+)$/);
    const sp = new URLSearchParams(window.location.search);
    folder.value = m ? decodeURIComponent(m[1]) : (rolePath('inbox') || 'INBOX');
    filter.value = sp.get('filter') || 'all';
    query.value = sp.get('q') || '';
    sort.value = sp.get('sort') || 'date';
    open.value = null;
    mobileRead.value = false;
    load(Number(sp.get('page')) || 1);
}

// ── Списки ────────────────────────────────────────────────────
// ── Живое обновление ──────────────────────────────────────────
let lastUidnext = null;
let lastPoll = 0;
// В приватном окне и при запрете данных сайта обращение к хранилищу бросает исключение —
// без защиты страница почты не отрисовывалась вовсе.
const shownReminders = new Set((() => {
    try { return JSON.parse(localStorage.getItem('mail.reminders.shown') || '[]'); } catch { return []; }
})());
function updateTitle() {
    const inbox = folders.value.find((f) => f.role === 'inbox');
    const n = inbox?.unread || 0;
    document.title = (n ? `(${n}) ` : '') + (folderInfo.value.name || 'Почта') + ' — ' + (props.user || 'Почта');
}
function canNotify() { return settings.value.notify_browser && typeof Notification !== 'undefined' && Notification.permission === 'granted'; }
function notify(title, body, tag, onclick) {
    if (!canNotify()) return;
    try {
        const n = new Notification(title, { body, tag, icon: '/favicon.ico' });
        n.onclick = () => { window.focus(); onclick?.(); n.close(); };
        setTimeout(() => n.close(), 15000);
    } catch {}
}
async function poll() {
    if (document.visibilityState !== 'visible' && Date.now() - lastPoll < 60000) return;
    if (compose.value || menu.value) return;
    lastPoll = Date.now();
    try {
        const st = await api.status(folder.value);
        const inbox = folders.value.find((f) => f.role === 'inbox');
        if (inbox && inbox.unread !== st.inboxUnseen) { inbox.unread = st.inboxUnseen; updateTitle(); }
        const cur = folders.value.find((f) => f.path === folder.value);
        if (cur) { cur.unread = st.folder.unseen; cur.total = st.folder.messages; }
        if (lastUidnext !== null && st.folder.uidnext > lastUidnext) {
            const prev = lastUidnext;
            // Тихая перезагрузка: обычная сбрасывала галочки и на секунду гасила список,
            // а письмо приходит как раз тогда, когда человек отмечает пачку.
            await load(list.value.page, true, true);
            // Сервер отдаёт признак «прочитано» (seen); поля unread в ответе нет никогда,
            // поэтому список новых всегда получался пустым и уведомления не приходили.
            const fresh = (list.value.messages || []).filter((m) => m.uid >= prev && !m.seen);
            fresh.slice(0, 3).forEach((m) => notify(m.from?.name || m.from?.mail || 'Новое письмо', m.subject || '(без темы)', 'mail-' + m.uid, () => openMessage(m.uid)));
            if (fresh.length > 3) notify('Новые письма', `и ещё ${fresh.length - 3}`, 'mail-more');
        }
        lastUidnext = st.folder.uidnext;
        for (const r of st.reminders || []) {
            if (shownReminders.has(r.key)) continue;
            shownReminders.add(r.key);
            try { localStorage.setItem('mail.reminders.shown', JSON.stringify([...shownReminders].slice(-200))); } catch { /* приватное окно */ }
            const t = r.allDay ? 'сегодня' : new Date(r.start).toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
            notify('Напоминание: ' + r.title, (r.allDay ? 'Весь день' : 'В ' + t) + (r.location ? ' · ' + r.location : ''), 'rem-' + r.key, () => { window.location.href = '/calendar'; });
            showToast({ text: 'Напоминание: ' + r.title + ' — ' + t }, 8000);
        }
    } catch {}
}

async function load(page = 1, keepOpen = false, silent = false) {
    if (!silent) loading.value = true;
    try {
        // Тихая перезагрузка (после действия, по приходу почты) счётчики папок не запрашивает:
        // их приносит отдельный опрос состояния.
        const r = await api.list(folder.value, { page, filter: filter.value, q: query.value, sort: sort.value, folders: !silent, scope: everywhere.value ? 'all' : 'folder' });
        // Страница оказалась за концом списка (удалили всё на последней) — показать последнюю существующую.
        if (!r.messages.length && r.page > 1 && r.pages < r.page) return load(Math.max(1, r.pages), keepOpen, silent);
        list.value = { messages: r.messages, total: r.total, page: r.page, pages: r.pages, everywhere: !!r.everywhere, skipped: r.skipped || [] };
        if (r.folders) folders.value = r.folders;
        if (!silent) selected.value = [];
        if (!keepOpen) { open.value = null; cursor.value = null; }
        syncUrl();
    } catch (e) { fail(e); } finally { if (!silent) loading.value = false; }
}
// После удаления/переноса страница «подтягивает» следующие письма фоном, а не пустеет (обращение №31).
const REMOVING = ['delete', 'move', 'archive', 'spam', 'notspam', 'lists', 'snooze', 'unsnooze'];
function refillAfter(op) {
    if (REMOVING.includes(op)) load(list.value.page, true, true);
}

function go(path, f = 'all') {
    folder.value = path;
    filter.value = f;
    query.value = '';
    navOpen.value = false;
    mobileRead.value = false;
    list.value.page = 1;
    pushUrl();
    load(1);
}
function setFilter(f) { filter.value = f; load(1); }
function search(q) { query.value = q; load(1); }
async function refresh() { await load(list.value.page, true); }

// ── Чтение ────────────────────────────────────────────────────
async function openMessage(uid, e) {
    if (e && (e.ctrlKey || e.metaKey)) { toggle(uid); return; }
    if (e && e.shiftKey && cursor.value) { rangeSelect(uid); return; }
    cursor.value = uid;
    compose.value = null;
    const row = list.value.messages.find((m) => m.uid === uid);
    if (folderInfo.value.role === 'drafts') { openDraft(uid); return; }
    // Гасить весь список на время загрузки письма не нужно: от этого он мигал на каждый клик.
    const want = ++openSeq;
    opening.value = uid;
    try {
        // При поиске по всем папкам строка знает свою папку — иначе письмо открывалось бы
        // из текущей и не находилось.
        const m = await api.message(row?.folder || folder.value, uid);
        // Пока ответ шёл, человек мог кликнуть другое письмо — устаревший ответ не показываем.
        if (want !== openSeq) return;
        open.value = m;
        mobileRead.value = true;
        if (row && !row.seen) { row.seen = true; bump(folder.value, -1); }
        // Цепочка ответов — фоном, чтобы письмо показывалось сразу.
        api.thread(folder.value, uid).then((t) => {
            if (!open.value || open.value.uid !== m.uid || open.value.folder !== m.folder) return;
            open.value.thread = Array.isArray(t) ? t : (t?.messages || []);
            open.value.threadHidden = Array.isArray(t) ? 0 : (t?.hidden || 0);
        }).catch(() => {});
    } catch (e) { if (want === openSeq) fail(e); } finally { if (want === openSeq) opening.value = null; }
}

function bump(path, delta, totalDelta = 0) {
    const f = folders.value.find((x) => x.path === path);
    if (!f) return;
    f.unread = Math.max(0, (f.unread || 0) + delta);
    // Общее число писем тоже: раньше в шапке списка было «194 письма», а у папки «4 / 195»,
    // пока не придёт ответ сервера.
    if (totalDelta) f.total = Math.max(0, (f.total || 0) + totalDelta);
}

// ── Выбор ─────────────────────────────────────────────────────
function toggle(uid) {
    const i = selected.value.indexOf(uid);
    if (i >= 0) selected.value.splice(i, 1); else selected.value.push(uid);
    cursor.value = uid;
}
function rangeSelect(uid) {
    const ids = list.value.messages.map((m) => m.uid);
    const a = ids.indexOf(cursor.value); const b = ids.indexOf(uid);
    if (a < 0 || b < 0) return;
    const [from, to] = a < b ? [a, b] : [b, a];
    const set = new Set(selected.value);
    ids.slice(from, to + 1).forEach((u) => set.add(u));
    selected.value = [...set];
}
function selectAll() {
    const all = list.value.messages.map((m) => m.uid);
    selected.value = all.every((u) => selected.value.includes(u)) ? [] : all;
}

// ── Действия ──────────────────────────────────────────────────
function removeRows(uids) {
    const set = new Set(uids);
    let unreadGone = 0;
    list.value.messages = list.value.messages.filter((m) => { if (set.has(m.uid)) { if (!m.seen) unreadGone++; return false; } return true; });
    list.value.total = Math.max(0, list.value.total - uids.length);
    bump(folder.value, -unreadGone, -uids.length);
    selected.value = selected.value.filter((u) => !set.has(u));
    if (open.value && set.has(open.value.uid)) { open.value = null; mobileRead.value = false; }
}

async function act(op, uids, extra = {}, deferrable = true) {
    if (!uids?.length) return;
    menu.value = null;
    const rows = list.value.messages.filter((m) => uids.includes(m.uid));
    // Сначала меняем экран, потом идём на сервер — так интерфейс не ждёт IMAP.
    switch (op) {
        case 'seen': rows.forEach((m) => { if (!m.seen) { m.seen = true; bump(folder.value, -1); } }); break;
        case 'unseen': rows.forEach((m) => { if (m.seen) { m.seen = false; bump(folder.value, 1); } }); if (open.value && uids.includes(open.value.uid)) open.value.seen = false; break;
        case 'flag': rows.forEach((m) => { m.flagged = true; }); if (open.value && uids.includes(open.value.uid)) open.value.flagged = true; break;
        case 'unflag': rows.forEach((m) => { m.flagged = false; }); if (open.value && uids.includes(open.value.uid)) open.value.flagged = false; break;
        case 'label': rows.forEach((m) => { if (!m.labels.includes(extra.label)) m.labels.push(extra.label); }); if (open.value && uids.includes(open.value.uid) && !open.value.labels.includes(extra.label)) open.value.labels.push(extra.label); break;
        case 'unlabel': rows.forEach((m) => { m.labels = m.labels.filter((l) => l !== extra.label); }); if (open.value && uids.includes(open.value.uid)) open.value.labels = open.value.labels.filter((l) => l !== extra.label); break;
        case 'delete': case 'move': case 'archive': case 'spam': case 'notspam': case 'lists': case 'snooze': case 'unsnooze': removeRows(uids); break;
        default: break;
    }
    const names = { delete: 'Удалено', archive: 'В архиве', spam: 'Помечено как спам', move: 'Перемещено', lists: 'В рассылки', snooze: 'Отложено', unsnooze: 'Возвращено во «Входящие»', notspam: 'Возвращено во Входящие', remind: 'Напомню, если не ответят' };
    const label = `${names[op] || ''}${uids.length > 1 ? ` · ${uids.length} ${plural(uids.length, 'письмо', 'письма', 'писем')}` : ''}`;
    // Удаление/перенос/архив/спам — с отменой: сервер получит команду через N секунд (Настройки → Общие),
    // до этого «Отменить» просто возвращает список. Диалоги по отправителю и повторные действия — сразу.
    const secs = Number(settings.value.undo_seconds ?? 5);
    // Отмена выключена в настройках: удаление уходит сразу и навсегда — спрашиваем.
    if (!secs && op === 'delete') {
        const forever = folderInfo.value.role === 'trash';
        const what = uids.length > 1 ? `${uids.length} ${plural(uids.length, 'письмо', 'письма', 'писем')}` : 'письмо';
        const q = forever ? `Стереть ${what} навсегда? Восстановить будет нельзя.` : `Удалить ${what}?`;
        if (!window.confirm(q)) return;
    }
    // Перетащили письмо мышью не в ту папку или ошиблись со «Спамом» — отмена нужна так же,
    // как при удалении. Раньше эти действия уходили на сервер сразу и без отмены.
    if (secs > 0 && ['delete', 'archive', 'spam', 'move', 'lists'].includes(op)) {
        flushPendingAct();
        pendingAct = { folder: folder.value, uids, op, extra, seconds: secs, timer: null };
        clearTimeout(toastTimer);
        toast.value = { text: label, actionLabel: 'Отменить', seconds: secs };
        const tick = () => {
            if (!pendingAct) return;
            pendingAct.seconds--;
            if (pendingAct.seconds <= 0) {
                const p = pendingAct; pendingAct = null; toast.value = null;
                runAct(p).then(() => refillAfter(p.op)).catch((e) => { fail(e); load(list.value.page, true); });
            } else {
                // Обновляем только своё сообщение внизу: если его уже сменило другое («Черновик сохранён»), чужое не трогаем.
                if (toast.value?.actionLabel === 'Отменить') toast.value = { ...toast.value, seconds: pendingAct.seconds };
                pendingAct.timer = setTimeout(tick, 1000);
            }
        };
        pendingAct.timer = setTimeout(tick, 1000);
        return;
    }
    try {
        const r = await runAct({ folder: folder.value, uids, op, extra });
        // Сервер сообщает, со сколькими письмами получилось: раньше из двадцати выделенных
        // могло отложиться девятнадцать, и сообщение всё равно было победным.
        const skipped = Number(r?.skipped || 0);
        if (names[op]) {
            showToast({ text: skipped ? `${label} · ${skipped} ${plural(skipped, 'письмо', 'письма', 'писем')} пропущено: нет Message-ID` : label }, skipped ? 6000 : 2500);
        }
        refillAfter(op);
    } catch (e) {
        fail(e);
        load(list.value.page, true);
    }
}

// ── Отложенное действие с отменой ────────────────────────────
let pendingAct = null;   // { folder, uids, op, extra, seconds, timer }
async function runAct(p, opts = {}) {
    const r = await api.action(p.folder, p.uids, p.op, p.extra, opts);
    if (r?.folders) folders.value = r.folders;
    return r;
}
function flushPendingAct(keepalive = false) {
    if (!pendingAct) return;
    clearTimeout(pendingAct.timer);
    const p = pendingAct; pendingAct = null;
    // Плашка с таймером без действия за ней зависала навсегда (обращение №4) — убираем вместе с действием.
    if (toast.value?.actionLabel === 'Отменить' && toast.value?.seconds) toast.value = null;
    runAct(p, keepalive ? { keepalive: true } : {})
        .then(() => { if (!keepalive) refillAfter(p.op); })
        // При уходе со страницы показывать уже нечего, в остальных случаях молчать нельзя:
        // письмо пропадало с экрана, хотя на сервере ничего не произошло.
        .catch((e) => { if (!keepalive) { fail(e); load(list.value.page, true); } });
}
function undoAct() {
    if (!pendingAct) return false;
    clearTimeout(pendingAct.timer);
    pendingAct = null; toast.value = null;
    // Сервер ничего не делал — достаточно перечитать список и счётчики.
    load(list.value.page, true);
    showToast({ text: 'Отменено' }, 2000);
    return true;
}
function undoToast() {
    if (undoAct()) return;
    undoSend();
}

function onDrop(data, target) {
    if (data.folder === folder.value) moveTo(data.uids, target);
}

// Единая точка переноса в папку — мышью, из меню «В папку», с клавиатуры. Что предложить, решает ПАПКА-ПОЛУЧАТЕЛЬ,
// откуда бы письмо ни тащили: «Спам» — решение по отправителю (спам), «Рассылки» — рассылка, своя папка — правило
// «класть сюда всегда» (отключается в Настройках). Корзина, архив, отложенные — просто перенос.
function moveTo(uids, target) {
    const f = folders.value.find((x) => x.path === target);
    if (!f || f.path === folder.value) return;
    if (f.role === 'spam') { askSender('spam', uids); return; }
    if (f.role === 'lists') { askSender('lists', uids); return; }
    if (f.role === 'custom' && settings.value.ask_rule_on_move !== false) { askSender('folder', uids, f); return; }
    act('move', uids, { target });
}

// ── Решение по отправителю: спам / рассылка / не спам ────────────
// Сначала письмо уезжает в папку (act), затем спрашиваем: только это письмо или все от адреса/домена.
function askSender(kind, uids, targetFolder = null) {
    if (folderInfo.value.role === 'shared') {
        // Чужой общий ящик: письмо уезжает в его «Спам»/«Рассылки»/папку (сервер подставит папку владельца), личных правил не предлагаем.
        if (kind === 'folder') act('move', uids, { target: targetFolder.path }); else act(kind === 'ham' ? 'notspam' : kind, uids);
        return;
    }
    const rows = list.value.messages.filter((m) => uids.includes(m.uid));
    const mails = [...new Set(rows.map((m) => m.from?.mail).concat(open.value && uids.includes(open.value.uid) ? [open.value.from?.mail] : []).filter(Boolean).map((s) => s.toLowerCase()))];
    if (kind === 'folder') act('move', uids, { target: targetFolder.path }, false); else act(kind === 'ham' ? 'notspam' : kind, uids, {}, false);
    if (!mails.length) return;
    const domains = [...new Set(mails.map((m) => m.split('@')[1]).filter(Boolean))];
    dialog.value = { kind: 'sender', what: kind, mails, domains, resort: true, busy: false, folder: targetFolder };
}
async function stopAskingOnMove() {
    settings.value.ask_rule_on_move = false;
    dialog.value = null;
    try { await api.saveSettings({ ask_rule_on_move: false }); showToast({ text: 'Больше не спрашиваю. Включить обратно можно в Настройках → Общие' }, 6000); } catch (e) { fail(e); }
}
const SENDER_TITLE = { spam: 'Это спам', lists: 'Это рассылка', ham: 'Это не спам', folder: 'Класть в эту папку всегда?' };
async function markSender(match) {
    const d = dialog.value;
    if (!d || d.busy) return;
    d.busy = true;
    const values = match === 'domain' ? d.domains : d.mails;
    let moved = 0; let global = false; let votes = null; let personalOnly = false;
    try {
        // Раньше запросы шли строго по одному, и ошибка на середине оставляла часть правил
        // созданной: повтор плодил дубли. Шлём разом и ждём все ответы.
        const results = await Promise.all(values.map((v) => api.markSender(d.what, match, v, d.resort, d.folder?.path || null)));
        for (const r of results) {
            moved += r.moved || 0; global = global || r.global; personalOnly = personalOnly || !!r.personalOnly; if (r.threshold > 1 && !r.personalOnly) votes = `${r.votes} из ${r.threshold}`;
            if (r.folders) folders.value = r.folders;
        }
        dialog.value = null;
        const who = values.length === 1 ? values[0]
            : `${values.length} ${match === 'domain' ? plural(values.length, 'домен', 'домена', 'доменов') : plural(values.length, 'адрес', 'адреса', 'адресов')}`;
        const tail = d.what === 'folder' ? '' : personalOnly ? ' Отправитель вашего домена: правило только у вас, общим не станет.' : d.what === 'ham' ? (global ? ' Фильтр больше не тронет эти письма — у всех сотрудников.' : ' Заявка на исключение ушла администратору.') : (global ? ' Правило стало общим для всех сотрудников.' : (votes ? ` Станет общим для всех, когда так отметят ${votes.split(' из ')[1]} ${plural(Number(votes.split(' из ')[1]) || 0, 'сотрудник', 'сотрудника', 'сотрудников')} (сейчас ${votes.split(' из ')[0]}).` : ''));
        showToast({ text: `${who}: правило добавлено${moved ? `, перемещено писем: ${moved}` : ''}.${tail}` }, 8000);
        await refresh();
    } catch (e) { d.busy = false; fail(e); }
}

// ── Ширина колонок (папки, список): тянется за разделитель, запоминается в браузере (обращение №7) ──
const COL_LIMITS = { nav: [160, 420], list: [360, 820] };   // уже 360 — обрезаются вкладки фильтра
const colW = ref((() => { try { return JSON.parse(localStorage.getItem('mail.cols') || '{}'); } catch { return {}; } })());
const resizing = ref(false);
const colStyle = computed(() => ({
    '--nav-w': colW.value.nav ? colW.value.nav + 'px' : undefined,
    '--list-w': colW.value.list ? colW.value.list + 'px' : undefined,
}));
function startResize(which, e) {
    if (e.button !== 0) return;
    e.preventDefault();
    const pane = e.currentTarget.previousElementSibling;
    const startX = e.clientX;
    const startW = pane.getBoundingClientRect().width;
    const [min, max] = COL_LIMITS[which];
    resizing.value = true;
    const move = (ev) => { colW.value = { ...colW.value, [which]: Math.round(Math.min(max, Math.max(min, startW + ev.clientX - startX))) }; };
    const up = () => {
        window.removeEventListener('pointermove', move);
        window.removeEventListener('pointerup', up);
        resizing.value = false;
        try { localStorage.setItem('mail.cols', JSON.stringify(colW.value)); } catch { /* приватный режим */ }
    };
    window.addEventListener('pointermove', move);
    window.addEventListener('pointerup', up);
}
function resetCol(which) {
    const next = { ...colW.value }; delete next[which]; colW.value = next;
    try { localStorage.setItem('mail.cols', JSON.stringify(next)); } catch { /* приватный режим */ }
}

// ── Меню ──────────────────────────────────────────────────────
function openMenu(e, uid, kind = 'context') {
    e.preventDefault?.();
    const uids = uid == null ? [...selected.value] : (selected.value.includes(uid) ? [...selected.value] : [uid]);
    if (!uids.length) return;
    const r = e.currentTarget?.getBoundingClientRect?.();
    const x = e.clientX || (r ? r.left : 100);
    const y = e.clientY || (r ? r.bottom + 4 : 100);
    menu.value = { kind, x, y, uids };
}
const menuRow = computed(() => (menu.value?.uids?.length === 1 ? list.value.messages.find((m) => m.uid === menu.value.uids[0]) || open.value : null));

function snooze(at) {
    const uids = menu.value?.uids || [];
    menu.value = null;
    act('snooze', uids, { until: at.toISOString() });
}
const customSnooze = ref('');
// Нижняя граница для «Отложить до…»: раньше можно было выбрать прошедшее время,
// и письмо возвращалось сразу же или не возвращалось вовсе.
const nowInput = computed(() => {
    const d = new Date(Date.now() - new Date().getTimezoneOffset() * 60000);
    return d.toISOString().slice(0, 16);
});

// Смена папки отложенное действие не выполняет досрочно: таймер идёт дальше, «Отменить» работает и из другой папки
// (сервер ещё ничего не делал, папка действия запомнена в pendingAct).
watch(folder, () => { lastUidnext = null; updateTitle(); });

// 368: у окна «Это спам / Это рассылка» не было ни Escape, ни автофокуса — в отличие
// от общего диалога. Обработчик клавиш списка писем при открытом окне выходит раньше,
// поэтому Escape ловим здесь.
let senderReturnTo = null;
function onSenderKey(e) {
    if (e.key === 'Escape') { e.stopPropagation(); dialog.value = null; }
}
watch(() => dialog.value?.kind === 'sender', async (on) => {
    if (on) {
        senderReturnTo = document.activeElement;
        document.addEventListener('keydown', onSenderKey, true);
        await nextTick();
        senderBox.value?.querySelector('button')?.focus();
    } else {
        document.removeEventListener('keydown', onSenderKey, true);
        if (senderReturnTo && document.contains(senderReturnTo)) senderReturnTo.focus();
        senderReturnTo = null;
    }
});
function folderContext(e, f) {
    menu.value = { kind: 'folder', x: e.clientX, y: e.clientY, folder: f };
}
function labelMenu(what, e, l) {
    if (what === 'new') dialog.value = { kind: 'label' };
    else menu.value = { kind: 'labelctx', x: e.clientX, y: e.clientY, label: l };
}

// ── Папки ─────────────────────────────────────────────────────
async function folderDialog(kind, f = null) {
    menu.value = null;
    dialog.value = { kind, folder: f };
}
async function confirmDialog(value) {
    const d = dialog.value;
    if (!d || d.busy) return;   // второй клик по «Ок», пока запрос в пути
    // Окно закрываем после ответа сервера: раньше при отказе («папка с таким именем уже есть»)
    // форма была уже закрыта, и набранное название приходилось вводить заново.
    dialog.value = { ...d, busy: true };
    try {
        if (d.kind === 'newFolder') { const r = await api.createFolder(value, d.folder?.path || null); folders.value = r.folders; showToast({ text: 'Папка создана' }); }
        if (d.kind === 'renameFolder') { const r = await api.renameFolder(d.folder.path, value); folders.value = r.folders; if (folder.value === d.folder.path) folder.value = r.path; }
        // Панель папок рисуется из этого списка: пустой ответ сервера её бы уронил.
        if (d.kind === 'deleteFolder') { const r = await api.deleteFolder(d.folder.path); if (Array.isArray(r?.folders)) folders.value = r.folders; if (folder.value === d.folder.path) go('INBOX'); }
        if (d.kind === 'emptyFolder') { const r = await api.emptyFolder(d.folder.path); folders.value = r.folders; if (folder.value === d.folder.path) load(1); }
        if (d.kind === 'label') { labels.value = await api.createLabel(value, d.color || '#2F6FEB'); }
        if (d.kind === 'renameLabel') { labels.value = await api.updateLabel(d.label.id, value, d.label.color); }
        if (d.kind === 'deleteLabel') { const r = await api.deleteLabel(d.label.id); if (Array.isArray(r)) labels.value = r; if (filter.value === 'label:' + d.label.id) go('INBOX'); }
        if (d.kind === 'outbox' && value?.cancel) { await api.cancelOutbox(value.cancel); }
        dialog.value = null;
    } catch (e) {
        dialog.value = { ...d, busy: false };   // оставляем окно с введённым текстом
        fail(e);
    }
}
// ── Общий доступ к папке ──────────────────────────────────────
async function openShare(f) {
    menu.value = null;
    try {
        const r = await api.folderShares(f.path);
        dialog.value = { kind: 'share', folder: f, shares: r.shares, candidates: r.candidates, pick: '', level: 'reader' };
    } catch (e) { fail(e); }
}
async function shareSet(mail, level) {
    try { const r = await api.shareFolder(dialog.value.folder.path, mail, level); dialog.value.shares = r.shares; if (r.folders) folders.value = r.folders; showToast({ text: 'Доступ выдан' }); } catch (e) { fail(e); }
}
async function shareRemove(mail) {
    try { const r = await api.unshareFolder(dialog.value.folder.path, mail); dialog.value.shares = r.shares; if (r.folders) folders.value = r.folders; } catch (e) { fail(e); }
}
async function recolor(l, color) {
    try { labels.value = await api.updateLabel(l.id, l.name, color); } catch (e) { fail(e); }
}
const COLORS = ['#2F6FEB', '#16A05C', '#D9791F', '#C0392B', '#7B3FE4', '#0E8A8A', '#6B7787'];

// ── Написать ──────────────────────────────────────────────────
// Подпись зависит от поля «От»: у общего ящика (info и т.п.) — его собственная, иначе личная.
function signatureText(fromMail) {
    const id = (props.identities || []).find((i) => i.shared && i.mail.toLowerCase() === (fromMail || '').toLowerCase());
    return id ? (id.signature || '') : (settings.value.signature || '');
}
function signature(forReply, fromMail) {
    const s = signatureText(fromMail);
    if (!s || (forReply && !settings.value.signature_reply)) return '';
    return `<p><br></p><div class="sig">${s}</div>`;
}
// Письмо из общей папки (или новое, пока открыта общая папка) — от имени её владельца, если нам разрешено писать за него.
function sharedFrom(m) {
    const path = m ? m.folder : folder.value;
    const owner = (folders.value.find((f) => f.path === path) || {}).owner;
    return owner && (props.identities || []).some((i) => i.shared && i.mail === owner) ? owner : '';
}
function quote(m) {
    const inner = m.html || `<pre style="white-space:pre-wrap;font:inherit">${escapeHtml(m.text || '')}</pre>`;
    return `<p><br></p><div class="quote"><div style="color:#6B7787">${escapeHtml(when(m.date, true))}, ${escapeHtml(m.from.name)} &lt;${escapeHtml(m.from.mail)}&gt; писал(а):</div><blockquote>${inner}</blockquote></div>`;
}
function me(a) { return (a.mail || '').toLowerCase() === props.user.toLowerCase() || props.identities.some((i) => i.mail.toLowerCase() === (a.mail || '').toLowerCase()); }
function replyTargets(m) {
    if (folderInfo.value.role === 'sent') return m.to;
    return m.replyTo?.length ? m.replyTo : [m.from];
}
// Своя метка у каждого окна письма: раньше два «Написать» подряд давали один и тот же ключ,
// Vue переиспользовал компонент, и во второй форме оставался текст первой.
let composeSeq = 0;
function startCompose(mode = 'new', m = null, text = '') {
    menu.value = null;
    if (mode === 'draft') { openDraft(m.uid); return; }
    const c = { token: ++composeSeq, mode, to: [], cc: [], bcc: [], subject: '', html: '', from: sharedFrom(m) || '' };
    if (mode === 'new') {
        c.html = `<p>${escapeHtml(text)}</p>${signature(false, c.from)}`;
    } else if (mode === 'reply' || mode === 'replyAll') {
        c.to = replyTargets(m).filter((a) => !me(a) || replyTargets(m).length === 1);
        if (mode === 'replyAll') {
            const seen = new Set(c.to.map((a) => a.mail));
            [...m.to, ...(m.cc || [])].forEach((a) => { if (!me(a) && !seen.has(a.mail)) { seen.add(a.mail); c.cc.push(a); } });
        }
        // trim(): у письма без темы получалось «Re: » с висящим пробелом.
        c.subject = /^re:/i.test(m.subject) ? m.subject : ('Re: ' + (m.subject === '(без темы)' ? '' : m.subject)).trim();
        c.html = `<p>${escapeHtml(text)}</p>${signature(true, c.from)}${quote(m)}`;
        c.inReplyTo = m.messageId;
        c.references = [m.references, m.messageId].filter(Boolean).join(' ');
        c.answeredFolder = m.folder; c.answeredUid = m.uid;
        c.attachments = m.attachments || []; c.sourceFolder = m.folder; c.sourceUid = m.uid; c.keepAttachments = false;
    } else if (mode === 'forward') {
        c.subject = /^fwd?:/i.test(m.subject) ? m.subject : ('Fwd: ' + (m.subject === '(без темы)' ? '' : m.subject)).trim();
        const hdr = `<div class="fwd" style="color:#6B7787">---------- Пересланное письмо ----------<br>От: ${escapeHtml(m.from.name)} &lt;${escapeHtml(m.from.mail)}&gt;<br>Дата: ${escapeHtml(when(m.date, true))}<br>Тема: ${escapeHtml(m.subject)}<br>Кому: ${escapeHtml(addrString(m.to))}</div><br>`;
        c.html = `<p><br></p>${signature(true, c.from)}<p><br></p>${hdr}${m.html || `<pre style="white-space:pre-wrap;font:inherit">${escapeHtml(m.text || '')}</pre>`}`;
        c.references = [m.references, m.messageId].filter(Boolean).join(' ');
        c.attachments = m.attachments || []; c.sourceFolder = m.folder; c.sourceUid = m.uid; c.keepAttachments = true;
    } else if (mode === 'again') {
        // «Изменить как новое» (как в Kerio/Outlook «Отправить повторно»): те же получатели, тема, текст и вложения,
        // без цитаты и шапки пересылки. Подпись не добавляем — в отправленном письме она уже есть.
        c.to = [...(m.to || [])]; c.cc = [...(m.cc || [])];
        c.subject = m.subject === '(без темы)' ? '' : (m.subject || '');
        c.html = m.html || `<pre style="white-space:pre-wrap;font:inherit">${escapeHtml(m.text || '')}</pre>`;
        c.attachments = m.attachments || []; c.sourceFolder = m.folder; c.sourceUid = m.uid; c.keepAttachments = true;
    }
    compose.value = c;
    mobileRead.value = true;
}
/** Из контекстного меню: открыть письмо (если ещё не открыто) и начать ответ или пересылку. */
async function openThen(mode) {
    const uid = menu.value?.uids?.[0];
    menu.value = null;
    if (!uid) return;
    let m = open.value && open.value.uid === uid ? open.value : null;
    if (!m) {
        try { m = await api.message(folder.value, uid); open.value = m; cursor.value = uid; } catch (e) { fail(e); return; }
    }
    startCompose(mode, m);
}
async function openDraft(uid) {
    try {
        const d = await api.openDraft(uid);
        compose.value = { token: ++composeSeq, mode: 'draft', ...d, to: parseList(d.to), cc: parseList(d.cc), bcc: parseList(d.bcc), keepAttachments: d.attachments?.length > 0, sourceFolder: rolePath('drafts'), sourceUid: uid };
        mobileRead.value = true;
    } catch (e) { fail(e); }
}
function parseList(s) {
    return (s || '').split(/,(?![^<]*>)/).map((p) => p.trim()).filter(Boolean).map((p) => {
        const m = p.match(/^"?([^"<]*)"?\s*<([^>]+)>$/);
        return m ? { name: m[1].trim(), mail: m[2].trim() } : { name: '', mail: p };
    });
}

// Черновик сохранён: обновляем счётчик папки и сам список, если открыты «Черновики».
function onDraftSaved() {
    api.folders().then((r) => { if (Array.isArray(r)) folders.value = r; }).catch(() => {});
    if (folderInfo.value.role === 'drafts') load(list.value.page, true);
}

function onComposeClose(opts) {
    if (opts?.discard && opts.draftUid) {
        api.action(rolePath('drafts'), [opts.draftUid], 'delete').then(refresh).catch(() => {});
    }
    compose.value = null;
    if (!open.value) mobileRead.value = false;
}

async function doSend(payload) {
    const r = await api.send(composeForm(payload.form, payload.files));
    if (r.folders) folders.value = r.folders;
    if (folderInfo.value.role === 'drafts' || folderInfo.value.role === 'sent') load(1, true);
    return r;
}

function send(payload) {
    compose.value = null;
    if (!open.value) mobileRead.value = false;
    if (payload.sendAt) {
        doSend(payload).then(() => { outboxCount.value++; showToast({ text: `Отправится ${when(payload.sendAt, true)}` }); }).catch(fail);
        return;
    }
    const secs = Number(settings.value.undo_seconds ?? 5);
    if (!secs) { doSend(payload).then(() => showToast({ text: 'Письмо отправлено' })).catch(fail); return; }
    pending = { payload, seconds: secs };
    toast.value = { text: 'Письмо отправлено', actionLabel: 'Отменить', seconds: secs };
    clearTimeout(toastTimer);
    const tick = () => {
        if (!pending) return;
        pending.seconds--;
        if (pending.seconds <= 0) {
            const p = pending; pending = null; toast.value = null;
            doSend(p.payload).then(() => showToast({ text: 'Письмо отправлено' }, 2000)).catch((e) => { fail(e); compose.value = { ...formToCompose(p.payload.form), files: p.payload.files }; });
        } else {
            toast.value = { ...toast.value, seconds: pending.seconds };
            pending.timer = setTimeout(tick, 1000);
        }
    };
    pending.timer = setTimeout(tick, 1000);
}
function undoSend() {
    if (!pending) { toast.value = null; return; }
    clearTimeout(pending.timer);
    const p = pending; pending = null; toast.value = null;
    // Вместе с формой возвращаем и приложенные файлы: раньше «Отменить» открывало письмо без них.
    compose.value = { ...formToCompose(p.payload.form), files: p.payload.files || [] };
    mobileRead.value = true;
    showToast({ text: 'Отправка отменена' }, 2000);
}
function formToCompose(f) {
    return { token: ++composeSeq, mode: 'new', ...f, to: parseList(f.to), cc: parseList(f.cc), bcc: parseList(f.bcc), attachments: [] };
}
function flushPending() {
    flushPendingAct(true);
    if (!pending) return;
    clearTimeout(pending.timer);
    const p = pending; pending = null;
    api.send(composeForm(p.payload.form, p.payload.files), { keepalive: true }).catch(() => {});
}

async function quickReply({ text, message: m, done }) {
    const to = replyTargets(m);
    const form = {
        to: addrString(to),
        // Та же тема, что и у полного ответа: раньше быстрый ответ уходил с «Re: (без темы)».
        subject: /^re:/i.test(m.subject) ? m.subject : ('Re: ' + (m.subject === '(без темы)' ? '' : m.subject)).trim(),
        ...(sharedFrom(m) ? { from: sharedFrom(m) } : {}),
        html: `<p>${escapeHtml(text).replace(/\n/g, '<br>')}</p>${signature(true, sharedFrom(m))}${quote(m)}`,
        inReplyTo: m.messageId,
        references: [m.references, m.messageId].filter(Boolean).join(' '),
        answeredFolder: m.folder, answeredUid: m.uid,
    };
    try {
        const r = await api.send(composeForm(form, []));
        if (r.folders) folders.value = r.folders;
        const row = list.value.messages.find((x) => x.uid === m.uid); if (row) row.answered = true;
        showToast({ text: 'Ответ отправлен' });
        // Поле очищает MessageView — но только после того, как письмо действительно ушло.
        if (done) done(true);
    } catch (e) { fail(e); if (done) done(false); }
}

/** «Встреча» из письма: событие с темой письма и всеми участниками переписки. */
/** Печатная форма письма — та же, что по кнопке «Печать» в панели действий. */
function printOpen(m) {
    if (m) window.open(`/mail/print/${encodeURIComponent(m.folder)}/${m.uid}`, '_blank');
}

function meetingFrom(m) {
    const people = [m.from, ...(m.to || []), ...(m.cc || [])].map((a) => a.mail).filter((x) => x && !me({ mail: x }));
    const p = new URLSearchParams({ new: '1', title: m.subject === '(без темы)' ? '' : m.subject, attendees: [...new Set(people)].join(','), description: (m.text || '').slice(0, 800) });
    router.visit('/calendar?' + p);
}

function unsubscribe(m) {
    const h = m.listUnsubscribe || '';
    const mailto = h.match(/<mailto:([^>]+)>/i);
    const http = h.match(/<(https?:[^>]+)>/i);
    if (http) {
        // Ссылка ведёт на чужой сайт из письма, которое человек уже счёл лишним: показываем адрес
        // и спрашиваем. Раньше один клик открывал произвольную страницу без предупреждения.
        let host = http[1];
        try { host = new URL(http[1]).host; } catch { /* оставим как есть */ }
        if (!window.confirm(`Открыть страницу отписки на сайте ${host}?`)) return;
        window.open(http[1], '_blank', 'noopener');
        return;
    }
    if (mailto) {
        const [addr, qs] = mailto[1].split('?');
        const subj = new URLSearchParams(qs || '').get('subject') || 'Unsubscribe';
        compose.value = { token: ++composeSeq, mode: 'new', to: [{ name: '', mail: addr }], cc: [], bcc: [], subject: subj, html: '<p>Unsubscribe</p>' };
        mobileRead.value = true;   // на телефоне окно письма иначе остаётся за кадром
    }
}

async function showOutbox() {
    try {
        const rows = await api.outbox();
        dialog.value = { kind: 'outbox', rows };
    } catch (e) { fail(e); }
}
async function cancelOutbox(id) {
    try { await api.cancelOutbox(id); dialog.value.rows = dialog.value.rows.filter((r) => r.id !== id); outboxCount.value = Math.max(0, outboxCount.value - 1); showToast({ text: 'Письмо вернулось в черновики' }); } catch (e) { fail(e); }
}

// ── Горячие клавиши ───────────────────────────────────────────
let gPrefix = false; let gTimer = null;
let starPrefix = false; let starTimer = null;
/** Подвести список к строке под курсором: без этого j/k уводят курсор за пределы экрана. */
function revealCursor() {
    nextTick(() => {
        const el = document.querySelector('.mrow--cursor');
        if (!el) return;
        const r = el.getBoundingClientRect();
        if (r.top < 70 || r.bottom > window.innerHeight - 60) {
            el.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        }
    });
}
function onKey(e) {
    if (!settings.value.shortcuts) return;
    const t = e.target;
    if (compose.value || dialog.value || help.value) return;
    if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.isContentEditable)) {
        if (e.key === 'Escape') t.blur();
        return;
    }
    if (e.ctrlKey || e.metaKey || e.altKey) return;
    const key = hotkey(e);
    const ids = list.value.messages.map((m) => m.uid);
    const cur = cursor.value ?? open.value?.uid ?? null;
    const idx = ids.indexOf(cur);
    const target = selected.value.length ? selected.value : (cur != null ? [cur] : []);
    const row = list.value.messages.find((m) => m.uid === cur);

    if (starPrefix) {
        starPrefix = false; clearTimeout(starTimer);
        if (key === 'a') { selected.value = list.value.messages.map((m) => m.uid); e.preventDefault(); }
        if (key === 'n') { selected.value = []; e.preventDefault(); }
        return;
    }
    if (gPrefix) {
        gPrefix = false; clearTimeout(gTimer);
        const map = { i: 'inbox', s: 'sent', d: 'drafts', t: 'trash', a: 'archive' };
        if (map[key] && rolePath(map[key])) { go(rolePath(map[key])); e.preventDefault(); }
        return;
    }
    switch (key) {
        case 'g': gPrefix = true; gTimer = setTimeout(() => { gPrefix = false; }, 1200); break;
        case 'j': case 'ArrowDown': if (menu.value) return; e.preventDefault(); { const n = ids[Math.min(ids.length - 1, idx + 1)]; if (n != null) { cursor.value = n; revealCursor(); if (open.value) openMessage(n); } } break;
        case 'k': case 'ArrowUp': if (menu.value) return; e.preventDefault(); { const n = ids[Math.max(0, idx - 1)]; if (n != null) { cursor.value = n; revealCursor(); if (open.value) openMessage(n); } } break;
        case 'Enter': case 'o': if (cur != null) openMessage(cur); break;
        case 'u': open.value = null; mobileRead.value = false; break;
        case 'x': if (cur != null) toggle(cur); break;
        case 'e': act('archive', target); break;
        case '#': case 'Delete': act('delete', target); break;
        // Ориентир — письмо под курсором, а если его нет (после «выбрать все»), первое выделенное:
        // раньше эти две клавиши в таком случае просто ничего не делали.
        case 's': { const r = row || list.value.messages.find((m) => target.includes(m.uid)); if (r) act(r.flagged ? 'unflag' : 'flag', target); break; }
        case 'i': { const r = row || list.value.messages.find((m) => target.includes(m.uid)); if (r) act(r.seen ? 'unseen' : 'seen', target); break; }
        case '!': act('spam', target); break;
        case 'r': if (open.value) startCompose(settings.value.reply_all ? 'replyAll' : 'reply', open.value); break;
        case 'a': if (open.value) startCompose('replyAll', open.value); break;
        case 'f': if (open.value) startCompose('forward', open.value); break;
        case 'c': startCompose('new'); break;
        // Меню появляется у строки под курсором, а не в жёстко заданной точке 420×160,
        // которая после изменения ширины колонок попадала в чужую колонку.
        case 'z': case 'v': case 'l': if (target.length) {
            const el = document.querySelector('.mrow--cursor') || document.querySelector('.mlist');
            const r = el ? el.getBoundingClientRect() : { left: 320, bottom: 160 };
            menu.value = { kind: { z: 'snooze', v: 'move', l: 'label' }[key], x: Math.round(r.left + 40), y: Math.round(Math.min(r.bottom, window.innerHeight - 120)), uids: target };
        } break;
        case '/': e.preventDefault(); listRef.value?.focusSearch(); break;
        case '?': help.value = true; break;
        case '*': starPrefix = true; clearTimeout(starTimer); starTimer = setTimeout(() => { starPrefix = false; }, 1200); e.preventDefault(); break;
        case 'Escape': if (menu.value) menu.value = null; else if (selected.value.length) selected.value = []; else { open.value = null; mobileRead.value = false; } break;
        default: return;
    }
}

onMounted(() => {
    document.addEventListener('keydown', onKey);
    window.addEventListener('beforeunload', flushPending);
    window.addEventListener('popstate', onPopState);
    // Опрос «есть ли новое» каждые 20 с (60 с в фоне): дёшево (один STATUS), список перечитываем только когда изменился.
    refreshTimer = setInterval(() => poll(), 20000);
    document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') poll(); });
    updateTitle();
    if (props.openUid) openMessage(props.openUid);
    if (props.composeTo !== null) {
        startCompose('new');
        compose.value.to = parseList(props.composeTo);
        window.history.replaceState({}, '', '/mail');
    }
});
onBeforeUnmount(() => {
    document.removeEventListener('keydown', onKey);
    window.removeEventListener('popstate', onPopState);
    window.removeEventListener('beforeunload', flushPending);
    clearInterval(refreshTimer);
    flushPending();
});
</script>

<template>
    <!-- 398: заголовок вкладки собирался ещё и в updateTitle(), в другом формате,
         и побеждал тот, кто отработал последним. Формат теперь один — там. -->
    <MailLayout :user="user" :theme="settings.theme">
        <!-- 361: экранный диктор не сообщал, какая это страница — заголовка не было вовсе.
             Показывать его незачем: название папки и так видно над списком. -->
        <h1 class="sr-only">Почта — {{ folderName }}</h1>
        <div class="mail" :class="{ 'mail--read': mobileRead, 'mail--resizing': resizing }" :style="colStyle">
            <FolderNav
                :class="{ 'mnav--open': navOpen }"
                :folders="folders"
                :labels="labels"
                :folder="folder"
                :filter="filter"
                :outbox="outboxCount"
                :quarantine="quarantine"
                :quota="quota"
                @go="go"
                @compose="startCompose('new')"
                @context="folderContext"
                @drop="onDrop"
                @new-folder="folderDialog('newFolder')"
                @label="labelMenu"
                @outbox="showOutbox"
            />
            <div class="mail__rs" title="Потяните, чтобы изменить ширину; двойной щелчок — как было" @pointerdown="startResize('nav', $event)" @dblclick="resetCol('nav')" />
            <div v-if="navOpen" class="drawer-backdrop" style="z-index: 89" @click="navOpen = false" />

            <MessageList
                ref="listRef"
                :list="list"
                :folder="folder"
                :folder-name="folderName"
                :folder-role="folderInfo.role"
                :highlight-unread="settings.unread_highlight !== false"
                :unread-color="settings.unread_color || ''"
                :density="settings.density || 'normal'"
                :filter="filter"
                :sort="sort"
                @sort="setSort"
                :query="query"
                :everywhere="everywhere"
                @everywhere="setEverywhere"
                :selected="selected"
                :cursor="cursor"
                :open-uid="open?.uid ?? null"
                :opening="opening"
                :labels="labels"
                :loading="loading"
                @open="openMessage"
                @toggle="toggle"
                @select-all="selectAll"
                @clear="selected = []"
                @act="act"
                @context="openMenu"
                @page="load"
                @filter="setFilter"
                @search="search"
                @refresh="refresh"
                @menu="navOpen = true"
            />
            <div class="mail__rs" title="Потяните, чтобы изменить ширину; двойной щелчок — как было" @pointerdown="startResize('list', $event)" @dblclick="resetCol('list')" />

            <section class="mread">
                <Compose
                    v-if="compose"
                    :key="compose.token"
                    :compose="compose"
                    :identities="identities"
                    :settings="settings"
                    :cloud="cloud"
                    @close="onComposeClose"
                    @send="send"
                    @toast="showToast"
                    @draft="onDraftSaved"
                />
                <MessageView
                    v-else-if="open"
                    :message="open"
                    :folder="folder"
                    :folder-role="folderInfo.role"
                    :labels="labels"
                    :settings="settings"
                    :user="user"
                    @act="act"
                    @reply="startCompose"
                    @quick="quickReply"
                    @context="openMenu"
                    @back="mobileRead = false"
                    @unsubscribe="unsubscribe"
                    @meeting="meetingFrom"
                />
                <div v-else-if="selected.length" class="mread__empty">
                    <b>Выбрано {{ selected.length }}</b>
                    <span>Действия — на панели над списком или клавишами:
                        <span class="kbd">e</span> архив <span class="kbd">#</span> удалить <span class="kbd">v</span> в папку <span class="kbd">l</span> метка</span>
                </div>
                <div v-else-if="!list.messages.length && folderInfo.role === 'inbox' && filter === 'all' && !query && !loading" class="mread__empty">
                    <div class="mread__ok"><Icon name="check" :size="32" /></div>
                    <b>Всё разобрано</b>
                    <span>Новых писем нет.</span>
                </div>
                <div v-else class="mread__empty">
                    <span>Выберите письмо слева</span>
                    <span class="hint"><span class="kbd">j</span> <span class="kbd">k</span> — по списку, <span class="kbd">c</span> — написать, <span class="kbd">?</span> — все клавиши</span>
                </div>
            </section>

            <button class="fab" type="button" title="Написать" @click="startCompose('new')" aria-label="Написать"><Icon name="edit" :size="24" /></button>
        </div>

        <!-- Контекстное меню письма -->
        <Popover v-if="menu && menu.kind === 'context'" :x="menu.x" :y="menu.y" @close="menu = null">
            <template v-if="menuRow && folderInfo.role !== 'drafts'">
                <button class="pop__item" type="button" @click="openThen('reply')"><Icon name="reply" :size="16" />Ответить<span class="k">r</span></button>
                <button class="pop__item" type="button" @click="openThen('forward')"><Icon name="fwd" :size="16" />Переслать<span class="k">f</span></button>
                <button class="pop__item" type="button" title="Открыть как новое письмо: те же получатели, тема, текст и вложения" @click="openThen('again')"><Icon name="edit" :size="16" />Изменить как новое</button>
                <div class="pop__sep" />
            </template>
            <!-- Для пачки писем показываем оба действия: раньше предлагался единственный пункт
                 «Непрочитано», то есть ровно противоположный ожидаемому. -->
            <template v-if="menu.uids.length > 1">
                <button class="pop__item" type="button" @click="act('seen', menu.uids)"><Icon name="eye" :size="16" />Прочитано</button>
                <button class="pop__item" type="button" @click="act('unseen', menu.uids)"><Icon name="unread" :size="16" />Непрочитано</button>
            </template>
            <button v-else class="pop__item" type="button" @click="act(menuRow && !menuRow.seen ? 'seen' : 'unseen', menu.uids)"><Icon name="eye" :size="16" />{{ menuRow && !menuRow.seen ? 'Прочитано' : 'Непрочитано' }}<span class="k">i</span></button>
            <button class="pop__item" type="button" @click="act(menuRow?.flagged ? 'unflag' : 'flag', menu.uids)"><Icon name="flag" :size="16" />{{ menuRow?.flagged ? 'Снять флажок' : 'Флажок' }}<span class="k">s</span></button>
            <button class="pop__item" type="button" @click="menu = { ...menu, kind: 'snooze' }"><Icon name="clock" :size="16" />Отложить до…<span class="k">z</span></button>
            <div class="pop__sep" />
            <button class="pop__item" type="button" @click="menu = { ...menu, kind: 'move' }"><Icon name="folder" :size="16" />В папку<span class="k">v</span></button>
            <button class="pop__item" type="button" @click="menu = { ...menu, kind: 'label' }"><Icon name="tag" :size="16" />Метка<span class="k">l</span></button>
            <button class="pop__item" type="button" @click="act('archive', menu.uids)"><Icon name="archive" :size="16" />Архив<span class="k">e</span></button>
            <div class="pop__sep" />
            <button v-if="folderInfo.role !== 'spam'" class="pop__item" type="button" @click="askSender('spam', menu.uids)"><Icon name="spam" :size="16" />Спам<span class="k">!</span></button>
            <button v-else class="pop__item" type="button" @click="askSender('ham', menu.uids)"><Icon name="inbox" :size="16" />Не спам</button>
            <button v-if="folderInfo.role !== 'lists' && folderInfo.role !== 'spam'" class="pop__item" type="button" @click="askSender('lists', menu.uids)"><Icon name="ul" :size="16" />Рассылка</button>
            <button v-if="folderInfo.role === 'snoozed'" class="pop__item" type="button" @click="act('unsnooze', menu.uids)"><Icon name="inbox" :size="16" />Вернуть во Входящие</button>
            <button class="pop__item pop__item--danger" type="button" @click="act('delete', menu.uids)"><Icon name="trash" :size="16" />{{ folderInfo.role === 'trash' ? 'Удалить навсегда' : 'Удалить' }}<span class="k">#</span></button>
        </Popover>

        <Popover v-if="menu && menu.kind === 'snooze'" :x="menu.x" :y="menu.y" @close="menu = null">
            <div class="pop__title">Вернуть во Входящие</div>
            <button v-for="p in presets()" :key="p.label" class="pop__item" type="button" @click="snooze(p.at)">{{ p.label }}<span class="k">{{ p.sub }}</span></button>
            <div class="pop__sep" />
            <div class="pop__form">
                <input v-model="customSnooze" class="input" type="datetime-local" :min="nowInput" style="height: 34px">
                <button class="btn btn--sm" type="button" :disabled="!customSnooze" @click="snooze(new Date(customSnooze))">Ок</button>
            </div>
        </Popover>

        <Popover v-if="menu && menu.kind === 'move'" :x="menu.x" :y="menu.y" @close="menu = null">
            <div class="pop__title">Переместить в</div>
            <button
                v-for="f in folders.filter((f) => f.path !== folder && f.role !== 'drafts' && f.role !== 'sent')"
                :key="f.path"
                class="pop__item"
                type="button"
                :style="{ paddingLeft: 12 + f.depth * 14 + 'px' }"
                @click="moveTo(menu.uids, f.path)"
            >
                <Icon :name="f.role === 'custom' ? 'folder' : 'inbox'" :size="15" style="color: var(--faint)" />{{ f.name }}
            </button>
            <div class="pop__sep" />
            <button class="pop__item" type="button" @click="folderDialog('newFolder')"><Icon name="plus" :size="15" />Новая папка…</button>
        </Popover>

        <Popover v-if="menu && menu.kind === 'label'" :x="menu.x" :y="menu.y" @close="menu = null">
            <div class="pop__title">Метки</div>
            <button
                v-for="l in labels"
                :key="l.id"
                class="pop__item"
                type="button"
                @click="act(menuRow?.labels?.includes(l.id) ? 'unlabel' : 'label', menu.uids, { label: l.id })"
            >
                <span class="sw" :style="{ background: l.color }" />{{ l.name }}
                <span v-if="menuRow?.labels?.includes(l.id)" class="k"><Icon name="check" :size="14" /></span>
            </button>
            <div v-if="!labels.length" class="pop__hint">Меток пока нет</div>
            <div class="pop__sep" />
            <button class="pop__item" type="button" @click="menu = null; dialog = { kind: 'label' }"><Icon name="plus" :size="15" />Новая метка…</button>
        </Popover>

        <Popover v-if="menu && menu.kind === 'more'" :x="menu.x" :y="menu.y" @close="menu = null">
            <!-- 341: на телефоне панель действий письма не вмещала все кнопки, поэтому
                 те, что там спрятаны, добавлены сюда — на широком экране они не показываются. -->
            <template v-if="open && menu.uids.length === 1 && menu.uids[0] === open.uid">
                <button class="pop__item mobile-only" type="button" @click="menu = null; startCompose('replyAll', open)"><Icon name="replyall" :size="16" />Ответить всем</button>
                <button class="pop__item mobile-only" type="button" @click="menu = null; meetingFrom(open)"><Icon name="cal" :size="16" />Назначить встречу</button>
                <button class="pop__item mobile-only" type="button" @click="menu = { ...menu, kind: 'label' }"><Icon name="tag" :size="16" />Метка…</button>
                <button class="pop__item mobile-only" type="button" @click="menu = { ...menu, kind: 'snooze' }"><Icon name="clock" :size="16" />Отложить…</button>
                <button class="pop__item mobile-only" type="button" @click="menu = null; printOpen(open)"><Icon name="print" :size="16" />Печать</button>
                <div class="pop__sep mobile-only" />
            </template>
            <button class="pop__item" type="button" @click="act('unseen', menu.uids)"><Icon name="unread" :size="16" />Пометить непрочитанным</button>
            <button class="pop__item" type="button" @click="menu = { ...menu, kind: 'remind' }"><Icon name="bell" :size="16" />Напомнить, если не ответят…</button>
            <!-- Оба пункта работают с одним письмом: при выделенной пачке честно говорим, с каким именно. -->
            <a class="pop__item" :href="api.rawUrl(folder, menu.uids[0])"><Icon name="download" :size="16" />Скачать .eml<span v-if="menu.uids.length > 1" class="k">только первое</span></a>
            <a class="pop__item" :href="api.rawUrl(folder, menu.uids[0]) + '?inline=1'" target="_blank" rel="noopener"><Icon name="code" :size="16" />Показать оригинал<span v-if="menu.uids.length > 1" class="k">только первое</span></a>
        </Popover>

        <Popover v-if="menu && menu.kind === 'remind'" :x="menu.x" :y="menu.y" @close="menu = null">
            <div class="pop__title">Напомнить, если не ответят</div>
            <button v-for="d in [1, 2, 3, 5, 7]" :key="d" class="pop__item" type="button" @click="act('remind', menu.uids, { until: new Date(Date.now() + d * 86400000).toISOString() })">через {{ d }} {{ d === 1 ? 'день' : d < 5 ? 'дня' : 'дней' }}</button>
        </Popover>

        <!-- Меню папки -->
        <Popover v-if="menu && menu.kind === 'folder'" :x="menu.x" :y="menu.y" @close="menu = null">
            <div class="pop__title">{{ menu.folder.name }}</div>
            <button class="pop__item" type="button" @click="go(menu.folder.path); menu = null"><Icon name="folder" :size="15" />Открыть</button>
            <button class="pop__item" type="button" @click="act('seen', list.messages.map((m) => m.uid))" :disabled="menu.folder.path !== folder"><Icon name="eye" :size="15" />Прочитать все на странице</button>
            <template v-if="menu.folder.role === 'custom'">
                <button class="pop__item" type="button" @click="folderDialog('newFolder', menu.folder)"><Icon name="plus" :size="15" />Вложенная папка…</button>
                <button class="pop__item" type="button" @click="folderDialog('renameFolder', menu.folder)"><Icon name="edit" :size="15" />Переименовать…</button>
                <button class="pop__item pop__item--danger" type="button" @click="folderDialog('deleteFolder', menu.folder)"><Icon name="trash" :size="15" />Удалить папку…</button>
            </template>
            <button v-if="menu.folder.role === 'trash' || menu.folder.role === 'spam'" class="pop__item pop__item--danger" type="button" @click="folderDialog('emptyFolder', menu.folder)"><Icon name="trash" :size="15" />Очистить…</button>
            <button v-if="menu.folder.role !== 'shared'" class="pop__item" type="button" @click="openShare(menu.folder)"><Icon name="share" :size="15" />Общий доступ…</button>
            <div v-else class="pop__hint">Папка {{ menu.folder.ownerName }} — доступ настраивает владелец</div>
        </Popover>

        <Popover v-if="menu && menu.kind === 'labelctx'" :x="menu.x" :y="menu.y" @close="menu = null">
            <div class="pop__title">{{ menu.label.name }}</div>
            <div class="color-dots" style="padding: 4px 12px 8px">
                <button v-for="c in COLORS" :key="c" type="button" :class="{ on: menu.label.color === c }" :style="{ background: c }" @click="recolor(menu.label, c); menu = null" />
            </div>
            <button class="pop__item" type="button" @click="dialog = { kind: 'renameLabel', label: menu.label }; menu = null"><Icon name="edit" :size="15" />Переименовать…</button>
            <button class="pop__item pop__item--danger" type="button" @click="dialog = { kind: 'deleteLabel', label: menu.label }; menu = null"><Icon name="trash" :size="15" />Удалить метку…</button>
        </Popover>

        <!-- Диалоги -->
        <!-- 368: своё окно вело себя не как общий диалог — не закрывалось по Escape
             и не ставило фокус на первую кнопку. -->
        <div v-if="dialog && dialog.kind === 'sender'" class="overlay" @mousedown.self="dialog = null">
            <div ref="senderBox" class="dialog" role="dialog" aria-modal="true" aria-labelledby="sender-dlg-title">
                <h2 id="sender-dlg-title">{{ dialog.what === 'folder' ? 'В папку «' + dialog.folder.name + '»' : SENDER_TITLE[dialog.what] }}</h2>
                <p class="hint" style="margin: 0 0 12px">
                    <template v-if="dialog.what === 'folder'">Письмо перемещено. Класть в «{{ dialog.folder.name }}» все письма от этого отправителя — и те, что придут потом?</template>
                    <template v-else-if="dialog.what === 'ham'">Письмо вернулось во «Входящие». Чтобы фильтр больше не задерживал такие письма, добавьте отправителя в исключения:</template>
                    <template v-else>Письмо перемещено. Сделать так со всеми письмами от этого отправителя — и с теми, что придут потом?</template>
                </p>
                <div style="display: grid; gap: 8px">
                    <button class="btn btn--primary" type="button" :disabled="dialog.busy" @click="markSender('address')">{{ dialog.what === 'ham' ? 'Адрес' : 'Все письма с адреса' }} <b>{{ dialog.mails.length === 1 ? dialog.mails[0] : dialog.mails.length + ' ' + plural(dialog.mails.length, 'адрес', 'адреса', 'адресов') }}</b></button>
                    <button class="btn" type="button" :disabled="dialog.busy" @click="markSender('domain')">{{ dialog.what === 'ham' ? 'Весь домен' : 'Все письма с домена' }} <b>{{ dialog.domains.length === 1 ? '@' + dialog.domains[0] : dialog.domains.length + ' ' + plural(dialog.domains.length, 'домен', 'домена', 'доменов') }}</b></button>
                </div>
                <div v-if="dialog.what !== 'ham'" style="margin-top: 12px; display: flex; gap: 10px; align-items: center"><label class="toggle"><input v-model="dialog.resort" type="checkbox"><span class="toggle__track" /></label><span>Сразу разложить уже полученные письма по всем папкам</span></div>
                <p class="hint" style="margin: 12px 0 0">{{ dialog.what === 'folder' ? 'Правило появится в Настройках → Правила, там его можно изменить или удалить.' : dialog.what === 'ham' ? 'Исключение действует для всей компании: сервер перестанет считать эти письма спамом.' : 'Правило появится в ваших «Правилах». Когда так же отметят несколько сотрудников, оно станет общим для всех ящиков.' }}</p>
                <div class="dialog__actions"><a v-if="dialog.what === 'folder'" href="#" class="hint" style="margin-right: auto" @click.prevent="stopAskingOnMove">Больше не спрашивать</a><button class="btn" type="button" @click="dialog = null">Только это письмо</button></div>
            </div>
        </div>
        <Dialog v-if="dialog && dialog.kind === 'newFolder'" :title="dialog.folder ? 'Папка внутри «' + dialog.folder.name + '»' : 'Новая папка'" :prompt="{ label: 'Название', placeholder: 'Например, Клиенты', maxlength: 80 }" confirm-label="Создать" @close="dialog = null" @confirm="confirmDialog" />
        <Dialog v-if="dialog && dialog.kind === 'renameFolder'" title="Переименовать папку" :prompt="{ label: 'Новое название', value: dialog.folder.name, maxlength: 80 }" confirm-label="Сохранить" @close="dialog = null" @confirm="confirmDialog" />
        <Dialog v-if="dialog && dialog.kind === 'deleteFolder'" :title="'Удалить папку «' + dialog.folder.name + '»?'" confirm-label="Удалить" danger @close="dialog = null" @confirm="confirmDialog">
            <p style="margin: 0" class="hint">Письма в ней ({{ dialog.folder.total }}) будут удалены вместе с папкой.</p>
        </Dialog>
        <Dialog v-if="dialog && dialog.kind === 'emptyFolder'" :title="'Очистить «' + dialog.folder.name + '»?'" confirm-label="Очистить" danger @close="dialog = null" @confirm="confirmDialog">
            <p style="margin: 0" class="hint">Все письма ({{ dialog.folder.total }}) будут удалены навсегда.</p>
        </Dialog>
        <Dialog v-if="dialog && dialog.kind === 'label'" title="Новая метка" :prompt="{ label: 'Название', placeholder: 'Например, Срочно' }" confirm-label="Создать" @close="dialog = null" @confirm="confirmDialog">
            <div class="color-dots"><button v-for="c in COLORS" :key="c" type="button" :class="{ on: (dialog.color || COLORS[0]) === c }" :style="{ background: c }" @click="dialog.color = c" /></div>
        </Dialog>
        <Dialog v-if="dialog && dialog.kind === 'renameLabel'" title="Переименовать метку" :prompt="{ label: 'Название', value: dialog.label.name, maxlength: 80 }" confirm-label="Сохранить" @close="dialog = null" @confirm="confirmDialog" />
        <Dialog v-if="dialog && dialog.kind === 'deleteLabel'" :title="'Удалить метку «' + dialog.label.name + '»?'" confirm-label="Удалить" danger @close="dialog = null" @confirm="confirmDialog">
            <p style="margin: 0" class="hint">Письма останутся, метка с них снимется при следующем разборе.</p>
        </Dialog>
        <Dialog v-if="dialog && dialog.kind === 'share'" :title="'Общий доступ: ' + dialog.folder.name" confirm-label="Готово" wide @close="dialog = null" @confirm="dialog = null">
            <p class="hint" style="margin: 0 0 10px">Сотрудник увидит эту папку у себя в разделе «Общие папки». Читатель только смотрит и помечает прочитанным, редактор ещё перекладывает и удаляет письма.<template v-if="dialog.folder.role === 'inbox'"> Владелец, кроме этого, может писать от имени этого ящика. Редактору и владельцу вместе с «Входящими» открываются «Спам», «Корзина» и другие системные папки ящика.</template></p>
            <div class="mset__list">
                <div v-for="s in dialog.shares" :key="s.mail" class="mset__li">
                    <div class="grow"><div>{{ s.name }}</div><div class="sub">{{ s.mail }}</div></div>
                    <select class="input" style="width: 130px; height: 32px" :value="s.level" @change="shareSet(s.mail, $event.target.value)"><option value="reader">читатель</option><option value="editor">редактор</option><option v-if="dialog.folder.role === 'inbox'" value="owner">владелец</option></select>
                    <button class="btn btn--sm" type="button" @click="shareRemove(s.mail)">Закрыть доступ</button>
                </div>
                <div v-if="!dialog.shares.length" class="empty" style="padding: 12px">Пока никому не открыта</div>
            </div>
            <div class="field__row" style="margin-top: 12px">
                <select v-model="dialog.pick" class="input" style="flex: 1; height: 34px"><option value="" disabled>кому открыть…</option><option v-for="c in dialog.candidates.filter((c) => !dialog.shares.some((s) => s.mail === c.mail))" :key="c.mail" :value="c.mail">{{ c.name }} — {{ c.mail }}</option></select>
                <select v-model="dialog.level" class="input" style="width: 130px; height: 34px"><option value="reader">читатель</option><option value="editor">редактор</option><option v-if="dialog.folder.role === 'inbox'" value="owner">владелец</option></select>
                <button class="btn btn--primary" type="button" :disabled="!dialog.pick" @click="shareSet(dialog.pick, dialog.level); dialog.pick = ''">Открыть</button>
            </div>
        </Dialog>
        <Dialog v-if="dialog && dialog.kind === 'outbox'" title="Ждут отправки" confirm-label="Закрыть" @close="dialog = null" @confirm="dialog = null">
            <div class="mset__list">
                <div v-for="r in dialog.rows" :key="r.id" class="mset__li">
                    <div class="grow">
                        <div>{{ r.subject || '(без темы)' }}</div>
                        <div class="sub">кому: {{ (r.recipients || []).join(', ') }} · {{ r.status === 'scheduled' ? when(r.send_at, true) : r.status === 'sent' ? 'отправлено' : 'ошибка: ' + r.error }}</div>
                    </div>
                    <button v-if="r.status === 'scheduled'" class="btn btn--sm" type="button" @click="cancelOutbox(r.id)">Отменить</button>
                </div>
                <div v-if="!dialog.rows.length" class="empty">Очередь пуста</div>
            </div>
        </Dialog>

        <ShortcutsHelp v-if="help" @close="help = false" />
        <Toast :toast="toast" @action="undoToast" @close="toast = null" />
    </MailLayout>
</template>
