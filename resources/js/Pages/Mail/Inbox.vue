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
import PrintPreview from '../../Components/Mail/PrintPreview.vue';
import { api, composeForm } from '../../mail/api';
import { addrString, escapeHtml, hotkey, plural, presets, when } from '../../mail/format';
import { useColumns } from '../../mail/useColumns';
import { useCompose } from '../../mail/useCompose';
import { useHotkeys } from '../../mail/useHotkeys';
import { useLiveUpdates } from '../../mail/useLiveUpdates';
import { useMessageActions } from '../../mail/useMessageActions';
import { useUrlState } from '../../mail/useUrlState';

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

// Адрес страницы, история и кнопка «Назад» — в useUrlState.
const { syncUrl, pushUrl, onPopState } = useUrlState({
    folder, filter, query, sort, list, open, mobileRead, load, rolePath,
});

// ── Списки ────────────────────────────────────────────────────
// ── Живое обновление ──────────────────────────────────────────
// Опрос сервера, счётчик в заголовке вкладки и уведомления — в useLiveUpdates.
const { poll, resetUidnext, schedule, stopPolling, wakeUp } = useLiveUpdates({
    folders, folder, list, settings, compose, menu,
    load, openMessage, showToast,
});

/**
 * Заголовок вкладки: «(3) Входящие». Слово «Почта» добавляет Inertia (app.js).
 *
 * Считать его вручную нельзя: заголовком управляет Inertia, и её отрисовка перетирала
 * выставленный нами document.title — счётчик непрочитанных во вкладке не появлялся никогда.
 */
const tabTitle = computed(() => {
    const inbox = folders.value.find((f) => f.role === 'inbox');
    const n = inbox?.unread || 0;

    return (n ? `(${n}) ` : '') + (folderInfo.value.name || 'Почта');
});

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
// Сами действия и окно отмены — в useMessageActions.
const { act, flushPendingAct, undoAct, undoToast } = useMessageActions({
    list, folder, folders, selected, open, mobileRead, menu, toast, settings, folderInfo,
    showToast, fail, load, refillAfter, bump,
    // Отмена отправки живёт в useCompose, а он создаётся ниже — иначе ему неоткуда взять
    // flushPendingAct. Поэтому здесь не сама функция, а обращение к ней в момент вызова.
    undoSend: () => undoSend(),
});

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

// Ширина колонок «папки» и «список» — в useColumns.
const { colStyle, resizing, startResize, resetCol } = useColumns();

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

// Смена папки отложенное действие не выполняет досрочно: таймер идёт дальше, «Отменить»
// работает и из другой папки — сервер ещё ничего не делал, а папку действие помнит само.
watch(folder, () => { resetUidnext(); });

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
        if (d.kind === 'deleteFolder') { const r = await api.deleteFolder(d.folder.path); if (Array.isArray(r?.folders)) folders.value = r.folders; if (r?.moved) showToast({ text: `Папка удалена, ${plural(r.moved, 'письмо', 'письма', 'писем')} — в «Корзине»` }); if (folder.value === d.folder.path) go('INBOX'); }
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

/** Предпросмотр печати поверх почты: печать — только по кнопке в нём. */
const printing = ref(null);
function printOpen(m) {
    if (m) printing.value = { folder: m.folder, uid: m.uid, subject: m.subject };
}

// ── Написать ──────────────────────────────────────────────────
// Вся работа с формой письма, отправкой и отменой — в useCompose.
const {
    startCompose, openThen, openDraft, onDraftSaved, onComposeClose,
    send, undoSend, flushPending, quickReply, meetingFrom, unsubscribe,
    showOutbox, cancelOutbox, parseList,
} = useCompose({
    props, settings, folders, folder, folderInfo, compose, open, cursor, mobileRead,
    menu, toast, list, dialog, outboxCount,
    load, refresh, fail, showToast, flushPendingAct, rolePath, router,
});

// ── Горячие клавиши ───────────────────────────────────────────
// Разбор нажатий — в useHotkeys; здесь остаётся только подписка (см. onMounted).
const { onKey } = useHotkeys({
    settings, list, cursor, open, selected, menu, compose, dialog, help, mobileRead, listRef,
    act, openMessage, toggle, startCompose, go, rolePath,
});

onMounted(() => {
    document.addEventListener('keydown', onKey);
    window.addEventListener('beforeunload', flushPending);
    window.addEventListener('popstate', onPopState);
    // Опрос «есть ли новое»: 20 с, пока что-то происходит, и до 60 с в тишине.
    // Список перечитываем только когда папка изменилась.
    schedule();
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') { wakeUp(); poll(); }
    });
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
    stopPolling();
    flushPending();
});
</script>

<template>
    <!-- 398: заголовок вкладки собирался в двух местах, и побеждал тот, кто отработал
         последним. Теперь он один и вычисляется из счётчика непрочитанных. -->
    <Head :title="tabTitle" />
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
                :readonly="!!folderInfo.readonly"
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
                    @search="search"
                    @print="printOpen"
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
            <!-- Раньше письма уничтожались вместе с папкой. Теперь они переезжают в корзину:
                 папка может выглядеть пустой из-за фильтра, а внутри лежать сотня писем. -->
            <p v-if="folders.some((f) => f.parent === dialog.folder.path)" style="margin: 0" class="hint hint--warn">У этой папки есть вложенные — сервер не удалит её, пока они есть. Сначала удалите их.</p>
            <p v-else style="margin: 0" class="hint">Письма в ней ({{ dialog.folder.total }}) переедут в «Корзину» — оттуда их можно вернуть.</p>
        </Dialog>
        <Dialog v-if="dialog && dialog.kind === 'emptyFolder'" :title="'Очистить «' + dialog.folder.name + '»?'" confirm-label="Очистить" danger @close="dialog = null" @confirm="confirmDialog">
            <p style="margin: 0" class="hint">Все письма ({{ dialog.folder.total }}) будут удалены навсегда.</p>
        </Dialog>
        <Dialog v-if="dialog && dialog.kind === 'label'" title="Новая метка" :prompt="{ label: 'Название', placeholder: 'Например, Срочно' }" confirm-label="Создать" @close="dialog = null" @confirm="confirmDialog">
            <div class="color-dots"><button v-for="c in COLORS" :key="c" type="button" :class="{ on: (dialog.color || COLORS[0]) === c }" :aria-label="'Цвет ' + c" :title="'Цвет ' + c" :style="{ background: c }" @click="dialog.color = c" /></div>
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
        <PrintPreview v-if="printing" :message="printing" @close="printing = null" />
        <Toast :toast="toast" @action="undoToast" @close="toast = null" />
    </MailLayout>
</template>
