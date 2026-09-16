<script setup>
// Состояние сервера: плитки и список проверок с подсказками, что делать.
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import Icon from '../../Components/Icon.vue';
import { http } from '../../admin/http';

const props = defineProps({ tiles: Array, checks: Array, summary: Object, at: String, available: Boolean });

const tiles = ref(props.tiles);
const checks = ref(props.checks);
const summary = ref(props.summary);
const at = ref(props.at);
const busy = ref(false);
const flash = ref(null);
const onlyProblems = ref(false);
let timer = null;

const KIND = { ok: ['в порядке', 'chip--ok'], warn: ['внимание', 'chip--warn'], no: ['проблема', 'chip--no'], off: ['справка', 'chip--off'] };
const groups = computed(() => {
    const out = [];
    for (const c of checks.value) {
        if (onlyProblems.value && (c.kind === 'ok' || c.kind === 'off')) continue;
        let g = out.find((x) => x.name === c.group);
        if (!g) { g = { name: c.group, items: [] }; out.push(g); }
        g.items.push(c);
    }
    return out;
});
const headline = computed(() => summary.value.bad ? `проблем: ${summary.value.bad}` : summary.value.warn ? `требуют внимания: ${summary.value.warn}` : 'всё в порядке');

function say(text, error = false) { flash.value = { text, error }; setTimeout(() => { flash.value = null; }, 4000); }
async function refresh(fresh = false) {
    busy.value = true;
    try {
        const r = await http('GET', '/health/json' + (fresh ? '?fresh=1' : ''));
        tiles.value = r.tiles; checks.value = r.checks; summary.value = r.summary; at.value = r.at;
    } catch (e) { say(e.message, true); } finally { busy.value = false; }
}
onMounted(() => { timer = setInterval(() => refresh(false), 60000); });
onBeforeUnmount(() => clearInterval(timer));
</script>

<template>
    <AppLayout title="Состояние" :count="`${headline} · данные на ${at}`">
        <template #actions>
            <label class="toggle" style="margin: 0"><input v-model="onlyProblems" type="checkbox"><span class="toggle__track" />Только проблемы</label>
            <button class="btn" type="button" :disabled="busy" @click="refresh(true)"><Icon name="refresh" :size="16" />Обновить</button>
        </template>

        <div v-if="!available" class="card card--pad" style="border-color: var(--warn)">Обёртка mailadmin-ctl не установлена на сервере — часть данных недоступна.</div>
        <transition name="flash"><div v-if="flash" class="flash" :class="{ 'flash--error': flash.error }">{{ flash.text }}</div></transition>

        <div class="tiles tiles--6">
            <div v-for="t in tiles" :key="t.label" class="tile">
                <div class="tile__value" :class="{ 'tile__value--warn': t.kind === 'warn', 'tile__value--no': t.kind === 'no' }">{{ t.value }}</div>
                <div class="tile__label">{{ t.label }}</div>
                <div class="tile__sub">{{ t.sub }}</div>
            </div>
        </div>

        <div v-for="g in groups" :key="g.name" class="card card--flush" style="margin-bottom: 16px">
            <div class="card__title" style="padding: 14px 18px 6px">{{ g.name }}</div>
            <div v-for="c in g.items" :key="c.title" class="row hrow">
                <span class="chip" :class="KIND[c.kind]?.[1] || 'chip--off'" style="width: 108px; justify-content: center">{{ KIND[c.kind]?.[0] || c.kind }}</span>
                <span class="hrow__body">
                    <span class="hrow__title">{{ c.title }}</span>
                    <span class="hrow__text">{{ c.text }}</span>
                    <span v-if="c.hint" class="hrow__hint">{{ c.hint }}</span>
                </span>
                <Link v-if="c.href" class="btn btn--sm" :href="c.href">Открыть</Link>
            </div>
        </div>
        <p v-if="onlyProblems && !groups.length" class="hint" style="padding: 12px">Проблем нет.</p>
    </AppLayout>
</template>

<style scoped>
.hrow { display: grid; grid-template-columns: 120px minmax(0, 1fr) auto; gap: 14px; align-items: center; padding: 10px 18px; }
.hrow__body { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
.hrow__title { font-weight: 600; }
.hrow__text { color: var(--muted); }
.hrow__hint { color: var(--faint); font-size: 12.5px; }
.tiles--6 { grid-template-columns: repeat(6, minmax(0, 1fr)); }
@media (max-width: 1100px) { .tiles--6 { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
</style>
