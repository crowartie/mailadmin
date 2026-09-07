<script setup>
import { router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import Icon from '../../Components/Icon.vue';

const props = defineProps({
    rules: Object,
    filters: Object,
    syncedAt: String,
});

const search = ref(props.filters.search ?? '');
const kind = ref(props.filters.kind ?? 'all');
let timer = null;

function reload() {
    router.get('/rules', { search: search.value || undefined, kind: kind.value !== 'all' ? kind.value : undefined }, {
        preserveState: true,
        replace: true,
    });
}
watch(search, () => { clearTimeout(timer); timer = setTimeout(reload, 300); });
function setKind(value) { kind.value = value; reload(); }

const kinds = [
    { key: 'all', label: 'все' },
    { key: 'vacation', label: 'автоответы' },
    { key: 'redirect', label: 'пересылки' },
    { key: 'disabled', label: 'выключенные' },
];

const COLS = '36px 230px minmax(0, 1fr) minmax(0, 1fr) 150px';

function initials(row) {
    const parts = (row.owner_name || row.owner).replace(/@.*/, '').split(/[\s._-]+/).filter(Boolean);
    return parts.slice(0, 2).map((p) => p[0].toUpperCase()).join('') || '?';
}
</script>

<template>
    <AppLayout title="Правила" :count="rules.total" search-placeholder="Сотрудник или текст правила…">
        <template #actions>
            <div class="seg">
                <span class="seg__item seg__item--on">Правила сотрудников</span>
                <span class="seg__item" title="Пока не реализовано">Входящие для всего сервера</span>
                <span class="seg__item" title="Пока не реализовано">Исходящие для всего сервера</span>
            </div>
        </template>

        <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap">
            <input v-model="search" class="input input--w" style="width: 360px" type="search" placeholder="Сотрудник или текст правила">
            <div style="display: flex; gap: 6px">
                <button
                    v-for="k in kinds"
                    :key="k.key"
                    type="button"
                    class="chip"
                    :class="kind === k.key ? 'chip--acc' : 'chip--off'"
                    style="border: none; cursor: pointer; font-family: inherit"
                    @click="setKind(k.key)"
                >
                    {{ k.label }}
                </button>
            </div>
            <span style="flex: 1" />
            <span class="faint">
                Правила хранятся у каждого сотрудника и выполняются на сервере при доставке
                <template v-if="syncedAt">· синхронизировано {{ syncedAt }}</template>
            </span>
        </div>

        <div class="card card--flush">
            <div class="thead" :style="{ gridTemplateColumns: COLS }">
                <span></span><span>Сотрудник</span><span>Если</span><span>То</span><span>Состояние</span>
            </div>
            <div v-for="row in rules.data" :key="row.id" class="row" :style="{ gridTemplateColumns: COLS }">
                <div class="avatar">{{ initials(row) }}</div>
                <div style="min-width: 0">
                    <div class="row__name">{{ row.owner_name || row.owner }}</div>
                    <div class="row__sub mono">{{ row.owner }}</div>
                </div>
                <div style="font-size: 13.5px">{{ row.condition }}</div>
                <div style="font-size: 13.5px">{{ row.action }}</div>
                <div>
                    <span class="chip" :class="row.active ? (row.until ? 'chip--warn' : 'chip--ok') : 'chip--off'">
                        {{ row.active ? (row.until ? 'до ' + row.until : 'активно') : 'выключено' }}
                    </span>
                </div>
            </div>
            <div v-if="!rules.data.length" class="empty">Правил пока нет — или синхронизация с сервером ещё не выполнялась</div>
        </div>

        <p class="faint">Показаны {{ rules.data.length }} из {{ rules.total }} · сначала недавно изменённые</p>
    </AppLayout>
</template>
