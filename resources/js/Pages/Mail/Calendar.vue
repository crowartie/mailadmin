<script setup>
// Календарь: день · неделя · месяц · повестка; событие — панель справа; занятость участников; общий доступ.
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head } from '@inertiajs/vue3';
import MailLayout from '../../Layouts/MailLayout.vue';
import Icon from '../../Components/Icon.vue';
import Popover from '../../Components/Mail/Popover.vue';
import Dialog from '../../Components/Mail/Dialog.vue';
import Toast from '../../Components/Mail/Toast.vue';
import RecipientInput from '../../Components/Mail/RecipientInput.vue';
import { api } from '../../mail/api';
import { initials, toLocalInput } from '../../mail/format';

const props = defineProps({
    user: String,
    userName: String,
    settings: Object,
    isAdmin: Boolean,
    calendars: Array,
    prefill: { type: Object, default: null },
});

// ── Даты ──────────────────────────────────────────────────────
const DAYS = ['пн', 'вт', 'ср', 'чт', 'пт', 'сб', 'вс'];
const DAYS_FULL = ['понедельник', 'вторник', 'среда', 'четверг', 'пятница', 'суббота', 'воскресенье'];
const MONTHS = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
const MONTHS_N = ['Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь', 'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'];
const HOUR = 48; // px на час в сетке
const origin = typeof window !== 'undefined' ? window.location.origin : '';
const day0 = (d) => { const x = new Date(d); x.setHours(0, 0, 0, 0); return x; };
const addDays = (d, n) => { const x = new Date(d); x.setDate(x.getDate() + n); return x; };
const monday = (d) => addDays(day0(d), -((d.getDay() + 6) % 7));
const sameDay = (a, b) => a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
const ymd = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const hm = (d) => d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
const parseDay = (s) => { const [y, m, d] = s.slice(0, 10).split('-').map(Number); return new Date(y, m - 1, d); };

// ── Состояние ─────────────────────────────────────────────────
const calendars = ref(props.calendars);
const hidden = ref(new Set(JSON.parse(localStorage.getItem('cal.hidden') || '[]')));
const view = ref(localStorage.getItem('cal.view') || (window.innerWidth < 700 ? 'day' : 'week'));
const anchor = ref(day0(new Date()));
const events = ref([]);
const loading = ref(false);
const open = ref(null);        // просмотр события
const editing = ref(null);     // форма
const menu = ref(null);
const dialog = ref(null);
const toast = ref(null);
const navOpen = ref(false);
const freebusy = ref({});
const shares = ref([]);
const gridRef = ref(null);
const now = ref(new Date());
let toastTimer = null; let fbTimer = null; let tick = null;

const writable = computed(() => calendars.value.filter((c) => !c.readonly));
const own = computed(() => calendars.value.filter((c) => c.kind === 'personal' || c.kind === 'own'));
const foreign = computed(() => calendars.value.filter((c) => c.kind === 'shared' || c.kind === 'company'));
const calMap = computed(() => Object.fromEntries(calendars.value.map((c) => [c.uri, c])));

function say(text, error = false) {
    clearTimeout(toastTimer);
    toast.value = { text, error };
    toastTimer = setTimeout(() => { toast.value = null; }, error ? 6000 : 3000);
}
function fail(e) { say(e?.message || 'Что-то пошло не так', true); }

// ── Диапазон и загрузка ───────────────────────────────────────
const range = computed(() => {
    const a = anchor.value;
    if (view.value === 'day') return { from: day0(a), to: addDays(a, 1) };
    if (view.value === 'week') return { from: monday(a), to: addDays(monday(a), 7) };
    if (view.value === 'month') { const first = new Date(a.getFullYear(), a.getMonth(), 1); const from = monday(first); return { from, to: addDays(from, 42) }; }
    return { from: day0(a), to: addDays(a, 30) };
});
const days = computed(() => {
    const out = [];
    const n = view.value === 'day' ? 1 : view.value === 'week' ? 7 : view.value === 'month' ? 42 : 30;
    for (let i = 0; i < n; i++) out.push(addDays(range.value.from, i));
    return out;
});
const heading = computed(() => {
    const a = anchor.value; const r = range.value;
    if (view.value === 'day') return `${a.getDate()} ${MONTHS[a.getMonth()]}, ${DAYS_FULL[(a.getDay() + 6) % 7]}`;
    if (view.value === 'month') return `${MONTHS_N[a.getMonth()]} ${a.getFullYear()}`;
    const last = addDays(r.to, -1);
    if (view.value === 'agenda') return `${a.getDate()} ${MONTHS[a.getMonth()]} — ${last.getDate()} ${MONTHS[last.getMonth()]}`;
    return r.from.getMonth() === last.getMonth() ? `${r.from.getDate()} — ${last.getDate()} ${MONTHS[last.getMonth()]}` : `${r.from.getDate()} ${MONTHS[r.from.getMonth()]} — ${last.getDate()} ${MONTHS[last.getMonth()]}`;
});

async function load() {
    loading.value = true;
    try {
        const list = await api.events(range.value.from.toISOString(), range.value.to.toISOString());
        events.value = list.map((e) => ({ ...e, s: e.allDay ? parseDay(e.start) : new Date(e.start), e: e.allDay ? parseDay(e.end) : new Date(e.end) }));
    } catch (e) { fail(e); } finally { loading.value = false; }
}
async function reloadCalendars() {
    try { calendars.value = await api.calendars(); } catch (e) { fail(e); }
}
watch([view, anchor], () => { localStorage.setItem('cal.view', view.value); load(); });

const visibleEvents = computed(() => events.value.filter((e) => !hidden.value.has(e.calendar)));
function toggleCal(uri) {
    if (hidden.value.has(uri)) hidden.value.delete(uri); else hidden.value.add(uri);
    hidden.value = new Set(hidden.value);
    localStorage.setItem('cal.hidden', JSON.stringify([...hidden.value]));
}
function goToday() { anchor.value = day0(new Date()); }
function shift(dir) {
    const a = anchor.value;
    if (view.value === 'day') anchor.value = addDays(a, dir);
    else if (view.value === 'week') anchor.value = addDays(a, 7 * dir);
    else if (view.value === 'month') anchor.value = new Date(a.getFullYear(), a.getMonth() + dir, 1);
    else anchor.value = addDays(a, 30 * dir);
}
function setView(v) { view.value = v; }
function openDay(d) { anchor.value = day0(d); view.value = 'day'; }

// ── Раскладка недели/дня ──────────────────────────────────────
function dayEvents(d) {
    const start = day0(d); const end = addDays(start, 1);
    return visibleEvents.value.filter((e) => !e.allDay && e.s < end && e.e > start);
}
function allDayEvents(d) {
    const start = day0(d); const end = addDays(start, 1);
    return visibleEvents.value.filter((e) => e.allDay && e.s < end && e.e > start);
}
/** Колонки для пересекающихся событий одного дня. */
function layout(d) {
    const start = day0(d); const end = addDays(start, 1);
    const items = dayEvents(d).map((e) => {
        const s = Math.max(0, (Math.max(e.s, start) - start) / 60000);
        const en = Math.min(1440, (Math.min(e.e, end) - start) / 60000);
        return { e, top: s, height: Math.max(22, (en - s) / 60 * HOUR), sMin: s, eMin: Math.max(en, s + 25), col: 0, cols: 1 };
    }).sort((a, b) => a.sMin - b.sMin || b.eMin - a.eMin);
    // Кластеры пересечений → распределение по колонкам.
    let cluster = []; let clusterEnd = -1;
    const flush = () => {
        const colsEnd = [];
        cluster.forEach((it) => {
            let c = colsEnd.findIndex((endMin) => endMin <= it.sMin);
            if (c < 0) { c = colsEnd.length; colsEnd.push(0); }
            colsEnd[c] = it.eMin; it.col = c;
        });
        cluster.forEach((it) => { it.cols = colsEnd.length; });
        cluster = [];
    };
    items.forEach((it) => {
        if (cluster.length && it.sMin >= clusterEnd) flush();
        cluster.push(it); clusterEnd = Math.max(clusterEnd, it.eMin);
    });
    if (cluster.length) flush();
    return items.map((it) => ({ ...it, style: { top: (it.top / 60 * HOUR) + 'px', height: it.height + 'px', left: `calc(${(100 / it.cols) * it.col}% + 2px)`, width: `calc(${100 / it.cols}% - 4px)`, background: it.e.color + '22', borderLeftColor: it.e.color, color: 'var(--text)' } }));
}
const nowTop = computed(() => (now.value.getHours() * 60 + now.value.getMinutes()) / 60 * HOUR);
const hours = Array.from({ length: 24 }, (_, i) => i);

function monthCell(d) {
    const start = day0(d); const end = addDays(start, 1);
    return visibleEvents.value.filter((e) => e.s < end && e.e > start).sort((a, b) => (b.allDay - a.allDay) || (a.s - b.s));
}
const agendaGroups = computed(() => {
    const m = new Map();
    visibleEvents.value.forEach((e) => { const k = ymd(e.s); if (!m.has(k)) m.set(k, []); m.get(k).push(e); });
    return [...m.entries()].sort(([a], [b]) => a.localeCompare(b)).map(([k, list]) => ({ day: parseDay(k), list }));
});

// ── Клик по сетке → новое событие ─────────────────────────────
function slotClick(d, ev) {
    if (ev.target.closest('.cal__ev')) return;
    const rect = ev.currentTarget.getBoundingClientRect();
    const min = Math.floor(((ev.clientY - rect.top) / HOUR) * 60 / 30) * 30;
    const s = new Date(d); s.setHours(0, min, 0, 0);
    create({ start: s });
}

// ── Форма события ─────────────────────────────────────────────
function blank(o = {}) {
    const s = o.start || (() => { const x = new Date(); x.setMinutes(0, 0, 0); x.setHours(x.getHours() + 1); return x; })();
    const e = o.end || new Date(s.getTime() + 60 * 60000);
    return {
        calendar: writable.value.find((c) => c.kind === 'personal')?.uri || writable.value[0]?.uri || 'personal',
        title: o.title || '', allDay: !!o.allDay, start: toLocalInput(s), end: toLocalInput(e), startDay: ymd(s), endDay: ymd(e),
        location: '', description: o.description || '', attendees: (o.attendees || []).map((m) => ({ name: '', mail: m })),
        alarm: 15, repeat: 'NONE', until: '', transparent: false,
    };
}
function create(o = {}) {
    open.value = null;
    editing.value = blank(o);
    navOpen.value = false;
}
function editEvent(e) {
    const calOk = !e.readonly;
    if (!calOk) { say('Этот календарь только для чтения', true); return; }
    editing.value = {
        ...blank(), sourceCal: e.calendar, sourceUri: e.id, calendar: e.calendar, title: e.title, allDay: e.allDay,
        start: toLocalInput(e.s), end: toLocalInput(e.allDay ? addDays(e.e, -1) : e.e), startDay: ymd(e.s), endDay: ymd(e.allDay ? addDays(e.e, -1) : e.e),
        location: e.location, description: e.description, attendees: (e.attendees || []).map((a) => ({ name: a.name, mail: a.mail })),
        alarm: e.alarm ?? '', repeat: e.rrule?.freq || 'NONE', until: e.rrule?.until || '', transparent: e.transparent, recurring: e.recurring,
    };
    open.value = null;
}
function payload(f) {
    const p = {
        calendar: f.calendar, title: f.title, allDay: f.allDay, location: f.location, description: f.description, transparent: f.transparent,
        attendees: f.attendees.filter((a) => a.mail), alarm: f.alarm === '' ? null : Number(f.alarm),
    };
    if (f.allDay) { p.start = f.startDay; p.end = f.endDay || f.startDay; } else { p.start = new Date(f.start).toISOString(); p.end = new Date(f.end).toISOString(); }
    if (f.repeat !== 'NONE') p.rrule = { freq: f.repeat, interval: 1, until: f.until || null, byday: f.repeat === 'WEEKLY' ? [['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU'][(new Date(f.allDay ? f.startDay : f.start).getDay() + 6) % 7]] : [] };
    return p;
}
async function save() {
    const f = editing.value;
    if (!f.allDay && new Date(f.end) <= new Date(f.start)) { say('Окончание раньше начала', true); return; }
    loading.value = true;
    try {
        const saved = f.sourceUri ? await api.updateEvent(f.sourceCal, f.sourceUri, payload(f)) : await api.createEvent(payload(f));
        editing.value = null;
        await load();
        say(saved.attendees?.length ? 'Сохранено, приглашения отправлены' : 'Сохранено');
    } catch (e) { fail(e); } finally { loading.value = false; }
}
function askDelete(e) {
    menu.value = null;
    dialog.value = { kind: 'delete', e, mode: e.recurring ? 'one' : 'all' };
}
async function confirmDialog(value) {
    const d = dialog.value; dialog.value = null;
    try {
        if (d.kind === 'delete') {
            await api.deleteEvent(d.e.calendar, d.e.id, d.mode === 'one' && d.e.recurring ? d.e.start : null);
            open.value = null; editing.value = null;
            await load();
            say('Удалено');
        }
        if (d.kind === 'newCal') {
            await api.createCalendar(value, d.color || '#16A05C');
            await reloadCalendars();
            say('Календарь создан');
        }
        if (d.kind === 'renameCal') {
            await api.updateCalendar(d.cal.uri, { name: value });
            await reloadCalendars();
        }
        if (d.kind === 'deleteCal') {
            await api.deleteCalendar(d.cal.uri);
            await reloadCalendars(); await load();
            say(d.cal.kind === 'shared' ? 'Отписались от календаря' : 'Календарь удалён');
        }
    } catch (e) { fail(e); }
}
async function respond(e, status) {
    try {
        await api.respond(e.calendar, e.id, status);
        await load();
        open.value = null;
        say({ ACCEPTED: 'Вы приняли приглашение', DECLINED: 'Вы отклонили приглашение', TENTATIVE: 'Ответ «под вопросом» отправлен' }[status]);
    } catch (err) { fail(err); }
}

// ── Занятость участников ──────────────────────────────────────
watch(() => editing.value && [editing.value.attendees.map((a) => a.mail).join(','), editing.value.start, editing.value.allDay], () => {
    clearTimeout(fbTimer);
    freebusy.value = {};
    const f = editing.value;
    if (!f || f.allDay || !f.attendees.some((a) => a.mail)) return;
    fbTimer = setTimeout(async () => {
        const day = day0(new Date(f.start));
        try { freebusy.value = await api.freebusy([props.user, ...f.attendees.map((a) => a.mail)], day.toISOString(), addDays(day, 1).toISOString()); } catch { freebusy.value = {}; }
    }, 300);
}, { deep: true });
function busyBlocks(mail) {
    const f = editing.value; if (!f) return [];
    const day = day0(new Date(f.start));
    return (freebusy.value[mail] || []).map((b) => {
        const s = Math.max(0, (new Date(b.start) - day) / 60000); const e = Math.min(1440, (new Date(b.end) - day) / 60000);
        return { left: (s / 1440 * 100) + '%', width: (Math.max(e - s, 8) / 1440 * 100) + '%' };
    });
}
const conflict = computed(() => {
    const f = editing.value; if (!f || f.allDay) return [];
    const s = new Date(f.start); const e = new Date(f.end);
    return Object.entries(freebusy.value).filter(([mail, list]) => mail !== props.user.toLowerCase() && list.some((b) => new Date(b.start) < e && new Date(b.end) > s)).map(([mail]) => mail);
});
const eventWindow = computed(() => {
    const f = editing.value; if (!f || f.allDay) return null;
    const day = day0(new Date(f.start));
    const s = (new Date(f.start) - day) / 60000; const e = (new Date(f.end) - day) / 60000;
    return { left: (Math.max(0, s) / 1440 * 100) + '%', width: (Math.max(8, Math.min(1440, e) - Math.max(0, s)) / 1440 * 100) + '%' };
});

// ── Календари: меню, общий доступ ─────────────────────────────
function calMenu(e, cal) { e.preventDefault(); menu.value = { kind: 'cal', x: e.clientX, y: e.clientY, cal }; }
async function recolor(cal, color) {
    menu.value = null;
    try { await api.updateCalendar(cal.uri, { color }); await reloadCalendars(); await load(); } catch (e) { fail(e); }
}
async function openShares(cal) {
    menu.value = null;
    try { shares.value = await api.shares(cal.uri); dialog.value = { kind: 'share', cal, with: [], level: 'read' }; } catch (e) { fail(e); }
}
async function addShare() {
    const d = dialog.value;
    const mail = d.with[0]?.mail; if (!mail) return;
    try { shares.value = await api.share(d.cal.uri, mail, d.level); d.with = []; say('Доступ выдан'); } catch (e) { fail(e); }
}
async function removeShare(mail) {
    try { shares.value = await api.unshare(dialog.value.cal.uri, mail); } catch (e) { fail(e); }
}
const COLORS = ['#2F6FEB', '#16A05C', '#D9791F', '#C0392B', '#7B3FE4', '#0E8A8A', '#6B7787'];

// ── Мини-месяц ────────────────────────────────────────────────
const miniMonth = ref(new Date(anchor.value.getFullYear(), anchor.value.getMonth(), 1));
watch(anchor, (a) => { miniMonth.value = new Date(a.getFullYear(), a.getMonth(), 1); });
const miniDays = computed(() => { const from = monday(miniMonth.value); return Array.from({ length: 42 }, (_, i) => addDays(from, i)); });
const busyDays = computed(() => new Set(visibleEvents.value.map((e) => ymd(e.s))));

function onKey(e) {
    const t = e.target;
    if (dialog.value || editing.value || menu.value) { if (e.key === 'Escape' && !dialog.value) { editing.value = null; menu.value = null; } return; }
    if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.tagName === 'SELECT' || t.isContentEditable)) return;
    if (e.key === 'c') create();
    if (e.key === 't') goToday();
    if (e.key === 'ArrowLeft') shift(-1);
    if (e.key === 'ArrowRight') shift(1);
    if (e.key === 'd') setView('day'); if (e.key === 'w') setView('week'); if (e.key === 'm') setView('month'); if (e.key === 'a') setView('agenda');
    if (e.key === 'Escape') open.value = null;
}
onMounted(async () => {
    document.addEventListener('keydown', onKey);
    tick = setInterval(() => { now.value = new Date(); }, 60000);
    await load();
    await nextTick();
    if (gridRef.value) gridRef.value.scrollTop = 7.5 * HOUR;
    if (props.prefill) create({ title: props.prefill.title, attendees: props.prefill.attendees, description: props.prefill.description });
});
onBeforeUnmount(() => { document.removeEventListener('keydown', onKey); clearInterval(tick); });
watch(view, async () => { await nextTick(); if (gridRef.value) gridRef.value.scrollTop = 7.5 * HOUR; });

const STATUS = { ACCEPTED: ['принял(а)', 'ok'], DECLINED: ['отказ', 'no'], TENTATIVE: ['под вопросом', 'warn'], 'NEEDS-ACTION': ['без ответа', 'off'] };
const ALARMS = [['', 'без напоминания'], [0, 'в момент начала'], [5, 'за 5 минут'], [15, 'за 15 минут'], [30, 'за 30 минут'], [60, 'за час'], [120, 'за 2 часа'], [1440, 'за день'], [2880, 'за 2 дня']];
</script>

<template>
    <Head title="Календарь" />
    <MailLayout :user="user" :theme="settings.theme">
        <div class="mail cal" :class="{ 'mail--read': !!(open || editing) }">
            <nav class="mnav" :class="{ 'mnav--open': navOpen }" aria-label="Календари">
                <button class="btn btn--primary" type="button" style="margin: 0 0 10px" @click="create()"><Icon name="plus" :size="16" />Событие</button>
                <div class="mini">
                    <div class="mini__head">
                        <button class="ib ib--sm" type="button" @click="miniMonth = new Date(miniMonth.getFullYear(), miniMonth.getMonth() - 1, 1)"><Icon name="left" :size="14" /></button>
                        <b>{{ MONTHS_N[miniMonth.getMonth()] }} {{ miniMonth.getFullYear() }}</b>
                        <button class="ib ib--sm" type="button" @click="miniMonth = new Date(miniMonth.getFullYear(), miniMonth.getMonth() + 1, 1)"><Icon name="right" :size="14" /></button>
                    </div>
                    <div class="mini__grid">
                        <span v-for="d in DAYS" :key="d" class="mini__dow">{{ d }}</span>
                        <button
                            v-for="d in miniDays"
                            :key="d.getTime()"
                            type="button"
                            class="mini__day"
                            :class="{ 'mini__day--out': d.getMonth() !== miniMonth.getMonth(), 'mini__day--today': sameDay(d, now), 'mini__day--on': sameDay(d, anchor), 'mini__day--busy': busyDays.has(ymd(d)) }"
                            @click="anchor = day0(d); if (view === 'month' || view === 'agenda') view = 'day'; navOpen = false"
                        >{{ d.getDate() }}</button>
                    </div>
                </div>
                <div class="mnav__group">Мои календари <button class="ib ib--sm" type="button" title="Новый календарь" @click="dialog = { kind: 'newCal', color: '#16A05C' }"><Icon name="plus" :size="14" /></button></div>
                <button v-for="c in own" :key="c.uri" class="mnav__item mnav__cal" type="button" @click="toggleCal(c.uri)" @contextmenu="calMenu($event, c)">
                    <span class="mnav__check" :class="{ on: !hidden.has(c.uri) }" :style="{ '--c': c.color }"><Icon v-if="!hidden.has(c.uri)" name="check" :size="11" /></span>
                    <span class="grow">{{ c.name }}</span>
                    <button class="ib ib--sm mnav__dots" type="button" @click.stop="calMenu($event, c)"><Icon name="dots" :size="14" /></button>
                </button>
                <div class="mnav__group">Общие</div>
                <button v-for="c in foreign" :key="c.uri" class="mnav__item mnav__cal" type="button" :title="c.owner ? 'Календарь: ' + c.owner.name : ''" @click="toggleCal(c.uri)" @contextmenu="calMenu($event, c)">
                    <span class="mnav__check" :class="{ on: !hidden.has(c.uri) }" :style="{ '--c': c.color }"><Icon v-if="!hidden.has(c.uri)" name="check" :size="11" /></span>
                    <span class="grow">{{ c.name }}<small v-if="c.owner" class="faint"> · {{ c.owner.name }}</small></span>
                    <Icon v-if="c.readonly" name="eye" :size="13" style="color: var(--faint)" title="только чтение" />
                </button>
                <div style="flex: 1" />
                <div class="hint" style="padding: 8px 12px">Телефон: CalDAV/CardDAV по адресу <span class="mono">{{ origin }}/dav/</span>, логин и пароль от почты.</div>
            </nav>
            <div v-if="navOpen" class="drawer-backdrop" style="z-index: 89" @click="navOpen = false" />

            <section class="cal__main">
                <div class="mread__bar cal__bar">
                    <button class="ib mobile-only" type="button" @click="navOpen = true"><Icon name="menu" :size="22" /></button>
                    <button class="btn btn--sm" type="button" @click="goToday">Сегодня</button>
                    <button class="ib ib--sm" type="button" title="Назад" @click="shift(-1)"><Icon name="left" :size="18" /></button>
                    <button class="ib ib--sm" type="button" title="Вперёд" @click="shift(1)"><Icon name="right" :size="18" /></button>
                    <b class="cal__heading">{{ heading }}</b>
                    <span class="grow" />
                    <span v-if="loading" class="faint">…</span>
                    <span class="seg seg--sm">
                        <button v-for="[v, l] in [['day', 'День'], ['week', 'Неделя'], ['month', 'Месяц'], ['agenda', 'Повестка']]" :key="v" type="button" class="seg__item" :class="{ 'seg__item--on': view === v }" @click="setView(v)">{{ l }}</button>
                    </span>
                </div>

                <!-- Неделя / день -->
                <div v-if="view === 'week' || view === 'day'" class="cal__week">
                    <div class="cal__dows" :style="{ gridTemplateColumns: `56px repeat(${days.length}, 1fr)` }">
                        <span />
                        <div v-for="d in days" :key="d.getTime()" class="cal__dow" :class="{ 'cal__dow--today': sameDay(d, now) }" @click="openDay(d)">
                            <span>{{ DAYS[(d.getDay() + 6) % 7] }}</span><b>{{ d.getDate() }}</b>
                        </div>
                    </div>
                    <div class="cal__allday" :style="{ gridTemplateColumns: `56px repeat(${days.length}, 1fr)` }">
                        <span class="cal__gutter-label">весь день</span>
                        <div v-for="d in days" :key="'a' + d.getTime()" class="cal__allday-cell" @click="create({ start: d, allDay: true })">
                            <button v-for="e in allDayEvents(d)" :key="e.calendar + e.id + e.start" type="button" class="cal__chip" :style="{ background: e.color, color: '#fff' }" @click.stop="open = e" @contextmenu.prevent.stop="menu = { kind: 'ev', x: $event.clientX, y: $event.clientY, e }">{{ e.title }}</button>
                        </div>
                    </div>
                    <div ref="gridRef" class="cal__scroll">
                        <div class="cal__grid" :style="{ gridTemplateColumns: `56px repeat(${days.length}, 1fr)`, height: 24 * HOUR + 'px' }">
                            <div class="cal__gutter">
                                <span v-for="h in hours" :key="h" :style="{ top: h * HOUR + 'px' }">{{ String(h).padStart(2, '0') }}:00</span>
                            </div>
                            <div v-for="d in days" :key="'c' + d.getTime()" class="cal__col" :class="{ 'cal__col--today': sameDay(d, now), 'cal__col--weekend': d.getDay() === 0 || d.getDay() === 6 }" @click="slotClick(d, $event)">
                                <div v-for="h in hours" :key="h" class="cal__line" :style="{ top: h * HOUR + 'px' }" />
                                <div v-if="sameDay(d, now)" class="cal__now" :style="{ top: nowTop + 'px' }" />
                                <button
                                    v-for="it in layout(d)"
                                    :key="it.e.calendar + it.e.id + it.e.start"
                                    type="button"
                                    class="cal__ev"
                                    :class="{ 'cal__ev--declined': it.e.myStatus === 'DECLINED', 'cal__ev--tentative': it.e.myStatus === 'TENTATIVE' || it.e.status === 'TENTATIVE', 'cal__ev--cancelled': it.e.status === 'CANCELLED' }"
                                    :style="it.style"
                                    :title="it.e.title"
                                    @click.stop="open = it.e"
                                    @contextmenu.prevent.stop="menu = { kind: 'ev', x: $event.clientX, y: $event.clientY, e: it.e }"
                                >
                                    <b>{{ it.e.title }}</b>
                                    <span v-if="it.height > 34">{{ hm(it.e.s) }}–{{ hm(it.e.e) }}<template v-if="it.e.location"> · {{ it.e.location }}</template></span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Месяц -->
                <div v-else-if="view === 'month'" class="cal__month">
                    <div class="cal__month-dows"><span v-for="d in DAYS" :key="d">{{ d }}</span></div>
                    <div class="cal__month-grid">
                        <div
                            v-for="d in days"
                            :key="d.getTime()"
                            class="cal__cell"
                            :class="{ 'cal__cell--out': d.getMonth() !== anchor.getMonth(), 'cal__cell--today': sameDay(d, now), 'cal__cell--weekend': d.getDay() === 0 || d.getDay() === 6 }"
                            @click="create({ start: (() => { const x = new Date(d); x.setHours(9, 0, 0, 0); return x; })() })"
                        >
                            <button type="button" class="cal__cell-day" @click.stop="openDay(d)">{{ d.getDate() }}</button>
                            <button v-for="e in monthCell(d).slice(0, 3)" :key="e.calendar + e.id + e.start" type="button" class="cal__chip" :class="{ 'cal__chip--timed': !e.allDay }" :style="e.allDay ? { background: e.color, color: '#fff' } : { '--c': e.color }" @click.stop="open = e" @contextmenu.prevent.stop="menu = { kind: 'ev', x: $event.clientX, y: $event.clientY, e }">
{{ (e.allDay ? '' : hm(e.s) + ' ') + e.title }}
                            </button>
                            <button v-if="monthCell(d).length > 3" type="button" class="cal__more" @click.stop="openDay(d)">ещё {{ monthCell(d).length - 3 }}</button>
                        </div>
                    </div>
                </div>

                <!-- Повестка -->
                <div v-else class="cal__agenda">
                    <div v-for="g in agendaGroups" :key="g.day.getTime()" class="cal__agenda-day">
                        <div class="cal__agenda-head" :class="{ 'cal__agenda-head--today': sameDay(g.day, now) }"><b>{{ g.day.getDate() }}</b><span>{{ MONTHS[g.day.getMonth()] }}, {{ DAYS_FULL[(g.day.getDay() + 6) % 7] }}</span></div>
                        <div>
                            <button v-for="e in g.list" :key="e.calendar + e.id + e.start" type="button" class="cal__agenda-ev" :style="{ '--c': e.color }" @click="open = e">
                                <span class="cal__agenda-time">{{ e.allDay ? 'весь день' : hm(e.s) + '–' + hm(e.e) }}</span>
                                <span class="grow"><b>{{ e.title }}</b><small v-if="e.location"> · {{ e.location }}</small></span>
                                <span v-if="e.attendees?.length" class="faint"><Icon name="users" :size="14" /> {{ e.attendees.length }}</span>
                            </button>
                        </div>
                    </div>
                    <div v-if="!agendaGroups.length" class="empty" style="padding: 60px 0">Ближайшие 30 дней свободны</div>
                </div>
            </section>

            <!-- Панель события -->
            <aside v-if="open || editing" class="cal__side">
                <form v-if="editing" class="cal__form" @submit.prevent="save">
                    <div class="mread__bar">
                        <b style="font-size: 15px">{{ editing.sourceUri ? 'Событие' : 'Новое событие' }}</b>
                        <span class="grow" />
                        <button class="ib ib--sm" type="button" title="Закрыть" @click="editing = null"><Icon name="x" :size="16" /></button>
                    </div>
                    <div class="mread__scroll" style="padding: 16px 18px">
                        <input v-model="editing.title" class="input cal__title" placeholder="Название" autofocus required>
                        <div class="field" style="margin-top: 12px">
                            <label class="check"><input v-model="editing.allDay" type="checkbox"> Весь день</label>
                        </div>
                        <div class="mset__cols">
                            <div class="field"><label>Начало</label><input v-if="editing.allDay" v-model="editing.startDay" class="input" type="date" required><input v-else v-model="editing.start" class="input" type="datetime-local" required></div>
                            <div class="field"><label>Окончание</label><input v-if="editing.allDay" v-model="editing.endDay" class="input" type="date"><input v-else v-model="editing.end" class="input" type="datetime-local" required></div>
                        </div>
                        <div class="mset__cols">
                            <div class="field"><label>Повтор</label>
                                <select v-model="editing.repeat" class="input"><option value="NONE">не повторять</option><option value="DAILY">каждый день</option><option value="WEEKLY">каждую неделю</option><option value="MONTHLY">каждый месяц</option><option value="YEARLY">каждый год</option></select>
                            </div>
                            <div v-if="editing.repeat !== 'NONE'" class="field"><label>До даты</label><input v-model="editing.until" class="input" type="date"></div>
                            <div v-else class="field"><label>Напоминание</label>
                                <select v-model="editing.alarm" class="input"><option v-for="[v, l] in ALARMS" :key="v" :value="v">{{ l }}</option></select>
                            </div>
                        </div>
                        <div v-if="editing.repeat !== 'NONE'" class="field"><label>Напоминание</label>
                            <select v-model="editing.alarm" class="input"><option v-for="[v, l] in ALARMS" :key="v" :value="v">{{ l }}</option></select>
                        </div>
                        <div class="field"><label>Где</label><input v-model="editing.location" class="input" placeholder="Переговорка, адрес или ссылка"></div>
                        <div class="field"><label>Календарь</label>
                            <select v-model="editing.calendar" class="input"><option v-for="c in writable" :key="c.uri" :value="c.uri">{{ c.name }}</option></select>
                        </div>
                        <div class="field"><label>Участники</label><RecipientInput v-model="editing.attendees" placeholder="Имя или адрес" /></div>
                        <div v-if="editing.attendees.some((a) => a.mail) && !editing.allDay" class="fb">
                            <div class="fb__title">Занятость {{ new Date(editing.start).toLocaleDateString('ru-RU', { day: 'numeric', month: 'long' }) }}<span v-if="conflict.length" class="chip chip--warn" style="margin-left: 8px">занято: {{ conflict.length }}</span></div>
                            <div v-for="mail in [...new Set([user.toLowerCase(), ...editing.attendees.filter((a) => a.mail).map((a) => a.mail)])]" :key="mail" class="fb__row" :class="{ 'fb__row--conflict': conflict.includes(mail) }">
                                <span class="fb__who" :title="mail">{{ mail === user.toLowerCase() ? 'вы' : (editing.attendees.find((a) => a.mail === mail)?.name || mail.split('@')[0]) }}</span>
                                <span class="fb__bar">
                                    <span v-if="eventWindow" class="fb__win" :style="eventWindow" />
                                    <span v-for="(b, i) in busyBlocks(mail)" :key="i" class="fb__busy" :style="b" />
                                    <span v-if="!freebusy[mail] && mail !== user.toLowerCase() && !mail.endsWith('@' + user.split('@')[1])" class="fb__ext">внешний участник</span>
                                </span>
                            </div>
                            <div class="fb__scale"><span>0</span><span>6</span><span>12</span><span>18</span><span>24</span></div>
                        </div>
                        <div class="field"><label>Описание</label><textarea v-model="editing.description" class="input" rows="3" /></div>
                        <label class="check"><input v-model="editing.transparent" type="checkbox"> Не показывать меня занятым</label>
                    </div>
                    <div class="cal__form-foot">
                        <button class="btn btn--primary" type="submit" :disabled="loading">{{ editing.attendees.some((a) => a.mail) ? 'Сохранить и пригласить' : 'Сохранить' }}</button>
                        <button class="btn" type="button" @click="editing = null">Отмена</button>
                        <span class="grow" />
                        <button v-if="editing.sourceUri" class="ib ib--danger" type="button" title="Удалить" @click="askDelete({ calendar: editing.sourceCal, id: editing.sourceUri, recurring: editing.recurring, start: editing.start, title: editing.title })"><Icon name="trash" :size="16" /></button>
                    </div>
                </form>

                <div v-else class="cal__view">
                    <div class="mread__bar">
                        <span class="dot" :style="{ background: open.color }" /><span class="hint">{{ open.calendarName }}</span>
                        <span class="grow" />
                        <button v-if="!open.readonly" class="ib ib--sm" type="button" title="Изменить" @click="editEvent(open)"><Icon name="edit" :size="16" /></button>
                        <button v-if="!open.readonly" class="ib ib--sm ib--danger" type="button" title="Удалить" @click="askDelete(open)"><Icon name="trash" :size="16" /></button>
                        <button class="ib ib--sm" type="button" title="Закрыть" @click="open = null"><Icon name="x" :size="16" /></button>
                    </div>
                    <div class="mread__scroll" style="padding: 16px 18px">
                        <h1 class="cal__h1" :class="{ 'cal__h1--cancelled': open.status === 'CANCELLED' }">{{ open.title }}</h1>
                        <div class="kv"><span>Когда</span><b>
                            <template v-if="open.allDay">{{ open.s.toLocaleDateString('ru-RU', { day: 'numeric', month: 'long', weekday: 'short' }) }}<template v-if="(open.e - open.s) > 86400000"> — {{ addDays(open.e, -1).toLocaleDateString('ru-RU', { day: 'numeric', month: 'long' }) }}</template>, весь день</template>
                            <template v-else>{{ open.s.toLocaleDateString('ru-RU', { day: 'numeric', month: 'long', weekday: 'short' }) }}, {{ hm(open.s) }}–{{ hm(open.e) }}</template>
                        </b></div>
                        <div v-if="open.recurring" class="kv"><span>Повтор</span><b>{{ { DAILY: 'каждый день', WEEKLY: 'каждую неделю', MONTHLY: 'каждый месяц', YEARLY: 'каждый год' }[open.rrule?.freq] || 'да' }}<template v-if="open.rrule?.until"> до {{ parseDay(open.rrule.until).toLocaleDateString('ru-RU') }}</template></b></div>
                        <div v-if="open.location" class="kv"><span>Где</span><b><a v-if="/^https?:/.test(open.location)" :href="open.location" target="_blank" rel="noopener">{{ open.location }}</a><template v-else>{{ open.location }}</template></b></div>
                        <div v-if="open.organizer && !open.mine" class="kv"><span>Организатор</span><b>{{ open.organizer.name }}</b></div>
                        <div v-if="open.alarm !== null && open.alarm !== undefined" class="kv"><span>Напоминание</span><b>{{ (ALARMS.find(([v]) => v === open.alarm) || [])[1] || `за ${open.alarm} мин` }}</b></div>
                        <div v-if="open.description" class="kv kv--note"><span>Описание</span><b style="white-space: pre-wrap; font-weight: 400">{{ open.description }}</b></div>
                        <template v-if="open.attendees?.length">
                            <div class="grp" style="margin-top: 14px">Участники · {{ open.attendees.length }}</div>
                            <div v-for="a in open.attendees" :key="a.mail" class="cal__att">
                                <span class="mrow__av" style="width: 28px; height: 28px; font-size: 11px">{{ initials(a.name, a.mail) }}</span>
                                <span class="grow" :title="a.mail">{{ a.name }}</span>
                                <span class="chip" :class="'chip--' + STATUS[a.status]?.[1]">{{ STATUS[a.status]?.[0] || a.status }}</span>
                            </div>
                        </template>
                        <div v-if="!open.mine && open.myStatus" class="cal__respond">
                            <div class="hint">Ваш ответ на приглашение</div>
                            <div style="display: flex; gap: 6px; flex-wrap: wrap">
                                <button class="btn btn--sm" :class="{ 'btn--primary': open.myStatus === 'ACCEPTED' }" type="button" @click="respond(open, 'ACCEPTED')">Принять</button>
                                <button class="btn btn--sm" type="button" @click="respond(open, 'TENTATIVE')">Под вопросом</button>
                                <button class="btn btn--sm" type="button" @click="respond(open, 'DECLINED')">Отклонить</button>
                            </div>
                        </div>
                    </div>
                </div>
            </aside>

            <button class="fab" type="button" title="Событие" @click="create()"><Icon name="plus" :size="24" /></button>
        </div>

        <Popover v-if="menu && menu.kind === 'ev'" :x="menu.x" :y="menu.y" @close="menu = null">
            <button class="pop__item" type="button" @click="open = menu.e; menu = null"><Icon name="eye" :size="16" />Открыть</button>
            <button v-if="!menu.e.readonly" class="pop__item" type="button" @click="editEvent(menu.e); menu = null"><Icon name="edit" :size="16" />Изменить</button>
            <button v-if="!menu.e.readonly" class="pop__item pop__item--danger" type="button" @click="askDelete(menu.e)"><Icon name="trash" :size="16" />Удалить</button>
        </Popover>
        <Popover v-if="menu && menu.kind === 'cal'" :x="menu.x" :y="menu.y" @close="menu = null">
            <div class="pop__title">{{ menu.cal.name }}</div>
            <div v-if="menu.cal.kind === 'personal' || menu.cal.kind === 'own' || (menu.cal.kind === 'company' && isAdmin)" class="color-dots" style="padding: 4px 12px 8px">
                <button v-for="c in COLORS" :key="c" type="button" :class="{ on: menu.cal.color === c }" :style="{ background: c }" @click="recolor(menu.cal, c)" />
            </div>
            <button v-if="menu.cal.kind === 'personal' || menu.cal.kind === 'own'" class="pop__item" type="button" @click="openShares(menu.cal)"><Icon name="share" :size="16" />Общий доступ…</button>
            <button v-if="menu.cal.kind === 'own'" class="pop__item" type="button" @click="dialog = { kind: 'renameCal', cal: menu.cal }; menu = null"><Icon name="edit" :size="16" />Переименовать…</button>
            <a class="pop__item" :href="`/dav/calendars/${user}/${menu.cal.uri}/?export`"><Icon name="download" :size="16" />Скачать .ics</a>
            <button v-if="menu.cal.kind === 'own' || menu.cal.kind === 'shared'" class="pop__item pop__item--danger" type="button" @click="dialog = { kind: 'deleteCal', cal: menu.cal }; menu = null"><Icon name="trash" :size="16" />{{ menu.cal.kind === 'shared' ? 'Отписаться' : 'Удалить календарь…' }}</button>
        </Popover>

        <Dialog v-if="dialog && dialog.kind === 'delete'" :title="'Удалить «' + dialog.e.title + '»?'" confirm-label="Удалить" danger @close="dialog = null" @confirm="confirmDialog">
            <div v-if="dialog.e.recurring" style="display: flex; flex-direction: column; gap: 6px">
                <label class="check"><input v-model="dialog.mode" type="radio" value="one"> Только это вхождение</label>
                <label class="check"><input v-model="dialog.mode" type="radio" value="all"> Все повторы</label>
            </div>
            <p v-else class="hint" style="margin: 0">{{ dialog.e.attendees?.length ? 'Участники получат отмену.' : 'Событие пропадёт и с телефона.' }}</p>
        </Dialog>
        <Dialog v-if="dialog && dialog.kind === 'newCal'" title="Новый календарь" :prompt="{ label: 'Название', placeholder: 'Например, Проекты' }" confirm-label="Создать" @close="dialog = null" @confirm="confirmDialog">
            <div class="color-dots"><button v-for="c in COLORS" :key="c" type="button" :class="{ on: dialog.color === c }" :style="{ background: c }" @click="dialog.color = c" /></div>
        </Dialog>
        <Dialog v-if="dialog && dialog.kind === 'renameCal'" title="Переименовать календарь" :prompt="{ label: 'Название', value: dialog.cal.name }" confirm-label="Сохранить" @close="dialog = null" @confirm="confirmDialog" />
        <Dialog v-if="dialog && dialog.kind === 'deleteCal'" :title="(dialog.cal.kind === 'shared' ? 'Отписаться от «' : 'Удалить «') + dialog.cal.name + '»?'" :confirm-label="dialog.cal.kind === 'shared' ? 'Отписаться' : 'Удалить'" danger @close="dialog = null" @confirm="confirmDialog">
            <p class="hint" style="margin: 0">{{ dialog.cal.kind === 'shared' ? 'Владелец сможет выдать доступ снова.' : 'Все события календаря будут удалены.' }}</p>
        </Dialog>
        <Dialog v-if="dialog && dialog.kind === 'share'" :title="'Общий доступ: ' + dialog.cal.name" confirm-label="Готово" @close="dialog = null" @confirm="dialog = null">
            <p class="hint" style="margin: 0 0 10px">Занятость по вашему календарю коллеги видят и так — при назначении встреч. Здесь можно открыть сами события.</p>
            <div class="mset__list">
                <div v-for="s in shares" :key="s.mail" class="mset__li">
                    <span class="mrow__av" style="width: 28px; height: 28px; font-size: 11px">{{ initials(s.name, s.mail) }}</span>
                    <div class="grow"><div>{{ s.name }}</div><div class="sub">{{ s.mail }} · {{ s.level === 'write' ? 'чтение и правка' : 'только чтение' }}</div></div>
                    <button class="ib ib--sm" type="button" title="Закрыть доступ" @click="removeShare(s.mail)"><Icon name="x" :size="14" /></button>
                </div>
                <div v-if="!shares.length" class="empty">Пока никому не открыт</div>
            </div>
            <div style="display: grid; grid-template-columns: minmax(0, 1fr) 150px auto; gap: 8px; align-items: start; margin-top: 12px">
                <RecipientInput v-model="dialog.with" placeholder="Сотрудник" />
                <select v-model="dialog.level" class="input"><option value="read">только чтение</option><option value="write">чтение и правка</option></select>
                <button class="btn" type="button" :disabled="!dialog.with.length" @click="addShare">Открыть</button>
            </div>
        </Dialog>
        <Toast :toast="toast" @close="toast = null" />
    </MailLayout>
</template>
