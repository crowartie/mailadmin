<script setup>
// «Приложить из облака» в окне письма: выбор файлов из своей папки; уходят ссылками.
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import Icon from '../Icon.vue';
import { api } from '../../mail/api';
import { plural, size } from '../../mail/format';

const emit = defineEmits(['close', 'attach']);

const path = ref('');
const items = ref([]);
const loading = ref(false);
const error = ref('');
const busy = ref(false);
const chosen = ref(new Map());   // путь → файл (выбор сохраняется при переходе по папкам)
const filter = ref('');

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
const total = computed(() => [...chosen.value.values()].reduce((s, i) => s + (i.size || 0), 0));

async function load(p) {
    loading.value = true;
    error.value = '';
    try {
        items.value = (await api.cloudList(p)).items || [];
        path.value = p;
    } catch (e) {
        error.value = e.message;
    } finally {
        loading.value = false;
    }
}
function toggle(it) {
    const m = new Map(chosen.value);
    m.has(it.path) ? m.delete(it.path) : m.set(it.path, it);
    chosen.value = m;
}
async function attach() {
    busy.value = true;
    error.value = '';
    try {
        const r = await api.cloudAttach([...chosen.value.keys()]);
        emit('attach', r);
    } catch (e) {
        error.value = e.message;
    } finally {
        busy.value = false;
    }
}
function onKey(e) { if (e.key === 'Escape') { e.stopPropagation(); emit('close'); } }
onMounted(() => { load(''); document.addEventListener('keydown', onKey, true); });
onBeforeUnmount(() => document.removeEventListener('keydown', onKey, true));
</script>

<template>
    <div class="overlay" @mousedown.self="emit('close')">
        <div class="dialog cl-picker" role="dialog" aria-modal="true" aria-labelledby="cp-title">
            <div class="cl-picker__head">
                <h2 id="cp-title">Приложить из облака</h2>
                <span class="grow" />
                <label class="cl-search"><Icon name="search" :size="15" /><input v-model="filter" type="search" placeholder="Найти в папке" aria-label="Найти в папке"></label>
            </div>
            <nav class="cl-picker__crumbs" aria-label="Путь">
                <template v-for="(c, i) in crumbs" :key="c.path">
                    <Icon v-if="i" name="chevron" :size="12" />
                    <button type="button" class="linklike" :disabled="i === crumbs.length - 1" @click="load(c.path)">{{ c.name }}</button>
                </template>
            </nav>
            <div class="cl-picker__list">
                <div v-if="loading" class="empty">Загружаю…</div>
                <div v-else-if="!shown.length" class="empty">{{ error || 'Здесь пусто' }}</div>
                <template v-else>
                    <button v-for="it in shown" :key="it.path" type="button" class="cl-picker__row" :class="{ on: chosen.has(it.path) }" @click="it.dir ? load(it.path) : toggle(it)">
                        <span v-if="!it.dir" class="cl-picker__cb" :class="{ on: chosen.has(it.path) }"><Icon v-if="chosen.has(it.path)" name="check" :size="12" /></span>
                        <span v-else class="cl-picker__cb cl-picker__cb--none" />
                        <span class="cl-ico" :class="it.dir ? '' : 'cl-ico--video'"><Icon :name="it.dir ? 'folder' : 'file'" :size="16" /></span>
                        <span class="grow cl-ell" style="text-align: left">{{ it.name }}<span v-if="it.link" class="cl-muted"> · есть ссылка{{ it.link.has_password ? ' с паролем' : '' }}</span></span>
                        <span class="cl-muted">{{ it.dir ? '' : size(it.size) }}</span>
                        <Icon v-if="it.dir" name="chevron" :size="14" />
                    </button>
                </template>
            </div>
            <div class="cl-picker__foot">
                <div class="cl-muted" style="line-height: 1.45">
                    <template v-if="chosen.size"><b style="color: var(--text)">Выбрано {{ chosen.size }} {{ plural(chosen.size, 'файл', 'файла', 'файлов') }} · {{ size(total) }}.</b> Уйдут ссылками, письмо останется лёгким.</template>
                    <template v-else>Отметьте файлы. Они не копируются — в письмо уйдут ссылки.</template>
                    <div v-if="error && items.length" class="cl-err">{{ error }}</div>
                </div>
                <span class="grow" />
                <button class="btn" type="button" @click="emit('close')">Отмена</button>
                <button class="btn btn--primary" type="button" :disabled="!chosen.size || busy" @click="attach"><Icon name="clip" :size="15" />{{ busy ? 'Готовлю ссылки…' : 'Приложить' }}</button>
            </div>
        </div>
    </div>
</template>

<style scoped>
.cl-picker { width: 760px; max-width: calc(100vw - 32px); padding: 0; display: flex; flex-direction: column; max-height: 80vh; }
.cl-picker__head { display: flex; align-items: center; gap: 12px; padding: 16px 20px; border-bottom: 1px solid var(--border); }
.cl-picker__head h2 { margin: 0; font-size: 18px; }
.cl-picker__head .cl-search { width: 240px; height: 34px; }
.cl-picker__crumbs { display: flex; align-items: center; gap: 6px; padding: 10px 20px; font-size: 13px; color: var(--muted); flex-wrap: wrap; }
.cl-picker__crumbs .linklike:disabled { color: var(--text); font-weight: 600; cursor: default; }
.cl-picker__list { overflow-y: auto; flex: 1; min-height: 240px; border-top: 1px solid var(--border); }
.cl-picker__row { display: flex; align-items: center; gap: 12px; width: 100%; padding: 10px 20px; border: none; border-bottom: 1px solid var(--border); background: var(--surface); font: inherit; font-size: 14px; color: var(--text); cursor: pointer; }
.cl-picker__row:hover { background: var(--surface-2); }
.cl-picker__row.on { background: var(--accent-soft); }
.cl-picker__cb { width: 18px; height: 18px; flex: 0 0 18px; border-radius: 5px; border: 1.5px solid var(--border-2); display: flex; align-items: center; justify-content: center; color: #fff; }
.cl-picker__cb.on { background: var(--accent); border-color: var(--accent); }
.cl-picker__cb--none { border-color: transparent; }
.cl-picker__foot { display: flex; align-items: center; gap: 10px; padding: 14px 20px; border-top: 1px solid var(--border); background: var(--surface-2); }
</style>
