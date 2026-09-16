<script setup>
// Импорт сотрудников из CSV: файл → сопоставление колонок → предпросмотр → создание.
import { computed, reactive, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import Icon from '../../Components/Icon.vue';
import Toggle from '../../Components/Toggle.vue';

const props = defineProps({ domains: Array });
const emit = defineEmits(['close']);

const step = ref(1);           // 1 файл · 2 колонки · 3 итог
const busy = ref(false);
const error = ref('');
const file = ref(null);
const parsed = ref(null);      // ответ preview
const mapping = reactive({});
const checked = ref([]);       // строки с оценкой
const opts = reactive({ domain: props.domains?.[0]?.value || props.domains?.[0] || '', send_passwords: true, create_units: true, quota: null });
const result = ref(null);

const csrf = () => decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
async function api(url, body, isForm = false) {
    const r = await fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'X-XSRF-TOKEN': csrf(), Accept: 'application/json', ...(isForm ? {} : { 'Content-Type': 'application/json' }) }, body: isForm ? body : JSON.stringify(body) });
    const data = await r.json().catch(() => ({}));
    if (!r.ok) throw new Error(data.message || 'Ошибка ' + r.status);
    return data;
}

async function pick(e) {
    const f = e.target.files?.[0] || e.dataTransfer?.files?.[0];
    if (!f) return;
    file.value = f; error.value = ''; busy.value = true;
    try {
        const fd = new FormData(); fd.append('file', f);
        parsed.value = await api('/mailboxes/import/preview', fd, true);
        Object.keys(mapping).forEach((k) => delete mapping[k]);
        Object.assign(mapping, parsed.value.mapping);
        step.value = 2;
    } catch (err) { error.value = err.message; } finally { busy.value = false; }
}
async function check() {
    busy.value = true; error.value = '';
    try {
        const r = await api('/mailboxes/import/check', { rows: parsed.value.rows, mapping, domain: opts.domain });
        checked.value = r.rows; step.value = 3;
    } catch (err) { error.value = err.message; } finally { busy.value = false; }
}
async function run() {
    if (!confirm(`Создать ${counts.value.create} ящиков и обновить ${counts.value.update}?`)) return;
    busy.value = true; error.value = '';
    try {
        result.value = await api('/mailboxes/import/run', { rows: parsed.value.rows, mapping, ...opts });
        step.value = 4;
    } catch (err) { error.value = err.message; } finally { busy.value = false; }
}
function finish() { emit('close'); router.reload(); }

const counts = computed(() => ({ create: checked.value.filter((r) => r.status === 'create').length, update: checked.value.filter((r) => r.status === 'update').length, skip: checked.value.filter((r) => r.status === 'skip').length }));
const sample = computed(() => (parsed.value?.rows || []).slice(0, 3));
</script>

<template>
    <div>
        <div class="modal-back" @click="emit('close')" />
        <div class="modal" role="dialog" aria-modal="true">
            <h2><Icon name="upload" /> Импорт сотрудников из CSV <span class="grow" /><button class="btn btn--sm btn--icon" type="button" @click="emit('close')"><Icon name="x" :size="18" /></button></h2>

            <div class="tabs" style="margin: 0">
                <span class="tabs__item" :class="{ 'tabs__item--on': step === 1 }">1 · Файл</span>
                <span class="tabs__item" :class="{ 'tabs__item--on': step === 2 }">2 · Колонки</span>
                <span class="tabs__item" :class="{ 'tabs__item--on': step === 3 }">3 · Проверка</span>
                <span class="tabs__item" :class="{ 'tabs__item--on': step === 4 }">4 · Готово</span>
            </div>

            <p v-if="error" class="flash flash--error" style="position: static; margin: 0">{{ error }}</p>

            <!-- 1. файл -->
            <template v-if="step === 1">
                <label class="dropzone" style="cursor: pointer; padding: 28px" @dragover.prevent @drop.prevent="pick">
                    <Icon name="file" :size="22" />
                    <span><b>Выберите или перетащите файл CSV</b><span class="row__sub" style="display: block">Выгрузка из 1С, Excel («Сохранить как CSV») или Active Directory. Кодировка Windows-1251 или UTF-8, разделитель «;» или «,» — определим сами.</span></span>
                    <input type="file" accept=".csv,.txt,text/csv" hidden @change="pick">
                </label>
                <p class="hint">Нужные колонки: ФИО (или Фамилия + Имя). Полезные: Логин или Адрес, Подразделение, Должность, Телефон, Личная почта (на неё уйдёт пароль), Пароль. Без логина он составится из фамилии и инициалов: Иванов Пётр Сергеевич → ivanovps.</p>
            </template>

            <!-- 2. колонки -->
            <template v-if="step === 2 && parsed">
                <div class="dropzone"><Icon name="file" :size="18" /><span><b>{{ file.name }}</b> · {{ parsed.total }} строк · {{ parsed.encoding }} · разделитель «{{ parsed.delimiter === 'tab' ? 'таб' : parsed.delimiter }}»</span><span class="grow" /><button class="btn btn--sm" type="button" @click="step = 1">Другой файл</button></div>
                <div class="thead" style="grid-template-columns: 220px minmax(0, 1fr) minmax(0, 1fr)"><span>Поле</span><span>Колонка в файле</span><span>Пример</span></div>
                <div v-for="(label, field) in parsed.fields" :key="field" class="row" style="grid-template-columns: 220px minmax(0, 1fr) minmax(0, 1fr); padding: 6px 0">
                    <span>{{ label }}<span v-if="field === 'name' || field === 'last_name'" class="faint"> *</span></span>
                    <select v-model="mapping[field]" class="input" style="height: 34px"><option :value="undefined">— нет —</option><option v-for="(h, i) in parsed.headers" :key="i" :value="i">{{ h || 'колонка ' + (i + 1) }}</option></select>
                    <span class="row__sub ellipsis">{{ mapping[field] !== undefined && mapping[field] !== null ? sample.map((r) => r[mapping[field]]).filter(Boolean).join(' · ') : '' }}</span>
                </div>
                <div class="grid-set" style="margin-top: 6px">
                    <label class="field"><span>Домен ящиков</span><select v-model="opts.domain" class="input"><option v-for="d in domains" :key="d.value || d" :value="d.value || d">{{ d.label || d }}</option></select></label>
                    <label class="field"><span>Размер ящика, МБ</span><input v-model.number="opts.quota" class="input" type="number" min="0" placeholder="по умолчанию из настроек"></label>
                    <Toggle v-model="opts.create_units" label="Создавать подразделения из колонки «Подразделение»" />
                    <Toggle v-model="opts.send_passwords" label="Отправить пароль на личную почту (если указана)" />
                </div>
                <div class="modal__foot"><button class="btn" type="button" @click="step = 1">Назад</button><button class="btn btn--primary" type="button" :disabled="busy || (mapping.name === undefined && mapping.last_name === undefined)" @click="check">{{ busy ? 'Проверяем…' : 'Проверить' }}</button></div>
            </template>

            <!-- 3. проверка -->
            <template v-if="step === 3">
                <div class="tiles" style="grid-template-columns: repeat(3, 1fr)">
                    <div class="tile"><div class="tile__value">{{ counts.create }}</div><div class="tile__label">Будет создано</div></div>
                    <div class="tile"><div class="tile__value" :class="{ 'tile__value--warn': counts.update }">{{ counts.update }}</div><div class="tile__label">Уже есть — обновить отдел и должность</div></div>
                    <div class="tile"><div class="tile__value" :class="{ 'tile__value--no': counts.skip }">{{ counts.skip }}</div><div class="tile__label">Пропущено</div></div>
                </div>
                <div style="max-height: 360px; overflow: auto">
                    <div class="thead" style="grid-template-columns: 40px minmax(0, 1.3fr) minmax(0, 1fr) minmax(0, 1fr) minmax(0, 1.2fr)"><span>№</span><span>Сотрудник</span><span>Адрес</span><span>Подразделение</span><span>Итог</span></div>
                    <div v-for="r in checked" :key="r.n" class="row" style="grid-template-columns: 40px minmax(0, 1.3fr) minmax(0, 1fr) minmax(0, 1fr) minmax(0, 1.2fr); padding: 7px 0">
                        <span class="faint">{{ r.n }}</span>
                        <span class="ellipsis">{{ r.name || 'пусто' }}<span v-if="r.title" class="row__sub"> · {{ r.title }}</span></span>
                        <span class="mono ellipsis">{{ r.username || '—' }}</span>
                        <span class="row__sub ellipsis">{{ r.unit || '—' }}</span>
                        <span><span class="chip" :class="r.status === 'create' ? 'chip--ok' : r.status === 'update' ? 'chip--warn' : 'chip--no'">{{ r.why }}</span></span>
                    </div>
                </div>
                <div class="modal__foot"><button class="btn" type="button" @click="step = 2">Назад</button><button class="btn btn--primary" type="button" :disabled="busy || (!counts.create && !counts.update)" @click="run">{{ busy ? 'Создаём…' : `Создать ${counts.create} и обновить ${counts.update}` }}</button></div>
            </template>

            <!-- 4. итог -->
            <template v-if="step === 4 && result">
                <div class="attn attn--ok"><Icon name="check" /><span>Создано {{ result.created }}, обновлено {{ result.updated }}, пропущено {{ result.skipped }}{{ result.mailed ? `, паролей отправлено ${result.mailed}` : '' }}.</span></div>
                <div v-if="result.errors.length" class="attn attn--no"><Icon name="warn" /><span><b>Ошибки:</b><br><span v-for="e in result.errors" :key="e" style="display: block">{{ e }}</span></span></div>
                <template v-if="Object.keys(result.passwords || {}).length">
                    <p class="hint">Пароли новых сотрудников — покажите каждому лично, второй раз они не отобразятся:</p>
                    <div style="max-height: 260px; overflow: auto"><div v-for="(p, u) in result.passwords" :key="u" class="kv"><span class="mono" style="color: inherit">{{ u }}</span><span class="mono">{{ p }}</span></div></div>
                </template>
                <div class="modal__foot"><button class="btn btn--primary" type="button" @click="finish">Готово</button></div>
            </template>
        </div>
    </div>
</template>
