<script setup>
// Настройки → Перенос: переезд ящиков со старого сервера (Kerio) — почта через imapsync, контакты и календарь по DAV.
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import { SETTINGS_TABS } from './tabs';
import Icon from '../../Components/Icon.vue';
import Toggle from '../../Components/Toggle.vue';

const page = usePage();
const props = defineProps({
    tab: String,
    ctl: Boolean,
    available: Boolean,
    source: Object,
    rows: Array,
    statuses: Object,
    defaultDomain: String,
});

const TABS = SETTINGS_TABS;

const sourceForm = useForm({ host: props.source?.host || '', port: props.source?.port || 993, ssl: props.source?.ssl ?? true, dav_url: props.source?.dav_url || '' });
const linesForm = useForm({ lines: '' });
const what = ref('all');
const WHAT = { all: 'почта, контакты и календарь', mail: 'только почта', dav: 'только контакты и календарь' };

// Живой список: пока что-то идёт, опрашиваем сервер.
const rows = ref(props.rows || []);
const busy = computed(() => rows.value.some((r) => r.status === 'running' || r.status === 'queued'));
let timer = null;
async function poll() {
    try {
        const r = await fetch('/settings/migrate/status', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
        if (r.ok) rows.value = (await r.json()).rows;
    } catch {}
    schedule();
}
function schedule() { clearTimeout(timer); if (busy.value) timer = setTimeout(poll, 4000); }
onMounted(schedule);
onBeforeUnmount(() => clearTimeout(timer));
function post(url, data = {}) { router.post(url, data, { preserveScroll: true, onSuccess: () => { rows.value = page.props.rows || rows.value; schedule(); } }); }

const csrf = () => decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
const testing = ref({});
const testResult = ref({});
async function test(row) {
    testing.value[row.id] = true; testResult.value[row.id] = null;
    try {
        const r = await fetch(`/settings/migrate/${row.id}/test`, { method: 'POST', credentials: 'same-origin', headers: { 'X-XSRF-TOKEN': csrf(), Accept: 'application/json' } });
        const d = await r.json();
        testResult.value[row.id] = { ok: !!d.ok, message: d.message };
    } catch (e) { testResult.value[row.id] = { ok: false, message: e.message }; }
    testing.value[row.id] = false;
}

const logOf = ref(null);
const logText = ref('');
async function showLog(row) {
    if (logOf.value === row.id) { logOf.value = null; return; }
    logOf.value = row.id; logText.value = 'загружаю…';
    try { logText.value = await (await fetch(`/settings/migrate/${row.id}/log`, { credentials: 'same-origin' })).text(); } catch (e) { logText.value = e.message; }
}

function fmtBytes(b) { if (!b) return ''; if (b > 1073741824) return (b / 1073741824).toFixed(1) + ' ГБ'; if (b > 1048576) return Math.round(b / 1048576) + ' МБ'; return Math.round(b / 1024) + ' КБ'; }
function summary(r) {
    const out = [];
    if (r.stats) out.push(`писем скопировано ${r.stats.transferred}${r.stats.skipped ? `, уже было ${r.stats.skipped}` : ''}${r.stats.bytes ? ', ' + fmtBytes(r.stats.bytes) : ''}${r.stats.folders ? `, папок ${r.stats.folders}` : ''}${r.stats.errors ? `, ошибок ${r.stats.errors}` : ''}`);
    if (r.dav) out.push(`контактов ${r.dav.contacts}, событий ${r.dav.events}`);
    return out.join(' · ');
}
const chip = { new: '', queued: 'chip--warn', running: 'chip--warn', done: 'chip--ok', failed: 'chip--no' };
const pendingCount = computed(() => rows.value.filter((r) => r.status !== 'running' && r.status !== 'queued').length);
</script>

<template>
    <AppLayout title="Настройки">
        <div class="tabs">
            <Link v-for="[k, l] in TABS" :key="k" :href="k === 'domains' ? '/settings' : `/settings/${k}`" class="tabs__item" :class="{ 'tabs__item--on': tab === k }">{{ l }}</Link>
        </div>

        <div v-if="!available" class="attn attn--no" style="margin-bottom: 14px"><Icon name="warn" /><span>На сервере нет imapsync (/usr/local/bin/imapsync) — перенос почты не запустится. Контакты и календарь переносятся без него.</span></div>

        <div class="grid-2-1">
            <div>
                <div class="card card--flush">
                    <div class="thead" style="grid-template-columns: minmax(0, 1.3fr) minmax(0, 1fr) 130px auto"><span>Откуда → куда</span><span>Итог</span><span>Состояние</span><span /></div>
                    <p v-if="!rows.length" class="hint" style="padding: 14px 16px; margin: 0">Пока пусто. Добавьте ящики справа — по одному на строку.</p>
                    <template v-for="r in rows" :key="r.id">
                        <div class="row" style="grid-template-columns: minmax(0, 1.3fr) minmax(0, 1fr) 130px auto">
                            <span><b>{{ r.login }}</b><span class="row__sub" style="display: block">→ {{ r.target }} · {{ WHAT[r.what] }}</span></span>
                            <span class="row__sub">
                                <template v-if="r.status === 'running' && r.progress">
                                    <span v-if="r.progress.total">писем {{ r.progress.done }} из {{ r.progress.total }}</span><span v-else>подключаюсь…</span>
                                    <span v-if="r.progress.folder" style="display: block">папка {{ r.progress.folder }}</span>
                                </template>
                                <template v-else>{{ summary(r) }}<span v-if="r.finishedAt" style="display: block">{{ r.startedAt }} — {{ r.finishedAt }}</span></template>
                                <span v-if="r.error" class="error" style="display: block; white-space: pre-line">{{ r.error }}</span>
                                <span v-if="testResult[r.id]" style="display: block" :class="testResult[r.id].ok ? 'ok' : 'error'">{{ testResult[r.id].message }}</span>
                            </span>
                            <span><span class="chip" :class="chip[r.status]">{{ r.statusTitle }}</span></span>
                            <span class="row__actions">
                                <button class="btn btn--sm btn--icon" type="button" :disabled="testing[r.id] || r.status === 'running'" title="Проверить вход на оба сервера" @click="test(r)"><Icon :name="testing[r.id] ? 'refresh' : 'check'" :size="16" /></button>
                                <button class="btn btn--sm btn--icon btn--primary" type="button" :disabled="r.status === 'running' || r.status === 'queued'" title="Запустить перенос" @click="post(`/settings/migrate/${r.id}/run`, { what })"><Icon name="play" :size="16" /></button>
                                <button v-if="r.hasLog" class="btn btn--sm btn--icon" type="button" title="Журнал imapsync" @click="showLog(r)"><Icon name="log" :size="16" /></button>
                                <button class="btn btn--sm btn--icon btn--danger" type="button" :disabled="r.status === 'running'" title="Убрать из списка" @click="confirm(`Убрать ${r.login} из списка? Уже перенесённые письма останутся.`) && router.delete(`/settings/migrate/${r.id}`, { preserveScroll: true })"><Icon name="trash" :size="16" /></button>
                            </span>
                        </div>
                        <div v-if="logOf === r.id" class="row__expand" style="grid-template-columns: 1fr"><pre class="log" style="max-height: 320px; overflow: auto; margin: 0; font-size: 12px; white-space: pre-wrap">{{ logText }}</pre></div>
                    </template>
                    <div v-if="rows.length" class="form-actions" style="padding: 12px 16px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap">
                        <select v-model="what" class="input" style="width: auto"><option v-for="(t, k) in WHAT" :key="k" :value="k">{{ t }}</option></select>
                        <button class="btn btn--primary" type="button" :disabled="busy || !pendingCount" @click="post('/settings/migrate/run-all', { what })"><Icon name="play" /> Перенести все по очереди</button>
                        <span v-if="busy" class="row__sub">идёт перенос — страница обновляется сама</span>
                    </div>
                </div>

                <div class="card card--pad" style="margin-top: 14px">
                    <div class="card__title">Как переезжать с Kerio</div>
                    <ol class="hint" style="margin: 0; padding-left: 18px; line-height: 1.6">
                        <li>Создайте у нас ящики сотрудников (раздел «Сотрудники» или импорт CSV) — перенос идёт только в существующие ящики.</li>
                        <li>Укажите старый сервер справа и добавьте ящики списком: логин в Kerio, его пароль и, если адрес отличается, наш ящик.</li>
                        <li>Нажмите галочку у строки — проверится вход на оба сервера. Затем «Перенести»: письма копируются с датами, флагами и папками (Sent Items → Отправленные, Deleted Items → Корзина и т. д.), контакты и календарь забираются по CardDAV/CalDAV в личную книгу и личный календарь сотрудника.</li>
                        <li>В день переключения MX запустите перенос ещё раз: докачается только то, что пришло с прошлого раза, дублей не будет. Пароли старого сервера после переезда удалите кнопкой «Убрать».</li>
                    </ol>
                    <p class="hint">Правила сортировки, подписи и автоответ Kerio не отдаёт по IMAP — их сотрудники настраивают заново в веб-почте. Общие и публичные папки Kerio пропускаются: их переносят один раз в тот ящик, который будет ими делиться.</p>
                </div>
            </div>

            <div>
                <form class="card card--pad" @submit.prevent="sourceForm.post('/settings/migrate/source', { preserveScroll: true })">
                    <div class="card__title">Старый сервер</div>
                    <label class="field"><span>IMAP-сервер</span><input v-model="sourceForm.host" class="input" required placeholder="mail.старый-домен.ru"></label>
                    <div class="grid-2">
                        <label class="field"><span>Порт</span><input v-model.number="sourceForm.port" class="input" type="number" min="1" max="65535"></label>
                        <div class="field"><span>&nbsp;</span><Toggle v-model="sourceForm.ssl" label="SSL (993)" /></div>
                    </div>
                    <label class="field"><span>Адрес CardDAV/CalDAV</span><input v-model="sourceForm.dav_url" class="input" placeholder="https://mail.старый-домен.ru"><span class="hint">Если пусто — тот же адрес, что IMAP. У Kerio Connect это адрес веб-почты.</span></label>
                    <div class="form-actions"><button class="btn btn--primary" type="submit" :disabled="sourceForm.processing">Сохранить</button></div>
                </form>

                <form class="card card--pad" style="margin-top: 14px" @submit.prevent="linesForm.post('/settings/migrate', { preserveScroll: true, onSuccess: () => { linesForm.reset(); rows.value = page.props.rows || rows.value; } })">
                    <div class="card__title">Добавить ящики</div>
                    <label class="field"><span>По одному на строку: логин; пароль; наш ящик</span>
                        <textarea v-model="linesForm.lines" class="input" rows="8" required :placeholder="`ivanov@старый.ru; пароль; ivanov@${defaultDomain}\npetrov@старый.ru; пароль`" style="font-family: ui-monospace, monospace; font-size: 13px"></textarea>
                        <span class="hint">Разделитель — точка с запятой или табуляция (можно вставить из Excel). Третья колонка не нужна, если адрес у нас тот же или совпадает часть до @.</span>
                    </label>
                    <div class="form-actions"><button class="btn btn--primary" type="submit" :disabled="linesForm.processing || !sourceForm.host">Добавить</button></div>
                    <p v-if="!sourceForm.host" class="hint">Сначала сохраните адрес старого сервера.</p>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
