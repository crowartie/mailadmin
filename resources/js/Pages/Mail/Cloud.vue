<script setup>
// Облако сотрудника: его папка в Nextcloud, интерфейс — наш. Папки, загрузка частями
// с докачкой, публичные ссылки на отдельные файлы, корзина, «Приложить к письму».
import { computed, onMounted, reactive, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import MailLayout from '../../Layouts/MailLayout.vue';
import Icon from '../../Components/Icon.vue';
import Dialog from '../../Components/Mail/Dialog.vue';
import Toast from '../../Components/Mail/Toast.vue';
import { api } from '../../mail/api';
import { plural, size, when } from '../../mail/format';
import { addUploads, cancelUpload, clearFinished, forgetUnfinished, retryUpload, unfinished, uploads } from '../../mail/cloudUpload';
import { ask as confirmAsk } from '../../confirm';

const props = defineProps({
    user: String,
    settings: { type: Object, default: () => ({}) },
    enabled: Boolean,
    linkDays: { type: Number, default: 30 },
    trashDays: { type: Number, default: 30 },
    chunkSize: { type: Number, default: 10485760 },
});

const q0 = new URLSearchParams(window.location.search);
const view = ref(q0.get('view') || 'folder');          // folder | recent | links | trash
const path = ref(q0.get('path') || '');
const items = ref([]);
const loading = ref(false);
const error = ref('');
const used = ref(0);
const quota = ref(0);
const filter = ref('');
const selected = ref(null);
const checked = ref(new Set());
const tree = reactive({});                              // путь → дочерние папки
const open = reactive({ '': true });                    // раскрытые ветки
const dialog = ref(null);
const toast = ref(null);
const dragging = ref(false);
const fileInput = ref(null);
const lost = ref(unfinished());
let toastTimer = null;

function say(text, isError = false) {
    clearTimeout(toastTimer);
    toast.value = { text, error: isError };
    toastTimer = setTimeout(() => { toast.value = null; }, isError ? 7000 : 3500);
}

const crumbs = computed(() => {
    const out = [{ name: 'Мои файлы', path: '' }];
    let acc = '';
    for (const part of path.value.split('/').filter(Boolean)) {
        acc = acc ? acc + '/' + part : part;
        out.push({ name: part, path: acc });
    }
    return out;
});

const shown = computed(() => {
    const f = filter.value.trim().toLowerCase();
    return f ? items.value.filter((i) => i.name.toLowerCase().includes(f)) : items.value;
});
const totals = computed(() => {
    const files = items.value.filter((i) => !i.dir);
    return { n: files.length, bytes: files.reduce((s, i) => s + (i.size || 0), 0) };
});
const pct = computed(() => (quota.value ? Math.min(100, Math.round((used.value / quota.value) * 100)) : 0));
const titles = { recent: 'Недавние', links: 'Со ссылками', trash: 'Корзина' };

function kind(it) {
    if (it.dir) return 'folder';
    const t = it.type || '';
    const ext = (it.name.split('.').pop() || '').toLowerCase();
    if (t.startsWith('video/') || ['mp4', 'mov', 'avi', 'mkv', 'webm', 'm4v', '3gp'].includes(ext)) return 'video';
    if (t.startsWith('image/') || ['jpg', 'jpeg', 'png', 'gif', 'webp', 'heic'].includes(ext)) return 'img';
    if (t === 'application/pdf' || ext === 'pdf') return 'pdf';
    if (['xls', 'xlsx', 'csv', 'ods'].includes(ext)) return 'xls';
    return 'doc';
}
const ICON = { folder: 'folder', video: 'video', img: 'img', pdf: 'file', xls: 'file', doc: 'file' };

function syncUrl() {
    const p = new URLSearchParams();
    if (view.value !== 'folder') p.set('view', view.value);
    if (view.value === 'folder' && path.value) p.set('path', path.value);
    const s = p.toString();
    window.history.replaceState({}, '', '/cloud' + (s ? '?' + s : ''));
}

async function load() {
    if (!props.enabled) return;
    loading.value = true;
    error.value = '';
    try {
        const r = view.value === 'recent' ? await api.cloudRecent()
            : view.value === 'links' ? await api.cloudLinks()
            : view.value === 'trash' ? await api.cloudTrash()
            : await api.cloudList(path.value);
        items.value = r.items || [];
        used.value = r.used ?? used.value;
        quota.value = r.quota ?? quota.value;
        if (selected.value) selected.value = items.value.find((i) => i.path === selected.value.path) || null;
        checked.value = new Set();
    } catch (e) {
        error.value = e.message;
        items.value = [];
    } finally {
        loading.value = false;
    }
    syncUrl();
}

async function loadTree(p) {
    try { tree[p] = (await api.cloudFolders(p)).items || []; } catch { tree[p] = []; }
}

// Дерево слева: корень всегда, дальше — раскрытые ветки и путь к текущей папке.
const treeRows = computed(() => {
    const rows = [];
    const walk = (p, depth) => {
        for (const f of tree[p] || []) {
            rows.push({ ...f, depth });
            if (open[f.path]) walk(f.path, depth + 1);
        }
    };
    walk('', 0);
    return rows;
});

async function toggleBranch(f) {
    open[f.path] = !open[f.path];
    if (open[f.path] && !tree[f.path]) await loadTree(f.path);
}

async function go(p, v = 'folder') {
    view.value = v;
    path.value = p;
    selected.value = null;
    filter.value = '';
    // Раскрыть в дереве путь до папки.
    let acc = '';
    for (const part of p.split('/').filter(Boolean)) {
        acc = acc ? acc + '/' + part : part;
        const parent = acc.includes('/') ? acc.slice(0, acc.lastIndexOf('/')) : '';
        open[parent] = true;
        if (!tree[parent]) await loadTree(parent);
    }
    await load();
}

function activate(it) {
    if (view.value === 'trash') { selected.value = it; return; }
    if (it.dir) { go(it.path); return; }
    selected.value = selected.value?.path === it.path ? null : it;
}

function toggleCheck(it) {
    const s = new Set(checked.value);
    s.has(it.path) ? s.delete(it.path) : s.add(it.path);
    checked.value = s;
}
const checkedItems = computed(() => items.value.filter((i) => checked.value.has(i.path)));

// ── загрузка ──
const here = computed(() => (view.value === 'folder' ? path.value : ''));
function pick() { fileInput.value?.click(); }
function onPicked(e) {
    const files = [...(e.target.files || [])];
    e.target.value = '';
    if (files.length) startUpload(files);
}
function startUpload(files) {
    const dir = here.value;
    addUploads(files, dir, (item) => {
        if (view.value === 'folder' && path.value === dir) {
            if (!items.value.some((i) => i.path === item.path)) items.value = [...items.value, { ...item, link: null }];
        }
        used.value += item.size || 0;
        lost.value = unfinished();
    });
    if (view.value !== 'folder') go(dir);
}
function onDrop(e) {
    dragging.value = false;
    const files = [...(e.dataTransfer?.files || [])];
    if (files.length) startUpload(files);
}
const upItems = computed(() => uploads.items);
const upActive = computed(() => upItems.value.filter((i) => i.state !== 'done').length);
function eta(it) {
    if (!it.rate || it.state !== 'uploading') return '';
    const s = Math.round((it.size - it.sent) / it.rate);
    return s < 60 ? 'меньше минуты' : 'около ' + Math.round(s / 60) + ' мин';
}

// ── действия ──
function newFolder() { dialog.value = { kind: 'mkdir' }; }
async function doMkdir(name) {
    try { await api.cloudMkdir(here.value, name); dialog.value = null; say('Папка создана'); tree[here.value] = undefined; await loadTree(here.value); await load(); } catch (e) { say(e.message, true); }
}
function rename(it) { dialog.value = { kind: 'rename', it }; }
async function doRename(name) {
    const it = dialog.value.it;
    try { await api.cloudRename(it.path, name); dialog.value = null; if (it.dir) await loadTree(parentOf(it.path)); await load(); } catch (e) { say(e.message, true); }
}
function remove(list) { dialog.value = { kind: 'delete', list }; }
async function doRemove() {
    const list = dialog.value.list;
    try {
        await api.cloudDelete(list.map((i) => i.path));
        dialog.value = null;
        selected.value = null;
        say(list.length === 1 ? '«' + list[0].name + '» в корзине' : list.length + ' ' + plural(list.length, 'объект', 'объекта', 'объектов') + ' в корзине');
        if (list.some((i) => i.dir)) await loadTree(here.value);
        await load();
    } catch (e) { say(e.message, true); }
}
const moveTarget = ref('');
function move(list) { moveTarget.value = ''; dialog.value = { kind: 'move', list }; if (!tree['']) loadTree(''); }
async function doMove() {
    const list = dialog.value.list;
    try { await api.cloudMove(list.map((i) => i.path), moveTarget.value); dialog.value = null; say('Перенесено'); await loadTree(''); await load(); } catch (e) { say(e.message, true); }
}
const parentOf = (p) => (p.includes('/') ? p.slice(0, p.lastIndexOf('/')) : '');

// ── ссылки ──
const linkForm = reactive({ days: 30, password: false });
const newPassword = ref('');
function editLink(it) {
    linkForm.days = it.link ? daysLeft(it.link.expires_at) : props.linkDays;
    linkForm.password = !!it.link?.has_password;
    newPassword.value = '';
    dialog.value = { kind: 'link', it };
}
function daysLeft(d) {
    if (!d) return 0;
    const n = Math.round((new Date(d) - new Date()) / 86400000);
    return [7, 30, 90, 365].reduce((a, b) => (Math.abs(b - n) < Math.abs(a - n) ? b : a), 30);
}
async function saveLink() {
    const it = dialog.value.it;
    const pwChanged = !!it.link?.has_password !== linkForm.password;
    try {
        const r = await api.cloudLink(it.path, linkForm.days, it.link && !pwChanged ? undefined : linkForm.password);
        it.link = r.link;
        if (r.link.password) newPassword.value = r.link.password;
        else { dialog.value = null; await copy(r.link.url, 'Ссылка скопирована'); }
    } catch (e) { say(e.message, true); }
}
async function unlink(it) {
    try { await api.cloudUnlink(it.path); it.link = null; if (view.value === 'links') await load(); say('Ссылка отозвана — по ней больше не скачать'); } catch (e) { say(e.message, true); }
}
async function copy(text, msg = 'Скопировано') {
    try { await navigator.clipboard.writeText(text); say(msg); } catch { say('Не удалось скопировать — выделите и скопируйте вручную', true); }
}
function expiresText(l) {
    if (!l) return '';
    if (!l.expires_at) return 'бессрочно';
    return 'до ' + new Date(l.expires_at).toLocaleDateString('ru-RU', { day: 'numeric', month: 'long' });
}

// ── к письму: ссылки создаются, новое письмо открывается уже с ними ──
function attachToMail(list) {
    try { sessionStorage.setItem('cloud-attach', JSON.stringify(list.filter((i) => !i.dir).map((i) => i.path))); } catch { /* без сохранения — просто откроем почту */ }
    router.visit('/mail');
}

// ── корзина ──
async function restore(t) {
    try { const r = await api.cloudRestore(t.id); say('Возвращено: ' + r.path); await loadTree(''); await load(); } catch (e) { say(e.message, true); }
}
async function purge(t) {
    if (!(await confirmAsk('Удалить «' + t.name + '» навсегда? Вернуть будет нельзя.', { ok: 'Удалить', danger: true }))) return;
    try { await api.cloudPurge(t.id); await load(); } catch (e) { say(e.message, true); }
}
async function emptyTrash() {
    if (!(await confirmAsk('Очистить корзину? Всё в ней удалится навсегда.', { ok: 'Очистить', danger: true }))) return;
    try { const r = await api.cloudEmptyTrash(); say('Удалено навсегда: ' + r.purged); await load(); } catch (e) { say(e.message, true); }
}

function fileUrl(it, inline = false) { return api.cloudFileUrl(it.path, inline); }

onMounted(async () => {
    if (!props.enabled) return;
    await loadTree('');
    await go(path.value, view.value);
});
</script>

<template>
    <MailLayout :user="user" :theme="settings.theme">
        <Head title="Облако" />
        <h1 class="sr-only">Облако</h1>

        <div v-if="!enabled" class="cl-off">
            <Icon name="cloud" :size="40" />
            <h2>Облако пока не подключено</h2>
            <p>Здесь будут ваши файлы: видео и документы любого размера, которые можно загрузить с телефона и отправить ссылкой. Облако включает администратор.</p>
        </div>

        <div v-else class="cl" :class="{ 'cl--details': selected }" @dragover.prevent="dragging = view === 'folder'" @dragleave.self="dragging = false" @drop.prevent="onDrop">
            <aside class="cl-side">
                <button class="btn btn--primary cl-side__up" type="button" @click="pick"><Icon name="upload" :size="16" />Загрузить</button>
                <input ref="fileInput" type="file" multiple hidden @change="onPicked">
                <nav class="cl-nav" aria-label="Облако">
                    <button type="button" class="cl-nav__item" :class="{ 'cl-nav__item--on': view === 'folder' && !path }" @click="go('')"><Icon name="cloud" :size="17" />Все файлы</button>
                    <button type="button" class="cl-nav__item" :class="{ 'cl-nav__item--on': view === 'recent' }" @click="go('', 'recent')"><Icon name="clock" :size="17" />Недавние</button>
                    <button type="button" class="cl-nav__item" :class="{ 'cl-nav__item--on': view === 'links' }" @click="go('', 'links')"><Icon name="link" :size="17" />Со ссылками</button>
                    <button type="button" class="cl-nav__item" :class="{ 'cl-nav__item--on': view === 'trash' }" @click="go('', 'trash')"><Icon name="trash" :size="17" />Корзина</button>
                    <div class="cl-nav__title">Мои папки</div>
                    <div v-for="f in treeRows" :key="f.path" class="cl-nav__row" :style="{ paddingLeft: 4 + f.depth * 16 + 'px' }">
                        <button type="button" class="cl-nav__chev" :aria-label="open[f.path] ? 'Свернуть' : 'Развернуть'" @click="toggleBranch(f)">
                            <Icon name="chevron" :size="13" :style="{ transform: open[f.path] ? 'rotate(90deg)' : 'none' }" />
                        </button>
                        <button type="button" class="cl-nav__item" :class="{ 'cl-nav__item--on': view === 'folder' && path === f.path }" @click="go(f.path)">
                            <Icon name="folder" :size="17" /><span class="cl-ell">{{ f.name }}</span>
                        </button>
                    </div>
                    <p v-if="tree[''] && !tree[''].length" class="cl-nav__hint">Папок пока нет</p>
                </nav>
                <div class="cl-quota">
                    Занято {{ size(used) }} из {{ size(quota) }}
                    <div class="cl-quota__bar"><div :style="{ width: pct + '%' }" :class="{ warn: pct > 90 }" /></div>
                </div>
            </aside>

            <main class="cl-main">
                <div class="cl-bar">
                    <label class="cl-search"><Icon name="search" :size="16" /><input v-model="filter" type="search" placeholder="Поиск в этой папке" aria-label="Поиск в этой папке"></label>
                    <span class="grow" />
                    <template v-if="view === 'folder'">
                        <button class="btn" type="button" @click="newFolder"><Icon name="folder" :size="15" /><span class="cl-hide-sm">Новая папка</span></button>
                        <button class="btn cl-hide-sm" type="button" @click="pick"><Icon name="upload" :size="15" />Загрузить сюда</button>
                    </template>
                    <button v-if="view === 'trash' && items.length" class="btn btn--danger" type="button" @click="emptyTrash"><Icon name="trash" :size="15" />Очистить корзину</button>
                </div>

                <div class="cl-crumbs">
                    <nav v-if="view === 'folder'" aria-label="Путь">
                        <template v-for="(c, i) in crumbs" :key="c.path">
                            <Icon v-if="i" name="chevron" :size="13" />
                            <button v-if="i < crumbs.length - 1" type="button" class="linklike" @click="go(c.path)">{{ c.name }}</button>
                            <b v-else>{{ c.name }}</b>
                        </template>
                    </nav>
                    <b v-else>{{ titles[view] }}</b>
                    <span class="grow" />
                    <span v-if="view === 'folder'" class="cl-muted">{{ totals.n }} {{ plural(totals.n, 'файл', 'файла', 'файлов') }} · {{ size(totals.bytes) }}</span>
                    <span v-if="view === 'trash'" class="cl-muted">Через {{ trashDays }} дней удаляется само</span>
                </div>

                <div v-if="checkedItems.length" class="cl-bulk">
                    <b>Выбрано {{ checkedItems.length }}</b>
                    <span class="grow" />
                    <button v-if="checkedItems.some((i) => !i.dir)" class="btn btn--sm" type="button" @click="attachToMail(checkedItems)"><Icon name="clip" :size="14" />К письму</button>
                    <button class="btn btn--sm" type="button" @click="move(checkedItems)"><Icon name="move" :size="14" />Перенести</button>
                    <button class="btn btn--sm btn--danger" type="button" @click="remove(checkedItems)"><Icon name="trash" :size="14" />Удалить</button>
                </div>

                <div v-if="lost.length && view === 'folder'" class="cl-lost">
                    <Icon name="warn" :size="16" />
                    <span>Не догрузились: <b>{{ lost.map((l) => l.name).join(', ') }}</b>. Выберите эти файлы снова — загрузка продолжится с того же места.</span>
                    <button class="btn btn--sm" type="button" @click="pick">Выбрать</button>
                    <button class="btn btn--sm" type="button" @click="lost.forEach((l) => forgetUnfinished(l.key)); lost = []">Забыть</button>
                </div>

                <div class="cl-table" role="table" aria-label="Файлы">
                    <div class="cl-row cl-row--head" role="row">
                        <span /><span>Имя</span><span>Размер</span><span class="cl-hide-sm">{{ view === 'trash' ? 'Удалено' : 'Изменён' }}</span><span class="cl-hide-sm">{{ view === 'trash' ? 'Откуда' : 'Публичная ссылка' }}</span><span />
                    </div>
                    <div v-if="loading && !items.length" class="empty">Загружаю…</div>
                    <div v-else-if="error" class="empty">{{ error }} <button class="linklike" type="button" @click="load">Повторить</button></div>
                    <div v-else-if="!shown.length" class="empty">
                        <template v-if="filter">Ничего не нашлось</template>
                        <template v-else-if="view === 'trash'">Корзина пуста</template>
                        <template v-else-if="view === 'links'">Ссылок пока нет. Откройте файл и нажмите «Создать ссылку».</template>
                        <template v-else-if="view === 'recent'">Здесь появятся файлы, которые вы загрузите</template>
                        <template v-else>Папка пуста. Перетащите сюда файлы или нажмите «Загрузить».</template>
                    </div>
                    <div
                        v-for="it in shown"
                        :key="it.path + (it.id || '')"
                        class="cl-row"
                        :class="{ 'cl-row--on': selected && selected.path === it.path && (!it.id || selected.id === it.id) }"
                        role="row"
                        tabindex="0"
                        @click="activate(it)"
                        @keydown.enter="activate(it)"
                    >
                        <span @click.stop>
                            <input v-if="view !== 'trash'" type="checkbox" class="cb" :checked="checked.has(it.path)" :aria-label="'Выбрать ' + it.name" @change="toggleCheck(it)">
                        </span>
                        <span class="cl-name">
                            <span class="cl-ico" :class="'cl-ico--' + kind(it)"><Icon :name="ICON[kind(it)]" :size="17" /></span>
                            <span class="cl-ell" :class="{ 'cl-dirname': it.dir }">{{ it.name }}</span>
                        </span>
                        <span class="cl-muted">{{ it.size != null ? size(it.size) : '' }}</span>
                        <span class="cl-muted cl-hide-sm">{{ view === 'trash' ? when(it.deleted, true) : (it.modified ? when(it.modified, true) : '') }}</span>
                        <span class="cl-hide-sm">
                            <template v-if="view === 'trash'"><span class="cl-muted cl-ell">{{ it.path }}</span></template>
                            <template v-else-if="it.link">
                                <span class="chip" :class="it.link.has_password ? 'chip--acc' : 'chip--ok'"><Icon :name="it.link.has_password ? 'lock' : 'link'" :size="12" />{{ it.link.has_password ? 'с паролем' : 'ссылка ' + expiresText(it.link) }}</span>
                            </template>
                            <span v-else-if="!it.dir" class="cl-muted">—</span>
                        </span>
                        <span class="cl-acts" @click.stop>
                            <template v-if="view === 'trash'">
                                <button class="ib" type="button" title="Вернуть" aria-label="Вернуть" @click="restore(it)"><Icon name="refresh" :size="16" /></button>
                                <button class="ib ib--danger" type="button" title="Удалить навсегда" aria-label="Удалить навсегда" @click="purge(it)"><Icon name="trash" :size="16" /></button>
                            </template>
                            <template v-else>
                                <button class="ib" type="button" title="Переименовать" aria-label="Переименовать" @click="rename(it)"><Icon name="edit" :size="15" /></button>
                                <button class="ib ib--danger" type="button" title="Удалить" aria-label="Удалить" @click="remove([it])"><Icon name="trash" :size="15" /></button>
                            </template>
                        </span>
                    </div>
                </div>

                <div v-if="view === 'folder'" class="cl-drop" :class="{ 'cl-drop--on': dragging }">
                    <Icon name="upload" :size="18" />Перетащите файлы сюда — большие видео загружаются частями и докачиваются после обрыва связи
                </div>
            </main>

            <aside v-if="selected && view !== 'trash'" class="cl-details" aria-label="Файл">
                <div class="cl-details__head">
                    <span class="cl-cap">Файл</span><span class="grow" />
                    <button class="ib" type="button" aria-label="Закрыть" @click="selected = null"><Icon name="x" :size="16" /></button>
                </div>
                <div class="cl-preview">
                    <video v-if="kind(selected) === 'video'" :key="selected.path" controls preload="metadata" :src="fileUrl(selected, true)" />
                    <img v-else-if="kind(selected) === 'img'" :key="selected.path" :src="fileUrl(selected, true)" :alt="selected.name">
                    <span v-else class="cl-ico cl-ico--big" :class="'cl-ico--' + kind(selected)"><Icon :name="ICON[kind(selected)]" :size="34" /></span>
                </div>
                <div>
                    <div class="cl-details__name">{{ selected.name }}</div>
                    <div class="cl-muted">{{ size(selected.size) }}<template v-if="selected.modified"> · изменён {{ when(selected.modified, true) }}</template></div>
                </div>
                <div class="cl-link">
                    <div class="cl-link__head"><Icon name="link" :size="17" /><b>Публичная ссылка</b><span class="grow" /><span v-if="selected.link" class="chip chip--ok">действует</span></div>
                    <template v-if="selected.link">
                        <div class="cl-link__url mono" :title="selected.link.url">{{ selected.link.url }}</div>
                        <div class="cl-link__grid">
                            <span class="cl-muted">Срок</span><span>{{ expiresText(selected.link) }}</span>
                            <span class="cl-muted">Пароль</span><span>{{ selected.link.has_password ? 'есть — сообщите получателю отдельно' : 'нет' }}</span>
                        </div>
                        <div class="cl-link__btns">
                            <button class="btn grow" type="button" @click="copy(selected.link.url, 'Ссылка скопирована')"><Icon name="copy" :size="15" />Скопировать</button>
                            <button class="btn" type="button" @click="editLink(selected)">Настроить</button>
                        </div>
                    </template>
                    <template v-else>
                        <p class="cl-muted" style="margin: 0">Ссылки нет. По ссылке файл сможет скачать любой, у кого она есть.</p>
                        <button class="btn" type="button" @click="editLink(selected)"><Icon name="link" :size="15" />Создать ссылку</button>
                    </template>
                    <p class="cl-note">По ссылке открывается только этот файл. Папки и остальные файлы не видны никому.</p>
                </div>
                <span class="grow" />
                <button class="btn btn--primary" type="button" @click="attachToMail([selected])"><Icon name="clip" :size="15" />Приложить к письму</button>
                <div class="cl-link__btns">
                    <a class="btn grow" :href="fileUrl(selected)"><Icon name="download" :size="15" />Скачать</a>
                    <button class="btn grow" type="button" @click="move([selected])"><Icon name="move" :size="15" />Перенести</button>
                    <button v-if="selected.link" class="btn btn--danger grow" type="button" @click="unlink(selected)"><Icon name="x" :size="15" />Отозвать</button>
                </div>
            </aside>

            <section v-if="upItems.length" class="cl-up" aria-label="Загрузки">
                <div class="cl-up__head">
                    <b>{{ upActive ? 'Загрузка: ' + upActive : 'Загрузки завершены' }}</b><span class="grow" />
                    <span v-if="upActive" class="cl-muted">не закрывайте вкладку</span>
                    <button v-else class="ib" type="button" aria-label="Закрыть" @click="clearFinished"><Icon name="x" :size="15" /></button>
                </div>
                <div v-for="u in upItems" :key="u.uid" class="cl-up__item">
                    <div class="cl-up__line">
                        <span class="cl-ico" :class="'cl-ico--' + kind({ name: u.name, type: u.file.type })"><Icon :name="ICON[kind({ name: u.name, type: u.file.type })]" :size="16" /></span>
                        <div class="grow" style="min-width: 0">
                            <div class="cl-ell"><b style="font-weight: 500">{{ u.name }}</b></div>
                            <div v-if="u.state === 'done'" class="cl-ok">Готово · {{ size(u.size) }}</div>
                            <div v-else-if="u.state === 'error'" class="cl-err">{{ u.error }}</div>
                            <div v-else-if="u.state === 'waiting'" class="cl-warn"><Icon name="warn" :size="12" />{{ u.waiting }} · {{ Math.floor((u.sent / (u.size || 1)) * 100) }}%</div>
                            <div v-else class="cl-muted">{{ size(u.sent) }} из {{ size(u.size) }}<template v-if="eta(u)"> · осталось {{ eta(u) }}</template><template v-if="u.state === 'queued'"> · в очереди</template></div>
                        </div>
                        <button v-if="u.state === 'error'" class="ib" type="button" title="Повторить" aria-label="Повторить" @click="retryUpload(u)"><Icon name="refresh" :size="15" /></button>
                        <button v-if="u.state !== 'done'" class="ib" type="button" title="Отменить" aria-label="Отменить загрузку" @click="cancelUpload(u)"><Icon name="x" :size="15" /></button>
                        <Icon v-else name="check" :size="17" class="cl-ok" />
                    </div>
                    <div v-if="u.state !== 'done'" class="cl-up__bar"><div :class="{ warn: u.state === 'waiting' }" :style="{ width: Math.floor((u.sent / (u.size || 1)) * 100) + '%' }" /></div>
                </div>
            </section>
        </div>

        <Dialog v-if="dialog && dialog.kind === 'mkdir'" title="Новая папка" :prompt="{ label: 'Название', value: '', placeholder: 'Например, Командировка', maxlength: 200 }" confirm-label="Создать" @close="dialog = null" @confirm="doMkdir" />
        <Dialog v-if="dialog && dialog.kind === 'rename'" title="Переименовать" :prompt="{ label: 'Новое имя', value: dialog.it.name, maxlength: 200 }" confirm-label="Переименовать" @close="dialog = null" @confirm="doRename" />
        <Dialog v-if="dialog && dialog.kind === 'delete'" :title="dialog.list.length === 1 ? 'Удалить «' + dialog.list[0].name + '»?' : 'Удалить ' + dialog.list.length + ' ' + plural(dialog.list.length, 'объект', 'объекта', 'объектов') + '?'" confirm-label="В корзину" danger @close="dialog = null" @confirm="doRemove">
            <p style="margin: 0">Удалённое лежит в корзине {{ trashDays }} дней, его можно вернуть. Публичные ссылки на удалённые файлы перестанут работать сразу.</p>
        </Dialog>
        <Dialog v-if="dialog && dialog.kind === 'move'" title="Куда перенести" confirm-label="Перенести" @close="dialog = null" @confirm="doMove">
            <div class="cl-pick">
                <label class="cl-pick__row"><input v-model="moveTarget" type="radio" value=""><Icon name="cloud" :size="16" />Мои файлы (корень)</label>
                <label v-for="f in treeRows" :key="f.path" class="cl-pick__row" :style="{ paddingLeft: 10 + (f.depth + 1) * 16 + 'px' }">
                    <input v-model="moveTarget" type="radio" :value="f.path" :disabled="dialog.list.some((i) => i.dir && (f.path === i.path || f.path.startsWith(i.path + '/')))">
                    <Icon name="folder" :size="16" />{{ f.name }}
                </label>
            </div>
        </Dialog>
        <Dialog v-if="dialog && dialog.kind === 'link'" title="Публичная ссылка" :confirm-label="newPassword ? 'Готово' : (dialog.it.link ? 'Сохранить и скопировать' : 'Создать и скопировать')" @close="dialog = null; newPassword = ''" @confirm="newPassword ? (dialog = null, newPassword = '') : saveLink()">
            <div v-if="newPassword" class="cl-form">
                <p style="margin: 0">Ссылка готова. Пароль показывается один раз — сообщите его получателю отдельно, не в том же письме.</p>
                <div class="cl-link__url mono">{{ dialog.it.link.url }}</div>
                <div class="cl-link__btns"><button class="btn grow" type="button" @click="copy(dialog.it.link.url, 'Ссылка скопирована')"><Icon name="copy" :size="15" />Ссылку</button></div>
                <div class="cl-pass mono">{{ newPassword }}</div>
                <div class="cl-link__btns"><button class="btn grow" type="button" @click="copy(newPassword, 'Пароль скопирован')"><Icon name="copy" :size="15" />Пароль</button></div>
            </div>
            <div v-else class="cl-form">
                <div class="cl-muted"><b style="color: var(--text)">{{ dialog.it.name }}</b> · {{ size(dialog.it.size) }}</div>
                <label class="field"><span>Срок действия</span>
                    <select v-model.number="linkForm.days" class="input">
                        <option :value="7">7 дней</option><option :value="30">30 дней</option><option :value="90">3 месяца</option><option :value="365">год</option><option :value="0">бессрочно</option>
                    </select>
                    <span class="hint">Продлить можно в любой момент, файл при этом не удаляется.</span>
                </label>
                <label class="toggle"><input v-model="linkForm.password" type="checkbox"><span class="toggle__track" />Спрашивать пароль</label>
                <div class="cl-note">Получатель увидит страницу с именем файла, размером и кнопкой «Скачать». Ни папки, ни другие ваши файлы, ни почта не видны.</div>
            </div>
        </Dialog>

        <Toast :toast="toast" @close="toast = null" />
    </MailLayout>
</template>
