<script setup>
import { Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import Icon from '../../Components/Icon.vue';
import Drawer from './Drawer.vue';

const props = defineProps({
    mailboxes: Object,
    filters: Object,
    editing: Object,      // карточка, открытая поверх списка
    domains: Array,
    serviceFlags: Array,
});

const search = ref(props.filters.search ?? '');
const filter = ref(props.filters.filter ?? 'all');
let timer = null;

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

function open(row) {
    router.get(`/mailboxes/${row.username}/edit`, {}, { preserveState: true, preserveScroll: true });
}

function close() {
    router.get('/mailboxes', { search: search.value || undefined }, { preserveState: true, preserveScroll: true });
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
                    <div class="row__name">{{ row.name || row.username }}</div>
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
                    <span class="chip" :class="row.active ? 'chip--ok' : 'chip--off'">{{ row.active ? 'активен' : 'заблокирован' }}</span>
                </div>
            </div>

            <div v-if="!mailboxes.data.length" class="empty">Ничего не найдено</div>
        </div>

        <p class="faint">Показаны {{ mailboxes.data.length }} из {{ mailboxes.total }} · сортировка по адресу</p>

        <template #overlay>
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
