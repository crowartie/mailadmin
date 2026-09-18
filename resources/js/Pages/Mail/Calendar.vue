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
import { hotkey, initials, toLocalInput } from '../../mail/format';
import { DAYS, DAYS_FULL, MONTHS, MONTHS_N, addDays, addLabel, at9, day0, hex6, hm, monday, parseDay, sameDay, ymd } from '../../mail/dates';
import { useCalendarTasks } from '../../mail/useCalendarTasks';
import { useFreeBusy } from '../../mail/useFreeBusy';
import { HOUR, useCalendarLayout } from '../../mail/useCalendarLayout';
import { useEventDrag } from '../../mail/useEventDrag';
import { useEventForm } from '../../mail/useEventForm';
import { useCalendarData } from '../../mail/useCalendarData';

const props = defineProps({
    user: String,
    userName: String,
    settings: Object,
    isAdmin: Boolean,
    calendars: Array,
    prefill: { type: Object, default: null },
});

// Даты и цвет календаря живут в mail/dates.js: это чистые функции, они нужны не только
// здесь, и проверять их удобнее по отдельности.
const origin = typeof window !== 'undefined' ? window.location.origin : '';

// ── Состояние ─────────────────────────────────────────────────
const hidden = ref(new Set(JSON.parse(localStorage.getItem('cal.hidden') || '[]')));
const view = ref(localStorage.getItem('cal.view') || (window.innerWidth < 700 ? 'day' : 'week'));
const anchor = ref(day0(new Date()));
const open = ref(null);        // просмотр события
const editing = ref(null);     // форма
const menu = ref(null);
const dialog = ref(null);
const toast = ref(null);
const navOpen = ref(false);
const shares = ref([]);
const gridRef = ref(null);
const now = ref(new Date());
// Ссылки на поля адресов: перед чтением списка просим их дописать набранное,
// иначе последний участник пропадает — фишка создаётся на 150 мс позже.
const evAttendees = ref(null);
const shareWith = ref(null);
let toastTimer = null; let tick = null;

function say(text, error = false) {
    clearTimeout(toastTimer);
    toast.value = { text, error };
    toastTimer = setTimeout(() => { toast.value = null; }, error ? 6000 : 3000);
}
/**
 * Текст ошибки для человека. Сетевой сбой в браузере — это английское «Failed to fetch»,
 * и оно попадало в уведомление как есть.
 */
function fail(e) {
    const raw = String(e?.message || '');
    const net = e?.status === 0 || /failed to fetch|networkerror|load failed|network request failed/i.test(raw);
    say(net ? 'Нет связи с сервером — проверьте подключение к сети и попробуйте ещё раз' : (raw || 'Что-то пошло не так'), true);
}

// Диапазон, заголовок и загрузка живут в своём композабле: это единственное место,
// которое ходит на сервер за событиями и держит таймер тихого обновления.
const { events, calendars, loading, range, days, heading, load, reloadCalendars } = useCalendarData({
    view, anchor, editing, dialog, fail,
});
calendars.value = props.calendars.map((c) => ({ ...c, color: hex6(c.color) }));

const writable = computed(() => calendars.value.filter((c) => !c.readonly));
const own = computed(() => calendars.value.filter((c) => c.kind === 'personal' || c.kind === 'own'));
const foreign = computed(() => calendars.value.filter((c) => c.kind === 'shared' || c.kind === 'company'));
const calMap = computed(() => Object.fromEntries(calendars.value.map((c) => [c.uri, c])));

// Задачи живут в своём композабле: сетка недели, форма события и занятость участников
// им не нужны — нужен только способ сказать об ошибке.
const {
    tasks, newTask, newTaskDue, showDone, editTask, taskWithTime, today,
    openTasks, visibleTasks,
    loadTasks, addTask, toggleTask, saveTask, removeTask, dueLabel,
} = useCalendarTasks(fail);
onMounted(loadTasks);

/** Обновить события, календари и задачи по кнопке. */
async function refresh() {
    await Promise.all([load(), reloadCalendars(), loadTasks()]);
}

// Перенос и растягивание мышью живут в своём композабле: это единственное место,
// где страница слушает мышь глобально, и подписку на window легко пережить страницу.
const { drag, dragging, startDrag, stopDrag } = useEventDrag({ events, view, say, fail, load });
onBeforeUnmount(stopDrag);

const visibleEvents = computed(() => {
    const d = drag.value;
    return events.value
        .filter((e) => !hidden.value.has(e.calendar))
        // Событие под курсором показываем на новом месте, ещё не сохранив: так видно,
        // куда оно встанет, и колонки пересчитываются обычным путём.
        .map((e) => (d && d.moved && e.id === d.id && e.calendar === d.calendar ? { ...e, s: d.s, e: d.end } : e));
});
/** Щелчок по строке календаря: по значку «…» — меню, иначе показать или скрыть календарь. */
function calClick(e, c) {
    if (e.target?.closest?.('.mnav__dots')) { calMenu(e, c); return; }
    toggleCal(c.uri);
}
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

// Раскладка сетки живёт в своём композабле: ей нужны только события, показанный
// диапазон и текущее время.
const { hours, dayEvents, allDayEvents, layout, monthCell, nowTop, agendaGroups } = useCalendarLayout(visibleEvents, range, now);

// ── Клик по сетке → новое событие ─────────────────────────────
function slotClick(d, ev) {
    if (ev.target.closest('.cal__ev')) return;
    const rect = ev.currentTarget.getBoundingClientRect();
    const min = Math.floor(((ev.clientY - rect.top) / HOUR) * 60 / 30) * 30;
    const s = new Date(d); s.setHours(0, min, 0, 0);
    create({ start: s });
}

// Форма события живёт в своём композабле: повторы, серии, целодневные и приглашения —
// самая «правиловая» часть календаря, и держать её рядом с сеткой незачем.
const { create, editEvent, save, askDelete, confirmDialog, respond } = useEventForm({
    editing, open, dialog, menu, navOpen, loading, events, writable,
    attendeesField: evAttendees, say, fail, load, reloadCalendars,
});

// Занятость участников живёт в своём композабле: она зависит ровно от открытой формы.
const { freebusy, busyBlocks, conflict, eventWindow, stopFreeBusy } = useFreeBusy(editing, props.user);
onBeforeUnmount(stopFreeBusy);

// Галочка «Весь день» только переключала поля, а значения в них оставались прежние:
// изменил время начала, включил «Весь день» — событие вставало на исходную дату.
watch(() => editing.value?.allDay, (on, was) => {
    const f = editing.value;
    if (!f || was === undefined || on === was) return;
    if (on) {
        if (f.start) f.startDay = ymd(new Date(f.start));
        if (f.end) f.endDay = ymd(new Date(f.end));
    } else {
        const keepTime = (day, prev, hour) => {
            const d = parseDay(day);
            const p = prev ? new Date(prev) : null;
            d.setHours(p && !isNaN(p) ? p.getHours() : hour, p && !isNaN(p) ? p.getMinutes() : 0, 0, 0);
            return toLocalInput(d);
        };
        if (f.startDay) f.start = keepTime(f.startDay, f.start, 9);
        if (f.endDay || f.startDay) f.end = keepTime(f.endDay || f.startDay, f.end, 10);
        if (new Date(f.end) <= new Date(f.start)) f.end = toLocalInput(new Date(new Date(f.start).getTime() + 3600000));
    }
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
    // Набранное, но не подтверждённое Enter, тоже считается.
    shareWith.value?.flush?.();
    await nextTick();
    const mails = (d.with || []).map((a) => a.mail).filter(Boolean);
    if (!mails.length) return;
    // Раньше брался только первый адрес: ввёл трёх коллег — доступ получал один,
    // остальные исчезали без всякого сообщения.
    const failed = [];
    for (const mail of mails) {
        try { shares.value = await api.share(d.cal.uri, mail, d.level); } catch (e) { failed.push(`${mail}: ${e.message}`); }
    }
    d.with = [];
    if (failed.length) say('Не удалось открыть доступ — ' + failed.join('; '), true);
    else say(mails.length > 1 ? `Доступ выдан: ${mails.length}` : 'Доступ выдан');
}
async function removeShare(mail) {
    try { shares.value = await api.unshare(dialog.value.cal.uri, mail); } catch (e) { fail(e); }
}
const COLORS = ['#2F6FEB', '#16A05C', '#D9791F', '#C0392B', '#7B3FE4', '#0E8A8A', '#6B7787'];


// ── Мини-месяц ────────────────────────────────────────────────
const miniMonth = ref(new Date(anchor.value.getFullYear(), anchor.value.getMonth(), 1));
watch(anchor, (a) => { miniMonth.value = new Date(a.getFullYear(), a.getMonth(), 1); });
const miniDays = computed(() => { const from = monday(miniMonth.value); return Array.from({ length: 42 }, (_, i) => addDays(from, i)); });
// Точки под числами показывали только те дни, что попали в загруженный диапазон:
// перелистнув мини-месяц вперёд, человек видел пустой месяц и решал, что дел нет.
// Поэтому для мини-месяца берём его собственный диапазон. Заодно отмечаем все дни
// многодневного события, а не только первый.
const miniRaw = ref([]);
async function loadMiniBusy() {
    const from = monday(miniMonth.value);
    const to = addDays(from, 42);
    try {
        miniRaw.value = await api.events(from.toISOString(), to.toISOString());
    } catch {
        miniRaw.value = [];   // точки — подсказка, а не работа: молчим
    }
}
watch(miniMonth, loadMiniBusy, { immediate: true });
const busyDays = computed(() => {
    const out = new Set();
    for (const e of miniRaw.value) {
        if (hidden.value.has(e.calendar)) continue;
        const s = e.allDay ? parseDay(e.start) : new Date(e.start);
        const end = e.allDay ? parseDay(e.end) : new Date(e.end);
        // У события «весь день» конец — уже следующий день (так устроен iCalendar),
        // поэтому последний занятый день считаем на миллисекунду раньше конца.
        const last = day0(new Date(Math.max(end.getTime() - 1, s.getTime())));
        let d = day0(s);
        for (let i = 0; i <= 62 && d <= last; i++) { out.add(ymd(d)); d = addDays(d, 1); }
    }
    return out;
});

function onKey(e) {
    const t = e.target;
    if (dialog.value || editing.value || menu.value) { if (e.key === 'Escape' && !dialog.value) { editing.value = null; menu.value = null; } return; }
    if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.tagName === 'SELECT' || t.isContentEditable)) return;
    // Ctrl+A, Ctrl+D и Ctrl+W — команды браузера, а не переключение вида.
    if (e.ctrlKey || e.metaKey || e.altKey) return;
    const key = hotkey(e);
    if (key === 'c') create();
    if (key === 't') goToday();
    if (e.key === 'ArrowLeft') shift(-1);
    if (e.key === 'ArrowRight') shift(1);
    if (key === 'd') setView('day'); if (key === 'w') setView('week'); if (key === 'm') setView('month'); if (key === 'a') setView('agenda');
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
        <!-- 361: заголовок страницы для экранного диктора; на экране его не видно. -->
        <h1 class="sr-only">Календарь</h1>
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
                <div class="mnav__group">Мои календари <button class="ib ib--sm" type="button" title="Новый календарь" @click="dialog = { kind: 'newCal', color: '#16A05C' }" aria-label="Новый календарь"><Icon name="plus" :size="14" /></button></div>
                <!-- 240: внутри кнопки стояла вторая кнопка — недопустимая разметка, и фокус
                     вёл себя непредсказуемо. Теперь это одна кнопка, а место щелчка решает,
                     показать меню или переключить календарь. -->
                <button v-for="c in own" :key="c.uri" class="mnav__item mnav__cal" type="button" @click="calClick($event, c)" @contextmenu="calMenu($event, c)">
                    <span class="mnav__check" :class="{ on: !hidden.has(c.uri) }" :style="{ '--c': c.color }"><Icon v-if="!hidden.has(c.uri)" name="check" :size="11" /></span>
                    <span class="grow">{{ c.name }}</span>
                    <span class="mnav__dots" role="presentation" :title="'Что можно сделать с календарём «' + c.name + '»'"><Icon name="dots" :size="14" /></span>
                </button>
                <div v-if="foreign.length" class="mnav__group">Общие</div>
                <button v-for="c in foreign" :key="c.uri" class="mnav__item mnav__cal" type="button" :title="c.owner ? 'Календарь: ' + c.owner.name : ''" @click="toggleCal(c.uri)" @contextmenu="calMenu($event, c)">
                    <span class="mnav__check" :class="{ on: !hidden.has(c.uri) }" :style="{ '--c': c.color }"><Icon v-if="!hidden.has(c.uri)" name="check" :size="11" /></span>
                    <!-- 244: у общего календаря имя уже содержит владельца, и в списке
                         выходило «Отдел «АСУ» · Отдел «…». -->
                    <span class="grow">{{ c.name }}<small v-if="c.owner && !c.name.includes(c.owner.name)" class="faint"> · {{ c.owner.name }}</small></span>
                    <Icon v-if="c.readonly" name="eye" :size="13" style="color: var(--faint)" title="только чтение" />
                </button>
                <div class="mnav__group">Задачи <span v-if="openTasks.length" class="mnav__count" style="margin-left: 4px">{{ openTasks.length }}</span><button class="ib ib--sm" type="button" title="Показать выполненные" :class="{ 'ib--on': showDone }" @click="showDone = !showDone" aria-label="Показать выполненные"><Icon name="check" :size="14" /></button></div>
                <!-- Поля стояли рядом в колонке шириной 280 px, и поле задачи сжималось до «Нова»:
                     подсказку было не прочитать. Теперь поле во всю ширину, а срок появляется
                     под ним, когда есть что записывать. -->
                <form class="task__add" @submit.prevent="addTask">
                    <input v-model="newTask" class="input" placeholder="Новая задача…" style="height: 32px">
                    <input v-if="newTask.trim()" v-model="newTaskDue" class="input" type="date" title="Срок" aria-label="Срок задачи" style="height: 32px; padding: 0 6px">
                </form>
                <div v-for="t in visibleTasks" :key="t.calendar + t.id" class="task" :class="{ 'task--done': t.done, 'task--late': !t.done && t.due && t.due < today }">
                    <input type="checkbox" class="check" :checked="t.done" @change="toggleTask(t)">
                    <span class="task__body" @click="editTask = { ...t }" title="Изменить">
                        <!-- 252: важность задавали, но нигде не показывали. -->
                        <span v-if="t.priority === 1" class="task__prio" title="Высокая важность">!</span>
                        <span class="task__title">{{ t.title }}</span>
                        <span v-if="t.priority === 9" class="task__due" title="Низкая важность">не срочно</span>
                        <span v-if="t.due" class="task__due">{{ dueLabel(t.due) }}</span>
                    </span>
                    <button class="ib ib--sm task__x" type="button" title="Удалить" @click="removeTask(t)" aria-label="Удалить"><Icon name="x" :size="13" /></button>
                </div>
                <div v-if="!visibleTasks.length" class="hint" style="padding: 2px 12px 6px">Задач нет — введите текст выше и нажмите Enter.</div>
                <div style="flex: 1" />
                <div class="hint" style="padding: 8px 12px">Телефон: CalDAV/CardDAV по адресу <span class="mono">{{ origin }}/dav/</span>, логин и пароль от почты.</div>
            </nav>
            <div v-if="navOpen" class="drawer-backdrop" style="z-index: 89" @click="navOpen = false" />

            <section class="cal__main">
                <div class="mread__bar cal__bar">
                    <button class="ib mobile-only" type="button" @click="navOpen = true"><Icon name="menu" :size="22" /></button>
                    <button class="btn btn--sm" type="button" @click="goToday">Сегодня</button>
                    <button class="ib ib--sm" type="button" title="Назад" @click="shift(-1)" aria-label="Назад"><Icon name="left" :size="18" /></button>
                    <button class="ib ib--sm" type="button" title="Вперёд" @click="shift(1)" aria-label="Вперёд"><Icon name="right" :size="18" /></button>
                    <!-- 243: календарь не обновлялся сам и не имел кнопки обновления —
                         чужие правки и новые приглашения не появлялись до перезагрузки. -->
                    <button class="ib ib--sm" type="button" title="Обновить" aria-label="Обновить" :disabled="loading" @click="refresh"><Icon name="refresh" :size="17" /></button>
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
                            <button type="button" class="cal__add cal__add--allday" :aria-label="'Создать событие на весь день: ' + d.toLocaleDateString('ru-RU', { day: 'numeric', month: 'long' })" :title="'Создать событие на весь день'" @click.stop="create({ start: d, allDay: true })"><Icon name="plus" :size="12" /></button>
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
                                <!-- 239: колонка — обычный div с обработчиком клика, и создать
                                     событие с клавиатуры было нельзя. Кнопка видна при наведении
                                     и при переходе табуляцией. -->
                                <button type="button" class="cal__add" :aria-label="addLabel(d)" :title="addLabel(d)" @click.stop="create({ start: at9(d) })"><Icon name="plus" :size="14" /></button>
                                <button
                                    v-for="it in layout(d)"
                                    :key="it.e.calendar + it.e.id + it.e.start"
                                    type="button"
                                    class="cal__ev"
                                    :class="{ 'cal__ev--declined': it.e.myStatus === 'DECLINED', 'cal__ev--tentative': it.e.myStatus === 'TENTATIVE' || it.e.status === 'TENTATIVE', 'cal__ev--cancelled': it.e.status === 'CANCELLED', 'cal__ev--drag': dragging(it.e) }"
                                    :style="it.style"
                                    :title="it.e.title"
                                    @pointerdown="startDrag($event, it, 'move')"
                                    @click.stop="open = it.e"
                                    @contextmenu.prevent.stop="menu = { kind: 'ev', x: $event.clientX, y: $event.clientY, e: it.e }"
                                >
                                    <b>{{ it.e.title }}</b>
                                    <span v-if="it.height > 34">{{ hm(it.e.s) }}–{{ hm(it.e.e) }}<template v-if="it.e.location"> · {{ it.e.location }}</template></span>
                                    <!-- Нижний край — ручка: тянем её, чтобы изменить длительность. -->
                                    <span v-if="!it.e.readonly && !it.e.recurring" class="cal__ev-grip" @pointerdown.stop="startDrag($event, it, 'resize')" />
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
                            @click="create({ start: at9(d) })"
                        >
                            <button type="button" class="cal__cell-day" @click.stop="openDay(d)">{{ d.getDate() }}</button>
                            <button type="button" class="cal__add cal__add--cell" :aria-label="addLabel(d)" :title="addLabel(d)" @click.stop="create({ start: at9(d) })"><Icon name="plus" :size="13" /></button>
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
                    <!-- 232: писалось «Ближайшие 30 дней свободны», даже если пролистать на месяц вперёд. -->
                    <div v-if="!agendaGroups.length" class="empty" style="padding: 60px 0">{{ heading }} — событий нет</div>
                </div>
            </section>

            <!-- Панель события -->
            <aside v-if="open || editing" class="cal__side">
                <form v-if="editing" class="cal__form" @submit.prevent="save">
                    <div class="mread__bar">
                        <b style="font-size: 15px">{{ editing.sourceUri ? 'Событие' : 'Новое событие' }}</b>
                        <span class="grow" />
                        <button class="ib ib--sm" type="button" title="Закрыть" @click="editing = null" aria-label="Закрыть"><Icon name="x" :size="16" /></button>
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
                            <div v-if="editing.repeat !== 'NONE'" class="field">
                                <label>Как долго повторять</label>
                                <!-- 224: «раз в две недели» и «десять раз» превращались в бесконечный
                                     еженедельный повтор — эти поля просто не читались. -->
                                <div class="field__row" style="gap: 6px; align-items: center">
                                    <span class="hint" style="margin: 0">каждые</span>
                                    <input v-model.number="editing.interval" class="input" type="number" min="1" max="99" style="width: 64px" aria-label="Через сколько повторять">
                                    <span class="hint" style="margin: 0">{{ { DAILY: 'дн.', WEEKLY: 'нед.', MONTHLY: 'мес.', YEARLY: 'г.' }[editing.repeat] || '' }}</span>
                                </div>
                                <div class="field__row" style="gap: 6px; align-items: center">
                                    <input v-model="editing.until" class="input" type="date" style="flex: 1" title="До какой даты повторять" :disabled="!!editing.count">
                                    <span class="hint" style="margin: 0">или</span>
                                    <input v-model.number="editing.count" class="input" type="number" min="1" max="999" placeholder="раз" style="width: 84px" title="Сколько раз повторить" :disabled="!!editing.until">
                                </div>
                            </div>
                            <div v-else class="field"><label>Напоминание</label>
                                <select v-model="editing.alarm" class="input"><option v-for="[v, l] in ALARMS" :key="v" :value="v">{{ l }}</option></select>
                            </div>
                        </div>
                        <!-- 225: выбор «только это вхождение» был только при удалении,
                             перенести одну встречу серии на час было невозможно. -->
                        <label v-if="editing.recurring" class="toggle" style="font-size: 12.5px">
                            <input v-model="editing.onlyThis" type="checkbox"><span class="toggle__track" />Изменить только эту встречу, серию не трогать
                        </label>
                        <p v-if="editing.recurring && !editing.onlyThis" class="hint" style="margin: 0">Правки применятся ко всей серии.</p>
                        <div v-if="editing.repeat !== 'NONE'" class="field"><label>Напоминание</label>
                            <select v-model="editing.alarm" class="input"><option v-for="[v, l] in ALARMS" :key="v" :value="v">{{ l }}</option></select>
                        </div>
                        <div class="field"><label>Где</label><input v-model="editing.location" class="input" placeholder="Переговорка, адрес или ссылка"></div>
                        <div class="field"><label>Календарь</label>
                            <select v-model="editing.calendar" class="input"><option v-for="c in writable" :key="c.uri" :value="c.uri">{{ c.name }}</option></select>
                        </div>
                        <div class="field"><label>Участники</label><RecipientInput ref="evAttendees" v-model="editing.attendees" placeholder="Имя или адрес" @note="say($event, true)" /></div>
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
                        <button v-if="editing.sourceUri" class="ib ib--danger" type="button" title="Удалить" @click="askDelete({ calendar: editing.sourceCal, id: editing.sourceUri, recurring: editing.recurring, start: editing.start, title: editing.title })" aria-label="Удалить"><Icon name="trash" :size="16" /></button>
                    </div>
                </form>

                <div v-else class="cal__view">
                    <div class="mread__bar">
                        <span class="dot" :style="{ background: open.color }" /><span class="hint">{{ open.calendarName }}</span>
                        <span class="grow" />
                        <button v-if="!open.readonly" class="ib ib--sm" type="button" title="Изменить" @click="editEvent(open)" aria-label="Изменить"><Icon name="edit" :size="16" /></button>
                        <button v-if="!open.readonly" class="ib ib--sm ib--danger" type="button" title="Удалить" @click="askDelete(open)" aria-label="Удалить"><Icon name="trash" :size="16" /></button>
                        <button class="ib ib--sm" type="button" title="Закрыть" @click="open = null" aria-label="Закрыть"><Icon name="x" :size="16" /></button>
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

            <button class="fab" type="button" title="Событие" @click="create()" aria-label="Событие"><Icon name="plus" :size="24" /></button>
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
        <Dialog v-if="editTask" title="Задача" confirm-label="Сохранить" @close="editTask = null" @confirm="saveTask">
            <div class="field"><label>Название</label><input v-model="editTask.title" class="input" required></div>
            <div class="grid-2">
                <!-- 253: тип поля выбирался по длине значения и навсегда оставался «дата» —
                     задать время у уже созданной задачи было нельзя. -->
                <div class="field">
                    <label>Срок</label>
                    <input v-model="editTask.due" class="input" :type="taskWithTime ? 'datetime-local' : 'date'">
                    <label class="toggle" style="font-size: 12.5px"><input v-model="taskWithTime" type="checkbox"><span class="toggle__track" />указать время</label>
                </div>
                <div class="field"><label>Важность</label><select v-model.number="editTask.priority" class="input"><option :value="0">обычная</option><option :value="1">высокая</option><option :value="9">низкая</option></select></div>
            </div>
            <div class="field"><label>Заметка</label><textarea v-model="editTask.description" class="input" rows="3" style="height: auto"></textarea></div>
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
                    <button class="ib ib--sm" type="button" title="Закрыть доступ" @click="removeShare(s.mail)" aria-label="Закрыть доступ"><Icon name="x" :size="14" /></button>
                </div>
                <div v-if="!shares.length" class="empty">Пока никому не открыт</div>
            </div>
            <div style="display: grid; grid-template-columns: minmax(0, 1fr) 150px auto; gap: 8px; align-items: start; margin-top: 12px">
                <RecipientInput ref="shareWith" v-model="dialog.with" placeholder="Сотрудник" @note="say($event, true)" />
                <select v-model="dialog.level" class="input"><option value="read">только чтение</option><option value="write">чтение и правка</option></select>
                <button class="btn" type="button" :disabled="!dialog.with.length" @click="addShare">Открыть</button>
            </div>
        </Dialog>
        <Toast :toast="toast" @close="toast = null" />
    </MailLayout>
</template>
