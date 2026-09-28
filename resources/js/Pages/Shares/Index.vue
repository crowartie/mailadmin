<script setup>
// Общий доступ: строка на сотрудника с итогом «какие папки · уровень» (сводка ShareSummary с сервера),
// папки раскрываются только там, где права различаются. Три вида: по ящикам, по сотрудникам (сотрудник × ящик)
// и таблица (сотрудник × папка одного ящика) для сверки одним взглядом.
import { computed, nextTick, ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import Icon from '../../Components/Icon.vue';
import { http } from '../../admin/http';
import { ask as confirmAsk } from '../../confirm';

const props = defineProps({ boxes: Array, rows: Array, candidates: Array, owners: { type: Array, default: () => [] }, levels: Object });

const boxes = ref(props.boxes || []);
const rows = ref(props.rows || []);
const view = ref('owners');   // owners | people | matrix
const q = ref('');
const busy = ref(false);
const flash = ref(null);

// ── форма «Открыть доступ» ──
const addOpen = ref(false);
const add = ref({ owner: '', folder: '*', with: '', level: 'reader' });
const ownerFolders = ref([]);
const foldersLoading = ref(false);
watch(() => add.value.owner, async (owner) => {
    ownerFolders.value = [];
    add.value.folder = '*';
    if (!owner) return;
    // Папки ящика уже есть в сводке — без лишнего запроса; иначе спрашиваем у сервера.
    const box = boxes.value.find((b) => b.owner === owner);
    if (box) { ownerFolders.value = box.folders.map((f) => ({ path: f.path, name: f.name, depth: 0 })); return; }
    foldersLoading.value = true;
    try {
        const r = await http('GET', `/mailboxes/${encodeURIComponent(owner)}/shares`);
        if (add.value.owner === owner) ownerFolders.value = (r.folders || []).map((f) => ({ path: f.path, name: f.name, depth: f.depth || 0 }));
    } catch (e) { say(e.message, true); } finally { foldersLoading.value = false; }
});
// Владельцем — только через «Входящие» или «все папки»: это весь ящик и право писать от его имени.
const addLevels = computed(() => (['*', 'INBOX'].includes(add.value.folder) ? ['reader', 'editor', 'owner'] : ['reader', 'editor']));
watch(addLevels, (list) => { if (!list.includes(add.value.level)) add.value.level = 'reader'; });
const addHint = computed(() => {
    const f = add.value.folder; const l = add.value.level;
    if (f === '*') return l === 'owner' ? 'Владелец: все папки ящика и право писать от его имени.' : l === 'reader' ? 'Все папки ящика — только читать: без права писать от имени ящика и что-либо удалять.' : 'Все папки ящика — читать, раскладывать и удалять письма, но не писать от имени ящика.';
    if (f === 'INBOX') return l === 'owner' ? 'Владелец: все папки ящика и право писать от его имени.' : l === 'editor' ? 'Редактор «Входящих» получает и системные папки: «Отправленные», «Черновики», «Спам», «Корзину», «Архив».' : 'Читатель видит только «Входящие».';
    return 'Откроется только эта папка.';
});
const ownerChoices = computed(() => (props.owners.length ? props.owners : props.candidates));
function openAdd(owner = '', withMail = '') {
    add.value = { owner, folder: '*', with: withMail, level: 'reader' };
    addOpen.value = true;
    nextTick(() => document.getElementById('share-add')?.scrollIntoView({ block: 'nearest', behavior: 'smooth' }));
}

// ── сводка ──
const LEVEL_TITLE = (l) => (l === 'mixed' ? 'разные' : (props.levels[l] || l));
const matches = (p) => !q.value || `${p.with} ${p.withName}`.toLowerCase().includes(q.value.toLowerCase());
const shown = computed(() => boxes.value
    .map((b) => ({ ...b, people: b.people.filter((p) => matches(p) || `${b.owner} ${b.ownerName}`.toLowerCase().includes(q.value.toLowerCase())) }))
    .filter((b) => !q.value || b.people.length || `${b.owner} ${b.ownerName}`.toLowerCase().includes(q.value.toLowerCase())));
const peopleCount = computed(() => new Set(boxes.value.flatMap((b) => b.people.map((p) => p.with))).size);

// Раскрытые ящики: до трёх — все, иначе только первый; стандартные строки сверх восьми спрятаны за «ещё N».
const openBoxes = ref(new Set(boxes.value.slice(0, boxes.value.length <= 3 ? 3 : 1).map((b) => b.owner)));
const showAll = ref(new Set());
const expanded = ref(new Set());   // «owner|with» — раскрытые по папкам строки
const key = (b, p) => `${b.owner}|${p.with}`;
// В шаблоне ref разворачивается в само множество, поэтому переключатели именованные — по своему ref каждый.
function flip(set, k) { const s = new Set(set.value); s.has(k) ? s.delete(k) : s.add(k); set.value = s; }
const toggleBox = (owner) => flip(openBoxes, owner);
const toggleAll = (owner) => flip(showAll, owner);
const toggleExpand = (k) => flip(expanded, k);
function visiblePeople(b) {
    if (q.value || showAll.value.has(b.owner) || b.people.length <= 8) return b.people;
    const nonStd = b.people.filter((p) => !p.standard);
    return b.people.slice(0, Math.max(8, nonStd.length));
}
function hiddenCount(b) { return b.people.length - visiblePeople(b).length; }
function summaryLine(b) {
    const parts = b.people.slice(0, 3).map((p) => `${p.withName} — ${p.label}`);
    return `${b.people.length} ${plural(b.people.length, 'сотрудник', 'сотрудника', 'сотрудников')}${parts.length ? ' · ' + parts.join(', ') : ''}${b.people.length > 3 ? ` и ещё ${b.people.length - 3}` : ''}`;
}
function plural(n, a, b, c) { const m = n % 100; if (m >= 11 && m <= 19) return c; const k = n % 10; return k === 1 ? a : k >= 2 && k <= 4 ? b : c; }
function ini(s) { const p = (s || '').replace(/@.*/, '').split(/[\s._-]+/).filter(Boolean); return p.slice(0, 2).map((x) => x[0].toUpperCase()).join('') || '?'; }
function personLevels(p) { return p.scope === 'all' || p.hasInbox ? ['reader', 'editor', 'owner'] : ['reader', 'editor']; }
function folderLevels(f) { return f.role === 'inbox' ? ['reader', 'editor', 'owner'] : ['reader', 'editor']; }
function freeFolders(b, p) { const have = new Set(p.perFolder.map((f) => f.folder)); return b.folders.filter((f) => !have.has(f.path)); }

// ── по сотрудникам: сотрудник × ящик ──
const byPerson = computed(() => {
    const map = new Map();
    for (const b of boxes.value) for (const p of b.people) {
        if (!map.has(p.with)) map.set(p.with, { with: p.with, withName: p.withName, cells: {} });
        map.get(p.with).cells[b.owner] = p;
    }
    return [...map.values()].filter(matches).sort((a, b) => a.withName.localeCompare(b.withName, 'ru'));
});

// ── таблица: сотрудник × папка одного ящика ──
const matrixOwner = ref(boxes.value[0]?.owner || '');
const matrixBox = computed(() => boxes.value.find((b) => b.owner === matrixOwner.value));
const cellLevel = (p, path) => p.perFolder.find((f) => f.folder === path)?.level || 'none';
const CELL = { owner: 'В', editor: 'Р', reader: 'Ч', none: '' };
function nextLevel(cur, f) { const cycle = ['none', 'reader', 'editor', ...(f.role === 'inbox' ? ['owner'] : []), 'none']; return cycle[cycle.indexOf(cur) + 1] || 'none'; }

// ── действия ──
function say(text, error = false) { flash.value = { text, error }; setTimeout(() => { flash.value = null; }, 4500); }
async function reload() { const r = await http('GET', '/shares/json'); boxes.value = r.boxes; rows.value = r.rows; if (!boxes.value.some((b) => b.owner === matrixOwner.value)) matrixOwner.value = boxes.value[0]?.owner || ''; }
async function act(fn, done) {
    busy.value = true;
    try { await fn(); await reload(); if (done) say(done); }
    catch (e) { say(e.message, true); } finally { busy.value = false; }
}
const post = (owner, body) => http('POST', `/mailboxes/${encodeURIComponent(owner)}/shares`, body);
const del = (owner, body) => http('DELETE', `/mailboxes/${encodeURIComponent(owner)}/shares`, body);

// Уровень строки: «все папки» или «владелец» — на весь ящик; перечень — по его папкам.
function setPersonLevel(b, p, level) {
    if (level === 'mixed') return;
    const body = p.scope === 'all' || level === 'owner' ? { folder: '*', with: p.with, level } : { folders: p.perFolder.map((f) => f.folder), with: p.with, level };
    return act(() => post(b.owner, body), 'Уровень изменён');
}
async function removePerson(b, p) {
    if (!(await confirmAsk(`Закрыть ящик ${b.ownerName} для ${p.withName}?`, { ok: 'Закрыть доступ', danger: true }))) return;
    return act(() => del(b.owner, { folder: '*', with: p.with }), 'Доступ закрыт');
}
const setFolderLevel = (b, p, f, level) => act(() => post(b.owner, { folder: f.folder, with: p.with, level }), 'Уровень изменён');
async function removeFolder(b, p, f) {
    if (!(await confirmAsk(`Закрыть «${f.name}» ящика ${b.ownerName} для ${p.withName}?`, { ok: 'Закрыть', danger: true }))) return;
    return act(() => del(b.owner, { folder: f.folder, with: p.with }), 'Папка закрыта');
}
const addFolder = (b, p, path) => path && act(() => post(b.owner, { folder: path, with: p.with, level: p.level === 'mixed' ? 'reader' : p.level }), 'Папка открыта');
function grant() {
    if (!add.value.owner || !add.value.with) return;
    return act(() => post(add.value.owner, { folder: add.value.folder, with: add.value.with, level: add.value.level }), add.value.folder === '*' ? 'Доступ ко всем папкам выдан' : 'Доступ выдан').then(() => { add.value.with = ''; });
}
function sync() {
    busy.value = true;
    http('POST', '/shares/sync').then((r) => { boxes.value = r.boxes; rows.value = r.rows; say(r.output ? r.output.split('\n').pop() : 'Права проверены'); }).catch((e) => say(e.message, true)).finally(() => { busy.value = false; });
}
// Клетка таблицы: клик — следующий уровень по кругу, Shift+клик — тот же уровень на весь ящик.
function cellClick(b, p, f, ev) {
    const next = nextLevel(cellLevel(p, f.path), f);
    if (ev.shiftKey) return act(() => (next === 'none' ? del(b.owner, { folder: '*', with: p.with }) : post(b.owner, { folder: '*', with: p.with, level: next })), next === 'none' ? 'Ящик закрыт' : 'Уровень на все папки: ' + props.levels[next]);
    return act(() => (next === 'none' ? del(b.owner, { folder: f.path, with: p.with }) : post(b.owner, { folder: f.path, with: p.with, level: next })));
}
// Из вида «по сотрудникам» — к строке в виде «по ящикам», раскрыв её по папкам.
function goTo(owner, withMail) {
    view.value = 'owners';
    openBoxes.value = new Set([...openBoxes.value, owner]);
    showAll.value = new Set([...showAll.value, owner]);
    expanded.value = new Set([...expanded.value, `${owner}|${withMail}`]);
    nextTick(() => document.getElementById(`sp-${owner}-${withMail}`)?.scrollIntoView({ block: 'center', behavior: 'smooth' }));
}
</script>

<template>
    <AppLayout title="Общий доступ" :count="`${boxes.length} ${plural(boxes.length, 'ящик', 'ящика', 'ящиков')} · ${peopleCount} ${plural(peopleCount, 'сотрудник', 'сотрудника', 'сотрудников')}`">
        <template #actions>
            <div class="seg">
                <button type="button" class="seg__item" :class="{ 'seg__item--on': view === 'owners' }" @click="view = 'owners'">По ящикам</button>
                <button type="button" class="seg__item" :class="{ 'seg__item--on': view === 'people' }" @click="view = 'people'">По сотрудникам</button>
                <button type="button" class="seg__item" :class="{ 'seg__item--on': view === 'matrix' }" @click="view = 'matrix'">Таблица</button>
            </div>
            <input v-model="q" class="input" style="width: 200px" type="search" placeholder="Ящик или сотрудник">
            <button class="btn btn--primary" type="button" @click="openAdd()"><Icon name="plus" :size="16" />Открыть доступ</button>
            <button class="btn" type="button" :disabled="busy" title="Доложить права на папки, появившиеся после выдачи" @click="sync"><Icon name="refresh" :size="16" />Доложить права</button>
        </template>
        <transition name="flash"><div v-if="flash" class="flash" :class="{ 'flash--error': flash.error }">{{ flash.text }}</div></transition>

        <div v-if="addOpen" id="share-add" class="card card--pad" style="margin-bottom: 16px">
            <div class="group-title" style="display: flex; align-items: center">Открыть доступ<span style="flex: 1" /><button class="ib" type="button" aria-label="Свернуть" @click="addOpen = false"><Icon name="x" :size="14" /></button></div>
            <div class="field__row" style="flex-wrap: wrap">
                <select v-model="add.owner" class="input" aria-label="Чей ящик" style="width: 240px; height: 34px"><option value="" disabled>чей ящик…</option><option v-for="c in ownerChoices" :key="'o' + c.mail" :value="c.mail">{{ c.name }} — {{ c.mail }}</option></select>
                <select v-model="add.with" class="input" aria-label="Кому дать доступ" style="width: 240px; height: 34px" :disabled="!add.owner"><option value="" disabled>кому…</option><option v-for="c in candidates.filter((x) => x.mail !== add.owner)" :key="'w' + c.mail" :value="c.mail">{{ c.name }} — {{ c.mail }}</option></select>
                <template v-if="add.owner && add.with">
                    <select v-model="add.folder" class="input" aria-label="Какие папки" style="width: 220px; height: 34px" :disabled="foldersLoading">
                        <option value="*">Все папки</option>
                        <option value="INBOX">Входящие (весь ящик)</option>
                        <option v-for="f in ownerFolders.filter((x) => x.path.toUpperCase() !== 'INBOX')" :key="f.path" :value="f.path">{{ '  '.repeat(f.depth) }}{{ f.name }}</option>
                    </select>
                    <select v-model="add.level" class="input" aria-label="Уровень доступа" style="width: 130px; height: 34px"><option v-for="k in addLevels" :key="k" :value="k">{{ levels[k] }}</option></select>
                </template>
                <button class="btn btn--primary" type="button" :disabled="busy || !add.owner || !add.with" @click="grant">Открыть</button>
            </div>
            <p class="hint">{{ add.owner && add.with ? addHint : 'Выберите, чей ящик и кому открыть, — дальше можно выбрать папки: все, «Входящие» (доступ к ящику) или одну.' }}</p>
        </div>

        <!-- ── По ящикам ── -->
        <template v-if="view === 'owners'">
            <div v-for="b in shown" :key="b.owner" class="card card--flush" style="margin-bottom: 16px">
                <div class="sbox__head">
                    <button class="sbox__toggle" type="button" :aria-expanded="openBoxes.has(b.owner)" :aria-label="openBoxes.has(b.owner) ? 'Свернуть ящик' : 'Раскрыть ящик'" @click="toggleBox(b.owner)"><Icon name="chevron" :size="16" :style="{ transform: openBoxes.has(b.owner) ? 'rotate(90deg)' : 'none', transition: 'transform .15s' }" /></button>
                    <div class="avatar">{{ ini(b.ownerName) }}</div>
                    <div style="min-width: 0">
                        <div class="sbox__name">{{ b.ownerName }} <span class="mono faint">{{ b.owner }}</span></div>
                        <div class="row__sub">{{ openBoxes.has(b.owner) ? `${b.people.length} ${plural(b.people.length, 'сотрудник', 'сотрудника', 'сотрудников')} · ${b.folders.length} ${plural(b.folders.length, 'папка', 'папки', 'папок')}` : summaryLine(b) }}</div>
                    </div>
                    <span style="flex: 1" />
                    <button class="btn btn--sm" type="button" @click="openAdd(b.owner)"><Icon name="plus" :size="14" />Открыть доступ</button>
                    <Link class="btn btn--sm" :href="`/mailboxes/${encodeURIComponent(b.owner)}/edit`">Карточка</Link>
                </div>
                <p v-if="b.error" class="error" style="padding: 0 18px 12px">{{ b.error }}</p>
                <template v-if="openBoxes.has(b.owner)">
                    <div v-if="b.people.length" class="thead sp">
                        <div>Сотрудник</div><div>Какие папки</div><div>Уровень</div><div />
                    </div>
                    <template v-for="p in visiblePeople(b)" :key="p.with">
                        <div :id="`sp-${b.owner}-${p.with}`" class="sp sp--row" :class="{ 'sp--open': expanded.has(key(b, p)) }">
                            <div class="sp__who"><div class="avatar avatar--sm">{{ ini(p.withName) }}</div><span class="sp__name" :title="p.with">{{ p.withName }}</span></div>
                            <div class="sp__what">
                                <span v-if="p.scope === 'all'" class="chip chip--ok">Все папки</span>
                                <template v-else-if="p.scope === 'except'"><span class="chip chip--ok">Все папки</span><span class="chip chip--warn">кроме: {{ p.except.join(', ') }}</span></template>
                                <template v-else><span v-for="n in p.folders" :key="n" class="chip chip--off">{{ n }}</span></template>
                                <button class="sp__link" type="button" @click="toggleExpand(key(b, p))">{{ expanded.has(key(b, p)) ? 'свернуть' : (p.level === 'mixed' ? 'по папкам…' : 'изменить папки…') }}</button>
                            </div>
                            <div>
                                <select class="input input--sm" :value="p.level" aria-label="Уровень доступа" :disabled="busy" @change="setPersonLevel(b, p, $event.target.value)">
                                    <option v-if="p.level === 'mixed'" value="mixed" disabled>разные</option>
                                    <option v-for="l in personLevels(p)" :key="l" :value="l">{{ levels[l] }}</option>
                                </select>
                            </div>
                            <div><button class="ib ib--sm" type="button" title="Закрыть весь ящик" :disabled="busy" @click="removePerson(b, p)" aria-label="Закрыть доступ"><Icon name="x" :size="13" /></button></div>
                        </div>
                        <div v-if="expanded.has(key(b, p))" class="sp__detail">
                            <div class="sp__detail-head"><b>{{ p.withName }} — по папкам</b><span class="faint">{{ p.level === 'mixed' ? 'права по папкам отличаются' : 'уровень и закрытие — по каждой папке отдельно' }}</span></div>
                            <div class="sp__grid">
                                <div v-for="f in p.perFolder" :key="f.folder" class="sp__cell">
                                    <span class="sp__cell-name" :title="f.folder">{{ f.name }}</span>
                                    <select class="input input--sm" style="width: 104px" :value="f.level" aria-label="Уровень" :disabled="busy" @change="setFolderLevel(b, p, f, $event.target.value)"><option v-for="l in folderLevels(f)" :key="l" :value="l">{{ levels[l] }}</option></select>
                                    <button class="ib ib--sm" type="button" title="Закрыть папку" :disabled="busy" @click="removeFolder(b, p, f)" aria-label="Закрыть папку"><Icon name="x" :size="12" /></button>
                                </div>
                            </div>
                            <div class="sp__detail-actions">
                                <template v-if="p.level === 'mixed'"><span class="faint">Сделать одинаково:</span><button v-for="l in personLevels(p)" :key="l" class="btn btn--sm" type="button" :disabled="busy" @click="setPersonLevel(b, p, l)">{{ levels[l] }}</button></template>
                                <select v-if="freeFolders(b, p).length" class="input input--sm" style="width: 200px" aria-label="Открыть ещё папку" :disabled="busy" @change="addFolder(b, p, $event.target.value); $event.target.value = ''">
                                    <option value="">открыть ещё папку…</option>
                                    <option v-for="f in freeFolders(b, p)" :key="f.path" :value="f.path">{{ f.name }}</option>
                                </select>
                            </div>
                        </div>
                    </template>
                    <div v-if="hiddenCount(b) > 0" class="row__foot">ещё {{ hiddenCount(b) }} {{ plural(hiddenCount(b), 'сотрудник', 'сотрудника', 'сотрудников') }} с доступом ко всем папкам · <button class="sp__link" type="button" @click="toggleAll(b.owner)">показать</button></div>
                    <div v-else-if="showAll.has(b.owner) && b.people.length > 8" class="row__foot"><button class="sp__link" type="button" @click="toggleAll(b.owner)">свернуть стандартные</button></div>
                    <p v-if="!b.people.length && !b.error" class="hint" style="padding: 8px 18px 14px">Ящик никому не открыт.</p>
                </template>
            </div>
            <p v-if="!shown.length" class="hint" style="padding: 12px">Общих папок нет.</p>
        </template>

        <!-- ── По сотрудникам: сотрудник × ящик ── -->
        <div v-else-if="view === 'people'" class="card card--flush">
            <div class="pm" :style="{ gridTemplateColumns: `260px repeat(${boxes.length}, minmax(180px, 1fr))` }">
                <div class="thead pm__head" style="display: contents">
                    <div class="pm__th">Сотрудник</div>
                    <div v-for="b in boxes" :key="b.owner" class="pm__th" :title="b.owner">{{ b.ownerName }} <span class="faint">{{ b.owner.replace(/@.*/, '@') }}</span></div>
                </div>
                <template v-for="p in byPerson" :key="p.with">
                    <div class="pm__who"><div class="avatar avatar--sm">{{ ini(p.withName) }}</div><span class="sp__name" :title="p.with">{{ p.withName }}</span></div>
                    <div v-for="b in boxes" :key="b.owner" class="pm__cell">
                        <button v-if="p.cells[b.owner]" class="chip pm__chip" :class="p.cells[b.owner].level === 'owner' ? 'chip--acc' : p.cells[b.owner].level === 'mixed' ? 'chip--warn' : 'chip--off'" type="button" :title="'Изменить: ' + p.cells[b.owner].with" @click="goTo(b.owner, p.with)">{{ p.cells[b.owner].label }}</button>
                        <button v-else class="pm__none" type="button" :title="`Открыть ${b.ownerName} для ${p.withName}`" @click="openAdd(b.owner, p.with)">—</button>
                    </div>
                </template>
            </div>
            <p v-if="!byPerson.length" class="hint" style="padding: 12px 18px">Никому ничего не открыто.</p>
            <p v-else class="row__foot">Прочерк — доступа нет, нажатие открывает доступ. Нажатие на плашку ведёт к строке сотрудника в виде «По ящикам».</p>
        </div>

        <!-- ── Таблица: сотрудник × папка одного ящика ── -->
        <div v-else class="card card--flush">
            <div class="sbox__head">
                <span>Ящик</span>
                <select v-model="matrixOwner" class="input" style="width: 300px; height: 34px" aria-label="Ящик"><option v-for="b in boxes" :key="b.owner" :value="b.owner">{{ b.ownerName }} — {{ b.owner }}</option></select>
                <span class="faint">клик по клетке — следующий уровень по кругу, Shift+клик — на весь ящик</span>
            </div>
            <div v-if="matrixBox" class="mx-wrap">
                <div class="mx" :style="{ gridTemplateColumns: `220px repeat(${matrixBox.folders.length}, minmax(64px, 1fr))` }">
                    <div />
                    <div v-for="f in matrixBox.folders" :key="f.path" class="mx__th" :title="f.folder">{{ f.name }}</div>
                    <template v-for="p in matrixBox.people.filter(matches)" :key="p.with">
                        <div class="mx__who" :title="p.with">{{ p.withName }}</div>
                        <button v-for="f in matrixBox.folders" :key="f.path" class="mx__cell" :class="'mx__cell--' + cellLevel(p, f.path)" type="button" :disabled="busy" :title="`${f.name}: ${LEVEL_TITLE(cellLevel(p, f.path)) === 'none' ? 'нет доступа' : LEVEL_TITLE(cellLevel(p, f.path))}`" :aria-label="`${p.withName}, ${f.name}`" @click="cellClick(matrixBox, p, f, $event)">{{ CELL[cellLevel(p, f.path)] }}</button>
                    </template>
                </div>
            </div>
            <div class="row__foot mx__legend"><span><b class="mx__cell mx__cell--owner">В</b> владелец</span><span><b class="mx__cell mx__cell--editor">Р</b> редактор</span><span><b class="mx__cell mx__cell--reader">Ч</b> читатель</span><span><b class="mx__cell mx__cell--none" /> нет доступа</span></div>
        </div>
    </AppLayout>
</template>

<style scoped>
.sbox__head { display: flex; align-items: center; gap: 10px; padding: 12px 18px; }
.sbox__toggle { border: none; background: none; color: var(--faint); cursor: pointer; padding: 4px; margin-left: -6px; display: flex; }
.sbox__name { font-size: 15px; font-weight: 600; }
.avatar--sm { width: 28px; height: 28px; flex-basis: 28px; font-size: 11px; border-radius: 14px; }
/* Строка сотрудника: кто | какие папки | уровень | закрыть. */
.sp { display: grid; grid-template-columns: 260px minmax(0, 1fr) 130px 32px; gap: 12px; align-items: center; }
.sp--row { padding: 8px 18px; border-top: 1px solid var(--border); }
.sp--open { background: var(--surface-2); }
.sp__who { display: flex; align-items: center; gap: 10px; min-width: 0; }
.sp__name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.sp__what { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.sp__link { border: none; background: none; color: var(--link, var(--accent-ink)); cursor: pointer; font: inherit; font-size: 12.5px; padding: 0; }
.sp__link:hover { text-decoration: underline; }
.sp__detail { margin: 0 18px 10px 18px; padding: 10px 14px 12px; border: 1px dashed var(--border-2); border-radius: 10px; background: var(--surface-2); font-size: 13px; }
.sp__detail-head { display: flex; justify-content: space-between; gap: 12px; margin-bottom: 8px; }
.sp__grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(270px, 1fr)); gap: 6px 16px; }
.sp__cell { display: grid; grid-template-columns: minmax(0, 1fr) 104px 24px; align-items: center; gap: 8px; }
.sp__cell-name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.sp__detail-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-top: 10px; }
.input--sm { height: 28px; padding: 0 6px; font-size: 12.5px; width: 120px; }
/* Сотрудник × ящик. */
.pm { display: grid; align-items: center; gap: 0 12px; overflow-x: auto; }
.pm__th { padding: 10px 18px 10px 0; font-size: 11px; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: var(--faint); border-bottom: 1px solid var(--border); }
.pm__th:first-child, .pm__who { padding-left: 18px; }
.pm__who { display: flex; align-items: center; gap: 10px; min-width: 0; padding: 8px 0 8px 18px; border-bottom: 1px solid var(--border); }
.pm__cell { padding: 8px 0; border-bottom: 1px solid var(--border); min-width: 0; }
.pm__chip { border: none; cursor: pointer; font: inherit; font-size: 12px; font-weight: 600; max-width: 100%; overflow: hidden; text-overflow: ellipsis; display: inline-block; line-height: 24px; }
.pm__chip:hover { filter: brightness(.95); }
.pm__none { border: none; background: none; color: var(--faint); cursor: pointer; font: inherit; padding: 2px 10px; border-radius: 999px; }
.pm__none:hover { background: var(--surface-2); color: var(--text); }
/* Сотрудник × папка. */
.mx-wrap { overflow-x: auto; padding: 0 18px 12px; }
.mx { display: grid; gap: 4px; align-items: center; font-size: 12px; }
.mx__th { text-align: center; color: var(--faint); font-weight: 600; padding: 4px 2px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.mx__who { font-size: 13.5px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; padding-right: 8px; }
.mx__cell { height: 30px; border-radius: 6px; border: 1px dashed var(--border-2); background: none; font: inherit; font-weight: 700; cursor: pointer; color: var(--muted); display: inline-flex; align-items: center; justify-content: center; }
.mx__cell--owner { background: var(--accent); color: #fff; border-style: solid; border-color: transparent; }
.mx__cell--editor { background: var(--accent-soft); color: var(--accent-ink); border-style: solid; border-color: transparent; }
.mx__cell--reader { background: var(--chip-off); color: var(--muted); border-style: solid; border-color: transparent; }
.mx__cell:hover:not(:disabled) { outline: 2px solid var(--accent); outline-offset: -1px; }
.mx__legend { display: flex; gap: 18px; align-items: center; }
.mx__legend .mx__cell { width: 22px; height: 22px; font-size: 11px; margin-right: 4px; cursor: default; }
</style>
