<script setup>
import { Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import Icon from '../../Components/Icon.vue';
import Drawer from './Drawer.vue';
import TraceNode from './TraceNode.vue';

const props = defineProps({
    rows: Array,
    total: Number,
    filters: Object,
    editing: Object,
    domains: Array,
    trace: Object,
    traceFor: String,
});

const search = ref(props.filters.search ?? '');
const traceInput = ref(props.traceFor ?? '');
let timer = null;

function reload(extra = {}) {
    router.get('/aliases', { search: search.value || undefined, trace: props.traceFor || undefined, ...extra }, {
        preserveState: true,
        replace: true,
    });
}
watch(search, () => { clearTimeout(timer); timer = setTimeout(() => reload(), 300); });

function runTrace() {
    const address = traceInput.value.trim();
    if (!address) return;
    router.get('/aliases', { search: search.value || undefined, trace: address }, { preserveState: true, preserveScroll: true });
}

function open(row) {
    if (!row.named) return; // дополнительный адрес правится в карточке сотрудника
    router.get(`/aliases/${row.address}/edit`, { search: search.value || undefined }, { preserveState: true, preserveScroll: true });
}

function close() {
    router.get('/aliases', { search: search.value || undefined }, { preserveState: true, preserveScroll: true });
}

const COLS = '300px minmax(0, 1fr) 260px 120px';
</script>

<template>
    <AppLayout title="Псевдонимы" :count="total" search-placeholder="Найти адрес…">
        <template #actions>
            <input v-model="search" class="input input--w" style="width: 260px" type="search" placeholder="Адрес, имя или получатель">
            <Link class="btn btn--primary" href="/aliases/create"><Icon name="plus" :size="16" />Добавить</Link>
        </template>

        <div class="card card--flush">
            <div class="thead" :style="{ gridTemplateColumns: COLS }">
                <span>Адрес</span><span>Доставлять на</span><span>Описание</span><span>Статус</span>
            </div>
            <div
                v-for="row in rows"
                :key="row.address"
                class="row"
                :class="{ 'row--link': row.named, 'row--on': editing && editing.address === row.address }"
                :style="{ gridTemplateColumns: COLS }"
                @click="open(row)"
            >
                <div class="row__name mono" style="font-size: 13.5px">{{ row.address }}</div>
                <div class="tags">
                    <span v-for="t in row.targets" :key="t" class="chip chip--acc">{{ t }}</span>
                    <span v-if="!row.targets.length" class="chip chip--no">никуда не доставляется</span>
                </div>
                <div class="hint" style="font-size: 13px">{{ row.name || '—' }}</div>
                <div><span class="chip" :class="row.active ? 'chip--ok' : 'chip--off'">{{ row.active ? 'включён' : 'выключен' }}</span></div>
            </div>
            <div v-if="!rows.length" class="empty">Псевдонимов нет</div>
        </div>
        <p class="faint">Показаны {{ rows.length }} из {{ total }} · дополнительные адреса сотрудников правятся в их карточках</p>

        <div class="card card--pad" style="display: flex; flex-direction: column; gap: 12px">
            <div class="card__title" style="margin: 0">Проверить адрес</div>
            <form style="display: flex; gap: 10px" @submit.prevent="runTrace">
                <input v-model="traceInput" class="input input--w" style="width: 420px" placeholder="sales@домен">
                <button class="btn" type="submit">Проследить</button>
            </form>
            <TraceNode v-if="trace" :node="trace" :root="true" />
            <p v-else class="hint" style="margin: 0">Покажет всю цепочку: алиас домена → псевдоним → пересылки → рассылка → ящик, и где она обрывается.</p>
        </div>

        <template #overlay>
            <transition name="drawer">
                <Drawer v-if="editing" :key="editing.address || 'new'" :alias="editing" :domains="domains" @close="close" />
            </transition>
        </template>
    </AppLayout>
</template>
