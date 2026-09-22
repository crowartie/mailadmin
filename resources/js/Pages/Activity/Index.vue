<script setup>
// Активность сотрудников в веб-почте: сводка с сравнением к прошлому периоду, по действиям,
// по людям, по устройствам и папкам, ошибки и медленные ответы.
import { router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import Icon from '../../Components/Icon.vue';

const props = defineProps({
    filters: Object, now: Object, prev: Object,
    byAction: Array, byUser: Array, byClient: Array, byFolder: Array,
    errors: Array, slow: Array, timeline: Array, users: Array, oldest: String,
});

const period = ref(props.filters.period || 'day');
const user = ref(props.filters.user || '');
const master = ref(!!props.filters.master);
const PERIODS = [['day', 'Сутки'], ['week', 'Неделя'], ['month', 'Месяц']];
const FOLDER = { inbox: 'Входящие', 'inbox-sub': 'папки во «Входящих»', sent: 'Отправленные', 'sent-sub': 'папки в «Отправленных»', drafts: 'Черновики', trash: 'Корзина', junk: 'Спам', archive: 'Архив', 'archive-sub': 'папки в «Архиве»', own: 'свои папки', shared: 'общие папки', 'trash-sub': 'папки в «Корзине»', 'junk-sub': 'папки в «Спаме»', 'drafts-sub': 'папки в «Черновиках»' };

function reload() {
    router.get('/activity', { period: period.value !== 'day' ? period.value : undefined, user: user.value || undefined, master: master.value ? 1 : undefined }, { preserveState: true, preserveScroll: true, replace: true });
}
watch([period, user, master], reload);

const delta = (a, b) => (b === 0 ? (a === 0 ? '' : 'новое') : Math.round(((a - b) / b) * 100) + '%');
const deltaClass = (a, b, badIfUp = false) => (a === b || b === 0 ? '' : (a > b) !== badIfUp ? 'chip--ok' : 'chip--warn');
const tiles = computed(() => [
    { label: 'Сотрудников работало', value: props.now.users, prev: props.prev.users },
    { label: 'Действий', value: props.now.actions, prev: props.prev.actions },
    { label: 'Писем отправлено', value: props.now.sent, prev: props.prev.sent },
    { label: 'Среднее ожидание', value: props.now.ms + ' мс', prev: props.prev.ms, raw: props.now.ms, badIfUp: true },
    { label: 'Ошибок сервера', value: props.now.errors, prev: props.prev.errors, badIfUp: true, kind: props.now.errors ? 'no' : '' },
]);
const maxT = computed(() => Math.max(1, ...props.timeline.map((t) => t.n)));
const when = (s) => (s || '').slice(5, 16).replace('-', '.').replace(' ', ' ');
const short = (u) => (u || '').replace(/@.*/, '');
</script>

<template>
    <AppLayout title="Активность">
        <template #actions>
            <span class="hint" v-if="oldest">записи с {{ (oldest || '').slice(0, 10) }}, хранятся 90 дней</span>
        </template>

        <div class="card" style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center; margin-bottom: 14px">
            <div class="seg">
                <button v-for="[k, t] in PERIODS" :key="k" type="button" class="seg__item" :class="{ 'seg__item--on': period === k }" @click="period = k">{{ t }}</button>
            </div>
            <select v-model="user" class="input" style="width: 260px">
                <option value="">Все сотрудники</option>
                <option v-for="u in users" :key="u" :value="u">{{ u }}</option>
            </select>
            <label class="toggle"><input v-model="master" type="checkbox"><span class="toggle__track" />Включая просмотр администратором</label>
            <span class="hint" style="margin-left: auto">Сравнение — с таким же периодом до этого. Содержимое писем, адреса и текст поиска не записываются.</span>
        </div>

        <div class="tiles tiles--5" style="margin-bottom: 14px">
            <div v-for="t in tiles" :key="t.label" class="tile">
                <div class="tile__value" :class="{ 'tile__value--no': t.kind === 'no' }">{{ t.value }}</div>
                <div class="tile__label">{{ t.label }}</div>
                <div class="tile__sub">
                    <span v-if="delta(t.raw ?? t.value, t.prev)" class="chip" :class="deltaClass(t.raw ?? t.value, t.prev, t.badIfUp)">{{ delta(t.raw ?? t.value, t.prev) }}</span>
                    <span v-else>как в прошлый период</span>
                    <span style="opacity: .7"> · было {{ t.prev }}{{ t.raw != null ? ' мс' : '' }}</span>
                </div>
            </div>
        </div>

        <div class="card" style="margin-bottom: 14px">
            <div class="thead" style="padding: 0 0 8px"><span>Ход по времени{{ period === 'day' ? ' (по часам)' : ' (по дням)' }}</span></div>
            <div v-if="timeline.length" style="display: flex; gap: 3px; align-items: flex-end; height: 90px">
                <div v-for="t in timeline" :key="t.b" :title="`${t.b}: действий ${t.n}, сотрудников ${t.users}`" style="flex: 1; display: flex; flex-direction: column; justify-content: flex-end; align-items: center; min-width: 0">
                    <div style="width: 100%; background: var(--accent); border-radius: 3px 3px 0 0; opacity: .85" :style="{ height: Math.max(2, Math.round((t.n / maxT) * 70)) + 'px' }" />
                    <span class="row__sub" style="font-size: 10px; white-space: nowrap; overflow: hidden">{{ t.b }}</span>
                </div>
            </div>
            <div v-else class="empty">Записей за период нет.</div>
        </div>

        <div class="grid-2-1" style="grid-template-columns: minmax(0, 1.2fr) minmax(0, 1fr); gap: 14px; align-items: start">
            <div class="card card--flush">
                <div class="thead" style="grid-template-columns: minmax(0, 1fr) 70px 90px 70px 60px 60px"><span>Действие</span><span>Сколько</span><span>К прошлому</span><span>Людей</span><span>Ср. мс</span><span>Ошибок</span></div>
                <div v-for="r in byAction" :key="r.action" class="row" style="grid-template-columns: minmax(0, 1fr) 70px 90px 70px 60px 60px">
                    <span :title="r.action">{{ r.label }}</span>
                    <span class="mono">{{ r.n }}</span>
                    <span><span v-if="delta(r.n, r.prev)" class="chip" :class="deltaClass(r.n, r.prev)">{{ delta(r.n, r.prev) }}</span><span v-else class="row__sub">=</span></span>
                    <span class="row__sub">{{ r.users }}</span>
                    <span class="row__sub" :title="'самый долгий ' + r.max + ' мс'">{{ r.ms || '' }}</span>
                    <span :class="r.errors ? 'chip chip--warn' : 'row__sub'">{{ r.errors || '' }}</span>
                </div>
                <div v-if="!byAction.length" class="empty">Пока пусто — журнал заполняется по мере работы сотрудников.</div>
            </div>

            <div style="display: grid; gap: 14px">
                <div class="card card--flush">
                    <div class="thead" style="grid-template-columns: minmax(0, 1fr) 70px 70px"><span>Устройство и браузер</span><span>Действий</span><span>Людей</span></div>
                    <div v-for="c in byClient" :key="c.client" class="row" style="grid-template-columns: minmax(0, 1fr) 70px 70px"><span>{{ c.client }}</span><span class="mono">{{ c.n }}</span><span class="row__sub">{{ c.users }}</span></div>
                    <div v-if="!byClient.length" class="empty">—</div>
                </div>
                <div class="card card--flush">
                    <div class="thead" style="grid-template-columns: minmax(0, 1fr) 90px"><span>Где работают (открытия и списки)</span><span>Сколько</span></div>
                    <div v-for="f in byFolder" :key="f.folder" class="row" style="grid-template-columns: minmax(0, 1fr) 90px"><span>{{ FOLDER[f.folder] || f.folder }}</span><span class="mono">{{ f.n }}</span></div>
                    <div v-if="!byFolder.length" class="empty">—</div>
                </div>
            </div>
        </div>

        <div class="card card--flush" style="margin-top: 14px">
            <div class="thead" style="grid-template-columns: minmax(0, 1fr) 70px 70px 70px 70px 60px 60px 120px"><span>Сотрудник</span><span>Действий</span><span>Отправил</span><span>Открыл</span><span>Искал</span><span>Ср. мс</span><span>Ошибок</span><span>Последний раз</span></div>
            <div v-for="u in byUser" :key="u.user" class="row row--click" :class="{ 'row--on': user === u.user }" style="grid-template-columns: minmax(0, 1fr) 70px 70px 70px 70px 60px 60px 120px" @click="user = user === u.user ? '' : u.user">
                <span>{{ u.user }}<span v-if="u.clients > 1" class="row__sub"> · устройств {{ u.clients }}</span></span>
                <span class="mono">{{ u.n }}</span><span class="row__sub">{{ u.sent || '' }}</span><span class="row__sub">{{ u.opened || '' }}</span><span class="row__sub">{{ u.searched || '' }}</span>
                <span class="row__sub">{{ u.ms || '' }}</span>
                <span :class="u.errors ? 'chip chip--warn' : 'row__sub'">{{ u.errors || '' }}</span>
                <span class="mono row__sub">{{ when(u.last) }}</span>
            </div>
            <div v-if="!byUser.length" class="empty">Никто не работал в веб-почте за этот период.</div>
        </div>

        <div class="grid-2-1" style="grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 14px; margin-top: 14px; align-items: start">
            <div class="card card--flush">
                <div class="thead" style="grid-template-columns: 90px minmax(0, 1fr)"><span><Icon name="warn" :size="14" /> Ошибки</span><span>{{ errors.length ? errors.length + ' за период' : 'ни одной' }}</span></div>
                <div v-for="(e, i) in errors" :key="i" class="row" style="grid-template-columns: 90px minmax(0, 1fr)">
                    <span class="mono row__sub">{{ when(e.at) }}</span>
                    <span><b>{{ short(e.user) }}</b> · {{ e.action }} · {{ e.status }}<span class="row__sub" style="display: block">{{ e.error || e.detail }} <span style="opacity: .7">{{ e.client }}</span></span></span>
                </div>
                <div v-if="!errors.length" class="empty">Ошибок сервера у сотрудников не было.</div>
            </div>
            <div class="card card--flush">
                <div class="thead" style="grid-template-columns: 90px minmax(0, 1fr) 70px"><span><Icon name="clock" :size="14" /> Долго</span><span>ответы дольше 3 секунд</span><span>мс</span></div>
                <div v-for="(s, i) in slow" :key="i" class="row" style="grid-template-columns: 90px minmax(0, 1fr) 70px">
                    <span class="mono row__sub">{{ when(s.at) }}</span>
                    <span><b>{{ short(s.user) }}</b> · {{ s.action }}<span v-if="s.folder" class="row__sub"> · {{ FOLDER[s.folder] || s.folder }}</span><span v-if="s.detail" class="row__sub"> · {{ s.detail }}</span></span>
                    <span class="mono">{{ s.ms }}</span>
                </div>
                <div v-if="!slow.length" class="empty">Медленных ответов не было.</div>
            </div>
        </div>
    </AppLayout>
</template>
