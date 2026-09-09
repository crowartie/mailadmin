<script setup>
// Настройки сервера: домены и DNS, антиспам и карантин, вложения и лимиты, сертификат, копии, администраторы, уведомления.
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import { SETTINGS_TABS } from './tabs';
import Icon from '../../Components/Icon.vue';
import Toggle from '../../Components/Toggle.vue';

const props = defineProps({
    tab: String,
    ctl: Boolean,
    // домены
    mailHost: String,
    domains: Array,
    reports: Object,
    reportsMailbox: String,
    mtasts: Object,
    // антиспам
    spam: Object,
    wblist: Array,
    quarantine: Array,
    quarantinePolicy: Object,
    senders: Object,
    senderRules: Array,
    senderPending: Array,
    // лимиты
    limits: Object,
    sizeLimitMb: Number,
    fail2ban: Object,
    throttle: Object,
    // сертификат
    cert: Object,
    lastAttempt: String,
    names: Array,
    // копии
    backup: Object,
    dirCheck: Object,
    running: Boolean,
    history: Array,
    files: Array,
    employees: Array,
    // администраторы
    admins: Array,
    roles: Object,
    // уведомления
    alerts: Object,
    channels: Object,
    // облако
    cloud: Object,
    cloudStatus: Object,
});

const TABS = SETTINGS_TABS;

function post(url, data = {}, opts = {}) { router.post(url, data, { preserveScroll: true, ...opts }); }
function del(url) { router.delete(url, { preserveScroll: true }); }
function when(iso) { if (!iso) return '—'; const d = new Date(iso); return d.toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' }) + ' ' + d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' }); }
function date(s) { if (!s) return '—'; return new Date(s).toLocaleDateString('ru-RU', { day: 'numeric', month: 'long', year: 'numeric' }); }
function mb(b) { if (!b && b !== 0) return '—'; if (b >= 1073741824) return (b / 1073741824).toFixed(1) + ' ГБ'; if (b >= 1048576) return Math.round(b / 1048576) + ' МБ'; return Math.max(1, Math.round(b / 1024)) + ' КБ'; }
function dur(s) { if (!s) return '—'; return s < 90 ? s + ' с' : Math.round(s / 60) + ' мин'; }
function copy(text) { navigator.clipboard?.writeText(text); }

// ── Домены ─────────────────────────────────────────────────────────────
const openDomain = ref(null);
const domainForm = reactive({});
function editDomain(d) {
    openDomain.value = openDomain.value === d.domain ? null : d.domain;
    Object.assign(domainForm, { description: d.description || '', mailboxLimit: d.mailboxLimit, aliasLimit: d.aliasLimit, maxQuotaMb: d.maxQuotaMb, active: d.active });
}
const dnsBad = (d) => (d.dns || []).filter((r) => r.kind === 'no').length;
const dnsWarn = (d) => (d.dns || []).filter((r) => r.kind === 'warn').length;
const rechecking = ref(false);
function recheck() { rechecking.value = true; post('/settings/dns/recheck', {}, { onFinish: () => (rechecking.value = false) }); }

// ── Антиспам ───────────────────────────────────────────────────────────
const spamForm = useForm({ tag2: props.spam?.tag2 ?? 6.2, kill: props.spam?.kill ?? 6.9, cutoff: props.spam?.cutoff ?? 10, virus: props.spam?.virus ?? false, greylist: props.spam?.greylist ?? false });
const wbForm = useForm({ pattern: '', wb: 'W', note: '' });
const qPolicy = useForm({ ...(props.quarantinePolicy || {}) });
const sendersForm = useForm({ ham_global: props.senders?.ham_global ?? true, spam_votes: props.senders?.spam_votes ?? 2, lists_votes: props.senders?.lists_votes ?? 2 });
const KIND = { spam: 'спам', lists: 'рассылка', ham: 'не спам' };
const qSearch = ref('');
const quarantineRows = computed(() => (props.quarantine || []).filter((q) => !qSearch.value || `${q.from} ${q.to} ${q.subject}`.toLowerCase().includes(qSearch.value.toLowerCase())));
const pct = (v) => Math.min(100, Math.max(0, (v / 20) * 100));

// ── Лимиты ─────────────────────────────────────────────────────────────
const limitsForm = useForm({ sizeLimitMb: props.sizeLimitMb ?? 15, ...(props.limits || {}), ...(props.fail2ban || {}), out_max_msgs: props.throttle?.max_msgs ?? 0, out_period_min: props.throttle?.period_min ?? 60 });

// ── Копии ──────────────────────────────────────────────────────────────
const backupForm = useForm({ ...(props.backup || {}) });
const restore = reactive({ file: '', user: '' });
const lastOk = computed(() => (props.history || []).find((h) => h.status === 'ok'));
const nextRun = computed(() => {
    if (!props.backup?.time) return '';
    const [h, m] = props.backup.time.split(':').map(Number);
    const d = new Date(); d.setHours(h, m, 0, 0); if (d < new Date()) d.setDate(d.getDate() + 1);
    return d.toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' }) + ' в ' + props.backup.time;
});

// ── Администраторы ─────────────────────────────────────────────────────
const adminForm = useForm({ email: '', name: '', role: 'admin', imap: true, password: '' });
const editingAdmin = ref(null);
const adminEdit = reactive({ name: '', role: 'admin', password: '' });
function pickEmployee(e) {
    const emp = (props.employees || []).find((x) => x.username === e.target.value);
    if (emp) { adminForm.email = emp.username; if (!adminForm.name) adminForm.name = emp.name; adminForm.imap = true; }
}
function openAdmin(a) { editingAdmin.value = editingAdmin.value === a.id ? null : a.id; Object.assign(adminEdit, { name: a.name, role: a.role, password: '' }); }
function saveAdmin(a) { router.put(`/settings/admins/${a.id}`, { ...adminEdit }, { preserveScroll: true, onSuccess: () => (editingAdmin.value = null) }); }
const ROLE_HINT = { owner: 'всё, включая администраторов и резервные копии', admin: 'всё, кроме назначения администраторов', viewer: 'смотрит обзор и журналы, ничего не меняет', operator: 'заводит ящики и псевдонимы, правит контакты компании' };

// ── Облако ─────────────────────────────────────────────────────────────
const cloudUrl = ref(props.cloud?.url || '');
const cloudLogin = ref('');          // ссылка Login Flow, пока ждём подтверждения
const cloudWaiting = ref(false);
const cloudError = ref('');
const manual = ref(false);
const cloudForm = useForm({ enabled: !!props.cloud?.enabled, folder: props.cloud?.folder || 'Почта', threshold_mb: props.cloud?.threshold_mb ?? 10, expire_days: props.cloud?.expire_days ?? 30, link_password: props.cloud?.link_password || '' });
const manualForm = useForm({ url: props.cloud?.url || '', login: '', app_password: '' });
let cloudTimer = null;
const csrf = () => decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
async function cloudConnect() {
    cloudError.value = ''; cloudWaiting.value = true;
    try {
        const r = await fetch('/settings/cloud/start', { method: 'POST', credentials: 'same-origin', headers: { 'X-XSRF-TOKEN': csrf(), Accept: 'application/json', 'Content-Type': 'application/json' }, body: JSON.stringify({ url: cloudUrl.value }) });
        const d = await r.json();
        if (!r.ok) throw new Error(d.message || 'Ошибка ' + r.status);
        cloudLogin.value = d.login;
        window.open(d.login, '_blank', 'noopener');
        cloudTimer = setInterval(cloudPoll, 3000);
    } catch (e) { cloudError.value = e.message; cloudWaiting.value = false; }
}
async function cloudPoll() {
    try {
        const r = await fetch('/settings/cloud/poll', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
        const d = await r.json();
        if (!r.ok) throw new Error(d.message || 'Ошибка ' + r.status);
        if (d.done) { clearInterval(cloudTimer); cloudWaiting.value = false; cloudLogin.value = ''; router.reload({ onSuccess: () => { if (d.folderError) cloudError.value = 'Подключено, но папка не создана: ' + d.folderError; } }); }
    } catch (e) { clearInterval(cloudTimer); cloudWaiting.value = false; cloudError.value = e.message; }
}
function cloudCancel() { clearInterval(cloudTimer); cloudWaiting.value = false; cloudLogin.value = ''; }

// ── Уведомления ────────────────────────────────────────────────────────
const alertsForm = useForm({ ...(props.alerts || {}), ...(props.channels || {}) });
const testing = ref(false);
function testAlerts() { testing.value = true; post('/settings/alerts/test', {}, { onFinish: () => (testing.value = false) }); }
</script>

<template>
    <AppLayout title="Настройки">
        <template #actions>
            <span v-if="!ctl" class="chip chip--no">служебная обёртка mailadmin-ctl недоступна — изменения на сервере не применятся</span>
        </template>

        <div class="tabs">
            <Link v-for="[k, l] in TABS" :key="k" :href="k === 'domains' ? '/settings' : `/settings/${k}`" class="tabs__item" :class="{ 'tabs__item--on': tab === k }">{{ l }}<template v-if="k === 'spam' && quarantine && quarantine.length"> · {{ quarantine.length }}</template></Link>
        </div>

        <!-- ── Домены и DNS ─────────────────────────────────────────── -->
        <template v-if="tab === 'domains'">
            <div class="toolbar" style="justify-content: space-between">
                <span class="hint" style="margin: 0">Проверяем публичный DNS так же, как это делают чужие серверы. Имя сервера: <b class="mono">{{ mailHost }}</b></span>
                <button class="btn" type="button" :disabled="rechecking" @click="recheck"><Icon name="repeat" /> {{ rechecking ? 'Проверяем…' : 'Перепроверить DNS' }}</button>
            </div>

            <div class="grid-set" style="margin-bottom: 16px">
                <div class="card card--pad">
                    <div class="card__title" style="display: flex; align-items: center">Отчёты DMARC за 30 дней <span class="grow" /><button class="btn btn--sm" type="button" @click="post('/settings/reports/fetch')"><Icon name="repeat" :size="14" /> Забрать сейчас</button></div>
                    <template v-if="reports && reports.total">
                        <div class="tiles" style="grid-template-columns: repeat(3, 1fr); margin-bottom: 12px">
                            <div class="tile"><div class="tile__value">{{ reports.total }}</div><div class="tile__label">писем от нашего имени</div><div class="tile__sub">по данным {{ reports.reports }} отчётов</div></div>
                            <div class="tile"><div class="tile__value" :class="{ 'tile__value--warn': reports.passPct < 95 }">{{ reports.passPct }}%</div><div class="tile__label">прошли DKIM или SPF</div><div class="tile__sub">это наши настоящие письма</div></div>
                            <div class="tile"><div class="tile__value" :class="{ 'tile__value--no': reports.fail > 0 }">{{ reports.fail }}</div><div class="tile__label">не прошли</div><div class="tile__sub">подделки или забытые рассылки</div></div>
                        </div>
                        <template v-if="reports.failing.length">
                            <div class="thead" style="grid-template-columns: 150px minmax(0, 1fr) 70px 90px"><span>IP</span><span>Кто сообщил / PTR</span><span>Писем</span><span>Решение</span></div>
                            <div v-for="f in reports.failing" :key="f.ip" class="row" style="grid-template-columns: 150px minmax(0, 1fr) 70px 90px; padding: 6px 0"><span class="mono">{{ f.ip }}</span><span class="row__sub ellipsis">{{ f.org }}{{ f.ptr ? ' · ' + f.ptr : '' }}</span><span>{{ f.count }}</span><span class="row__sub">{{ f.disposition || '—' }}</span></div>
                            <p class="hint">Не прошедшие — чужие серверы, которые шлют письма от нашего домена: обычно спамеры (пусть блокируются), реже забытый сервис (CRM, сайт), который надо внести в SPF.</p>
                        </template>
                        <p v-else class="hint">Все письма от нашего имени подтверждены — подделок не замечено.</p>
                        <p class="hint" style="margin: 4px 0 0">Присылают: {{ reports.orgs.map((o) => o.org + ' (' + o.reports + ')').join(', ') }}. Последний отчёт {{ reports.lastReport ? date(reports.lastReport) : '—' }}.</p>
                    </template>
                    <p v-else class="hint" style="margin-top: 0">Отчётов ещё нет. Крупные почтовики (Mail.ru, Google, Яндекс) присылают их раз в сутки на адрес из DMARC-записи (rua=mailto:{{ reportsMailbox }}). Они забираются автоматически раз в час из ящика postmaster и складываются в папку «Reports».</p>
                    <div class="card__title" style="margin-top: 14px">TLS-отчёты (TLS-RPT)</div>
                    <p v-if="reports && reports.tls.reports" class="hint" style="margin: 0">За 30 дней: {{ reports.tls.ok }} соединений с TLS удачно, {{ reports.tls.fail }} сбоев.<span v-for="(f, i) in reports.tls.failures" :key="i" style="display: block">{{ f.type }} · {{ f.ip }} → {{ f.mx }} · {{ f.count }} ({{ f.org }})</span></p>
                    <p v-else class="hint" style="margin: 0">Пока нет: добавьте TXT-запись <span class="mono">_smtp._tls</span> со значением <span class="mono">v=TLSRPTv1; rua=mailto:{{ reportsMailbox }}</span> — чужие серверы начнут сообщать, если не смогли установить TLS с нами.</p>
                </div>
                <div class="card card--pad">
                    <div class="card__title">MTA-STS — защита входящей почты от подмены</div>
                    <p class="hint" style="margin-top: 0">Отправители (Google, Яндекс, Mail.ru) будут требовать настоящий сертификат нашего сервера и откажутся отдать письмо перехватчику. Три шага:</p>
                    <ol class="hint" style="margin: 0 0 10px; padding-left: 18px">
                        <li>A-запись <span class="mono">{{ mtasts.host }}</span> → внешний IP сервера (как у mail).</li>
                        <li>Кнопка ниже: имя добавится в сертификат и в веб-сервер, политика появится по адресу <span class="mono">https://{{ mtasts.host }}/.well-known/mta-sts.txt</span>.</li>
                        <li>TXT-запись <span class="mono">_mta-sts</span> = <span class="mono">v=STSv1; id={{ mtasts.id || 'ГГГГММДДччмм' }}</span>. При смене режима меняйте id.</li>
                    </ol>
                    <div class="attn" :class="mtasts.enabled ? 'attn--ok' : ''" style="margin-bottom: 10px"><Icon :name="mtasts.enabled ? 'shield' : 'clock'" /><span>{{ mtasts.enabled ? `Включён, режим ${mtasts.mode}${mtasts.inCert ? ', имя в сертификате есть' : ', имени в сертификате ещё нет'}` : 'Не включён' }}</span></div>
                    <div class="form-actions">
                        <button v-if="!mtasts.enabled || !mtasts.inCert" class="btn btn--primary" type="button" @click="post('/settings/mtasts/enable', { mode: 'testing' })">Включить (режим testing)</button>
                        <button v-if="mtasts.enabled && mtasts.mode === 'testing'" class="btn" type="button" @click="confirm('Перевести в enforce? Отправители будут отказываться доставлять письма, если сертификат или MX не совпадут. Включайте после недели без ошибок в TLS-отчётах.') && post('/settings/mtasts/mode', { mode: 'enforce' })">Перевести в enforce</button>
                        <button v-if="mtasts.enabled" class="btn" type="button" @click="post('/settings/mtasts/mode', { mode: 'off' })">Выключить</button>
                    </div>
                </div>
            </div>

            <div v-for="d in domains" :key="d.domain" class="card card--flush" style="margin-bottom: 16px">
                <div class="row row--click" style="grid-template-columns: minmax(0, 1fr) auto auto auto; padding: 14px 18px" @click="editDomain(d)">
                    <span><b style="font-size: 15px">{{ d.domain }}</b><span v-if="d.description" class="row__sub"> — {{ d.description }}</span><span v-if="!d.active" class="tag" style="margin-left: 8px">выключен</span></span>
                    <span class="row__sub">{{ d.mailboxes }} ящиков{{ d.mailboxLimit > 0 ? ` из ${d.mailboxLimit}` : '' }} · {{ d.aliases }} псевдонимов</span>
                    <span class="chip" :class="dnsBad(d) ? 'chip--no' : dnsWarn(d) ? 'chip--warn' : 'chip--ok'">{{ dnsBad(d) ? `DNS: ${dnsBad(d)} ошиб.` : dnsWarn(d) ? `DNS: ${dnsWarn(d)} замеч.` : 'DNS в порядке' }}</span>
                    <Icon :name="openDomain === d.domain ? 'up' : 'down'" />
                </div>

                <div v-if="openDomain === d.domain" style="border-top: 1px solid var(--border); padding: 16px 18px; display: grid; grid-template-columns: 2fr minmax(0, 1fr); gap: 20px">
                    <div>
                        <div class="card__title">Записи DNS</div>
                        <div class="thead" style="grid-template-columns: 150px minmax(0, 1fr) 18px minmax(0, 1fr)"><span>Запись</span><span>Должно быть</span><span /><span>Сейчас</span></div>
                        <div v-for="r in d.dns" :key="r.name" class="row" style="grid-template-columns: 150px minmax(0, 1fr) 18px minmax(0, 1fr); padding: 8px 0; font-size: 12.5px">
                            <span><b>{{ r.name }}</b></span>
                            <span class="mono ellipsis" :title="r.expected">{{ r.expected }}</span>
                            <span class="dot" :class="`dot--${r.kind}`" style="margin-top: 4px" />
                            <span><span class="mono ellipsis" style="display: block" :title="r.actual">{{ r.actual }}</span><span class="row__sub">{{ r.note }}</span></span>
                        </div>
                        <div v-if="!d.dns || !d.dns.length" class="empty">Не удалось опросить DNS — сервер без доступа к резолверу</div>

                        <div v-if="d.dkim" class="card card--pad" style="margin-top: 14px">
                            <div class="card__title" style="display: flex; justify-content: space-between; align-items: center">
                                <span>Ключ DKIM <span class="row__sub">RSA {{ d.dkim.bits }} бит · с {{ date(d.dkim.since) }}</span></span>
                                <span style="display: flex; gap: 6px"><button class="btn btn--sm" type="button" @click="copy(d.dkim.txt)"><Icon name="copy" /> Скопировать TXT</button><button class="btn btn--sm btn--danger" type="button" @click="confirm('Сменить ключ DKIM? Письма будут подписываться новым ключом — до обновления TXT-записи в DNS чужие серверы не смогут проверить подпись.') && post('/settings/dkim/rotate', { domain: d.domain })">Сменить ключ</button></span>
                            </div>
                            <div class="kv"><span>Имя записи</span><span class="mono">{{ d.dkim.host }}</span></div>
                            <div class="kv" style="align-items: flex-start"><span>Значение TXT</span><span class="mono" style="font-size: 11px; overflow-wrap: anywhere; max-width: 520px">{{ d.dkim.txt }}</span></div>
                        </div>
                    </div>
                    <form @submit.prevent="post(`/settings/domains/${d.domain}`, domainForm)">
                        <div class="card__title">Домен</div>
                        <label class="field"><span>Описание</span><input v-model="domainForm.description" class="input" placeholder="Основной домен компании"></label>
                        <label class="field"><span>Ящиков не больше</span><input v-model.number="domainForm.mailboxLimit" class="input" type="number" min="-1"><span class="hint">0 — без ограничения, −1 — запретить создание</span></label>
                        <label class="field"><span>Псевдонимов не больше</span><input v-model.number="domainForm.aliasLimit" class="input" type="number" min="-1"></label>
                        <label class="field"><span>Максимальный размер ящика, МБ</span><input v-model.number="domainForm.maxQuotaMb" class="input" type="number" min="0"><span class="hint">0 — не ограничивать при создании ящика</span></label>
                        <Toggle v-model="domainForm.active" label="Домен принимает почту" />
                        <div class="form-actions" style="margin-top: 14px"><button class="btn btn--primary" type="submit">Сохранить</button><Link href="/domains" class="btn">Все домены</Link></div>
                    </form>
                </div>
            </div>
        </template>

        <!-- ── Антиспам и карантин ──────────────────────────────────── -->
        <template v-if="tab === 'spam'">
            <div class="grid-set">
                <form class="card card--pad" @submit.prevent="spamForm.post('/settings/spam', { preserveScroll: true })">
                    <div class="card__title">Пороги SpamAssassin</div>
                    <div class="slider" style="background: linear-gradient(90deg, var(--ok) 0 var(--p1), var(--warn) var(--p1) var(--p2), var(--no) var(--p2) 100%)" :style="{ '--p1': pct(spamForm.tag2) + '%', '--p2': pct(spamForm.kill) + '%' }">
                        <span class="slider__knob" :style="{ left: pct(spamForm.tag2) + '%' }"><span class="slider__label">помечать {{ spamForm.tag2 }}</span></span>
                        <span class="slider__knob" :style="{ left: pct(spamForm.kill) + '%' }"><span class="slider__label" style="top: 24px">в карантин {{ spamForm.kill }}</span></span>
                    </div>
                    <div class="slider__scale" style="margin-top: 22px"><span>0</span><span>5</span><span>10</span><span>15</span><span>20 баллов</span></div>
                    <div class="toggles--3" style="margin-top: 14px">
                        <label class="field"><span>Помечать «***SPAM***» от</span><input v-model.number="spamForm.tag2" class="input" type="number" step="0.1" min="1" max="20"></label>
                        <label class="field"><span>В карантин от</span><input v-model.number="spamForm.kill" class="input" type="number" step="0.1" min="1" max="30"></label>
                        <label class="field"><span>Не уведомлять отправителя от</span><input v-model.number="spamForm.cutoff" class="input" type="number" step="0.5" min="1" max="50"></label>
                    </div>
                    <p class="hint">Ниже первого порога письмо идёт во «Входящие», между порогами — с пометкой в теме, выше второго — в карантин (получатель видит его в папке «Спам» веб-почты и в сводке).</p>
                    <div class="toggles--3" style="grid-template-columns: 1fr 1fr">
                        <Toggle v-model="spamForm.greylist" label="Серый список для незнакомых серверов (iRedAPD)" />
                        <Toggle v-model="spamForm.virus" label="Антивирус ClamAV (нужна память ≥ 1,5 ГБ)" />
                    </div>
                    <div style="margin-top: 14px"><button class="btn btn--primary" type="submit" :disabled="spamForm.processing">Применить</button></div>
                </form>

                <div style="display: flex; flex-direction: column; gap: 16px">
                    <div class="card card--pad">
                        <div class="card__title">Белый и чёрный списки</div>
                        <form style="display: grid; grid-template-columns: minmax(0, 1fr) 150px; gap: 8px; margin-bottom: 10px" @submit.prevent="wbForm.post('/settings/wblist', { preserveScroll: true, onSuccess: () => wbForm.reset() })">
                            <input v-model="wbForm.pattern" class="input" placeholder="адрес, @домен или *@домен" required>
                            <select v-model="wbForm.wb" class="input"><option value="W">пропускать</option><option value="B">блокировать</option></select>
                            <input v-model="wbForm.note" class="input" placeholder="зачем (необязательно)">
                            <button class="btn btn--primary" type="submit">Добавить</button>
                        </form>
                        <div v-for="w in wblist" :key="w.id" class="row" style="grid-template-columns: 18px minmax(0, 1fr) auto auto; padding: 7px 0">
                            <span class="dot" :class="w.wb === 'W' ? 'dot--ok' : 'dot--no'" />
                            <span class="mono ellipsis">{{ w.email }}<span v-if="w.note" class="row__sub"> — {{ w.note }}</span></span>
                            <span class="row__sub">{{ w.wb === 'W' ? 'без проверки' : 'отклоняется' }}</span>
                            <button class="btn btn--sm" type="button" @click="del(`/settings/wblist/${w.id}`)">Убрать</button>
                        </div>
                        <div v-if="!wblist.length" class="empty-inline">Списки пусты — все письма проходят проверку на общих основаниях</div>
                    </div>
                    <form class="card card--pad" @submit.prevent="qPolicy.post('/settings/quarantine/policy', { preserveScroll: true })">
                        <div class="card__title">Карантин</div>
                        <div class="field__row" style="flex-wrap: wrap">
                            <Toggle v-model="qPolicy.digest" label="Присылать сотрудникам сводку в" /><input v-model="qPolicy.digest_time" class="input" type="time" style="width: 110px; height: 34px">
                            <span>· хранить</span><input v-model.number="qPolicy.keep_days" class="input" type="number" min="1" max="365" style="width: 70px; height: 34px"><span>дн</span>
                            <button class="btn btn--primary" type="submit" :disabled="qPolicy.processing">Сохранить</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card card--flush" style="margin-top: 16px">
                <div class="toolbar" style="padding: 12px 18px; border-bottom: 1px solid var(--border)">
                    <b>В карантине: {{ quarantine.length }}</b>
                    <input v-model="qSearch" class="input" placeholder="отправитель, получатель, тема…" style="width: 320px; height: 34px; margin-left: auto">
                </div>
                <div class="thead" style="grid-template-columns: 110px minmax(0, 1.4fr) minmax(0, 1fr) minmax(0, 1.6fr) 60px auto"><span>Когда</span><span>От кого</span><span>Кому</span><span>Тема</span><span>Балл</span><span /></div>
                <div v-for="q in quarantineRows" :key="q.id + q.to" class="row" style="grid-template-columns: 110px minmax(0, 1.4fr) minmax(0, 1fr) minmax(0, 1.6fr) 60px auto">
                    <span class="row__sub">{{ when(q.time) }}</span>
                    <span class="ellipsis" :title="q.from">{{ q.from }}</span>
                    <span class="ellipsis row__sub">{{ q.to }}</span>
                    <span class="ellipsis" :title="q.subject">{{ q.subject }}<span class="row__sub"> · {{ q.kind }} · {{ mb(q.size) }}</span></span>
                    <span class="mono" :style="{ color: q.score >= 10 ? 'var(--no)' : 'var(--warn)' }">{{ q.score ?? '—' }}</span>
                    <span style="display: flex; gap: 6px">
                        <button v-if="!q.released" class="btn btn--sm" type="button" @click="post(`/settings/quarantine/${q.id}/release`, { secret: q.secret })">Доставить</button>
                        <span v-else class="tag">доставлено</span>
                        <button class="btn btn--sm" type="button" @click="del(`/settings/quarantine/${q.id}`)">Удалить</button>
                    </span>
                </div>
                <div v-if="!quarantine.length" class="empty">Карантин пуст — за последние {{ quarantinePolicy.keep_days }} дней ничего не задержано</div>
            </div>

            <div class="grid-set" style="margin-top: 16px">
                <div class="card card--pad">
                    <div class="card__title">Решения сотрудников</div>
                    <p class="hint" style="margin-top: 0">Сотрудник в веб-почте отмечает письмо как спам, рассылку или «не спам» — по адресу или по всему домену. Это сразу становится его личным правилом, а старые письма раскладываются по папкам. Когда одинаково отметят несколько человек, правило становится общим для всех ящиков.</p>
                    <form @submit.prevent="sendersForm.post('/settings/senders', { preserveScroll: true })">
                        <Toggle v-model="sendersForm.ham_global" label="«Не спам» сразу добавляет отправителя в общий белый список" />
                        <div class="grid-2" style="margin-top: 10px">
                            <label class="field"><span>«Спам» становится общим после</span><input v-model.number="sendersForm.spam_votes" class="input" type="number" min="1" max="50"><span class="hint">сотрудников; 1 — сразу</span></label>
                            <label class="field"><span>«Рассылка» становится общей после</span><input v-model.number="sendersForm.lists_votes" class="input" type="number" min="1" max="50"><span class="hint">сотрудников; 1 — сразу</span></label>
                        </div>
                        <div class="form-actions"><button class="btn btn--primary" type="submit" :disabled="sendersForm.processing">Сохранить</button></div>
                    </form>
                    <div class="card__title" style="margin-top: 18px">Общие правила <span class="chip">{{ senderRules.length }}</span></div>
                    <div v-for="r in senderRules" :key="r.id" class="row" style="grid-template-columns: minmax(0, 1fr) 90px auto; padding: 7px 0">
                        <span><b>{{ r.match === 'domain' ? '@' + r.value : r.value }}</b><span class="row__sub" style="display: block">{{ r.source === 'admin' ? 'поставил ' + (r.by || 'администратор') : 'голосов ' + r.votes }} · {{ r.at }}</span></span>
                        <span><span class="chip" :class="r.kind === 'spam' ? 'chip--no' : 'chip--warn'">{{ KIND[r.kind] }}</span></span>
                        <button class="btn btn--sm" type="button" @click="del(`/settings/senders/${r.id}`)">Снять</button>
                    </div>
                    <div v-if="!senderRules.length" class="empty-inline">Общих правил пока нет</div>
                </div>
                <div class="card card--pad">
                    <div class="card__title">Личные отметки, ещё не общие <span class="chip">{{ senderPending.length }}</span></div>
                    <p class="hint" style="margin-top: 0">Что сотрудники отметили у себя. Любое можно сделать общим сразу, не дожидаясь голосов.</p>
                    <div v-for="p in senderPending" :key="p.kind + p.value" class="row" style="grid-template-columns: minmax(0, 1fr) 90px auto; padding: 7px 0">
                        <span><b>{{ p.match === 'domain' ? '@' + p.value : p.value }}</b><span class="row__sub" style="display: block" :title="p.users">{{ p.votes }} {{ p.votes === 1 ? 'сотрудник' : (p.votes < 5 ? 'сотрудника' : 'сотрудников') }}: {{ p.users }}</span></span>
                        <span><span class="chip" :class="p.kind === 'spam' ? 'chip--no' : 'chip--warn'">{{ KIND[p.kind] }}</span></span>
                        <button class="btn btn--sm" type="button" @click="post('/settings/senders/promote', { kind: p.kind, match: p.match, value: p.value })">Сделать общим</button>
                    </div>
                    <div v-if="!senderPending.length" class="empty-inline">Пока никто ничего не отмечал</div>
                </div>
            </div>
        </template>

        <!-- ── Вложения и лимиты ────────────────────────────────────── -->
        <template v-if="tab === 'limits'">
            <form class="grid-set" @submit.prevent="limitsForm.post('/settings/limits', { preserveScroll: true })">
                <div class="card card--pad">
                    <div class="card__title">Письма</div>
                    <label class="field"><span>Письмо целиком не больше, МБ</span><input v-model.number="limitsForm.sizeLimitMb" class="input" type="number" min="1" max="1024"><span class="hint">Postfix message_size_limit. Вложение в base64 тяжелее файла на треть: лимит 15 МБ пропускает файл около 10 МБ.</span></label>
                    <label class="field"><span>Получателей в одном письме не больше</span><input v-model.number="limitsForm.max_recipients" class="input" type="number" min="1" max="5000"></label>
                    <label class="field"><span>Размер нового ящика по умолчанию, МБ</span><input v-model.number="limitsForm.default_quota_mb" class="input" type="number" min="0"><span class="hint">Подставляется при создании ящика; 0 — без ограничения.</span></label>
                    <label class="field"><span>Опасные расширения вложений</span><input v-model="limitsForm.blocked_ext" class="input" placeholder="exe, scr, bat, cmd, js"><span class="hint">Такие вложения веб-почта не даёт открыть напрямую, только сохранить. Проверку на уровне Amavis iRedMail держит выключенной.</span></label>
                </div>
                <div class="card card--pad">
                    <div class="card__title">Защита от перебора паролей</div>
                    <div class="field__row" style="flex-wrap: wrap">
                        <input v-model.number="limitsForm.maxretry" class="input" type="number" min="2" max="100" style="width: 70px; height: 34px"><span>неверных паролей за</span>
                        <input v-model.number="limitsForm.findtime" class="input" type="number" min="1" max="1440" style="width: 70px; height: 34px"><span>минут — блок на</span>
                        <input v-model.number="limitsForm.bantime_hours" class="input" type="number" min="1" max="8760" style="width: 70px; height: 34px"><span>ч</span>
                    </div>
                    <p class="hint">Действует на IMAP, SMTP, веб-почту и админку. Свои адреса добавляйте в белый список в разделе «Безопасность → Блокировки».</p>
                    <div class="card__title" style="margin-top: 18px">Лимит исходящих на ящик</div>
                    <div class="field__row" style="flex-wrap: wrap">
                        <span>Не больше</span><input v-model.number="limitsForm.out_max_msgs" class="input" type="number" min="0" max="100000" style="width: 80px; height: 34px"><span>писем за</span>
                        <input v-model.number="limitsForm.out_period_min" class="input" type="number" min="1" max="1440" style="width: 70px; height: 34px"><span>минут с одного ящика</span>
                    </div>
                    <p class="hint">Одно правило на всех сотрудников (iRedAPD). Если ящик украдут, спамер упрётся в лимит, а не разошлёт тысячи писем и не загонит сервер в чёрные списки. Обычному человеку хватает 100–200 в час. 0 — без ограничения.</p>
                    <div class="form-actions" style="margin-top: 20px"><button class="btn btn--primary" type="submit" :disabled="limitsForm.processing">Сохранить</button></div>
                </div>
            </form>
        </template>

        <!-- ── Сертификат ──────────────────────────────────────────── -->
        <template v-if="tab === 'cert'">
            <div class="grid-set">
                <div class="card card--pad">
                    <div class="card__title">Сертификат сервера</div>
                    <template v-if="cert">
                        <div class="attn" :class="cert.daysLeft > 30 ? 'attn--ok' : cert.daysLeft > 7 ? '' : 'attn--no'" style="margin-bottom: 14px">
                            <Icon :name="cert.daysLeft > 7 ? 'shield' : 'warn'" />
                            <span><b>{{ cert.daysLeft > 0 ? `Действует ещё ${cert.daysLeft} дн` : 'Истёк' }}</b> — до {{ date(cert.to) }}. {{ cert.letsEncrypt ? `Let's Encrypt продлевает сам примерно ${cert.renewAt}.` : 'Выдан не Let\'s Encrypt: продление вручную.' }}</span>
                        </div>
                        <div class="kv"><span>Выдан для</span><span class="mono">{{ cert.subject }}</span></div>
                        <div class="kv"><span>Кем</span><span>{{ cert.issuer }}</span></div>
                        <div class="kv"><span>Срок</span><span>{{ date(cert.from) }} — {{ date(cert.to) }}</span></div>
                        <div class="kv"><span>Последняя попытка продления</span><span>{{ lastAttempt || 'записей нет' }}</span></div>
                        <div style="margin-top: 16px">
                            <button class="btn btn--primary" type="button" @click="post('/settings/cert/renew')">Проверить и продлить сейчас</button>
                            <p class="hint">certbot renew + перечитывание nginx, Postfix и Dovecot. Занимает до минуты.</p>
                        </div>
                    </template>
                    <div v-else class="empty">Сертификат не прочитан — проверьте служебную обёртку</div>
                </div>
                <div class="card card--pad">
                    <div class="card__title">Имена в сертификате</div>
                    <div v-for="n in names" :key="n" class="row" style="grid-template-columns: 18px minmax(0, 1fr) auto; padding: 7px 0">
                        <span class="dot" :class="cert && cert.names.includes(n) ? 'dot--ok' : 'dot--warn'" />
                        <span class="mono">{{ n }}</span>
                        <span class="row__sub">{{ cert && cert.names.includes(n) ? 'есть' : 'нет в сертификате' }}</span>
                    </div>
                    <p class="hint">Одно имя на все службы: клиенты настраиваются на {{ mailHost || (cert && cert.subject) }} для IMAP и SMTP, веб-почта и админка — по нему же. Лишние имена ничему не мешают.</p>
                </div>
            </div>
        </template>

        <!-- ── Резервные копии ─────────────────────────────────────── -->
        <template v-if="tab === 'backup'">
            <div class="tiles" style="margin-bottom: 16px">
                <div class="tile"><div class="tile__value" :class="{ 'tile__value--warn': !lastOk }">{{ lastOk ? when(lastOk.at) : 'ещё не было' }}</div><div class="tile__label">Последняя удачная копия</div><div class="tile__sub">{{ lastOk ? `${mb(lastOk.size)} за ${dur(lastOk.seconds)}` : 'запустите вручную или дождитесь ночи' }}</div></div>
                <div class="tile"><div class="tile__value">{{ running ? 'выполняется' : nextRun }}</div><div class="tile__label">{{ running ? 'Сейчас' : 'Следующая' }}</div><div class="tile__sub">ежедневно в {{ backup.time }}</div></div>
                <div class="tile"><div class="tile__value" :class="{ 'tile__value--no': !dirCheck.ok }">{{ dirCheck.ok ? (dirCheck.free != null ? mb(dirCheck.free) : 'доступен') : 'недоступен' }}</div><div class="tile__label">Место в хранилище</div><div class="tile__sub ellipsis" :title="backup.dir">{{ backup.dir }}</div></div>
                <div class="tile"><div class="tile__value">{{ files.length }}</div><div class="tile__label">Архивов на диске</div><div class="tile__sub">{{ backup.keep_daily }} дневных + {{ backup.keep_weekly }} недельных</div></div>
            </div>

            <div class="grid-set">
                <form class="card card--pad" @submit.prevent="backupForm.post('/settings/backup', { preserveScroll: true })">
                    <div class="card__title">Расписание</div>
                    <label class="field"><span>Куда складывать</span><input v-model="backupForm.dir" class="input" placeholder="/var/backups/mail или /mnt/nas/mail"><span class="hint">Сетевую папку NAS смонтируйте на сервере заранее (fstab, CIFS или NFS); проверка доступа — плитка «Место в хранилище».</span></label>
                    <div class="field__row" style="flex-wrap: wrap; margin-bottom: 12px">
                        <span>Каждый день в</span><input v-model="backupForm.time" class="input" type="time" style="width: 110px; height: 34px">
                        <span>· хранить</span><input v-model.number="backupForm.keep_daily" class="input" type="number" min="1" style="width: 64px; height: 34px"><span>дневных и</span>
                        <input v-model.number="backupForm.keep_weekly" class="input" type="number" min="0" style="width: 64px; height: 34px"><span>недельных</span>
                    </div>
                    <div class="toggles--3" style="grid-template-columns: 1fr 1fr">
                        <Toggle v-model="backupForm.mail" label="Почта (/var/vmail)" />
                        <Toggle v-model="backupForm.db" label="Базы: ящики, настройки, календари" />
                        <Toggle v-model="backupForm.config" label="Конфигурация Postfix, Dovecot, Amavis, nginx" />
                        <Toggle v-model="backupForm.vm_snapshot" label="Напоминать о снимке ВМ в Hyper-V" />
                    </div>
                    <div class="form-actions" style="margin-top: 16px">
                        <button class="btn btn--primary" type="submit" :disabled="backupForm.processing">Сохранить</button>
                        <button class="btn" type="button" :disabled="running" @click="post('/settings/backup/run')"><Icon name="play" /> {{ running ? 'Выполняется…' : 'Сделать копию сейчас' }}</button>
                    </div>
                </form>

                <div class="card card--pad">
                    <div class="card__title">Восстановить ящик из копии</div>
                    <form @submit.prevent="confirm(`Восстановить письма ${restore.user} из архива? Существующие письма не удаляются, недостающие добавятся.`) && post('/settings/backup/restore', restore)">
                        <label class="field"><span>Архив</span><select v-model="restore.file" class="input" required><option value="" disabled>выберите…</option><option v-for="f in files" :key="f.file" :value="f.file">{{ new Date(f.mtime * 1000).toLocaleString('ru-RU') }} — {{ mb(f.size) }}</option></select></label>
                        <label class="field"><span>Ящик</span><select v-model="restore.user" class="input" required><option value="" disabled>выберите…</option><option v-for="u in employees" :key="u" :value="u">{{ u }}</option></select></label>
                        <button class="btn" type="submit" :disabled="!restore.file || !restore.user"><Icon name="upload" /> Восстановить</button>
                    </form>
                    <p class="hint">Восстановление всего сервера — из консоли: распаковать архив, восстановить базы из SQL-дампов и вернуть /var/vmail. Инструкция в README на сервере.</p>
                </div>
            </div>

            <div class="card card--flush" style="margin-top: 16px">
                <div class="thead" style="grid-template-columns: 150px minmax(0, 1fr) 100px 90px 120px"><span>Когда</span><span>Архив</span><span>Размер</span><span>Время</span><span>Итог</span></div>
                <div v-for="h in history" :key="h.id" class="row" style="grid-template-columns: 150px minmax(0, 1fr) 100px 90px 120px">
                    <span class="row__sub">{{ when(h.at) }}</span>
                    <span class="mono ellipsis" :title="h.file || h.error">{{ h.file ? h.file.split('/').pop() : '—' }}<span v-if="h.error" class="row__sub"> — {{ h.error }}</span></span>
                    <span>{{ h.size ? mb(h.size) : '—' }}</span>
                    <span class="row__sub">{{ dur(h.seconds) }}</span>
                    <span><span class="chip" :class="h.status === 'ok' ? 'chip--ok' : 'chip--no'">{{ h.status === 'ok' ? 'успешно' : 'ошибка' }}</span></span>
                </div>
                <div v-if="!history.length" class="empty">Журнал пуст — копий ещё не делали</div>
            </div>
        </template>

        <!-- ── Администраторы ───────────────────────────────────────── -->
        <template v-if="tab === 'admins'">
            <div class="grid-2-1">
                <div class="card card--flush">
                    <div class="thead" style="grid-template-columns: minmax(0, 1.4fr) minmax(0, 1fr) 90px 90px auto"><span>Кто</span><span>Роль</span><span>Вход</span><span>2FA</span><span /></div>
                    <template v-for="a in admins" :key="a.id">
                        <div class="row row--click" :class="{ 'row--on': editingAdmin === a.id }" style="grid-template-columns: minmax(0, 1.4fr) minmax(0, 1fr) 90px 90px auto" @click="openAdmin(a)">
                            <span><b>{{ a.name }}</b><span v-if="a.me" class="tag" style="margin-left: 6px">это вы</span><span v-if="!a.active" class="tag" style="margin-left: 6px">отключён</span><span class="row__sub" style="display: block">{{ a.email }}</span></span>
                            <span>{{ a.roleTitle }}<span class="row__sub" style="display: block">{{ ROLE_HINT[a.role] }}</span></span>
                            <span class="row__sub">{{ a.imap ? 'пароль ящика' : 'свой пароль' }}</span>
                            <span><span class="chip" :class="a.twofa ? 'chip--ok' : 'chip--warn'">{{ a.twofa ? 'включена' : 'нет' }}</span></span>
                            <Icon :name="editingAdmin === a.id ? 'up' : 'down'" />
                        </div>
                        <div v-if="editingAdmin === a.id" class="row__expand" style="grid-template-columns: 1fr 1fr 1fr; padding-left: 18px">
                            <label class="field"><span>Имя</span><input v-model="adminEdit.name" class="input"></label>
                            <label class="field"><span>Роль</span><select v-model="adminEdit.role" class="input" :disabled="a.me"><option v-for="(t, k) in roles" :key="k" :value="k">{{ t }}</option></select></label>
                            <label class="field"><span>Новый пароль <span class="row__sub">(если не через ящик)</span></span><input v-model="adminEdit.password" class="input" type="password" autocomplete="new-password" placeholder="оставить как есть"></label>
                            <div class="row__expand-actions" style="grid-column: 1 / -1">
                                <button class="btn btn--primary" type="button" @click="saveAdmin(a)">Сохранить</button>
                                <button v-if="a.twofa" class="btn" type="button" @click="router.put(`/settings/admins/${a.id}`, { reset2fa: true }, { preserveScroll: true })">Сбросить 2FA</button>
                                <button v-if="!a.me" class="btn" type="button" @click="router.put(`/settings/admins/${a.id}`, { active: !a.active }, { preserveScroll: true })">{{ a.active ? 'Отключить доступ' : 'Включить доступ' }}</button>
                                <button v-if="!a.me" class="btn btn--danger" type="button" @click="confirm(`Снять администратора ${a.email}?`) && del(`/settings/admins/${a.id}`)">Снять</button>
                            </div>
                        </div>
                    </template>
                </div>

                <form class="card card--pad" @submit.prevent="adminForm.post('/settings/admins', { preserveScroll: true, onSuccess: () => adminForm.reset() })">
                    <div class="card__title">Назначить администратора</div>
                    <label class="field"><span>Из сотрудников</span><select class="input" @change="pickEmployee"><option value="">выбрать…</option><option v-for="e in employees" :key="e.username" :value="e.username">{{ e.name }} — {{ e.username }}</option></select></label>
                    <label class="field"><span>Адрес</span><input v-model="adminForm.email" class="input" type="email" required placeholder="ivanov@домен"></label>
                    <label class="field"><span>Имя</span><input v-model="adminForm.name" class="input" required></label>
                    <label class="field"><span>Роль</span><select v-model="adminForm.role" class="input"><option v-for="(t, k) in roles" :key="k" :value="k">{{ t }}</option></select><span class="hint">{{ ROLE_HINT[adminForm.role] }}</span></label>
                    <Toggle v-model="adminForm.imap" label="Входит паролем своего почтового ящика" />
                    <label v-if="!adminForm.imap" class="field" style="margin-top: 10px"><span>Пароль для админки</span><input v-model="adminForm.password" class="input" type="password" autocomplete="new-password" minlength="10" required></label>
                    <p class="hint">Двухфакторную защиту администратор подключает сам при первом входе (раздел «Безопасность»). Роли «Только просмотр» и «Оператор» не могут менять настройки сервера.</p>
                    <div class="form-actions"><button class="btn btn--primary" type="submit" :disabled="adminForm.processing">Назначить</button></div>
                </form>
            </div>
        </template>

        <!-- ── Файлы и облако ───────────────────────────────────────── -->
        <template v-if="tab === 'cloud'">
            <div class="grid-set">
                <div class="card card--pad">
                    <div class="card__title">Nextcloud для больших вложений</div>
                    <template v-if="!cloud.connected">
                        <p class="hint" style="margin-top: 0">Файлы крупнее порога не вкладываются в письмо, а загружаются в облако, получатель получает ссылку. Нужен любой Nextcloud (свой или у провайдера) и учётная запись в нём — она станет служебной, в её облаке появится папка «{{ cloudForm.folder }}».</p>
                        <label class="field"><span>Адрес Nextcloud</span><input v-model="cloudUrl" class="input" placeholder="https://cloud.deltaservices.ru" :disabled="cloudWaiting"></label>
                        <div v-if="!cloudWaiting" class="form-actions"><button class="btn btn--primary" type="button" :disabled="!cloudUrl" @click="cloudConnect">Подключить</button><button class="btn" type="button" @click="manual = !manual">Ввести пароль приложения вручную</button></div>
                        <div v-else class="attn"><Icon name="clock" /><span>Откройте окно Nextcloud, войдите под служебной учёткой и нажмите «Разрешить доступ». Ждём подтверждения… <a :href="cloudLogin" target="_blank" rel="noopener">открыть ещё раз</a> · <a href="#" @click.prevent="cloudCancel">отменить</a></span></div>
                        <p v-if="cloudError" class="error">{{ cloudError }}</p>
                        <form v-if="manual" style="margin-top: 14px" @submit.prevent="manualForm.post('/settings/cloud/manual', { preserveScroll: true })">
                            <label class="field"><span>Адрес</span><input v-model="manualForm.url" class="input" required></label>
                            <label class="field"><span>Логин</span><input v-model="manualForm.login" class="input" required></label>
                            <label class="field"><span>Пароль приложения</span><input v-model="manualForm.app_password" class="input" type="password" required><span class="hint">Nextcloud → Настройки → Безопасность → «Создать новый пароль приложения».</span></label>
                            <button class="btn btn--primary" type="submit" :disabled="manualForm.processing">Подключить</button>
                        </form>
                    </template>
                    <template v-else>
                        <div class="attn" :class="cloudStatus && cloudStatus.ok ? 'attn--ok' : 'attn--no'" style="margin-bottom: 14px">
                            <Icon :name="cloudStatus && cloudStatus.ok ? 'cloud' : 'warn'" />
                            <span><b>{{ cloudStatus && cloudStatus.ok ? 'Подключено' : 'Нет связи' }}</b> — {{ cloud.login }} @ {{ cloud.url }}{{ cloudStatus && cloudStatus.version ? ' · Nextcloud ' + cloudStatus.version : '' }}<br><span class="row__sub">{{ cloudStatus ? cloudStatus.message : '' }}{{ cloudStatus && cloudStatus.free != null ? ' · свободно ' + mb(cloudStatus.free) : '' }}</span></span>
                        </div>
                        <form @submit.prevent="cloudForm.post('/settings/cloud', { preserveScroll: true })">
                            <Toggle v-model="cloudForm.enabled" label="Отправлять большие вложения через облако" />
                            <div class="toggles--3" style="margin-top: 12px">
                                <label class="field"><span>Порог, МБ</span><input v-model.number="cloudForm.threshold_mb" class="input" type="number" min="1" max="1024"><span class="hint">Файлы крупнее уходят ссылкой. Лимит письма сейчас {{ sizeLimitMb }} МБ.</span></label>
                                <label class="field"><span>Ссылка действует, дней</span><input v-model.number="cloudForm.expire_days" class="input" type="number" min="0" max="3650"><span class="hint">0 — бессрочно</span></label>
                                <label class="field"><span>Пароль на ссылки</span><input v-model="cloudForm.link_password" class="input" placeholder="не нужен"><span class="hint">Один на все ссылки; получателю его сообщает отправитель</span></label>
                            </div>
                            <label class="field" style="margin-top: 8px"><span>Папка в облаке</span><input v-model="cloudForm.folder" class="input"><span class="hint">Внутри — подпапки по сотрудникам и месяцам: {{ cloudForm.folder }}/ivanov@…/2026-09/файл</span></label>
                            <div class="form-actions" style="margin-top: 14px">
                                <button class="btn btn--primary" type="submit" :disabled="cloudForm.processing">Сохранить</button>
                                <button class="btn" type="button" @click="post('/settings/cloud/test')"><Icon name="upload" /> Проверить загрузку</button>
                                <button class="btn btn--danger" type="button" @click="confirm('Отключить облако? Уже отправленные ссылки продолжат работать, новые вложения пойдут внутри писем.') && post('/settings/cloud/disconnect')">Отключить</button>
                            </div>
                        </form>
                    </template>
                </div>
                <div class="card card--pad">
                    <div class="card__title">Как это работает</div>
                    <p class="hint" style="margin-top: 0">Сотрудник пишет письмо и прикладывает файлы как обычно. Всё, что крупнее порога, веб-почта помечает облачком; такие файлы при отправке загружаются в Nextcloud, а в письмо вставляется список ссылок. Любой файл можно переключить вручную: маленький отправить ссылкой или большой вложить, если он влезает в лимит письма.</p>
                    <p class="hint">Файлы лежат в облаке служебной учётной записи, а не в личных облаках сотрудников — их видит и чистит администратор. Срок ссылки ограничивает доступ, сам файл остаётся в папке.</p>
                    <p class="hint">Пароль приложения хранится в базе в зашифрованном виде и не показывается. Чтобы сменить учётку, отключите облако и подключите заново.</p>
                </div>
            </div>
        </template>

        <!-- ── Уведомления ─────────────────────────────────────────── -->
        <template v-if="tab === 'alerts'">
            <form class="grid-set" @submit.prevent="alertsForm.post('/settings/alerts', { preserveScroll: true })">
                <div class="card card--pad">
                    <div class="card__title">О чём сообщать</div>
                    <div style="display: flex; flex-direction: column; gap: 12px">
                        <div class="field__row" style="flex-wrap: wrap"><Toggle v-model="alertsForm.queue" label="Очередь: больше" /><input v-model.number="alertsForm.queue_size" class="input" type="number" min="1" style="width: 64px; height: 34px"><span>писем или ждёт дольше</span><input v-model.number="alertsForm.queue_age_hours" class="input" type="number" min="1" style="width: 56px; height: 34px"><span>ч</span></div>
                        <div class="field__row"><Toggle v-model="alertsForm.disk" label="Диск с почтой занят больше чем на" /><input v-model.number="alertsForm.disk_pct" class="input" type="number" min="50" max="99" style="width: 64px; height: 34px"><span>%</span></div>
                        <Toggle v-model="alertsForm.services" label="Служба остановилась или сертификат скоро истечёт" />
                        <Toggle v-model="alertsForm.backup" label="Резервная копия не удалась или не делалась больше 36 ч" />
                        <Toggle v-model="alertsForm.admin_login" label="Вход администратора с нового адреса" />
                        <div class="field__row"><Toggle v-model="alertsForm.digest" label="Ежедневная сводка в" /><input v-model="alertsForm.digest_time" class="input" type="time" style="width: 110px; height: 34px"></div>
                    </div>
                    <p class="hint">Проверка каждые 5 минут; одно и то же событие не повторяется чаще раза в 6 часов.</p>
                </div>
                <div class="card card--pad">
                    <div class="card__title">Куда</div>
                    <label class="field"><span>Почта администраторов</span><input v-model="alertsForm.emails" class="input" placeholder="admin@домен, it@домен"><span class="hint">Письма уходят с этого же сервера; если он лежит совсем — спасает Telegram.</span></label>
                    <label class="field"><span>Telegram: токен бота</span><input v-model="alertsForm.telegram_token" class="input" placeholder="123456:ABC-DEF…" autocomplete="off"></label>
                    <label class="field"><span>Telegram: chat_id</span><input v-model="alertsForm.telegram_chat" class="input" placeholder="-1001234567890 или ваш id"><span class="hint">Напишите боту любое сообщение, затем откройте api.telegram.org/bot&lt;токен&gt;/getUpdates — там будет chat.id.</span></label>
                    <label class="field"><span>Прокси для Telegram</span><input v-model="alertsForm.telegram_proxy" class="input" placeholder="socks5://user:pass@host:1080 (если api.telegram.org недоступен напрямую)"></label>
                    <div class="form-actions" style="margin-top: 16px">
                        <button class="btn btn--primary" type="submit" :disabled="alertsForm.processing">Сохранить</button>
                        <button class="btn" type="button" :disabled="testing" @click="testAlerts"><Icon name="tg" /> {{ testing ? 'Отправляем…' : 'Отправить проверочное' }}</button>
                    </div>
                </div>
            </form>
        </template>
    </AppLayout>
</template>
