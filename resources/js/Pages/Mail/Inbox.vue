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
import { addrString, escapeHtml, presets, when } from '../../mail/format';

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
const list = ref(props.list);
const selected = ref([]);
const cursor = ref(null);
const open = ref(null);
const loading = ref(false);
const compose = ref(null);
const menu = ref(null);      // { kind, x, y, uids, folder, label }
const toast = ref(null);
const dialog = ref(null);    // { kind, ... }
const help = ref(false);
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

function syncUrl() {
    const p = new URLSearchParams();
    if (filter.value !== 'all') p.set('filter', filter.value);
    if (query.value) p.set('q', query.value);
    const qs = p.toString();
    window.history.replaceState({}, '', `/mail/folder/${encodeURIComponent(folder.value)}${qs ? '?' + qs : ''}`);
}

// ── Списки ────────────────────────────────────────────────────
// ── Живое обновление ──────────────────────────────────────────
let lastUidnext = null;
let lastPoll = 0;
const shownReminders = new Set(JSON.parse(localStorage.getItem('mail.reminders.shown') || '[]'));
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
            await load(list.value.page, true);
            const fresh = (list.value.messages || []).filter((m) => m.uid >= prev && m.unread);
            fresh.slice(0, 3).forEach((m) => notify(m.from?.name || m.from?.mail || 'Новое письмо', m.subject || '(без темы)', 'mail-' + m.uid, () => openMessage(m.uid)));
            if (fresh.length > 3) notify('Новые письма', `и ещё ${fresh.length - 3}`, 'mail-more');
        }
        lastUidnext = st.folder.uidnext;
        for (const r of st.reminders || []) {
            if (shownReminders.has(r.key)) continue;
            shownReminders.add(r.key);
            localStorage.setItem('mail.reminders.shown', JSON.stringify([...shownReminders].slice(-200)));
            const t = r.allDay ? 'сегодня' : new Date(r.start).toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
            notify('Напоминание: ' + r.title, (r.allDay ? 'Весь день' : 'В ' + t) + (r.location ? ' · ' + r.location : ''), 'rem-' + r.key, () => { window.location.href = '/calendar'; });
            showToast({ text: 'Напоминание: ' + r.title + ' — ' + t }, 8000);
        }
    } catch {}
}

async function load(page = 1, keepOpen = false) {
    loading.value = true;
    try {
        const r = await api.list(folder.value, { page, filter: filter.value, q: query.value });
        list.value = { messages: r.messages, total: r.total, page: r.page, pages: r.pages };
        folders.value = r.folders;
        selected.value = [];
        if (!keepOpen) { open.value = null; cursor.value = null; }
        syncUrl();
    } catch (e) { fail(e); } finally { loading.value = false; }
}

function go(path, f = 'all') {
    folder.value = path;
    filter.value = f;
    query.value = '';
    navOpen.value = false;
    mobileRead.value = false;
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
    loading.value = true;
    try {
        const m = await api.message(folder.value, uid);
        open.value = m;
        mobileRead.value = true;
        if (row && !row.seen) { row.seen = true; bump(folder.value, -1); }
    } catch (e) { fail(e); } finally { loading.value = false; }
}

function bump(path, delta) {
    const f = folders.value.find((x) => x.path === path);
    if (f) f.unread = Math.max(0, (f.unread || 0) + delta);
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
    bump(folder.value, -unreadGone);
    selected.value = selected.value.filter((u) => !set.has(u));
    if (open.value && set.has(open.value.uid)) { open.value = null; mobileRead.value = false; }
}

async function act(op, uids, extra = {}) {
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
    try {
        const r = await api.action(folder.value, uids, op, extra);
        if (r.folders) folders.value = r.folders;
        const names = { delete: 'Удалено', archive: 'В архиве', spam: 'Помечено как спам', move: 'Перемещено', snooze: 'Отложено', notspam: 'Возвращено во Входящие', remind: 'Напомню, если не ответят' };
        if (names[op]) showToast({ text: `${names[op]}${uids.length > 1 ? ' · ' + uids.length : ''}` }, 2500);
    } catch (e) {
        fail(e);
        load(list.value.page, true);
    }
}

function onDrop(data, target) {
    if (data.folder === folder.value) moveTo(data.uids, target);
}

// Перенос в свою папку из «Входящих»: после переноса предлагаем правило «всегда класть сюда письма от…».
function moveTo(uids, target) {
    const f = folders.value.find((x) => x.path === target);
    const ask = settings.value.ask_rule_on_move !== false && folderInfo.value.role === 'inbox' && f && (f.role === 'custom' || f.role === 'lists');
    if (!ask) { act('move', uids, { target }); return; }
    askSender('folder', uids, f);
}

// ── Решение по отправителю: спам / рассылка / не спам ────────────
// Сначала письмо уезжает в папку (act), затем спрашиваем: только это письмо или все от адреса/домена.
function askSender(kind, uids, targetFolder = null) {
    const rows = list.value.messages.filter((m) => uids.includes(m.uid));
    const mails = [...new Set(rows.map((m) => m.from?.mail).concat(open.value && uids.includes(open.value.uid) ? [open.value.from?.mail] : []).filter(Boolean).map((s) => s.toLowerCase()))];
    if (kind === 'folder') act('move', uids, { target: targetFolder.path }); else act(kind === 'ham' ? 'notspam' : kind, uids);
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
        for (const v of values) {
            const r = await api.markSender(d.what, match, v, d.resort, d.folder?.path || null);
            moved += r.moved || 0; global = global || r.global; personalOnly = personalOnly || !!r.personalOnly; if (r.threshold > 1 && !r.personalOnly) votes = `${r.votes} из ${r.threshold}`;
            if (r.folders) folders.value = r.folders;
        }
        dialog.value = null;
        const who = values.length === 1 ? values[0] : `${values.length} ${match === 'domain' ? 'домена' : 'адреса'}`;
        const tail = d.what === 'folder' ? '' : personalOnly ? ' Отправитель вашего домена: правило только у вас, общим не станет.' : d.what === 'ham' ? (global ? ' Фильтр больше не тронет эти письма — у всех сотрудников.' : ' Заявка на исключение ушла администратору.') : (global ? ' Правило стало общим для всех сотрудников.' : (votes ? ` Станет общим для всех, когда так отметят ${votes.split(' из ')[1]} сотрудника (сейчас ${votes.split(' из ')[0]}).` : ''));
        showToast({ text: `${who}: правило добавлено${moved ? `, перемещено писем: ${moved}` : ''}.${tail}` }, 8000);
        await refresh();
    } catch (e) { d.busy = false; fail(e); }
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

watch(folder, () => { lastUidnext = null; updateTitle(); });
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
    dialog.value = null;
    try {
        if (d.kind === 'newFolder') { const r = await api.createFolder(value, d.folder?.path || null); folders.value = r.folders; showToast({ text: 'Папка создана' }); }
        if (d.kind === 'renameFolder') { const r = await api.renameFolder(d.folder.path, value); folders.value = r.folders; if (folder.value === d.folder.path) folder.value = r.path; }
        if (d.kind === 'deleteFolder') { const r = await api.deleteFolder(d.folder.path); folders.value = r.folders; if (folder.value === d.folder.path) go('INBOX'); }
        if (d.kind === 'emptyFolder') { const r = await api.emptyFolder(d.folder.path); folders.value = r.folders; if (folder.value === d.folder.path) load(1); }
        if (d.kind === 'label') { labels.value = await api.createLabel(value, d.color || '#2F6FEB'); }
        if (d.kind === 'renameLabel') { labels.value = await api.updateLabel(d.label.id, value, d.label.color); }
        if (d.kind === 'deleteLabel') { labels.value = await api.deleteLabel(d.label.id); if (filter.value === 'label:' + d.label.id) go('INBOX'); }
        if (d.kind === 'outbox' && value?.cancel) { await api.cancelOutbox(value.cancel); }
    } catch (e) { fail(e); }
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
    try { const r = await api.shareFolder(dialog.value.folder.path, mail, level); dialog.value.shares = r.shares; showToast({ text: 'Доступ выдан' }); } catch (e) { fail(e); }
}
async function shareRemove(mail) {
    try { const r = await api.unshareFolder(dialog.value.folder.path, mail); dialog.value.shares = r.shares; } catch (e) { fail(e); }
}
async function recolor(l, color) {
    try { labels.value = await api.updateLabel(l.id, l.name, color); } catch (e) { fail(e); }
}
const COLORS = ['#2F6FEB', '#16A05C', '#D9791F', '#C0392B', '#7B3FE4', '#0E8A8A', '#6B7787'];

// ── Написать ──────────────────────────────────────────────────
function signature(forReply) {
    const s = settings.value.signature || '';
    if (!s || (forReply && !settings.value.signature_reply)) return '';
    return `<p><br></p><div class="sig">${s}</div>`;
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
function startCompose(mode = 'new', m = null, text = '') {
    menu.value = null;
    if (mode === 'draft') { openDraft(m.uid); return; }
    const c = { mode, to: [], cc: [], bcc: [], subject: '', html: '' };
    if (mode === 'new') {
        c.html = `<p>${escapeHtml(text)}</p>${signature(false)}`;
    } else if (mode === 'reply' || mode === 'replyAll') {
        c.to = replyTargets(m).filter((a) => !me(a) || replyTargets(m).length === 1);
        if (mode === 'replyAll') {
            const seen = new Set(c.to.map((a) => a.mail));
            [...m.to, ...(m.cc || [])].forEach((a) => { if (!me(a) && !seen.has(a.mail)) { seen.add(a.mail); c.cc.push(a); } });
        }
        c.subject = /^re:/i.test(m.subject) ? m.subject : 'Re: ' + (m.subject === '(без темы)' ? '' : m.subject);
        c.html = `<p>${escapeHtml(text)}</p>${signature(true)}${quote(m)}`;
        c.inReplyTo = m.messageId;
        c.references = [m.references, m.messageId].filter(Boolean).join(' ');
        c.answeredFolder = m.folder; c.answeredUid = m.uid;
        c.attachments = m.attachments || []; c.sourceFolder = m.folder; c.sourceUid = m.uid; c.keepAttachments = false;
    } else if (mode === 'forward') {
        c.subject = /^fwd?:/i.test(m.subject) ? m.subject : 'Fwd: ' + (m.subject === '(без темы)' ? '' : m.subject);
        const hdr = `<div style="color:#6B7787">---------- Пересланное письмо ----------<br>От: ${escapeHtml(m.from.name)} &lt;${escapeHtml(m.from.mail)}&gt;<br>Дата: ${escapeHtml(when(m.date, true))}<br>Тема: ${escapeHtml(m.subject)}<br>Кому: ${escapeHtml(addrString(m.to))}</div><br>`;
        c.html = `<p><br></p>${signature(true)}<p><br></p>${hdr}${m.html || `<pre style="white-space:pre-wrap;font:inherit">${escapeHtml(m.text || '')}</pre>`}`;
        c.references = [m.references, m.messageId].filter(Boolean).join(' ');
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
        compose.value = { mode: 'draft', ...d, to: parseList(d.to), cc: parseList(d.cc), bcc: [], keepAttachments: d.attachments?.length > 0, sourceFolder: rolePath('drafts'), sourceUid: uid };
        mobileRead.value = true;
    } catch (e) { fail(e); }
}
function parseList(s) {
    return (s || '').split(/,(?![^<]*>)/).map((p) => p.trim()).filter(Boolean).map((p) => {
        const m = p.match(/^"?([^"<]*)"?\s*<([^>]+)>$/);
        return m ? { name: m[1].trim(), mail: m[2].trim() } : { name: '', mail: p };
    });
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
    compose.value = formToCompose(p.payload.form);
    mobileRead.value = true;
    showToast({ text: 'Отправка отменена' }, 2000);
}
function formToCompose(f) {
    return { mode: 'new', ...f, to: parseList(f.to), cc: parseList(f.cc), bcc: parseList(f.bcc), attachments: [] };
}
function flushPending() {
    if (!pending) return;
    clearTimeout(pending.timer);
    const p = pending; pending = null;
    api.send(composeForm(p.payload.form, p.payload.files), { keepalive: true }).catch(() => {});
}

async function quickReply({ text, message: m }) {
    const to = replyTargets(m);
    const form = {
        to: addrString(to),
        subject: /^re:/i.test(m.subject) ? m.subject : 'Re: ' + m.subject,
        html: `<p>${escapeHtml(text).replace(/\n/g, '<br>')}</p>${signature(true)}${quote(m)}`,
        inReplyTo: m.messageId,
        references: [m.references, m.messageId].filter(Boolean).join(' '),
        answeredFolder: m.folder, answeredUid: m.uid,
    };
    try {
        const r = await api.send(composeForm(form, []));
        if (r.folders) folders.value = r.folders;
        const row = list.value.messages.find((x) => x.uid === m.uid); if (row) row.answered = true;
        showToast({ text: 'Ответ отправлен' });
    } catch (e) { fail(e); throw e; }
}

/** «Встреча» из письма: событие с темой письма и всеми участниками переписки. */
function meetingFrom(m) {
    const people = [m.from, ...(m.to || []), ...(m.cc || [])].map((a) => a.mail).filter((x) => x && !me({ mail: x }));
    const p = new URLSearchParams({ new: '1', title: m.subject === '(без темы)' ? '' : m.subject, attendees: [...new Set(people)].join(','), description: (m.text || '').slice(0, 800) });
    router.visit('/calendar?' + p);
}

function unsubscribe(m) {
    const h = m.listUnsubscribe || '';
    const mailto = h.match(/<mailto:([^>]+)>/i);
    const http = h.match(/<(https?:[^>]+)>/i);
    if (http) { window.open(http[1], '_blank', 'noopener'); return; }
    if (mailto) {
        const [addr, qs] = mailto[1].split('?');
        const subj = new URLSearchParams(qs || '').get('subject') || 'Unsubscribe';
        compose.value = { mode: 'new', to: [{ name: '', mail: addr }], cc: [], bcc: [], subject: subj, html: '<p>Unsubscribe</p>' };
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
function onKey(e) {
    if (!settings.value.shortcuts) return;
    const t = e.target;
    if (compose.value || dialog.value || help.value) return;
    if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.isContentEditable)) {
        if (e.key === 'Escape') t.blur();
        return;
    }
    if (e.ctrlKey || e.metaKey || e.altKey) return;
    const ids = list.value.messages.map((m) => m.uid);
    const cur = cursor.value ?? open.value?.uid ?? null;
    const idx = ids.indexOf(cur);
    const target = selected.value.length ? selected.value : (cur != null ? [cur] : []);
    const row = list.value.messages.find((m) => m.uid === cur);

    if (gPrefix) {
        gPrefix = false; clearTimeout(gTimer);
        const map = { i: 'inbox', s: 'sent', d: 'drafts', t: 'trash', a: 'archive' };
        if (map[e.key] && rolePath(map[e.key])) { go(rolePath(map[e.key])); e.preventDefault(); }
        return;
    }
    switch (e.key) {
        case 'g': gPrefix = true; gTimer = setTimeout(() => { gPrefix = false; }, 1200); break;
        case 'j': case 'ArrowDown': if (menu.value) return; e.preventDefault(); { const n = ids[Math.min(ids.length - 1, idx + 1)]; if (n != null) { cursor.value = n; if (open.value) openMessage(n); } } break;
        case 'k': case 'ArrowUp': if (menu.value) return; e.preventDefault(); { const n = ids[Math.max(0, idx - 1)]; if (n != null) { cursor.value = n; if (open.value) openMessage(n); } } break;
        case 'Enter': case 'o': if (cur != null) openMessage(cur); break;
        case 'u': open.value = null; mobileRead.value = false; break;
        case 'x': if (cur != null) toggle(cur); break;
        case 'e': act('archive', target); break;
        case '#': case 'Delete': act('delete', target); break;
        case 's': if (row) act(row.flagged ? 'unflag' : 'flag', target); break;
        case 'i': if (row) act(row.seen ? 'unseen' : 'seen', target); break;
        case '!': act('spam', target); break;
        case 'r': if (open.value) startCompose('reply', open.value); break;
        case 'a': if (open.value) startCompose('replyAll', open.value); break;
        case 'f': if (open.value) startCompose('forward', open.value); break;
        case 'c': startCompose('new'); break;
        case 'z': case 'v': case 'l': if (target.length) { menu.value = { kind: { z: 'snooze', v: 'move', l: 'label' }[e.key], x: 420, y: 160, uids: target }; } break;
        case '/': e.preventDefault(); listRef.value?.focusSearch(); break;
        case '?': help.value = true; break;
        case '*': break;
        case 'Escape': if (menu.value) menu.value = null; else if (selected.value.length) selected.value = []; else { open.value = null; mobileRead.value = false; } break;
        default: return;
    }
}

onMounted(() => {
    document.addEventListener('keydown', onKey);
    window.addEventListener('beforeunload', flushPending);
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
    window.removeEventListener('beforeunload', flushPending);
    clearInterval(refreshTimer);
    flushPending();
});
</script>

<template>
    <Head :title="folderName + (folderInfo.unread ? ` (${folderInfo.unread})` : '')" />
    <MailLayout :user="user" :theme="settings.theme">
        <div class="mail" :class="{ 'mail--read': mobileRead }">
            <FolderNav
                :class="{ 'mnav--open': navOpen }"
                :folders="folders"
                :labels="labels"
                :folder="folder"
                :filter="filter"
                :outbox="outboxCount"
                :quarantine="quarantine"
                @go="go"
                @compose="startCompose('new')"
                @context="folderContext"
                @drop="onDrop"
                @new-folder="folderDialog('newFolder')"
                @label="labelMenu"
                @outbox="showOutbox"
            />
            <div v-if="navOpen" class="drawer-backdrop" style="z-index: 89" @click="navOpen = false" />

            <MessageList
                ref="listRef"
                :list="list"
                :folder="folder"
                :folder-name="folderName"
                :folder-role="folderInfo.role"
                :filter="filter"
                :query="query"
                :selected="selected"
                :cursor="cursor"
                :open-uid="open?.uid ?? null"
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

            <section class="mread">
                <Compose
                    v-if="compose"
                    :key="compose.draftUid || compose.mode + (compose.answeredUid || '') + (compose.sourceUid || '')"
                    :compose="compose"
                    :identities="identities"
                    :settings="settings"
                    :cloud="cloud"
                    @close="onComposeClose"
                    @send="send"
                    @toast="showToast"
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

            <button class="fab" type="button" title="Написать" @click="startCompose('new')"><Icon name="edit" :size="24" /></button>
        </div>

        <!-- Контекстное меню письма -->
        <Popover v-if="menu && menu.kind === 'context'" :x="menu.x" :y="menu.y" @close="menu = null">
            <template v-if="menuRow && folderInfo.role !== 'drafts'">
                <button class="pop__item" type="button" @click="openThen('reply')"><Icon name="reply" :size="16" />Ответить<span class="k">r</span></button>
                <button class="pop__item" type="button" @click="openThen('forward')"><Icon name="fwd" :size="16" />Переслать<span class="k">f</span></button>
                <div class="pop__sep" />
            </template>
            <button class="pop__item" type="button" @click="act(menuRow && !menuRow.seen ? 'seen' : 'unseen', menu.uids)"><Icon name="eye" :size="16" />{{ menuRow && !menuRow.seen ? 'Прочитано' : 'Непрочитано' }}<span class="k">i</span></button>
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
                <input v-model="customSnooze" class="input" type="datetime-local" style="height: 34px">
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
            <button class="pop__item" type="button" @click="act('unseen', menu.uids)"><Icon name="unread" :size="16" />Пометить непрочитанным</button>
            <button class="pop__item" type="button" @click="menu = { ...menu, kind: 'remind' }"><Icon name="bell" :size="16" />Напомнить, если не ответят…</button>
            <a class="pop__item" :href="api.rawUrl(folder, menu.uids[0])"><Icon name="download" :size="16" />Скачать .eml</a>
            <a class="pop__item" :href="api.rawUrl(folder, menu.uids[0])" target="_blank" rel="noopener"><Icon name="code" :size="16" />Показать оригинал</a>
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
        <div v-if="dialog && dialog.kind === 'sender'" class="overlay" @mousedown.self="dialog = null">
            <div class="dialog">
                <h2>{{ dialog.what === 'folder' ? 'В папку «' + dialog.folder.name + '»' : SENDER_TITLE[dialog.what] }}</h2>
                <p class="hint" style="margin: 0 0 12px">
                    <template v-if="dialog.what === 'folder'">Письмо перемещено. Класть в «{{ dialog.folder.name }}» все письма от этого отправителя — и те, что придут потом?</template>
                    <template v-else-if="dialog.what === 'ham'">Письмо вернулось во «Входящие». Чтобы фильтр больше не задерживал такие письма, добавьте отправителя в исключения:</template>
                    <template v-else>Письмо перемещено. Сделать так со всеми письмами от этого отправителя — и с теми, что придут потом?</template>
                </p>
                <div style="display: grid; gap: 8px">
                    <button class="btn btn--primary" type="button" :disabled="dialog.busy" @click="markSender('address')">{{ dialog.what === 'ham' ? 'Адрес' : 'Все письма с адреса' }} <b>{{ dialog.mails.length === 1 ? dialog.mails[0] : dialog.mails.length + ' адреса' }}</b></button>
                    <button class="btn" type="button" :disabled="dialog.busy" @click="markSender('domain')">{{ dialog.what === 'ham' ? 'Весь домен' : 'Все письма с домена' }} <b>{{ dialog.domains.length === 1 ? '@' + dialog.domains[0] : dialog.domains.length + ' домена' }}</b></button>
                </div>
                <div v-if="dialog.what !== 'ham'" style="margin-top: 12px; display: flex; gap: 10px; align-items: center"><label class="toggle"><input v-model="dialog.resort" type="checkbox"><span class="toggle__track" /></label><span>Сразу разложить уже полученные письма по всем папкам</span></div>
                <p class="hint" style="margin: 12px 0 0">{{ dialog.what === 'folder' ? 'Правило появится в Настройках → Правила, там его можно изменить или удалить.' : dialog.what === 'ham' ? 'Исключение действует для всей компании: сервер перестанет считать эти письма спамом.' : 'Правило появится в ваших «Правилах». Когда так же отметят несколько сотрудников, оно станет общим для всех ящиков.' }}</p>
                <div class="dialog__actions"><a v-if="dialog.what === 'folder'" href="#" class="hint" style="margin-right: auto" @click.prevent="stopAskingOnMove">Больше не спрашивать</a><button class="btn" type="button" @click="dialog = null">Только это письмо</button></div>
            </div>
        </div>
        <Dialog v-if="dialog && dialog.kind === 'newFolder'" :title="dialog.folder ? 'Папка внутри «' + dialog.folder.name + '»' : 'Новая папка'" :prompt="{ label: 'Название', placeholder: 'Например, Клиенты' }" confirm-label="Создать" @close="dialog = null" @confirm="confirmDialog" />
        <Dialog v-if="dialog && dialog.kind === 'renameFolder'" title="Переименовать папку" :prompt="{ label: 'Новое название', value: dialog.folder.name }" confirm-label="Сохранить" @close="dialog = null" @confirm="confirmDialog" />
        <Dialog v-if="dialog && dialog.kind === 'deleteFolder'" :title="'Удалить папку «' + dialog.folder.name + '»?'" confirm-label="Удалить" danger @close="dialog = null" @confirm="confirmDialog">
            <p style="margin: 0" class="hint">Письма в ней ({{ dialog.folder.total }}) будут удалены вместе с папкой.</p>
        </Dialog>
        <Dialog v-if="dialog && dialog.kind === 'emptyFolder'" :title="'Очистить «' + dialog.folder.name + '»?'" confirm-label="Очистить" danger @close="dialog = null" @confirm="confirmDialog">
            <p style="margin: 0" class="hint">Все письма ({{ dialog.folder.total }}) будут удалены навсегда.</p>
        </Dialog>
        <Dialog v-if="dialog && dialog.kind === 'label'" title="Новая метка" :prompt="{ label: 'Название', placeholder: 'Например, Срочно' }" confirm-label="Создать" @close="dialog = null" @confirm="confirmDialog">
            <div class="color-dots"><button v-for="c in COLORS" :key="c" type="button" :class="{ on: (dialog.color || COLORS[0]) === c }" :style="{ background: c }" @click="dialog.color = c" /></div>
        </Dialog>
        <Dialog v-if="dialog && dialog.kind === 'renameLabel'" title="Переименовать метку" :prompt="{ label: 'Название', value: dialog.label.name }" confirm-label="Сохранить" @close="dialog = null" @confirm="confirmDialog" />
        <Dialog v-if="dialog && dialog.kind === 'deleteLabel'" :title="'Удалить метку «' + dialog.label.name + '»?'" confirm-label="Удалить" danger @close="dialog = null" @confirm="confirmDialog">
            <p style="margin: 0" class="hint">Письма останутся, метка с них снимется при следующем разборе.</p>
        </Dialog>
        <Dialog v-if="dialog && dialog.kind === 'share'" :title="'Общий доступ: ' + dialog.folder.name" confirm-label="Готово" wide @close="dialog = null" @confirm="dialog = null">
            <p class="hint" style="margin: 0 0 10px">Сотрудник увидит эту папку у себя в разделе «Общие папки». Читатель только смотрит и помечает прочитанным, редактор ещё перекладывает и удаляет письма.</p>
            <div class="mset__list">
                <div v-for="s in dialog.shares" :key="s.mail" class="mset__li">
                    <div class="grow"><div>{{ s.name }}</div><div class="sub">{{ s.mail }}</div></div>
                    <select class="input" style="width: 130px; height: 32px" :value="s.level" @change="shareSet(s.mail, $event.target.value)"><option value="reader">читатель</option><option value="editor">редактор</option></select>
                    <button class="btn btn--sm" type="button" @click="shareRemove(s.mail)">Закрыть доступ</button>
                </div>
                <div v-if="!dialog.shares.length" class="empty" style="padding: 12px">Пока никому не открыта</div>
            </div>
            <div class="field__row" style="margin-top: 12px">
                <select v-model="dialog.pick" class="input" style="flex: 1; height: 34px"><option value="" disabled>кому открыть…</option><option v-for="c in dialog.candidates.filter((c) => !dialog.shares.some((s) => s.mail === c.mail))" :key="c.mail" :value="c.mail">{{ c.name }} — {{ c.mail }}</option></select>
                <select v-model="dialog.level" class="input" style="width: 130px; height: 34px"><option value="reader">читатель</option><option value="editor">редактор</option></select>
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
        <Toast :toast="toast" @action="undoSend" @close="toast = null" />
    </MailLayout>
</template>
