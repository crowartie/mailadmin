<script setup>
// Очередь Postfix: фильтры, выбор нескольких, действия, раскрытие письма с заголовками и попытками.
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import Icon from '../../Components/Icon.vue';

const props = defineProps({
    rows: Array,
    available: Boolean,
    filters: Object,
});

const rows = ref(props.rows);
const state = ref(props.filters.state || 'all');
const q = ref(props.filters.q || '');
const selected = ref(new Set());
const open = ref(null);       // id раскрытой строки
const details = ref({});      // id → {headers, tries}
const busy = ref(false);
const flash = ref(null);
let timer = null;

const STATES = { deferred: ['отложено', 'warn'], active: ['отправляется', 'acc'], hold: ['на удержании', 'off'], incoming: ['входящее', 'acc'] };
const counts = computed(() => rows.value.reduce((m, r) => { m[r.state] = (m[r.state] || 0) + 1; return m; }, {}));
const totalSize = computed(() => rows.value.reduce((s, r) => s + r.size, 0));
const visible = computed(() => rows.value.filter((r) => (state.value === 'all' || r.state === state.value) && (!q.value || `${r.id} ${r.sender} ${r.recipients.map((x) => x.address).join(' ')} ${r.reason}`.toLowerCase().includes(q.value.toLowerCase()))));
const allChecked = computed(() => visible.value.length > 0 && visible.value.every((r) => selected.value.has(r.id)));

function size(b) { return b < 1024 ? b + ' Б' : b < 1048576 ? Math.round(b / 1024) + ' КБ' : (b / 1048576).toFixed(1) + ' МБ'; }
function age(s) { if (s == null) return ''; return s < 60 ? 'только что' : s < 3600 ? Math.round(s / 60) + ' мин' : s < 86400 ? Math.round(s / 3600) + ' ч' : Math.round(s / 86400) + ' дн'; }
function hm(iso) { return iso ? new Date(iso).toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' }) : ''; }
function xsrf() { const m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/); return m ? decodeURIComponent(m[1]) : ''; }
async function api(method, url, body) {
    const r = await fetch(url, { method, headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf(), 'X-Requested-With': 'XMLHttpRequest' }, body: body ? JSON.stringify(body) : undefined, credentials: 'same-origin' });
    const data = await r.json().catch(() => ({}));
    if (!r.ok) throw new Error(data.message || 'Ошибка ' + r.status);
    return data;
}
function say(text, error = false) { flash.value = { text, error }; setTimeout(() => { flash.value = null; }, 4000); }

async function refresh() {
    try { rows.value = await api('GET', '/queue/list'); } catch {}
}
function toggle(id) { const s = new Set(selected.value); s.has(id) ? s.delete(id) : s.add(id); selected.value = s; }
function toggleAll() { selected.value = allChecked.value ? new Set() : new Set(visible.value.map((r) => r.id)); }
async function expand(id) {
    if (open.value === id) { open.value = null; return; }
    open.value = id;
    if (!details.value[id]) {
        try { details.value = { ...details.value, [id]: await api('GET', `/queue/${id}`) }; } catch (e) { say(e.message, true); }
    }
}
async function act(op, ids = [...selected.value]) {
    if (op === 'delete' && !confirm(`Удалить ${ids.length === 1 ? 'письмо' : ids.length + ' писем'} из очереди? Отправитель уведомление не получит.`)) return;
    busy.value = true;
    try {
        const r = await api('POST', '/queue/action', { op, ids });
        rows.value = r.rows; selected.value = new Set(); if (op === 'delete') open.value = null;
        say(r.message);
    } catch (e) { say(e.message, true); } finally { busy.value = false; }
}
onMounted(() => { timer = setInterval(refresh, 30000); });
onBeforeUnmount(() => clearInterval(timer));
const COLS = '22px 100px 60px minmax(0, 1fr) minmax(0, 1fr) 80px 120px minmax(0, 1.2fr)';
</script>

<template>
    <AppLayout title="Очередь" :count="`${rows.length} ${rows.length % 10 === 1 && rows.length % 100 !== 11 ? 'письмо' : rows.length % 10 >= 2 && rows.length % 10 <= 4 && (rows.length % 100 < 10 || rows.length % 100 >= 20) ? 'письма' : 'писем'} · ${size(totalSize)}`" search-placeholder="Адрес или message-id…">
        <template #actions>
            <div class="seg">
                <button type="button" class="seg__item" :class="{ 'seg__item--on': state === 'all' }" @click="state = 'all'">Все</button>
                <button v-for="k in ['deferred', 'hold', 'active', 'incoming']" :key="k" type="button" class="seg__item" :class="{ 'seg__item--on': state === k }" @click="state = k">{{ STATES[k][0] }}<template v-if="counts[k]"> · {{ counts[k] }}</template></button>
            </div>
            <input v-model="q" class="input" style="width: 220px" type="search" placeholder="Адрес, id или причина">
            <button class="btn" type="button" @click="refresh"><Icon name="refresh" :size="16" />Обновить</button>
            <button class="btn btn--primary" type="button" :disabled="busy || !rows.length" @click="act('flush', [])"><Icon name="play" :size="16" />Отправить всё</button>
        </template>

        <div v-if="!available" class="card card--pad" style="border-color: var(--warn)">Обёртка mailadmin-ctl не установлена на сервере — очередь недоступна. Запустите deploy/mail01-wave2.sh.</div>
        <transition name="flash"><div v-if="flash" class="flash" :class="{ 'flash--error': flash.error }">{{ flash.text }}</div></transition>

        <div v-if="selected.size" class="bulkbar">
            <b>Выбрано {{ selected.size }}</b>
            <span style="flex: 1" />
            <button class="btn btn--sm" type="button" :disabled="busy" @click="act('retry')"><Icon name="play" :size="14" />Повторить</button>
            <button class="btn btn--sm" type="button" :disabled="busy" @click="act('hold')">На удержание</button>
            <button class="btn btn--sm" type="button" :disabled="busy" @click="act('release')">Снять с удержания</button>
            <button class="btn btn--sm btn--danger" type="button" :disabled="busy" @click="act('delete')">Удалить</button>
        </div>

        <div class="card card--flush">
            <div class="thead" :style="{ gridTemplateColumns: COLS }">
                <span class="cb" :class="{ 'cb--on': allChecked }" role="checkbox" @click="toggleAll"><Icon v-if="allChecked" name="check" :size="12" /></span>
                <span>ID</span><span>Время</span><span>От</span><span>Кому</span><span>Размер</span><span>Состояние</span><span>Причина</span>
            </div>
            <template v-for="r in visible" :key="r.id">
                <div class="row row--click" :class="{ 'row--on': open === r.id }" :style="{ gridTemplateColumns: COLS }" @click="expand(r.id)">
                    <span class="cb" :class="{ 'cb--on': selected.has(r.id) }" role="checkbox" @click.stop="toggle(r.id)"><Icon v-if="selected.has(r.id)" name="check" :size="12" /></span>
                    <span class="mono">{{ r.id }}</span>
                    <span class="mono faint" :title="r.arrival">{{ hm(r.arrival) }}</span>
                    <span class="mono ellipsis" :title="r.sender">{{ r.sender }}</span>
                    <span class="mono ellipsis" :title="r.recipients.map((x) => x.address).join(', ')">{{ r.recipients.length > 1 ? r.recipients.length + ' получателей' : (r.recipients[0]?.address || '—') }}</span>
                    <span class="faint">{{ size(r.size) }}</span>
                    <span><span class="chip" :class="`chip--${STATES[r.state]?.[1] || 'off'}`">{{ STATES[r.state]?.[0] || r.state }}</span></span>
                    <span class="row__sub ellipsis" :title="r.reason">{{ r.reason || (r.state === 'active' ? 'отправляется' : '—') }}<template v-if="r.age > 300"> · в очереди {{ age(r.age) }}</template></span>
                </div>
                <div v-if="open === r.id" class="row__expand">
                    <div>
                        <div class="group-title">Заголовки</div>
                        <pre v-if="details[r.id]" class="mono pre">{{ details[r.id].headers || 'нет данных' }}</pre>
                        <div v-else class="faint">Загрузка…</div>
                    </div>
                    <div>
                        <div class="group-title">Попытки доставки</div>
                        <pre v-if="details[r.id]" class="mono pre">{{ details[r.id].tries.length ? details[r.id].tries.join('\n') : 'попыток пока не было' }}</pre>
                        <div class="row__expand-actions">
                            <button class="btn btn--sm btn--primary" type="button" :disabled="busy" @click="act('retry', [r.id])"><Icon name="play" :size="14" />Повторить сейчас</button>
                            <button v-if="r.state !== 'hold'" class="btn btn--sm" type="button" :disabled="busy" @click="act('hold', [r.id])">На удержание</button>
                            <button v-else class="btn btn--sm" type="button" :disabled="busy" @click="act('release', [r.id])">Снять с удержания</button>
                            <a class="btn btn--sm" :href="`/queue/${r.id}/raw`">Скачать .eml</a>
                            <button class="btn btn--sm btn--danger" type="button" :disabled="busy" @click="act('delete', [r.id])">Удалить</button>
                        </div>
                    </div>
                </div>
            </template>
            <div v-if="!visible.length" class="empty">{{ rows.length ? 'Ничего не найдено' : 'Очередь пуста — все письма доставлены' }}</div>
        </div>
        <p class="hint">Обновляется каждые 30 секунд · Postfix на этом же сервере, агент не нужен. Отложенные письма Postfix повторяет сам по расписанию, «Повторить» ускоряет попытку.</p>
    </AppLayout>
</template>
