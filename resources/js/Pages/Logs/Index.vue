<script setup>
// Журналы: лента событий с фильтрами и живым обновлением; справа — «путь письма».
import { router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import Icon from '../../Components/Icon.vue';

const props = defineProps({
    events: Array,
    path: Object,
    filters: Object,
    readable: Boolean,
});

const events = ref(props.events);
const type = ref(props.filters.type || 'all');
const q = ref(props.filters.q || '');
const period = ref(props.filters.period || 'day');
const trace = ref(props.filters.trace || '');
const path = ref(props.path);
const live = ref(false);
const tracing = ref(false);
const showRaw = ref(false);
let timer = null; let liveTimer = null;

const TYPES = [['all', 'Все'], ['mail', 'Почта'], ['spam', 'Спам'], ['auth', 'Входы'], ['error', 'Ошибки'], ['admin', 'Действия админов']];
const KIND = { mail: ['почта', 'ok'], spam: ['спам', 'warn'], grey: ['отложено', 'off'], auth: ['вход', 'acc'], error: ['ошибка', 'no'], admin: ['админ', 'acc'] };

function reload() {
    router.get('/logs', { type: type.value !== 'all' ? type.value : undefined, q: q.value || undefined, period: period.value !== 'day' ? period.value : undefined, trace: trace.value || undefined }, { preserveState: true, preserveScroll: true, replace: true, only: ['events', 'path', 'filters'], onSuccess: (p) => { events.value = p.props.events; path.value = p.props.path; } });
}
watch([type, period], reload);
watch(q, () => { clearTimeout(timer); timer = setTimeout(reload, 350); });

async function tail() {
    if (!live.value || document.visibilityState !== 'visible') return;
    const after = events.value[0]?.time;
    const p = new URLSearchParams({ type: type.value, q: q.value });
    if (after) p.set('after', after);
    try {
        const r = await fetch('/logs/tail?' + p, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        const fresh = await r.json();
        const known = new Set(events.value.map((e) => e.time + e.who + e.what));
        const add = fresh.filter((e) => !known.has(e.time + e.who + e.what));
        if (add.length) events.value = [...add, ...events.value].slice(0, 400);
    } catch {}
}
watch(live, (v) => { clearInterval(liveTimer); if (v) liveTimer = setInterval(tail, 5000); });
onMounted(() => { if (live.value) liveTimer = setInterval(tail, 5000); });
onBeforeUnmount(() => { clearInterval(liveTimer); clearTimeout(timer); });

async function doTrace(id = trace.value) {
    trace.value = id; if (!id) { path.value = null; return; }
    tracing.value = true;
    try {
        const r = await fetch('/logs/path?id=' + encodeURIComponent(id), { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        path.value = await r.json();
        window.history.replaceState({}, '', '/logs?trace=' + encodeURIComponent(id));
    } finally { tracing.value = false; }
}
const exportUrl = computed(() => `/logs/export?${new URLSearchParams({ type: type.value, q: q.value, period: period.value })}`);
const STEP = { ok: 'var(--ok)', warn: 'var(--warn)', no: 'var(--no)', acc: 'var(--accent)' };
const COLS = '80px 90px minmax(0, 1.1fr) minmax(0, 1fr)';
</script>

<template>
    <AppLayout title="Журналы" search-placeholder="Адрес, тема или message-id…">
        <template #actions>
            <div class="seg"><button v-for="[k, l] in TYPES" :key="k" type="button" class="seg__item" :class="{ 'seg__item--on': type === k }" @click="type = k">{{ l }}</button></div>
            <select v-model="period" class="input" style="width: 130px"><option value="hour">За час</option><option value="day">За сутки</option><option value="week">Всё, что есть</option></select>
            <label class="btn" style="cursor: pointer"><input v-model="live" type="checkbox" style="accent-color: var(--accent)"> Живая лента</label>
        </template>

        <div v-if="!readable" class="card card--pad" style="border-color: var(--warn)">Журнал /var/log/mail.log недоступен приложению: добавьте www-data в группу adm и перезапустите php-fpm (deploy/mail01-wave2.sh).</div>

        <div class="grid-log">
            <div class="card card--flush">
                <div class="thead" :style="{ gridTemplateColumns: COLS }"><span>Время</span><span>Тип</span><span>Кто · кому</span><span>Что</span></div>
                <div style="padding: 8px 18px; border-bottom: 1px solid var(--border)"><input v-model="q" class="input" type="search" placeholder="Фильтр по адресу, IP, тексту…" style="height: 34px"></div>
                <div v-for="(e, i) in events" :key="e.time + i" class="row row--log" :style="{ gridTemplateColumns: COLS }">
                    <span class="mono faint" :title="e.time">{{ e.ts }}</span>
                    <span><span class="chip" :class="`chip--${KIND[e.kind]?.[1] || 'off'}`">{{ KIND[e.kind]?.[0] || e.kind }}</span></span>
                    <span class="mono ellipsis" :title="e.who">{{ e.who }}</span>
                    <span class="row__sub" style="display: flex; align-items: flex-start; gap: 6px">
                        <span style="flex: 1">{{ e.what }}</span>
                        <button v-if="e.qid || e.msgid" class="ib ib--sm" type="button" title="Путь письма" @click="doTrace(e.qid || e.msgid)"><Icon name="chevron" :size="14" /></button>
                    </span>
                </div>
                <div v-if="!events.length" class="empty">Событий нет</div>
                <div class="row__foot">Показаны {{ events.length }} · <a :href="exportUrl">экспорт CSV</a></div>
            </div>

            <div class="card card--pad path">
                <div class="card__title" style="display: flex; align-items: center; gap: 10px">Путь письма <span style="flex: 1" /><span v-if="path && path.found" class="chip" :class="path.steps.some((s) => s.kind === 'no') ? 'chip--no' : path.steps.some((s) => s.kind === 'warn') ? 'chip--warn' : 'chip--ok'">{{ path.steps.some((s) => s.kind === 'no') ? 'не доставлено' : path.steps.some((s) => s.kind === 'warn') ? 'с задержкой' : 'доставлено' }}</span></div>
                <form class="field__row" @submit.prevent="doTrace()">
                    <input v-model="trace" class="input" placeholder="message-id, queue-id или адрес" style="flex: 1">
                    <button class="btn" type="submit" :disabled="tracing">Найти</button>
                </form>
                <template v-if="path && path.found">
                    <div class="hint" style="margin: 8px 0">от {{ path.from || '—' }} · кому {{ path.to || '—' }}<template v-if="path.msgid"> · <span class="mono">&lt;{{ path.msgid }}&gt;</span></template></div>
                    <div class="timeline">
                        <div v-for="(s, i) in path.steps" :key="i" class="timeline__step">
                            <span class="mono faint">{{ s.time }}</span>
                            <span class="timeline__dot" :style="{ background: STEP[s.kind] || STEP.ok }" />
                            <div><div class="timeline__title">{{ s.title }}</div><div class="timeline__sub">{{ s.sub }}</div></div>
                        </div>
                    </div>
                    <div style="display: flex; gap: 6px; margin-top: 10px">
                        <button class="btn btn--sm" type="button" @click="showRaw = !showRaw">{{ showRaw ? 'Скрыть сырой лог' : 'Сырой лог' }}</button>
                        <button v-for="qid in (path.qids || [])" :key="qid" class="btn btn--sm" type="button" @click="q = qid; type = 'all'">В ленте: {{ qid }}</button>
                    </div>
                    <pre v-if="showRaw" class="mono pre" style="margin-top: 10px; max-height: 300px; overflow: auto">{{ path.raw.join('\n') }}</pre>
                </template>
                <div v-else-if="path" class="empty">По «{{ trace }}» ничего не нашлось за доступный период</div>
                <div v-else class="hint" style="margin-top: 8px">Введите адрес, message-id или queue-id, либо нажмите стрелку у события в ленте.</div>
            </div>
        </div>
    </AppLayout>
</template>
