<script setup>
import { Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import Icon from '../../Components/Icon.vue';
import Drawer from './Drawer.vue';
import ImportModal from './ImportModal.vue';

const props = defineProps({
    mailboxes: Object,
    filters: Object,
    editing: Object,      // карточка, открытая поверх списка
    domains: Array,
    serviceFlags: Array,
});

const importing = ref(false);
const search = ref(props.filters.search ?? '');
const filter = ref(props.filters.filter ?? 'all');
let timer = null;

function pageUrl(p) {
    const u = new URL(props.mailboxes.path, window.location.origin);
    if (search.value) u.searchParams.set('search', search.value);
    if (filter.value !== 'all') u.searchParams.set('filter', filter.value);
    u.searchParams.set('page', p);
    return u.pathname + u.search;
}

function reload() {
    router.get('/mailboxes', { search: search.value || undefined, filter: filter.value !== 'all' ? filter.value : undefined }, {
        preserveState: true,
        replace: true,
    });
}

watch(search, () => {
    clearTimeout(timer);
    timer = setTimeout(reload, 300);
});

function setFilter(value) {
    filter.value = value;
    reload();
}

const filtersList = [
    { key: 'all', label: 'Все' },
    { key: 'admins', label: 'Администраторы' },
    { key: 'blocked', label: 'Заблокированные' },
];

const COLS = '36px minmax(220px, 1.4fr) minmax(120px, 1fr) 170px 140px minmax(120px, 1fr) 110px';

function initials(row) {
    const source = row.name || row.username;
    const parts = source.replace(/@.*/, '').split(/[\s._-]+/).filter(Boolean);
    return parts.slice(0, 2).map((p) => p[0].toUpperCase()).join('') || '?';
}

function formatSize(bytes) {
    if (!bytes) return '0';
    const mb = bytes / 1048576;
    return mb >= 1024 ? `${(mb / 1024).toFixed(1)} ГБ` : `${Math.round(mb)} МБ`;
}

function usagePercent(row) {
    if (!row.quotaMb) return null;
    return Math.min(100, Math.round((row.usedBytes / 1048576 / row.quotaMb) * 100));
}

// Карточка открывается поверх списка — страница, поиск и фильтр передаются дальше, иначе список откатится на первую страницу.
function listQuery() {
    return { search: search.value || undefined, filter: filter.value !== 'all' ? filter.value : undefined, page: props.mailboxes.current_page > 1 ? props.mailboxes.current_page : undefined };
}

function open(row) {
    router.get(`/mailboxes/${row.username}/edit`, listQuery(), { preserveState: true, preserveScroll: true });
}

function close() {
    router.get('/mailboxes', listQuery(), { preserveState: true, preserveScroll: true });
}
</script>

<template>
    <AppLayout title="Сотрудники" :count="mailboxes.total">
        <template #actions>
            <div class="seg">
                <button
                    v-for="f in filtersList"
                    :key="f.key"
                    type="button"
                    class="seg__item"
                    :class="{ 'seg__item--on': filter === f.key }"
                    @click="setFilter(f.key)"
                >
                    {{ f.label }}
                </button>
            </div>
            <input v-model="search" class="input input--w" style="width: 260px" type="search" placeholder="Имя или адрес">
            <button class="btn" type="button" @click="importing = true"><Icon name="upload" :size="16" />Импорт CSV</button>
            <Link class="btn btn--primary" href="/mailboxes/create"><Icon name="plus" :size="16" />Добавить</Link>
        </template>

        <div class="card card--flush">
            <div class="thead" :style="{ gridTemplateColumns: COLS }">
                <span></span><span>Сотрудник</span><span>Подразделение</span><span>Занято</span><span>Службы</span><span></span><span>Статус</span>
            </div>

            <div
                v-for="row in mailboxes.data"
                :key="row.username"
                class="row row--link"
                :class="{ 'row--on': editing && editing.username === row.username }"
                :style="{ gridTemplateColumns: COLS }"
                @click="open(row)"
            >
                <div class="avatar" :class="{ 'avatar--off': !row.active }">{{ initials(row) }}</div>
                <div style="min-width: 0">
                    <div class="row__name">{{ row.name || row.username }}<span v-if="row.service" class="tag" style="margin-left: 6px">служебный</span></div>
                    <div class="row__sub mono">{{ row.username }}</div>
                </div>
                <div class="hint" style="font-size: 13.5px">{{ row.department || '—' }}</div>
                <div>
                    <div style="font-size: 13px">
                        {{ formatSize(row.usedBytes) }}
                        <span class="faint">{{ row.quotaMb ? `из ${row.quotaMb >= 1024 ? (row.quotaMb / 1024) + ' ГБ' : row.quotaMb + ' МБ'}` : 'без лимита' }}</span>
                    </div>
                    <div v-if="usagePercent(row) !== null" class="meter">
                        <span class="meter__fill" :class="{ 'meter__fill--warn': usagePercent(row) >= 80 }" :style="{ width: usagePercent(row) + '%' }" />
                    </div>
                </div>
                <div class="tags">
                    <span class="tag" :class="{ 'tag--off': !row.imap }">IMAP</span>
                    <span class="tag" :class="{ 'tag--off': !row.smtp }">SMTP</span>
                    <span class="tag" :class="{ 'tag--off': !row.sogo }">Веб</span>
                </div>
                <div class="row__actions">
                    <span class="btn btn--sm">Открыть</span>
                    <span class="btn btn--sm btn--icon"><Icon name="dots" :size="16" /></span>
                </div>
                <div>
                    <span class="chip" :class="!row.active ? 'chip--off' : row.loginBlocked ? 'chip--warn' : 'chip--ok'" :title="!row.active ? 'Ящик выключен: почта не принимается' : row.loginBlocked ? 'Вход закрыт: почта приходит, войти нельзя' : ''">{{ !row.active ? 'выключен' : row.loginBlocked ? 'вход закрыт' : 'активен' }}</span>
                </div>
            </div>

            <div v-if="!mailboxes.data.length" class="empty">Ничего не найдено</div>
        </div>

        <div class="faint" style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap">
            <span>Показаны {{ mailboxes.from ?? 0 }}–{{ mailboxes.to ?? 0 }} из {{ mailboxes.total }} · сортировка по адресу</span>
            <template v-if="mailboxes.last_page > 1">
                <span style="flex: 1" />
                <Link v-if="mailboxes.prev_page_url" class="btn btn--sm" :href="mailboxes.prev_page_url" preserve-scroll>← Назад</Link>
                <Link
                    v-for="p in mailboxes.last_page"
                    :key="p"
                    class="btn btn--sm"
                    :class="{ 'btn--primary': p === mailboxes.current_page }"
                    :href="pageUrl(p)"
                    preserve-scroll
                >{{ p }}</Link>
                <Link v-if="mailboxes.next_page_url" class="btn btn--sm" :href="mailboxes.next_page_url" preserve-scroll>Вперёд →</Link>
            </template>
        </div>

        <template #overlay>
            <ImportModal v-if="importing" :domains="domains" @close="importing = false" />
            <transition name="drawer">
                <Drawer
                    v-if="editing"
                    :key="editing.username"
                    :mailbox="editing"
                    :domains="domains"
                    :service-flags="serviceFlags"
                    @close="close"
                />
            </transition>
        </template>
    </AppLayout>
</template>
