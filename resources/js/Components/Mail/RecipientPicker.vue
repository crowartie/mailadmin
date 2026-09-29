<script setup>
// Окно «Получатели» (обращение №56): выбор адресатов из адресной книги — сотрудники по подразделениям, книги,
// история общения. Галочка у человека, переключатель «Кому / Копия / Скрытая» у каждого, отдел целиком одной
// галочкой. Возвращает выбранных родителю (Compose), тот раскладывает их по строкам письма.
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import Icon from '../Icon.vue';
import { api } from '../../mail/api';
import { avatarColor, initials } from '../../mail/format';

const props = defineProps({
    kind: { type: String, default: 'to' },              // из какой строки открыли: to | cc | bcc — уровень по умолчанию
    taken: { type: Object, default: () => ({ to: [], cc: [], bcc: [] }) },   // уже вписанные в письмо
});
const emit = defineEmits(['close', 'add']);

const KINDS = { to: 'Кому', cc: 'Копия', bcc: 'Скрытая' };
const loading = ref(true);
const error = ref('');
const books = ref([]);
const cards = ref([]);
const history = ref([]);
const q = ref('');
const side = ref('emp:all');   // emp:all | emp:<группа> | book:<uri> | history
const picked = ref(new Map()); // mail → { name, kind }
const searchEl = ref(null);
let returnTo = null;

// Адреса, уже стоящие в письме: показываем отмеченными и не добавляем повторно.
const inMail = computed(() => {
    const m = new Map();
    for (const k of ['to', 'cc', 'bcc']) (props.taken[k] || []).forEach((a) => a?.mail && m.set(a.mail.toLowerCase(), k));
    return m;
});

/** Один человек в списке: адрес, имя, подпись (должность, компания), группы, книга. */
function personOf(c) {
    const mail = (c.emails || []).find((e) => e.value)?.value || '';
    if (!mail) return null;
    return { mail: mail.toLowerCase(), name: c.fn || mail, sub: [c.title, c.org].filter(Boolean).join(' · ') || mail, groups: c.groups || [], book: c.book, employee: !!c.employee };
}
const people = computed(() => {
    const out = new Map();
    for (const c of cards.value) {
        if (c.mergedInto) continue;   // личная копия сотрудника уже слита в его карточку
        const p = personOf(c);
        if (p && !out.has(p.mail)) out.set(p.mail, p);
    }
    return [...out.values()].sort((a, b) => a.name.localeCompare(b.name, 'ru'));
});
const employees = computed(() => people.value.filter((p) => p.employee));
const groups = computed(() => {
    const m = {};
    employees.value.forEach((p) => p.groups.forEach((g) => { m[g] = (m[g] || 0) + 1; }));
    return Object.entries(m).sort((a, b) => a[0].localeCompare(b[0], 'ru')).map(([name, count]) => ({ name, count }));
});
const historyPeople = computed(() => history.value.map((h) => ({ mail: (h.email || '').toLowerCase(), name: h.name || h.email, sub: h.email, groups: [], book: 'history', employee: false })).filter((p) => p.mail));

const rows = computed(() => {
    let list;
    if (side.value === 'emp:all') list = employees.value;
    else if (side.value.startsWith('emp:')) { const g = side.value.slice(4); list = employees.value.filter((p) => p.groups.includes(g)); }
    else if (side.value === 'history') list = historyPeople.value;
    else if (side.value.startsWith('book:')) { const b = side.value.slice(5); list = people.value.filter((p) => p.book === b); }
    else list = people.value;
    const s = q.value.trim().toLowerCase();
    // Поиск — по всем книгам сразу: искать сотрудника, стоя в «Моих контактах», неудобно.
    if (s) list = [...people.value, ...historyPeople.value.filter((h) => !people.value.some((p) => p.mail === h.mail))].filter((p) => `${p.name} ${p.mail} ${p.sub}`.toLowerCase().includes(s));
    return list;
});
const groupTitle = computed(() => (side.value.startsWith('emp:') && side.value !== 'emp:all' ? side.value.slice(4) : ''));
const allRowsPicked = computed(() => rows.value.length > 0 && rows.value.every((p) => isOn(p.mail)));

function isOn(mail) { return picked.value.has(mail) || inMail.value.has(mail); }
function kindOf(mail) { return picked.value.get(mail)?.kind || inMail.value.get(mail) || props.kind; }
function toggle(p) {
    if (inMail.value.has(p.mail)) return;   // уже в письме — убирать отсюда нельзя, только в самом письме
    const next = new Map(picked.value);
    if (next.has(p.mail)) next.delete(p.mail); else next.set(p.mail, { name: p.name, kind: props.kind });
    picked.value = next;
}
function toggleAll() {
    const next = new Map(picked.value);
    const free = rows.value.filter((p) => !inMail.value.has(p.mail));
    if (allRowsPicked.value) free.forEach((p) => next.delete(p.mail)); else free.forEach((p) => { if (!next.has(p.mail)) next.set(p.mail, { name: p.name, kind: props.kind }); });
    picked.value = next;
}
function setKind(p, kind) {
    if (inMail.value.has(p.mail)) return;
    const next = new Map(picked.value);
    next.set(p.mail, { name: p.name, kind });
    picked.value = next;
}
const counts = computed(() => {
    const c = { to: 0, cc: 0, bcc: 0 };
    picked.value.forEach((v) => { c[v.kind]++; });
    return c;
});
function add() {
    const out = { to: [], cc: [], bcc: [] };
    picked.value.forEach((v, mail) => out[v.kind].push({ name: v.name, mail }));
    emit('add', out);
}
function onKey(e) {
    if (e.key === 'Escape') { e.stopPropagation(); emit('close'); }
    else if (e.key === 'Enter' && !(e.target instanceof HTMLButtonElement)) { e.preventDefault(); if (picked.value.size) add(); }
}

onMounted(async () => {
    returnTo = document.activeElement;
    document.addEventListener('keydown', onKey, true);
    setTimeout(() => searchEl.value?.focus(), 30);
    try {
        const [b, c, h] = await Promise.all([api.books(), api.contacts(), api.contactHistory().catch(() => [])]);
        books.value = b || []; cards.value = c || []; history.value = h || [];
    } catch (e) { error.value = e.message || 'Не удалось загрузить книгу'; }
    finally { loading.value = false; }
});
onBeforeUnmount(() => {
    document.removeEventListener('keydown', onKey, true);
    if (returnTo && document.contains(returnTo)) returnTo.focus();
});
</script>

<template>
    <div class="overlay" @mousedown.self="$emit('close')">
        <div class="dialog rpick" role="dialog" aria-modal="true" aria-labelledby="rpick-title">
            <div class="rpick__head">
                <h2 id="rpick-title">Получатели <span class="faint">— в «{{ KINDS[kind] }}»</span></h2>
                <input ref="searchEl" v-model="q" class="input rpick__search" type="search" placeholder="Имя, адрес, должность, компания" aria-label="Поиск получателя">
                <button class="ib" type="button" title="Закрыть" aria-label="Закрыть" @click="$emit('close')"><Icon name="x" :size="16" /></button>
            </div>
            <div class="rpick__body">
                <nav class="rpick__side" aria-label="Книги">
                    <div class="rpick__group">Сотрудники</div>
                    <button type="button" class="rpick__item" :class="{ 'rpick__item--on': side === 'emp:all' }" @click="side = 'emp:all'"><span>Все</span><small>{{ employees.length }}</small></button>
                    <button v-for="g in groups" :key="g.name" type="button" class="rpick__item" :class="{ 'rpick__item--on': side === 'emp:' + g.name }" @click="side = 'emp:' + g.name"><span>{{ g.name }}</span><small>{{ g.count }}</small></button>
                    <div class="rpick__group">Книги</div>
                    <button v-for="b in books.filter((x) => x.uri !== 'employees')" :key="b.uri" type="button" class="rpick__item" :class="{ 'rpick__item--on': side === 'book:' + b.uri }" @click="side = 'book:' + b.uri"><span>{{ b.name }}</span><small>{{ people.filter((p) => p.book === b.uri).length || '' }}</small></button>
                    <div class="rpick__group">Ещё</div>
                    <button type="button" class="rpick__item" :class="{ 'rpick__item--on': side === 'history' }" @click="side = 'history'"><span>История общения</span><small>{{ historyPeople.length }}</small></button>
                </nav>
                <div class="rpick__list" role="list">
                    <p v-if="loading" class="hint" style="padding: 16px">Загружаю книгу…</p>
                    <p v-else-if="error" class="error" style="padding: 16px">{{ error }}</p>
                    <p v-else-if="!rows.length" class="hint" style="padding: 16px">{{ q ? 'Никого не нашлось' : 'Здесь пусто' }}</p>
                    <template v-else>
                        <!-- Отдел целиком — одной галочкой (первая строка), как просили в обращении. -->
                        <label v-if="groupTitle && !q" class="rpick__row rpick__row--all">
                            <input type="checkbox" :checked="allRowsPicked" @change="toggleAll">
                            <span class="rpick__letter">{{ groupTitle.slice(0, 1) }}</span>
                            <span><b>{{ groupTitle }} целиком</b><small>{{ rows.length }} {{ rows.length === 1 ? 'сотрудник' : rows.length < 5 ? 'сотрудника' : 'сотрудников' }}</small></span>
                        </label>
                        <label v-for="p in rows" :key="p.mail" class="rpick__row" :class="{ 'rpick__row--taken': inMail.has(p.mail) }" :title="inMail.has(p.mail) ? 'Уже в письме' : ''">
                            <input type="checkbox" :checked="isOn(p.mail)" :disabled="inMail.has(p.mail)" @change="toggle(p)">
                            <span class="rpick__av" :style="{ '--av': avatarColor(p.mail) }">{{ initials(p.name, p.mail) }}</span>
                            <span class="rpick__who"><span class="rpick__name">{{ p.name }}</span><small>{{ p.sub === p.mail ? p.mail : p.mail + ' · ' + p.sub }}</small></span>
                            <span v-if="isOn(p.mail)" class="rpick__kind" role="radiogroup" aria-label="Куда добавить">
                                <button v-for="(t, k) in KINDS" :key="k" type="button" :class="{ on: kindOf(p.mail) === k }" :disabled="inMail.has(p.mail)" role="radio" :aria-checked="kindOf(p.mail) === k" @click.prevent="setKind(p, k)">{{ t }}</button>
                            </span>
                        </label>
                    </template>
                </div>
            </div>
            <div class="rpick__foot">
                <span v-if="picked.size">Выбрано: <b>{{ picked.size }}</b> · {{ ['to', 'cc', 'bcc'].filter((k) => counts[k]).map((k) => KINDS[k] + ' ' + counts[k]).join(', ') }}</span>
                <span v-else class="faint">Отметьте людей или отдел целиком</span>
                <span class="grow" />
                <button class="btn" type="button" @click="$emit('close')">Отмена</button>
                <button class="btn btn--primary" type="button" :disabled="!picked.size" @click="add">Добавить в письмо</button>
            </div>
        </div>
    </div>
</template>
