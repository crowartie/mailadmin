<script setup>
// Настройки веб-почты: общие, подпись, автоответ, правила, папки и метки, безопасность, клавиши.
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import MailLayout from '../../Layouts/MailLayout.vue';
import Icon from '../../Components/Icon.vue';
import Editor from '../../Components/Mail/Editor.vue';
import Toast from '../../Components/Mail/Toast.vue';
import Dialog from '../../Components/Mail/Dialog.vue';
import { api } from '../../mail/api';

const props = defineProps({
    user: String,
    section: { type: String, default: 'general' },
    settings: Object,
    identities: Array,
    folders: Array,
    labels: Array,
    rules: Object,
    force2fa: Boolean,
});

const SECTIONS = [
    ['general', 'Общие'], ['signature', 'Подпись'], ['autoreply', 'Автоответ'], ['rules', 'Правила'],
    ['folders', 'Папки и метки'], ['security', 'Безопасность'], ['shortcuts', 'Горячие клавиши'],
];

const s = ref({ ...props.settings });
const quickText = ref((props.settings.quick_replies || []).join('\n'));
const folders = ref(props.folders);
const labels = ref(props.labels);
const rules = ref((props.rules?.rules || []).map((r) => ({ ...r })));
const autoreply = ref({ enabled: false, from: '', to: '', subject: 'Автоответ', body: '', days: 1, ...(props.rules?.autoreply || {}) });
const toast = ref(null);
const dialog = ref(null);
const editing = ref(null); // редактируемое правило
const pw = ref({ current: '', password: '', password_confirmation: '' });
const sec = ref(null);
const twofa = ref(null);
const twofaCode = ref('');
const twofaPassword = ref('');
const newAppPassword = ref(null);
const createdPassword = ref(null);
async function loadSecurity() { try { sec.value = await api.security(); } catch (e) { say(e.message, true); } }
async function startTwofa() { busy.value = true; try { twofa.value = await api.twofaSetup(); twofaCode.value = ''; } catch (e) { say(e.message, true); } finally { busy.value = false; } }
async function enableTwofa() { busy.value = true; try { await api.twofaEnable(twofaCode.value); twofa.value = null; await loadSecurity(); say('Двухфакторная защита включена'); if (props.force2fa) window.location.href = '/mail'; } catch (e) { say(e.message, true); } finally { busy.value = false; } }
async function disableTwofa() { busy.value = true; try { await api.twofaDisable(twofaPassword.value); twofaPassword.value = ''; await loadSecurity(); say('Защита выключена'); } catch (e) { say(e.message, true); } finally { busy.value = false; } }
async function createAppPassword() { busy.value = true; try { createdPassword.value = await api.createAppPassword(newAppPassword.value.name, newAppPassword.value.password); newAppPassword.value = null; await loadSecurity(); } catch (e) { say(e.message, true); } finally { busy.value = false; } }
async function revokeAppPassword(p) { if (!confirm(`Отозвать пароль «${p.name}»? Устройство перестанет получать почту.`)) return; try { await api.deleteAppPassword(p.id); await loadSecurity(); } catch (e) { say(e.message, true); } }
async function kickSession(s) { try { const r = await api.kickSession(s.id); sec.value.sessions = r.sessions; } catch (e) { say(e.message, true); } }
async function kickOthers() { try { const r = await api.kickOthers(); sec.value.sessions = r.sessions; say('Остальные сеансы завершены'); } catch (e) { say(e.message, true); } }
function when(iso, long = false) { if (!iso) return ''; const d = new Date(iso); const diff = (Date.now() - d) / 60000; if (!long) { if (diff < 1) return 'сейчас'; if (diff < 60) return Math.round(diff) + ' мин назад'; if (diff < 1440) return Math.round(diff / 60) + ' ч назад'; } return d.toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' }) + ', ' + d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' }); }
if (props.section === 'security') loadSecurity();
const busy = ref(false);
let toastTimer = null;

const custom = computed(() => folders.value.filter((f) => f.role === 'custom'));
const COLORS = ['#2F6FEB', '#16A05C', '#D9791F', '#C0392B', '#7B3FE4', '#0E8A8A', '#6B7787'];

function say(text, error = false) {
    clearTimeout(toastTimer);
    toast.value = { text, error };
    toastTimer = setTimeout(() => { toast.value = null; }, error ? 6000 : 3000);
}

async function saveSettings(patch) {
    busy.value = true;
    try {
        const r = await api.saveSettings(patch);
        Object.assign(s.value, r);
        say('Сохранено');
    } catch (e) { say(e.message, true); } finally { busy.value = false; }
}
function saveGeneral() {
    saveSettings({
        display_name: s.value.display_name, reply_all: s.value.reply_all, undo_seconds: Number(s.value.undo_seconds),
        preview: s.value.preview, shortcuts: s.value.shortcuts, theme: s.value.theme, show_images: s.value.show_images,
        quick_replies: quickText.value.split('\n').map((x) => x.trim()).filter(Boolean).slice(0, 8),
    });
}

// ── Правила ─────────────────────────────────────────────────
const FIELDS = { from: 'Отправитель', to: 'Получатель', recipient: 'Кому или копия', subject: 'Тема', body: 'Текст письма', header: 'Заголовок', size: 'Размер, КБ' };
const OPS = { contains: 'содержит', not_contains: 'не содержит', is: 'равно', starts: 'начинается с', ends: 'заканчивается на', over: 'больше', under: 'меньше' };
const ACTIONS = { move: 'Переместить в папку', copy: 'Копию в папку', label: 'Поставить метку', flag: 'Флажок', seen: 'Пометить прочитанным', forward: 'Переслать на адрес', forward_copy: 'Переслать копию на адрес', discard: 'Удалить', reply: 'Ответить текстом', stop: 'Остановить обработку' };

function newRule() {
    editing.value = { id: Date.now(), name: '', enabled: true, match: 'all', stop: false, conditions: [{ field: 'from', op: 'contains', value: '' }], actions: [{ type: 'move', value: '' }] };
}
function describeCond(c) {
    if (c.field === 'size') return `Размер ${OPS[c.op] || ''} ${c.value} КБ`;
    return `${FIELDS[c.field] || c.field}${c.field === 'header' ? ' ' + (c.header || '') : ''} ${OPS[c.op] || ''} «${c.value}»`;
}
function describeAct(a) {
    const folderName = (p) => folders.value.find((f) => f.path === p)?.name || p;
    const labelName = (id) => labels.value.find((l) => String(l.id) === String(id))?.name || id;
    switch (a.type) {
        case 'move': return `в папку «${folderName(a.value)}»`;
        case 'copy': return `копия в «${folderName(a.value)}»`;
        case 'label': return `метка «${labelName(a.value)}»`;
        case 'forward': case 'forward_copy': return `${ACTIONS[a.type].toLowerCase()} ${a.value}`;
        case 'reply': return 'автоответ';
        default: return ACTIONS[a.type]?.toLowerCase() || a.type;
    }
}
function ruleName(r) {
    return r.name || (r.conditions?.length ? describeCond(r.conditions[0]) : 'Все письма');
}
function saveRule() {
    const r = editing.value;
    if (!r.actions.length) { say('Добавьте хотя бы одно действие', true); return; }
    const i = rules.value.findIndex((x) => x.id === r.id);
    if (i >= 0) rules.value[i] = r; else rules.value.push(r);
    editing.value = null;
    pushRules();
}
function removeRule(id) {
    rules.value = rules.value.filter((r) => r.id !== id);
    pushRules();
}
function moveRule(i, d) {
    const j = i + d;
    if (j < 0 || j >= rules.value.length) return;
    const arr = [...rules.value]; [arr[i], arr[j]] = [arr[j], arr[i]]; rules.value = arr;
    pushRules();
}
async function pushRules() {
    busy.value = true;
    try {
        await api.saveRules(rules.value, autoreply.value);
        say('Правила применены на сервере');
    } catch (e) { say(e.message, true); } finally { busy.value = false; }
}

// ── Папки и метки ────────────────────────────────────────────
async function confirmDialog(value) {
    const d = dialog.value; dialog.value = null;
    try {
        if (d.kind === 'newFolder') folders.value = (await api.createFolder(value, d.parent || null)).folders;
        if (d.kind === 'renameFolder') folders.value = (await api.renameFolder(d.folder.path, value)).folders;
        if (d.kind === 'deleteFolder') folders.value = (await api.deleteFolder(d.folder.path)).folders;
        if (d.kind === 'label') labels.value = await api.createLabel(value, d.color || COLORS[0]);
        if (d.kind === 'renameLabel') labels.value = await api.updateLabel(d.label.id, value, d.label.color);
        if (d.kind === 'deleteLabel') labels.value = await api.deleteLabel(d.label.id);
        say('Готово');
    } catch (e) { say(e.message, true); }
}
async function recolor(l, color) {
    try { labels.value = await api.updateLabel(l.id, l.name, color); } catch (e) { say(e.message, true); }
}

// ── Пароль ───────────────────────────────────────────────────
async function changePassword() {
    busy.value = true;
    try {
        await api.password(pw.value);
        pw.value = { current: '', password: '', password_confirmation: '' };
        say('Пароль изменён. Обновите его в телефоне и почтовой программе.');
    } catch (e) { say(e.message, true); } finally { busy.value = false; }
}

const shortcuts = [
    ['Навигация', [['j / k', 'следующее / предыдущее письмо'], ['Enter', 'открыть'], ['u', 'к списку'], ['g i', 'Входящие'], ['g s', 'Отправленные'], ['g d', 'Черновики'], ['/', 'поиск']]],
    ['Письмо', [['r', 'ответить'], ['a', 'ответить всем'], ['f', 'переслать'], ['e', 'архив'], ['#', 'удалить'], ['s', 'флажок'], ['i', 'прочитано / нет'], ['z', 'отложить']]],
    ['Разбор', [['v', 'в папку'], ['l', 'метка'], ['!', 'спам'], ['x', 'выбрать'], ['Esc', 'снять выбор']]],
    ['Написать', [['c', 'новое письмо'], ['Ctrl Enter', 'отправить'], ['Ctrl S', 'черновик'], ['Ctrl K', 'ссылка'], ['?', 'подсказка']]],
];
</script>

<template>
    <Head title="Настройки" />
    <MailLayout :user="user" :theme="s.theme">
        <div class="mset">
            <div class="page-head" style="margin-bottom: 16px">
                <Link href="/mail" class="ib" title="К письмам"><Icon name="back" :size="18" /></Link>
                <h1>Настройки</h1>
                <span class="page-head__count">{{ user }}</span>
            </div>
            <div class="mset__grid">
                <nav class="card mset__menu">
                    <Link v-for="[key, label] in SECTIONS" :key="key" :href="'/mail/settings/' + key" :class="{ on: section === key }">{{ label }}</Link>
                </nav>

                <div class="mset__body">
                    <!-- Общие -->
                    <template v-if="section === 'general'">
                        <form class="card mset__section" @submit.prevent="saveGeneral">
                            <h2>Общие</h2>
                            <div class="mset__cols">
                                <div class="field"><label>Имя отправителя</label><input v-model="s.display_name" class="input" placeholder="Как вас видят получатели"></div>
                                <div class="field"><label>Адрес</label><input class="input" :value="user" disabled></div>
                                <div class="field">
                                    <label>Тема оформления</label>
                                    <select v-model="s.theme" class="input"><option value="light">Светлая</option><option value="dark">Тёмная</option><option value="system">Как в системе</option></select>
                                </div>
                                <div class="field">
                                    <label>Отмена отправки</label>
                                    <select v-model="s.undo_seconds" class="input"><option :value="0">Выключена</option><option :value="5">5 секунд</option><option :value="10">10 секунд</option><option :value="20">20 секунд</option><option :value="30">30 секунд</option></select>
                                </div>
                                <div class="field">
                                    <label>Картинки из интернета в письмах</label>
                                    <select v-model="s.show_images" class="input"><option value="ask">Показывать по кнопке</option><option value="always">Показывать всегда</option></select>
                                </div>
                            </div>
                            <label class="toggle"><input v-model="s.shortcuts" type="checkbox"><span class="toggle__track" />Горячие клавиши</label>
                            <label class="toggle"><input v-model="s.reply_all" type="checkbox"><span class="toggle__track" />По умолчанию отвечать всем</label>
                            <div class="field">
                                <label>Быстрые ответы (каждый с новой строки)</label>
                                <textarea v-model="quickText" class="input" rows="4" />
                            </div>
                            <div><button class="btn btn--primary" type="submit" :disabled="busy">Сохранить</button></div>
                        </form>
                        <div v-if="identities.length > 1" class="card mset__section">
                            <h2>Мои адреса</h2>
                            <div class="mset__list">
                                <div v-for="i in identities" :key="i.mail" class="mset__li"><span class="grow mono">{{ i.mail }}</span><span class="chip" :class="i.primary ? 'chip--ok' : 'chip--off'">{{ i.primary ? 'основной' : 'дополнительный' }}</span></div>
                            </div>
                            <p class="hint" style="margin: 0">Дополнительные адреса назначает администратор в карточке сотрудника.</p>
                        </div>
                    </template>

                    <!-- Подпись -->
                    <template v-if="section === 'signature'">
                        <div class="card mset__section">
                            <h2>Подпись</h2>
                            <div class="editor-box"><Editor v-model="s.signature" placeholder="Имя, должность, телефон…" /></div>
                            <label class="toggle"><input v-model="s.signature_reply" type="checkbox"><span class="toggle__track" />Добавлять подпись в ответах и пересылках</label>
                            <div><button class="btn btn--primary" type="button" :disabled="busy" @click="saveSettings({ signature: s.signature, signature_reply: s.signature_reply })">Сохранить</button></div>
                        </div>
                    </template>

                    <!-- Автоответ -->
                    <template v-if="section === 'autoreply'">
                        <form class="card mset__section" @submit.prevent="pushRules">
                            <h2>Автоответ <span class="grow" /><label class="toggle"><input v-model="autoreply.enabled" type="checkbox"><span class="toggle__track" />{{ autoreply.enabled ? 'Включён' : 'Выключен' }}</label></h2>
                            <div class="mset__cols">
                                <div class="field"><label>С (необязательно)</label><input v-model="autoreply.from" class="input" type="date"></div>
                                <div class="field"><label>По (необязательно)</label><input v-model="autoreply.to" class="input" type="date"></div>
                            </div>
                            <div class="field"><label>Тема</label><input v-model="autoreply.subject" class="input" required></div>
                            <div class="field"><label>Текст</label><textarea v-model="autoreply.body" class="input" rows="5" required /></div>
                            <div class="field" style="max-width: 260px"><label>Отвечать одному адресу не чаще, чем раз в</label>
                                <select v-model="autoreply.days" class="input"><option :value="1">день</option><option :value="3">3 дня</option><option :value="7">неделю</option></select></div>
                            <div><button class="btn btn--primary" type="submit" :disabled="busy">Сохранить</button></div>
                            <p class="hint" style="margin: 0">Автоответ работает на сервере: отвечает и когда веб-почта закрыта. Рассылкам и спаму сервер не отвечает.</p>
                        </form>
                    </template>

                    <!-- Правила -->
                    <template v-if="section === 'rules'">
                        <div class="card mset__section">
                            <h2>Правила <span class="chip chip--off">{{ rules.length }}</span><span class="grow" /><button class="btn btn--sm btn--primary" type="button" @click="newRule"><Icon name="plus" :size="14" />Новое правило</button></h2>
                            <div v-if="!rules.length" class="empty">Правил пока нет. Например: письма от бухгалтерии — в папку «Счета» и с меткой «Срочно».</div>
                            <div v-for="(r, i) in rules" :key="r.id" class="rule">
                                <label class="toggle"><input v-model="r.enabled" type="checkbox" @change="pushRules"><span class="toggle__track" /></label>
                                <div><small>Если</small>{{ r.conditions?.length ? r.conditions.map(describeCond).join(r.match === 'any' ? ' или ' : ' и ') : 'любое письмо' }}</div>
                                <div><small>То</small>{{ r.actions.map(describeAct).join(', ') }}{{ r.stop ? ', остановить' : '' }}</div>
                                <div style="display: flex; gap: 2px">
                                    <button class="ib ib--sm" type="button" title="Выше" :disabled="i === 0" @click="moveRule(i, -1)"><Icon name="up" :size="14" /></button>
                                    <button class="ib ib--sm" type="button" title="Ниже" :disabled="i === rules.length - 1" @click="moveRule(i, 1)"><Icon name="down" :size="14" /></button>
                                    <button class="ib ib--sm" type="button" title="Изменить" @click="editing = JSON.parse(JSON.stringify(r))"><Icon name="edit" :size="14" /></button>
                                    <button class="ib ib--sm ib--danger" type="button" title="Удалить" @click="removeRule(r.id)"><Icon name="trash" :size="14" /></button>
                                </div>
                            </div>
                            <p class="hint" style="margin: 0">Правила выполняются на сервере по порядку — работают и для телефона, и для почтовой программы.</p>
                        </div>

                        <form v-if="editing" class="card mset__section" @submit.prevent="saveRule">
                            <h2>{{ rules.some((x) => x.id === editing.id) ? 'Правило' : 'Новое правило' }}</h2>
                            <div class="field"><label>Название (необязательно)</label><input v-model="editing.name" class="input" placeholder="Счета от Сибстроя"></div>
                            <div class="field">
                                <label>Если <select v-model="editing.match" style="font: inherit; border: none; background: none; color: var(--accent-ink)"><option value="all">выполнены все условия</option><option value="any">выполнено любое условие</option></select></label>
                                <div v-for="(c, ci) in editing.conditions" :key="ci" class="rule__cond">
                                    <select v-model="c.field" class="input" style="max-width: 190px"><option v-for="(t, k) in FIELDS" :key="k" :value="k">{{ t }}</option></select>
                                    <input v-if="c.field === 'header'" v-model="c.header" class="input" placeholder="X-Priority" style="max-width: 160px">
                                    <select v-model="c.op" class="input" style="max-width: 170px">
                                        <template v-if="c.field === 'size'"><option value="over">больше</option><option value="under">меньше</option></template>
                                        <template v-else><option v-for="(t, k) in OPS" v-show="k !== 'over' && k !== 'under'" :key="k" :value="k">{{ t }}</option></template>
                                    </select>
                                    <input v-model="c.value" class="input" :placeholder="c.field === 'size' ? '10240' : 'значение'">
                                    <button class="ib ib--sm" type="button" title="Убрать" @click="editing.conditions.splice(ci, 1)"><Icon name="x" :size="14" /></button>
                                </div>
                                <a style="cursor: pointer; font-size: 13px" @click="editing.conditions.push({ field: 'subject', op: 'contains', value: '' })">+ ещё условие</a>
                            </div>
                            <div class="field">
                                <label>То</label>
                                <div v-for="(a, ai) in editing.actions" :key="ai" class="rule__cond">
                                    <select v-model="a.type" class="input" style="max-width: 240px"><option v-for="(t, k) in ACTIONS" :key="k" :value="k">{{ t }}</option></select>
                                    <select v-if="a.type === 'move' || a.type === 'copy'" v-model="a.value" class="input">
                                        <option v-for="f in folders" :key="f.path" :value="f.path">{{ '— '.repeat(f.depth) + f.name }}</option>
                                    </select>
                                    <select v-else-if="a.type === 'label'" v-model="a.value" class="input"><option v-for="l in labels" :key="l.id" :value="String(l.id)">{{ l.name }}</option></select>
                                    <input v-else-if="a.type === 'forward' || a.type === 'forward_copy'" v-model="a.value" class="input" type="email" placeholder="кому@домен">
                                    <input v-else-if="a.type === 'reply'" v-model="a.value" class="input" placeholder="Текст ответа">
                                    <button class="ib ib--sm" type="button" title="Убрать" @click="editing.actions.splice(ai, 1)"><Icon name="x" :size="14" /></button>
                                </div>
                                <a style="cursor: pointer; font-size: 13px" @click="editing.actions.push({ type: 'label', value: '' })">+ ещё действие</a>
                            </div>
                            <label class="toggle"><input v-model="editing.stop" type="checkbox"><span class="toggle__track" />Не применять следующие правила к этому письму</label>
                            <div style="display: flex; gap: 8px"><button class="btn btn--primary" type="submit" :disabled="busy">Сохранить</button><button class="btn" type="button" @click="editing = null">Отмена</button></div>
                        </form>
                    </template>

                    <!-- Папки и метки -->
                    <template v-if="section === 'folders'">
                        <div class="card mset__section">
                            <h2>Папки <span class="grow" /><button class="btn btn--sm btn--primary" type="button" @click="dialog = { kind: 'newFolder' }"><Icon name="plus" :size="14" />Новая папка</button></h2>
                            <div class="mset__list">
                                <div v-for="f in folders" :key="f.path" class="mset__li" :style="{ paddingLeft: f.depth * 18 + 'px' }">
                                    <Icon :name="f.role === 'custom' ? 'folder' : 'inbox'" :size="16" style="color: var(--faint)" />
                                    <span class="grow">{{ f.name }} <span class="sub">· {{ f.total }} писем{{ f.unread ? ', ' + f.unread + ' непрочит.' : '' }}</span></span>
                                    <template v-if="f.role === 'custom'">
                                        <button class="ib ib--sm" type="button" title="Вложенная папка" @click="dialog = { kind: 'newFolder', parent: f.path }"><Icon name="plus" :size="14" /></button>
                                        <button class="ib ib--sm" type="button" title="Переименовать" @click="dialog = { kind: 'renameFolder', folder: f }"><Icon name="edit" :size="14" /></button>
                                        <button class="ib ib--sm ib--danger" type="button" title="Удалить" @click="dialog = { kind: 'deleteFolder', folder: f }"><Icon name="trash" :size="14" /></button>
                                    </template>
                                    <span v-else class="chip chip--off">системная</span>
                                </div>
                            </div>
                        </div>
                        <div class="card mset__section">
                            <h2>Метки <span class="grow" /><button class="btn btn--sm btn--primary" type="button" @click="dialog = { kind: 'label' }"><Icon name="plus" :size="14" />Новая метка</button></h2>
                            <div class="mset__list">
                                <div v-for="l in labels" :key="l.id" class="mset__li">
                                    <span class="mnav__swatch mnav__swatch--round" :style="{ background: l.color }" />
                                    <span class="grow">{{ l.name }}</span>
                                    <div class="color-dots"><button v-for="c in COLORS" :key="c" type="button" :class="{ on: l.color === c }" :style="{ background: c, width: 18, height: 18 }" @click="recolor(l, c)" /></div>
                                    <button class="ib ib--sm" type="button" title="Переименовать" @click="dialog = { kind: 'renameLabel', label: l }"><Icon name="edit" :size="14" /></button>
                                    <button class="ib ib--sm ib--danger" type="button" title="Удалить" @click="dialog = { kind: 'deleteLabel', label: l }"><Icon name="trash" :size="14" /></button>
                                </div>
                                <div v-if="!labels.length" class="empty">Меток пока нет</div>
                            </div>
                            <p class="hint" style="margin: 0">Метки хранятся в самом ящике (ключевые слова IMAP), поэтому видны и в почтовых программах, которые их поддерживают.</p>
                        </div>
                    </template>

                    <!-- Безопасность -->
                    <template v-if="section === 'security'">
                        <div v-if="force2fa" class="card mset__section" style="border-color: var(--warn)">
                            <h2>Администратор требует двухфакторную защиту</h2>
                            <p class="hint" style="margin: 0">Подключите приложение-аутентификатор ниже — после этого почта откроется как обычно.</p>
                        </div>

                        <div class="card mset__section">
                            <h2>Двухфакторная защита <span class="grow" /><span v-if="sec" class="chip" :class="sec.totp ? 'chip--ok' : 'chip--warn'">{{ sec.totp ? 'включена' : 'выключена' }}</span></h2>
                            <template v-if="sec && !sec.totp && !twofa">
                                <p class="hint" style="margin: 0">При входе в веб-почту кроме пароля понадобится код из приложения на телефоне (Яндекс Ключ, Google Authenticator, любое TOTP). Почтовые программы и телефон подключаются паролем приложения.</p>
                                <div><button class="btn btn--primary" type="button" :disabled="busy" @click="startTwofa">Подключить приложение</button></div>
                            </template>
                            <template v-if="twofa">
                                <div style="display: flex; gap: 20px; align-items: flex-start; flex-wrap: wrap">
                                    <img :src="twofa.qr" alt="QR" style="width: 180px; height: 180px; border-radius: 10px; background: #fff; border: 1px solid var(--border)">
                                    <div style="flex: 1; min-width: 240px; display: flex; flex-direction: column; gap: 10px">
                                        <div>1. Отсканируйте код приложением или введите ключ вручную:</div>
                                        <div class="mono" style="font-size: 13px; letter-spacing: .08em; word-break: break-all">{{ twofa.secret.match(/.{1,4}/g).join(' ') }}</div>
                                        <div>2. Введите шесть цифр из приложения:</div>
                                        <form class="field__row" @submit.prevent="enableTwofa"><input v-model="twofaCode" class="input" inputmode="numeric" maxlength="6" placeholder="000000" style="width: 140px" required><button class="btn btn--primary" type="submit" :disabled="busy">Включить</button><button class="btn" type="button" @click="twofa = null">Отмена</button></form>
                                    </div>
                                </div>
                            </template>
                            <template v-if="sec && sec.totp">
                                <p class="hint" style="margin: 0">Чтобы выключить, введите пароль от почты.</p>
                                <form class="field__row" @submit.prevent="disableTwofa"><input v-model="twofaPassword" class="input" type="password" placeholder="Пароль от почты" style="max-width: 260px" required><button class="btn" type="submit" :disabled="busy || sec.required">Выключить</button><span v-if="sec.required" class="hint">выключить нельзя — требование администратора</span></form>
                            </template>
                        </div>

                        <div class="card mset__section">
                            <h2>Пароли приложений <span class="grow" /><button v-if="sec && sec.appPasswordsAllowed" class="btn btn--sm btn--primary" type="button" @click="newAppPassword = { name: '', password: '' }"><Icon name="plus" :size="14" />Создать</button></h2>
                            <p class="hint" style="margin: 0">Отдельный пароль для телефона или Outlook: если устройство потеряется, отзовите его пароль, основной менять не придётся.</p>
                            <form v-if="newAppPassword" class="mset__cols" style="align-items: end" @submit.prevent="createAppPassword">
                                <div class="field"><label>Для чего</label><input v-model="newAppPassword.name" class="input" placeholder="iPhone, Outlook на работе…" required></div>
                                <div class="field"><label>Ваш пароль от почты</label><div class="field__row"><input v-model="newAppPassword.password" class="input" type="password" required><button class="btn btn--primary" type="submit" :disabled="busy">Создать</button></div></div>
                            </form>
                            <div v-if="createdPassword" class="card" style="padding: 14px 16px; background: var(--ok-soft); border-color: var(--ok)">
                                <div>Пароль для <b>{{ createdPassword.name }}</b> — скопируйте сейчас, второй раз он не покажется:</div>
                                <div class="mono" style="font-size: 20px; letter-spacing: .1em; margin: 8px 0">{{ createdPassword.plain }}</div>
                                <div class="hint">В программе укажите логин {{ user }} и этот пароль вместо основного.</div>
                            </div>
                            <div class="mset__list">
                                <div v-for="p in (sec ? sec.appPasswords : [])" :key="p.id" class="mset__li">
                                    <Icon name="key" :size="16" style="color: var(--faint)" />
                                    <div class="grow"><div>{{ p.name }}</div><div class="sub">создан {{ when(p.created, true) }}<template v-if="p.lastUsed"> · использован {{ when(p.lastUsed, true) }}</template></div></div>
                                    <button class="btn btn--sm" type="button" @click="revokeAppPassword(p)">Отозвать</button>
                                </div>
                                <div v-if="sec && !sec.appPasswords.length" class="empty">Паролей приложений нет</div>
                            </div>
                        </div>

                        <div class="card mset__section">
                            <h2>Где вы вошли <span class="grow" /><button v-if="sec && sec.sessions.some((s) => !s.me)" class="btn btn--sm" type="button" @click="kickOthers">Завершить все, кроме этого</button></h2>
                            <div class="mset__list">
                                <div v-for="s in (sec ? sec.sessions : [])" :key="s.id" class="mset__li">
                                    <Icon :name="s.kind === 'web' ? 'laptop' : 'phone'" :size="16" style="color: var(--faint)" />
                                    <div class="grow"><div>{{ s.device }}<span v-if="s.me" class="chip chip--acc" style="margin-left: 8px">это вы</span></div><div class="sub mono">{{ s.ip }}<template v-if="s.seen"> · {{ when(s.seen) }}</template></div></div>
                                    <button v-if="!s.me" class="btn btn--sm" type="button" @click="kickSession(s)">Завершить</button>
                                </div>
                            </div>
                            <div v-if="sec && sec.logins.length" class="grp" style="margin-top: 6px">Последние входы</div>
                            <div v-for="(l, i) in (sec ? sec.logins : [])" :key="i" class="kv"><span>{{ when(l.at, true) }} · {{ l.device }}</span><b style="font-weight: 500" :style="{ color: l.result === 'ok' || l.result === 'new_device' ? 'var(--ok)' : 'var(--no)' }">{{ { ok: 'вход', new_device: 'вход с нового устройства', bad_password: 'неверный пароль', bad_code: 'неверный код', blocked: 'заблокировано' }[l.result] || l.result }} · <span class="mono">{{ l.ip }}</span></b></div>
                        </div>

                        <form class="card mset__section" style="max-width: 560px" @submit.prevent="changePassword">
                            <h2>Смена пароля</h2>
                            <div class="field"><label>Текущий пароль</label><input v-model="pw.current" class="input" type="password" autocomplete="current-password" required></div>
                            <div class="field"><label>Новый пароль (не короче {{ sec ? sec.minPassword : 10 }} символов)</label><input v-model="pw.password" class="input" type="password" autocomplete="new-password" :minlength="sec ? sec.minPassword : 10" required></div>
                            <div class="field"><label>Ещё раз</label><input v-model="pw.password_confirmation" class="input" type="password" autocomplete="new-password" required></div>
                            <div><button class="btn btn--primary" type="submit" :disabled="busy">Изменить пароль</button></div>
                            <p class="hint" style="margin: 0">Тот же пароль используется в телефоне и почтовой программе — после смены обновите его там. Пароль проверяется по базе известных утечек.</p>
                        </form>
                        <div class="card mset__section">
                            <h2>Подключение почтовых программ</h2>
                            <div class="mset__cols">
                                <div class="kv"><span>Входящие (IMAP)</span><b class="mono">imap.{{ user.split('@')[1] }} : 993, SSL</b></div>
                                <div class="kv"><span>Исходящие (SMTP)</span><b class="mono">smtp.{{ user.split('@')[1] }} : 587, STARTTLS</b></div>
                                <div class="kv"><span>Календарь и контакты</span><b class="mono">https://mail.{{ user.split('@')[1] }}/dav/</b></div>
                                <div class="kv"><span>Логин</span><b class="mono">{{ user }}</b></div>
                            </div>
                            <p class="hint" style="margin: 0">iPhone, Android и Outlook находят настройки сами по адресу. Пароль — от почты или пароль приложения.</p>
                        </div>
                    </template>

                    <!-- Клавиши -->
                    <template v-if="section === 'shortcuts'">
                        <div class="card mset__section">
                            <h2>Горячие клавиши <span class="grow" /><label class="toggle"><input v-model="s.shortcuts" type="checkbox" @change="saveSettings({ shortcuts: s.shortcuts })"><span class="toggle__track" />{{ s.shortcuts ? 'Включены' : 'Выключены' }}</label></h2>
                            <div class="keys">
                                <div v-for="[title, items] in shortcuts" :key="title">
                                    <div class="keys__group">{{ title }}</div>
                                    <div v-for="[combo, desc] in items" :key="combo" class="keys__row">
                                        <span><span v-for="k in combo.split(' ')" :key="k" class="kbd">{{ k }}</span></span><span>{{ desc }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        </div>

        <Dialog v-if="dialog && dialog.kind === 'newFolder'" title="Новая папка" :prompt="{ label: 'Название', placeholder: 'Например, Клиенты' }" confirm-label="Создать" @close="dialog = null" @confirm="confirmDialog" />
        <Dialog v-if="dialog && dialog.kind === 'renameFolder'" title="Переименовать папку" :prompt="{ label: 'Название', value: dialog.folder.name }" confirm-label="Сохранить" @close="dialog = null" @confirm="confirmDialog" />
        <Dialog v-if="dialog && dialog.kind === 'deleteFolder'" :title="'Удалить папку «' + dialog.folder.name + '»?'" confirm-label="Удалить" danger @close="dialog = null" @confirm="confirmDialog"><p class="hint" style="margin: 0">Письма в ней будут удалены.</p></Dialog>
        <Dialog v-if="dialog && dialog.kind === 'label'" title="Новая метка" :prompt="{ label: 'Название' }" confirm-label="Создать" @close="dialog = null" @confirm="confirmDialog">
            <div class="color-dots"><button v-for="c in COLORS" :key="c" type="button" :class="{ on: (dialog.color || COLORS[0]) === c }" :style="{ background: c }" @click="dialog.color = c" /></div>
        </Dialog>
        <Dialog v-if="dialog && dialog.kind === 'renameLabel'" title="Переименовать метку" :prompt="{ label: 'Название', value: dialog.label.name }" confirm-label="Сохранить" @close="dialog = null" @confirm="confirmDialog" />
        <Dialog v-if="dialog && dialog.kind === 'deleteLabel'" :title="'Удалить метку «' + dialog.label.name + '»?'" confirm-label="Удалить" danger @close="dialog = null" @confirm="confirmDialog" />
        <Toast :toast="toast" @close="toast = null" />
    </MailLayout>
</template>
