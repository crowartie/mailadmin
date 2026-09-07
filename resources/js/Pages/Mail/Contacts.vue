<script setup>
// Контакты: книги (личная, сотрудники, компания) · список · карточка / форма.
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import MailLayout from '../../Layouts/MailLayout.vue';
import Icon from '../../Components/Icon.vue';
import Popover from '../../Components/Mail/Popover.vue';
import Dialog from '../../Components/Mail/Dialog.vue';
import Toast from '../../Components/Mail/Toast.vue';
import { api } from '../../mail/api';
import { initials, plural } from '../../mail/format';

const props = defineProps({
    user: String,
    settings: Object,
    isAdmin: Boolean,
    books: Array,
    book: { type: String, default: null },
    contacts: Array,
    query: { type: String, default: '' },
    openUri: { type: String, default: null },
});

const books = ref(props.books);
const all = ref(props.contacts);            // все карточки всех книг; фильтруем на клиенте
const filter = ref(props.book || 'all');    // all | favorites | <book uri> | group:<name>
const q = ref(props.query || '');
const open = ref(null);
const editing = ref(null);
const menu = ref(null);
const dialog = ref(null);
const toast = ref(null);
const loading = ref(false);
const mobileRead = ref(false);
const navOpen = ref(false);
const searchInput = ref(null);
const fileInput = ref(null);
let toastTimer = null;

const KINDS = { personal: 'Мои контакты', own: 'Моя книга', employees: 'Сотрудники', company: 'Контакты компании' };
const TYPES = { work: 'рабочий', home: 'домашний', cell: 'мобильный', fax: 'факс', other: 'другой' };

const groups = computed(() => {
    const m = {};
    all.value.forEach((c) => (c.groups || []).forEach((g) => { m[g] = (m[g] || 0) + 1; }));
    return Object.keys(m).sort((a, b) => a.localeCompare(b, 'ru')).map((name) => ({ name, count: m[name] }));
});
const favorites = computed(() => all.value.filter((c) => c.favorite).length);
const writableBooks = computed(() => books.value.filter((b) => !b.readonly));

const visible = computed(() => {
    const words = q.value.trim().toLowerCase().split(/\s+/).filter(Boolean);
    return all.value.filter((c) => {
        if (filter.value === 'favorites' && !c.favorite) return false;
        if (filter.value.startsWith('group:') && !(c.groups || []).includes(filter.value.slice(6))) return false;
        if (filter.value !== 'all' && filter.value !== 'favorites' && !filter.value.startsWith('group:') && c.book !== filter.value) return false;
        if (!words.length) return true;
        const hay = [c.fn, c.org, c.title, c.note, ...(c.emails || []).map((e) => e.value), ...(c.phones || []).map((p) => p.value), ...(c.groups || [])].join(' ').toLowerCase();
        return words.every((w) => hay.includes(w));
    });
});
// Буквенные заголовки в списке.
const rows = computed(() => {
    const out = []; let last = null;
    visible.value.forEach((c) => {
        const letter = (c.fn || '?')[0].toUpperCase();
        if (letter !== last) { out.push({ letter }); last = letter; }
        out.push({ contact: c });
    });
    return out;
});
const title = computed(() => {
    if (filter.value === 'all') return 'Все контакты';
    if (filter.value === 'favorites') return 'Избранные';
    if (filter.value.startsWith('group:')) return filter.value.slice(6);
    return books.value.find((b) => b.uri === filter.value)?.name || 'Контакты';
});

function say(text, error = false) {
    clearTimeout(toastTimer);
    toast.value = { text, error };
    toastTimer = setTimeout(() => { toast.value = null; }, error ? 6000 : 3000);
}
function fail(e) { say(e?.message || 'Что-то пошло не так', true); }

async function reload(keepOpen = true) {
    loading.value = true;
    try {
        [all.value, books.value] = await Promise.all([api.contacts(), api.books()]);
        if (keepOpen && open.value) {
            const fresh = all.value.find((c) => c.uri === open.value.uri && c.book === open.value.book);
            if (!fresh) open.value = null;
        }
    } catch (e) { fail(e); } finally { loading.value = false; }
}

function go(f) {
    filter.value = f;
    navOpen.value = false;
    mobileRead.value = false;
    const p = new URLSearchParams();
    if (f !== 'all') p.set('book', f);
    window.history.replaceState({}, '', `/contacts${p.toString() ? '?' + p : ''}`);
}

async function select(c) {
    editing.value = null;
    loading.value = true;
    try {
        open.value = await api.contact(c.book, c.uri);
        mobileRead.value = true;
    } catch (e) { fail(e); } finally { loading.value = false; }
}

// ── Форма ─────────────────────────────────────────────────────
function blank(book = null) {
    const target = book && writableBooks.value.some((b) => b.uri === book) ? book : (writableBooks.value[0]?.uri || 'personal');
    return { book: target, first: '', last: '', middle: '', nick: '', org: '', department: '', title: '', emails: [{ value: '', type: 'work' }], phones: [{ value: '', type: 'cell' }], addresses: [], birthday: '', url: '', note: '', groups: [], favorite: false, groupsText: '' };
}
function create() {
    open.value = null;
    editing.value = blank(filter.value !== 'all' && filter.value !== 'favorites' && !filter.value.startsWith('group:') ? filter.value : null);
    if (filter.value.startsWith('group:')) { editing.value.groups = [filter.value.slice(6)]; editing.value.groupsText = filter.value.slice(6); }
    mobileRead.value = true;
    navOpen.value = false;
}
function edit(c) {
    editing.value = {
        ...c, sourceBook: c.book, sourceUri: c.uri,
        emails: c.emails?.length ? c.emails.map((e) => ({ ...e })) : [{ value: '', type: 'work' }],
        phones: c.phones?.length ? c.phones.map((p) => ({ ...p })) : [{ value: '', type: 'cell' }],
        addresses: (c.addresses || []).map((a) => ({ ...a })),
        groups: [...(c.groups || [])], groupsText: (c.groups || []).join(', '),
    };
}
async function save() {
    const f = editing.value;
    const payload = {
        book: f.book, first: f.first, last: f.last, middle: f.middle, nick: f.nick, org: f.org, department: f.department, title: f.title,
        emails: f.emails.filter((e) => e.value.trim()), phones: f.phones.filter((p) => p.value.trim()), addresses: f.addresses,
        birthday: f.birthday, url: f.url, note: f.note, groups: f.groupsText.split(',').map((s) => s.trim()).filter(Boolean), favorite: !!f.favorite,
    };
    if (f.photo !== undefined) payload.photo = f.photo;
    loading.value = true;
    try {
        const saved = f.sourceUri ? await api.updateContact(f.sourceBook, f.sourceUri, payload) : await api.createContact(payload);
        editing.value = null;
        await reload(false);
        open.value = await api.contact(saved.book, saved.uri);
        say('Сохранено');
    } catch (e) { fail(e); } finally { loading.value = false; }
}
function onPhoto(e) {
    const file = e.target.files?.[0];
    if (!file) return;
    if (file.size > 1500000) { say('Фото больше 1,5 МБ — уменьшите его', true); return; }
    const r = new FileReader();
    r.onload = () => { editing.value.photo = r.result; };
    r.readAsDataURL(file);
}

// ── Действия ──────────────────────────────────────────────────
function menuFor(e, c) {
    e.preventDefault?.();
    const r = e.currentTarget?.getBoundingClientRect?.();
    menu.value = { x: e.clientX || (r ? r.left : 100), y: e.clientY || (r ? r.bottom + 4 : 100), c };
}
function write(c) {
    const mail = c.email || c.emails?.[0]?.value;
    if (!mail) { say('У контакта нет адреса почты', true); return; }
    router.visit(`/mail?compose=1&to=${encodeURIComponent(c.fn && c.fn !== mail ? `${c.fn} <${mail}>` : mail)}`);
}
function meeting(c) {
    const mail = c.email || c.emails?.[0]?.value;
    router.visit(`/calendar?new=1&attendees=${encodeURIComponent(mail || '')}&title=${encodeURIComponent('Встреча: ' + c.fn)}`);
}
async function toggleFavorite(c) {
    menu.value = null;
    if (c.readonly) { say('Сначала скопируйте контакт к себе — тогда можно и в избранное', true); return; }
    try {
        const full = await api.contact(c.book, c.uri);
        const saved = await api.updateContact(c.book, c.uri, { ...full, favorite: !full.favorite });
        const row = all.value.find((x) => x.uri === c.uri && x.book === c.book);
        if (row) row.favorite = saved.favorite;
        if (open.value?.uri === c.uri) open.value.favorite = saved.favorite;
    } catch (e) { fail(e); }
}
async function copyToMine(c) {
    menu.value = null;
    try {
        const saved = await api.copyContact(c.book, c.uri, 'personal');
        await reload(false);
        open.value = await api.contact(saved.book, saved.uri);
        say('Скопировано в «Мои контакты»');
    } catch (e) { fail(e); }
}
async function suggest(c) {
    menu.value = null;
    try {
        const r = await api.suggestContact(c.book, c.uri);
        say(r.status === 'approved' ? 'Добавлено в «Контакты компании»' : 'Отправлено администратору — появится в общей книге после одобрения');
        if (r.status === 'approved') reload();
    } catch (e) { fail(e); }
}
function askDelete(c) { menu.value = null; dialog.value = { kind: 'delete', c }; }
async function confirmDialog() {
    const d = dialog.value; dialog.value = null;
    try {
        if (d.kind === 'delete') {
            await api.deleteContact(d.c.book, d.c.uri);
            all.value = all.value.filter((x) => !(x.uri === d.c.uri && x.book === d.c.book));
            if (open.value?.uri === d.c.uri) { open.value = null; mobileRead.value = false; }
            say('Контакт удалён');
        }
    } catch (e) { fail(e); }
}
async function importFile(e) {
    const file = e.target.files?.[0];
    e.target.value = '';
    if (!file) return;
    try {
        const r = await api.importContacts(file, writableBooks.value[0]?.uri || 'personal');
        say(`Импортировано: ${r.imported}`);
        reload();
    } catch (err) { fail(err); }
}

function onKey(e) {
    const t = e.target;
    if (dialog.value || editing.value) return;
    if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.isContentEditable)) { if (e.key === 'Escape') t.blur(); return; }
    if (e.key === '/') { e.preventDefault(); searchInput.value?.focus(); }
    if (e.key === 'n' && !e.ctrlKey && !e.metaKey) create();
    if (e.key === 'Escape') { menu.value = null; open.value = null; mobileRead.value = false; }
}
onMounted(() => {
    document.addEventListener('keydown', onKey);
    if (props.openUri) {
        const c = all.value.find((x) => x.uri === props.openUri);
        if (c) select(c);
    }
});
onBeforeUnmount(() => document.removeEventListener('keydown', onKey));
</script>

<template>
    <Head :title="title" />
    <MailLayout :user="user" :theme="settings.theme">
        <div class="mail" :class="{ 'mail--read': mobileRead }">
            <nav class="mnav" :class="{ 'mnav--open': navOpen }" aria-label="Книги">
                <button class="btn btn--primary" type="button" style="margin: 0 0 10px" @click="create"><Icon name="plus" :size="16" />Новый контакт</button>
                <button class="mnav__item" :class="{ 'mnav__item--on': filter === 'all' }" type="button" @click="go('all')"><span>Все контакты</span><span class="mnav__count">{{ all.length }}</span></button>
                <button class="mnav__item" :class="{ 'mnav__item--on': filter === 'favorites' }" type="button" @click="go('favorites')"><span>Избранные</span><span class="mnav__count">{{ favorites || '' }}</span></button>
                <div class="mnav__group">Книги</div>
                <button v-for="b in books" :key="b.uri" class="mnav__item" :class="{ 'mnav__item--on': filter === b.uri }" type="button" :title="b.description" @click="go(b.uri)">
                    <Icon :name="b.kind === 'employees' ? 'users' : b.kind === 'company' ? 'building' : 'book'" :size="16" style="color: var(--faint)" />
                    <span>{{ b.name }}</span>
                    <span class="mnav__count">{{ b.count || '' }}</span>
                </button>
                <div v-if="groups.length" class="mnav__group">Группы</div>
                <button v-for="g in groups" :key="g.name" class="mnav__item" :class="{ 'mnav__item--on': filter === 'group:' + g.name }" type="button" @click="go('group:' + g.name)">
                    <Icon name="tag" :size="16" style="color: var(--faint)" /><span>{{ g.name }}</span><span class="mnav__count">{{ g.count }}</span>
                </button>
                <div style="flex: 1" />
                <div class="mnav__foot">
                    <button class="ib ib--sm" type="button" title="Импорт из файла .vcf" @click="fileInput?.click()"><Icon name="upload" :size="15" />Импорт</button>
                    <a class="ib ib--sm" :href="api.exportUrl(filter !== 'all' && !filter.startsWith('group:') && filter !== 'favorites' ? filter : '')" title="Выгрузить .vcf"><Icon name="download" :size="15" />Экспорт</a>
                    <input ref="fileInput" type="file" accept=".vcf,text/vcard" hidden @change="importFile">
                </div>
            </nav>
            <div v-if="navOpen" class="drawer-backdrop" style="z-index: 89" @click="navOpen = false" />

            <section class="mlist">
                <div class="mobile-bar">
                    <button class="ib" type="button" @click="navOpen = true"><Icon name="menu" :size="22" /></button>
                    <b>{{ title }}</b>
                    <button class="ib" type="button" title="Новый контакт" @click="create"><Icon name="plus" :size="20" /></button>
                </div>
                <form class="mlist__search" @submit.prevent>
                    <Icon name="search" :size="18" />
                    <input ref="searchInput" v-model="q" type="search" placeholder="Имя, телефон, компания" @keydown.esc="q = ''">
                    <button v-if="q" class="ib ib--sm" type="button" title="Очистить" @click="q = ''"><Icon name="x" :size="14" /></button>
                    <span v-else class="kbd">/</span>
                </form>
                <div class="mlist__meta">
                    <span>{{ visible.length }} {{ plural(visible.length, 'контакт', 'контакта', 'контактов') }}</span>
                    <button class="ib ib--sm" type="button" title="Обновить" @click="reload()"><Icon name="refresh" :size="14" /></button>
                </div>
                <div class="mlist__rows" :style="loading ? 'opacity:.6' : ''">
                    <template v-for="r in rows" :key="r.letter ? 'L' + r.letter : r.contact.book + r.contact.uri">
                        <div v-if="r.letter" class="crow__letter">{{ r.letter }}</div>
                        <div
                            v-else
                            class="mrow crow"
                            :class="{ 'mrow--on': open && open.uri === r.contact.uri && open.book === r.contact.book }"
                            @click="select(r.contact)"
                            @contextmenu.prevent="menuFor($event, r.contact)"
                        >
                            <span class="mrow__av" :class="{ 'mrow__av--emp': r.contact.employee }">{{ initials(r.contact.fn, r.contact.email) }}</span>
                            <span class="mrow__body">
                                <span class="mrow__from"><b>{{ r.contact.fn }}</b><Icon v-if="r.contact.favorite" name="star" :size="13" style="color: var(--warn)" /></span>
                                <span class="mrow__prev">{{ [r.contact.title, r.contact.org].filter(Boolean).join(' · ') || r.contact.email || (r.contact.phones && r.contact.phones[0] && r.contact.phones[0].value) || '—' }}</span>
                            </span>
                            <span class="mrow__when"><span class="crow__book">{{ r.contact.book === 'personal' ? '' : r.contact.bookName }}</span></span>
                        </div>
                    </template>
                    <div v-if="!rows.length && !loading" class="empty" style="padding-top: 60px">{{ q ? 'Ничего не найдено' : 'Здесь пока пусто' }}</div>
                </div>
            </section>

            <section class="mread">
                <!-- Форма -->
                <form v-if="editing" class="ccard ccard--form" @submit.prevent="save">
                    <div class="mread__bar">
                        <button class="ib" type="button" @click="editing = null; if (!open) mobileRead = false"><Icon name="back" :size="18" />Назад</button>
                        <b style="font-size: 15px">{{ editing.sourceUri ? 'Правка контакта' : 'Новый контакт' }}</b>
                        <span class="grow" />
                        <button class="btn btn--primary" type="submit" :disabled="loading">Сохранить</button>
                    </div>
                    <div class="mread__scroll">
                        <div class="cform">
                            <div class="cform__top">
                                <label class="cform__photo" title="Фото">
                                    <img v-if="editing.photo" :src="editing.photo" alt="">
                                    <span v-else>{{ initials([editing.last, editing.first].filter(Boolean).join(' '), editing.emails[0]?.value) }}</span>
                                    <input type="file" accept="image/*" hidden @change="onPhoto">
                                </label>
                                <div class="cform__names">
                                    <input v-model="editing.last" class="input" placeholder="Фамилия">
                                    <input v-model="editing.first" class="input" placeholder="Имя">
                                    <input v-model="editing.middle" class="input" placeholder="Отчество">
                                </div>
                            </div>
                            <div class="mset__cols">
                                <div class="field"><label>Организация</label><input v-model="editing.org" class="input"></div>
                                <div class="field"><label>Должность</label><input v-model="editing.title" class="input"></div>
                                <div class="field"><label>Отдел</label><input v-model="editing.department" class="input"></div>
                                <div class="field"><label>Книга</label>
                                    <select v-model="editing.book" class="input">
                                        <option v-for="b in writableBooks" :key="b.uri" :value="b.uri">{{ b.name }}</option>
                                    </select>
                                </div>
                            </div>

                            <div class="field">
                                <label>Почта</label>
                                <div v-for="(e, i) in editing.emails" :key="'e' + i" class="cform__multi">
                                    <input v-model="e.value" class="input" type="email" placeholder="адрес@домен.ru">
                                    <select v-model="e.type" class="input cform__type"><option value="work">рабочая</option><option value="home">личная</option><option value="other">другая</option></select>
                                    <button class="ib ib--sm" type="button" title="Убрать" @click="editing.emails.splice(i, 1)"><Icon name="x" :size="14" /></button>
                                </div>
                                <button class="ib ib--sm" type="button" @click="editing.emails.push({ value: '', type: 'work' })"><Icon name="plus" :size="14" />ещё адрес</button>
                            </div>
                            <div class="field">
                                <label>Телефоны</label>
                                <div v-for="(p, i) in editing.phones" :key="'p' + i" class="cform__multi">
                                    <input v-model="p.value" class="input" type="tel" placeholder="+7 …">
                                    <select v-model="p.type" class="input cform__type"><option value="cell">мобильный</option><option value="work">рабочий</option><option value="home">домашний</option><option value="fax">факс</option></select>
                                    <button class="ib ib--sm" type="button" title="Убрать" @click="editing.phones.splice(i, 1)"><Icon name="x" :size="14" /></button>
                                </div>
                                <button class="ib ib--sm" type="button" @click="editing.phones.push({ value: '', type: 'work' })"><Icon name="plus" :size="14" />ещё телефон</button>
                            </div>
                            <div class="field">
                                <label>Адрес</label>
                                <div v-for="(a, i) in editing.addresses" :key="'a' + i" class="cform__addr">
                                    <input v-model="a.street" class="input" placeholder="Улица, дом" style="grid-column: 1 / -1">
                                    <input v-model="a.city" class="input" placeholder="Город">
                                    <input v-model="a.postal" class="input" placeholder="Индекс">
                                    <input v-model="a.country" class="input" placeholder="Страна">
                                    <button class="ib ib--sm" type="button" title="Убрать" @click="editing.addresses.splice(i, 1)"><Icon name="x" :size="14" /></button>
                                </div>
                                <button v-if="!editing.addresses.length" class="ib ib--sm" type="button" @click="editing.addresses.push({ type: 'work', street: '', city: '', region: '', postal: '', country: '' })"><Icon name="plus" :size="14" />добавить адрес</button>
                            </div>
                            <div class="mset__cols">
                                <div class="field"><label>День рождения</label><input v-model="editing.birthday" class="input" type="date"></div>
                                <div class="field"><label>Сайт</label><input v-model="editing.url" class="input" placeholder="https://"></div>
                            </div>
                            <div class="field"><label>Группы <span class="faint">через запятую</span></label><input v-model="editing.groupsText" class="input" placeholder="Клиенты, Поставщики"></div>
                            <div class="field"><label>Заметка</label><textarea v-model="editing.note" class="input" rows="3" /></div>
                            <label class="check"><input v-model="editing.favorite" type="checkbox"> В избранные</label>
                        </div>
                    </div>
                </form>

                <!-- Карточка -->
                <div v-else-if="open" class="ccard">
                    <div class="mread__bar">
                        <button class="ib mobile-only" type="button" @click="mobileRead = false"><Icon name="back" :size="18" /></button>
                        <button class="ib" type="button" @click="write(open)"><Icon name="mail" :size="16" />Написать</button>
                        <button class="ib" type="button" @click="meeting(open)"><Icon name="cal" :size="16" />Встреча</button>
                        <span class="sep" />
                        <button v-if="!open.readonly" class="ib" type="button" @click="edit(open)"><Icon name="edit" :size="16" />Изменить</button>
                        <button v-else class="ib" type="button" title="Скопировать в «Мои контакты»" @click="copyToMine(open)"><Icon name="copy" :size="16" />К себе</button>
                        <button v-if="open.book !== 'company' && open.book !== 'employees'" class="ib" type="button" title="Предложить в общую книгу компании" @click="suggest(open)"><Icon name="share" :size="16" />В общую</button>
                        <span class="grow" />
                        <button class="ib" type="button" :title="open.favorite ? 'Убрать из избранного' : 'В избранное'" :class="{ 'ib--on': open.favorite }" @click="toggleFavorite(open)"><Icon name="star" :size="16" /></button>
                        <button v-if="!open.readonly" class="ib ib--danger" type="button" title="Удалить" @click="askDelete(open)"><Icon name="trash" :size="16" /></button>
                    </div>
                    <div class="mread__scroll">
                        <div class="msg ccard__head">
                            <div class="ccard__av">
                                <img v-if="open.photo" :src="open.photo" alt="">
                                <span v-else>{{ initials(open.fn, open.email) }}</span>
                            </div>
                            <div class="grow" style="min-width: 0">
                                <h1>{{ open.fn }}</h1>
                                <div class="hint">{{ [open.title, open.org].filter(Boolean).join(' · ') || (open.employee ? 'Сотрудник' : open.bookName) }}</div>
                                <div class="ccard__chips">
                                    <span class="chip chip--off">{{ open.bookName }}</span>
                                    <span v-for="g in open.groups" :key="g" class="chip chip--acc">{{ g }}</span>
                                </div>
                            </div>
                        </div>
                        <div class="msg ccard__body">
                            <div v-for="(e, i) in open.emails" :key="'e' + i" class="kv"><span>Почта <small>{{ TYPES[e.type] || e.type }}</small></span><b><a href="#" @click.prevent="write({ ...open, email: e.value })">{{ e.value }}</a></b></div>
                            <div v-for="(p, i) in open.phones" :key="'p' + i" class="kv"><span>Телефон <small>{{ TYPES[p.type] || p.type }}</small></span><b><a :href="'tel:' + p.value.replace(/[^+\d]/g, '')">{{ p.value }}</a></b></div>
                            <div v-for="(a, i) in open.addresses" :key="'a' + i" class="kv"><span>Адрес</span><b>{{ [a.postal, a.country, a.city, a.street].filter(Boolean).join(', ') }}</b></div>
                            <div v-if="open.department" class="kv"><span>Отдел</span><b>{{ open.department }}</b></div>
                            <div v-if="open.birthday" class="kv"><span>День рождения</span><b>{{ new Date(open.birthday).toLocaleDateString('ru-RU', { day: 'numeric', month: 'long', year: 'numeric' }) }}</b></div>
                            <div v-if="open.url" class="kv"><span>Сайт</span><b><a :href="open.url" target="_blank" rel="noopener">{{ open.url }}</a></b></div>
                            <div v-if="open.nick" class="kv"><span>Псевдоним</span><b>{{ open.nick }}</b></div>
                            <div v-if="open.note" class="kv kv--note"><span>Заметка</span><b>{{ open.note }}</b></div>
                            <div v-if="!open.emails.length && !open.phones.length" class="empty">Ни адреса, ни телефона</div>
                        </div>
                        <p v-if="open.readonly" class="hint">Это общая книга: править её может администратор. Хотите свой вариант — скопируйте контакт к себе.</p>
                    </div>
                </div>

                <div v-else class="mread__empty">
                    <span>Выберите контакт слева</span>
                    <span class="hint"><span class="kbd">n</span> — новый контакт, <span class="kbd">/</span> — поиск</span>
                </div>
            </section>

            <button class="fab" type="button" title="Новый контакт" @click="create"><Icon name="plus" :size="24" /></button>
        </div>

        <Popover v-if="menu" :x="menu.x" :y="menu.y" @close="menu = null">
            <button class="pop__item" type="button" @click="write(menu.c); menu = null"><Icon name="mail" :size="16" />Написать</button>
            <button class="pop__item" type="button" @click="meeting(menu.c); menu = null"><Icon name="cal" :size="16" />Назначить встречу</button>
            <div class="pop__sep" />
            <button class="pop__item" type="button" @click="toggleFavorite(menu.c)"><Icon name="star" :size="16" />{{ menu.c.favorite ? 'Убрать из избранного' : 'В избранное' }}</button>
            <button v-if="menu.c.readonly" class="pop__item" type="button" @click="copyToMine(menu.c)"><Icon name="copy" :size="16" />Скопировать к себе</button>
            <button v-else class="pop__item" type="button" @click="select(menu.c).then(() => edit(open)); menu = null"><Icon name="edit" :size="16" />Изменить</button>
            <button v-if="menu.c.book !== 'company' && menu.c.book !== 'employees'" class="pop__item" type="button" @click="suggest(menu.c)"><Icon name="share" :size="16" />Предложить в общую</button>
            <div v-if="!menu.c.readonly" class="pop__sep" />
            <button v-if="!menu.c.readonly" class="pop__item pop__item--danger" type="button" @click="askDelete(menu.c)"><Icon name="trash" :size="16" />Удалить</button>
        </Popover>

        <Dialog v-if="dialog && dialog.kind === 'delete'" :title="'Удалить «' + dialog.c.fn + '»?'" confirm-label="Удалить" danger @close="dialog = null" @confirm="confirmDialog">
            <p class="hint" style="margin: 0">Контакт пропадёт и с телефона, если он синхронизирован.</p>
        </Dialog>
        <Toast :toast="toast" @close="toast = null" />
    </MailLayout>
</template>
