<script setup>
// Настройки веб-почты: общие, подпись, автоответ, правила, папки и метки, безопасность, клавиши.
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import MailLayout from '../../Layouts/MailLayout.vue';
import Icon from '../../Components/Icon.vue';
import Editor from '../../Components/Mail/Editor.vue';
import RuleParts from '../../Components/Mail/RuleParts.vue';
import Toast from '../../Components/Mail/Toast.vue';
import { plural, size, when as whenCommon } from '../../mail/format';
import { uiSimple, setUiSimple } from '../../mail/uiMode';
import Dialog from '../../Components/Mail/Dialog.vue';
import { api } from '../../mail/api';
import { isIos, standalone, supported as pushSupported, sync as pushSync } from '../../mail/push';
import { useSecuritySettings } from '../../mail/useSecuritySettings';
import { useMailRules } from '../../mail/useMailRules';
import { GLASS_PALETTES, GLASS_WALLPAPERS, GLASS_MOTIONS } from '../../mail/glass';

const props = defineProps({
    user: String,
    section: { type: String, default: 'general' },
    settings: Object,
    identities: Array,
    folders: Array,
    labels: Array,
    rules: Object,
    force2fa: Boolean,
    // Адреса и порты почтовых программ приходят с сервера (HelpController::hosts):
    // раньше каждый раздел писал их у себя и все три расходились.
    hosts: { type: Object, default: () => ({}) },
    directoryName: { type: String, default: '' },
});

// Правило для папки (обращение №38): у каждой папки, куда письма вообще можно складывать,
// есть кнопка — она открывает раздел «Правила» с готовым действием «переместить в эту папку».
const NO_RULES = ['drafts', 'sent', 'trash', 'shared'];
const canRule = (f) => !NO_RULES.includes(f.role) && !f.owner;
function ruleForFolder(f) {
    router.visit('/mail/settings/rules?folder=' + encodeURIComponent(f.path));
}

const SECTIONS = [
    ['general', 'Общие'], ['signature', 'Подпись'], ['autoreply', 'Автоответ'], ['rules', 'Правила'],
    ['folders', 'Папки и метки'], ['files', 'Мои файлы'], ['devices', 'Телефон и программы'], ['security', 'Безопасность'], ['shortcuts', 'Горячие клавиши'],
];

const s = ref({ ...props.settings });
const quickText = ref((props.settings.quick_replies || []).join('\n'));
const folders = ref(props.folders);
const labels = ref(props.labels);
const rules = ref((props.rules?.rules || []).map((r) => ({ ...r })));
const autoreply = ref({ enabled: false, from: '', to: '', subject: 'Автоответ', body: '', days: 1, ...(props.rules?.autoreply || {}) });
// Свой скрипт Sieve (вкладка «Свой скрипт»): проверяется самим сервером до сохранения.
const rulesTab = ref('builder');
const sieveCustom = ref(props.rules?.custom || '');
const sieveCheck = ref(null);   // { ok, error }
async function checkCustom() {
    busy.value = true;
    try { const r = await api.checkSieve(sieveCustom.value); sieveCheck.value = r; }
    catch (e) { sieveCheck.value = { ok: false, error: e.message }; } finally { busy.value = false; }
}
async function saveCustom() {
    busy.value = true;
    try {
        const r = await api.checkSieve(sieveCustom.value);
        sieveCheck.value = r;
        if (!r.ok) { say('Скрипт не сохранён: ' + r.error, true); return; }
        await api.saveRules(rules.value, autoreply.value, sieveCustom.value);
        say(sieveCustom.value.trim() ? 'Свой скрипт сохранён — сервер выполняет его после правил конструктора' : 'Свой скрипт убран');
    } catch (e) { say(e.message, true); } finally { busy.value = false; }
}
const toast = ref(null);
const dialog = ref(null);
const editing = ref(null); // редактируемое правило

// Свежие события показываем по-своему («12 мин назад»), всё остальное — общей функцией
// почты: местная копия давала «17 сент., 14:03» там, где весь интерфейс пишет
// «17 сентября, 14:03».
function when(iso, long = false) {
    if (!iso) return '';
    const diff = (Date.now() - new Date(iso)) / 60000;
    if (!long && diff >= 0) {
        if (diff < 1) return 'сейчас';
        if (diff < 60) return Math.round(diff) + ' мин назад';
        if (diff < 1440) return Math.round(diff / 60) + ' ч назад';
    }
    return whenCommon(iso, true);
}
const busy = ref(false);
let toastTimer = null;

const custom = computed(() => folders.value.filter((f) => f.role === 'custom'));
/** Сколько папок лежит внутри этой — чтобы сказать об этом перед удалением. */
function childCount(f) {
    if (!f?.path) return 0;
    const sep = f.path.includes('/') ? '/' : '.';

    return folders.value.filter((x) => x.path !== f.path && x.path.startsWith(f.path + sep)).length;
}
const COLORS = ['#2F6FEB', '#16A05C', '#D9791F', '#C0392B', '#7B3FE4', '#0E8A8A', '#6B7787'];

/**
 * Спросить подтверждение своим диалогом. На одной странице были вперемешку
 * системное окно браузера (отзыв пароля, «Разложить Входящие») и собственный диалог
 * (папки и метки) — теперь спрашиваем везде одинаково.
 */
const confirmBox = ref(null);
function ask(title, text = '', confirmLabel = 'Продолжить', danger = false) {
    return new Promise((resolve) => { confirmBox.value = { title, text, confirmLabel, danger, resolve }; });
}
function closeAsk(ok) {
    const b = confirmBox.value;
    confirmBox.value = null;
    if (b) b.resolve(ok);
}

function say(text, error = false) {
    clearTimeout(toastTimer);
    toast.value = { text, error };
    // Ошибку не прячем по таймеру: длинное сообщение исчезало раньше, чем его дочитывали,
    // и вернуть его было нечем. Закрывает человек — крестиком.
    if (!error) toastTimer = setTimeout(() => { toast.value = null; }, 3000);
}

// Безопасность живёт в своём композабле: двухфакторная защита, пароли для почтовых
// программ и сеансы — единственная часть настроек, где ошибка стоит дорого.
const {
    sec, secError, twofa, twofaCode, twofaPassword, newAppPassword, createdPassword,
    loadSecurity, copyPassword, startTwofa, enableTwofa, disableTwofa,
    createAppPassword, revokeAppPassword, kickSession, kickOthers, setRemember,
} = useSecuritySettings({ busy, say, ask, force2fa: props.force2fa });
// Открыли сразу «Безопасность» — читаем её данные, не дожидаясь щелчка по разделу.
if (props.section === 'security') loadSecurity();

// «Мои файлы»: большие вложения, ушедшие ссылкой через своё хранилище.
const cloudFiles = ref(null);
const cloudFilesError = ref('');
const fmtDay = (d) => (d ? new Date(d + 'T00:00:00').toLocaleDateString('ru-RU') : '');
async function loadFiles() {
    cloudFilesError.value = '';
    try { cloudFiles.value = await api.files(); } catch (e) { cloudFilesError.value = e.message; }
}
async function renewFile(f) {
    try { Object.assign(f, await api.fileRenew(f.token)); say('Ссылка продлена до ' + fmtDay(f.expires)); } catch (e) { say(e.message, true); }
}
async function deleteFile(f) {
    if (!await ask('Удалить файл?', '«' + f.name + '» исчезнет с сервера, ссылка из письма перестанет работать.', 'Удалить', true)) return;
    try {
        const r = await api.fileDelete(f.token);
        cloudFiles.value.files = cloudFiles.value.files.filter((x) => x.token !== f.token);
        cloudFiles.value.used = r.used;
        say('Файл удалён');
    } catch (e) { say(e.message, true); }
}
async function copyLink(f) {
    try { await navigator.clipboard.writeText(f.url); say('Ссылка скопирована'); } catch { say('Скопируйте вручную: ' + f.url, true); }
}
if (props.section === 'files') loadFiles();


/**
 * 196: поля, которые ждут кнопки «Сохранить». Раздел настроек — обычная ссылка,
 * и набранное имя отправителя, подпись или быстрые ответы пропадали молча.
 * Переключатели, которые сохраняются сразу (тема, клавиши, уведомления), сюда не входят.
 */
function snap() {
    return JSON.stringify([s.value.display_name, s.value.signature, quickText.value, s.value.undo_seconds,
        s.value.preview, s.value.show_images, s.value.unread_highlight, s.value.unread_color,
        s.value.reply_all, s.value.ask_rule_on_move, s.value.row_actions, s.value.after_remove, s.value.open_first, autoreply.value]);
}
const clean = ref(snap());
const dirty = computed(() => snap() !== clean.value || !!editing.value);
let leaving = false;
let offBefore = null;
function warnLeave(e) { if (dirty.value) { e.preventDefault(); e.returnValue = ''; } }
onMounted(() => {
    window.addEventListener('beforeunload', warnLeave);
    offBefore = router.on('before', (ev) => {
        const v = ev.detail?.visit;
        if (!dirty.value || leaving || !v || (v.method || 'get').toLowerCase() !== 'get') return;
        const url = String(v.url || '');
        ask('Уйти без сохранения?', 'В этом разделе есть несохранённые изменения — они пропадут.', 'Уйти', true)
            .then((ok) => { if (ok) { leaving = true; router.visit(url); } });
        return false;
    });
});
onBeforeUnmount(() => { window.removeEventListener('beforeunload', warnLeave); if (offBefore) offBefore(); });

async function saveSettings(patch) {
    busy.value = true;
    try {
        const r = await api.saveSettings(patch);
        Object.assign(s.value, r);
        clean.value = snap();
        say('Сохранено');
    } catch (e) { say(e.message, true); } finally { busy.value = false; }
}
/**
 * 197, 198, 199: тема, горячие клавиши и уведомления применяются сразу же, поэтому
 * и на сервер уходят сразу. Раньше тема оставалась тёмной в этом браузере и светлой
 * на телефоне, а один и тот же переключатель клавиш в двух разделах вёл себя по-разному.
 * Ответ сервера здесь не раскладываем по форме — иначе затрёт то, что человек набрал.
 */
async function saveOne(patch, text = 'Сохранено') {
    busy.value = true;
    try { await api.saveSettings(patch); say(text); } catch (e) { say(e.message, true); } finally { busy.value = false; }
}

function saveGeneral() {
    if (quickOver.value) { say(`Быстрых ответов не больше ${MAX_QUICK} — уберите лишние ${quickOver.value}`, true); return; }
    saveSettings({
        display_name: s.value.display_name, reply_all: s.value.reply_all, notify_browser: !!s.value.notify_browser, ask_rule_on_move: !!s.value.ask_rule_on_move, row_actions: !!s.value.row_actions, after_remove: s.value.after_remove || 'list', open_first: !!s.value.open_first, undo_seconds: Number(s.value.undo_seconds),
        preview: s.value.preview, shortcuts: s.value.shortcuts, theme: s.value.theme, scheme: s.value.scheme, show_images: s.value.show_images, unread_highlight: !!s.value.unread_highlight, unread_color: s.value.unread_color || '',
        quick_replies: quickReplies.value,
    });
}
// 200: сервер хранит не больше восьми быстрых ответов — раньше лишние строки
// молча исчезали при сохранении, теперь про предел написано рядом с полем.
const MAX_QUICK = 8;
const quickReplies = computed(() => quickText.value.split('\n').map((x) => x.trim()).filter(Boolean));
const quickOver = computed(() => Math.max(0, quickReplies.value.length - MAX_QUICK));

// Правила и автоответ живут в своём композабле: там же и ограничения сервера,
// продублированные на клиенте, и проверки, выведенные из живых жалоб.
const {
    applying, FIELDS, OPS, ACTIONS, MAX_RULES, MAX_CONDITIONS, MAX_ACTIONS, MAX_REFINE,
    applyRules, opsFor, onFieldChange, newRule, addRefine, describeCond, describeAct, ruleName,
    saveRule, removeRule, moveRule, pushRules, saveAutoreply,
} = useMailRules({ rules, autoreply, editing, folders, labels, busy, say, ask });
// Пришли из «Папок» по кнопке «Правило»: открываем новое правило с уже выбранной папкой,
// человеку остаётся вписать отправителя.
if (props.section === 'rules') {
    const want = new URLSearchParams(window.location.search).get('folder');
    if (want && folders.value.some((f) => f.path === want)) {
        newRule();
        if (editing.value) {
            editing.value.actions = [{ type: 'move', value: want }];
            const name = folders.value.find((f) => f.path === want)?.name || '';
            editing.value.name = name ? 'В папку «' + name + '»' : '';
        }
    }
}

// ── Папки и метки ────────────────────────────────────────────
async function confirmDialog(value) {
    const d = dialog.value; dialog.value = null;
    try {
        if (d.kind === 'newFolder') folders.value = (await api.createFolder(value, d.parent || null)).folders;
        if (d.kind === 'renameFolder') { const r = await api.renameFolder(d.folder.path, value); folders.value = r.folders; if (r.rules) say(`Папка переименована, ${plural(r.rules, 'правило', 'правила', 'правил')} перенастроено на новое имя`); }
        if (d.kind === 'deleteFolder') { const r = await api.deleteFolder(d.folder.path); folders.value = r.folders; if (r.rules) say(`Папка удалена, ${plural(r.rules, 'правило', 'правила', 'правил')}, которые клали в неё письма, выключено — см. «Правила»`); }
        if (d.kind === 'label') labels.value = await api.createLabel(value, d.color || COLORS[0]);
        if (d.kind === 'renameLabel') labels.value = await api.updateLabel(d.label.id, value, d.label.color);
        if (d.kind === 'deleteLabel') labels.value = await api.deleteLabel(d.label.id);
        say('Готово');
    } catch (e) { say(e.message, true); }
}
async function recolor(l, color) {
    try { labels.value = await api.updateLabel(l.id, l.name, color); } catch (e) { say(e.message, true); }
}

// ── Уведомления браузера ─────────────────────────────────────
const notifyState = ref(window.pochta ? 'В приложении «Почта» для Windows уведомления включаются и выключаются в меню значка в трее — эта настройка для браузера' : typeof Notification === 'undefined' ? 'Этот браузер не поддерживает уведомления' : Notification.permission === 'denied' ? 'Уведомления запрещены в настройках браузера для этого сайта' : '');
async function askNotify(e) {
    if (typeof Notification === 'undefined') { s.value.notify_browser = false; return; }
    if (!e.target.checked) { notifyState.value = ''; await saveOne({ notify_browser: false }, 'Уведомления выключены'); pushSync(false); return; }
    // Разрешение спрашиваем сразу — значит и настройку сохраняем сразу: раньше подпись
    // уверяла «Разрешено, придёт при новом письме», а на сервере флаг оставался выключенным,
    // пока человек не нажмёт «Сохранить».
    const p = await Notification.requestPermission();
    if (p === 'granted') {
        notifyState.value = 'Разрешено — придёт при новом письме, даже если вкладка не активна';
        await saveOne({ notify_browser: true }, 'Уведомления включены');
        // Push: уведомление приходит и когда почта закрыта — в браузере на компьютере и в приложении на телефоне.
        const r = await pushSync(true);
        notifyState.value = r === 'ok' ? 'Разрешено — уведомления придут и при закрытой почте, на этом устройстве'
            : r === 'ios-not-installed' ? 'Разрешено для вкладки. На iPhone уведомления при закрытой почте работают, если добавить её на экран «Домой» (Поделиться → На экран «Домой») и включить уведомления там'
            : r === 'unsupported' ? 'Разрешено для вкладки; уведомления при закрытой почте этот браузер или сервер не поддерживает'
            : notifyState.value;
    } else {
        notifyState.value = p === 'denied' ? 'Уведомления запрещены в настройках браузера для этого сайта' : 'Браузер не дал разрешение';
        s.value.notify_browser = false;
    }
}

// Тот же список, что в подсказке по «?» и в справке: раньше все три расходились,
// а «j / k» рисовалось тремя клавишами, включая несуществующую «/».
const shortcuts = [
    ['Навигация', [['j', 'следующее письмо'], ['k', 'предыдущее письмо'], ['Enter', 'открыть'], ['o', 'открыть'], ['u', 'к списку'],
        ['g i', 'Входящие'], ['g s', 'Отправленные'], ['g d', 'Черновики'], ['g a', 'Архив'], ['g t', 'Корзина'], ['/', 'поиск']]],
    ['Письмо', [['r', 'ответить'], ['a', 'ответить всем'], ['f', 'переслать'], ['e', 'архив'], ['#', 'удалить'], ['Delete', 'удалить'],
        ['s', 'флажок'], ['i', 'прочитано / нет'], ['z', 'отложить']]],
    ['Разбор', [['v', 'в папку'], ['l', 'метка'], ['!', 'спам'], ['x', 'выбрать'], ['* a', 'выбрать все'], ['Esc', 'снять выбор']]],
    ['Написать', [['c', 'новое письмо'], ['Ctrl Enter', 'отправить'], ['Ctrl S', 'черновик'], ['Ctrl B', 'жирный (I — курсив, U — подчёркнутый)'], ['Ctrl K', 'ссылка'], ['?', 'эта подсказка']]],
];
</script>

<template>
    <Head title="Настройки" />
    <MailLayout :user="user" :theme="s.theme" :scheme="s.scheme" :glass="s">
        <div class="mset">
            <div class="page-head" style="margin-bottom: 16px">
                <Link href="/mail" class="ib" title="К письмам"><Icon name="back" :size="18" /></Link>
                <h1>Настройки</h1>
                <span class="page-head__count">{{ user }}</span>
            </div>
            <div class="mset__grid">
                <nav class="card mset__menu">
                    <Link v-for="[key, label] in SECTIONS" :key="key" :href="'/mail/settings/' + key" :class="{ on: section === key }">{{ label }}</Link>
                    <Link href="/mail/feedback">Обращения</Link>
                    <Link href="/mail/help">Справка</Link>
                </nav>

                <div class="mset__body">
                    <!-- Общие -->
                    <template v-if="section === 'general'">
                        <form class="card mset__section" @submit.prevent="saveGeneral">
                            <h2>Общие</h2>
                            <div class="mset__cols">
                                <div class="field">
                                    <label for="mset-name">Имя отправителя</label>
                                    <input id="mset-name" v-model="s.display_name" class="input" :placeholder="directoryName || 'Как вас видят получатели'">
                                    <!-- Пустое поле не значит «без имени»: в письма уходит ФИО из справочника. Раньше это было не видно. -->
                                    <span v-if="!(s.display_name || '').trim()" class="hint" style="margin: 4px 0 0">
                                        <template v-if="directoryName">Получатели видят: <b>{{ directoryName }}</b> — из справочника сотрудников. Впишите своё, если нужно иначе.</template>
                                        <template v-else>Сейчас в письмах стоит только адрес — впишите имя.</template>
                                    </span>
                                </div>
                                <!-- 201: адрес выглядел полем ввода, хотя менять его может только администратор. -->
                                <div class="field"><label>Адрес</label><div class="field__row" style="align-items: baseline; gap: 10px; min-height: 38px"><b class="mono">{{ user }}</b><span class="hint" style="margin: 0">меняет администратор</span></div></div>
                                <div class="field">
                                    <label for="set-theme">Тема оформления</label>
                                    <!-- 197: тема применяется сразу, значит и сохраняется сразу — иначе в этом
                                         браузере темно, а на телефоне светло. -->
                                    <select id="set-theme" v-model="s.theme" class="input" @change="saveOne({ theme: s.theme })"><option value="light">Светлая</option><option value="dark">Тёмная</option><option value="system">Как в системе</option></select>
                                    <span class="hint" style="margin: 0">Применяется и сохраняется сразу</span>
                                </div>
                                <div class="field">
                                    <label for="set-scheme">Цветовая схема</label>
                                    <select id="set-scheme" v-model="s.scheme" class="input" @change="saveOne({ scheme: s.scheme })"><option value="brand">Фирменная — оранжевая</option><option value="classic">Классическая — синяя</option><option value="glass">Стекло — палитры и эффекты (обращение №55)</option></select>
                                    <!-- Схема «Стекло»: настройки по паспорту RECS. Меняются сразу (MailLayout следит) и сохраняются по одной. -->
                                    <div v-if="s.scheme === 'glass'" class="glass-opts">
                                        <p class="hint" style="margin: 0">Стекло, блик и анимации считает ваш браузер. На слабом компьютере выберите «Спокойное» или «Без анимации» движение; системные настройки «меньше движения» и «меньше прозрачности» действуют сами.</p>
                                        <div class="glass-pal" role="radiogroup" aria-label="Палитра">
                                            <button v-for="p in GLASS_PALETTES" :key="p.id" type="button" class="glass-pal__item" :class="{ 'glass-pal__item--on': s.glass_palette === p.id }" role="radio" :aria-checked="s.glass_palette === p.id" :style="{ '--a': p.tokens.base, '--b': p.tokens.surface, '--c': p.tokens.blue }" @click="s.glass_palette = p.id; saveOne({ glass_palette: p.id })"><i /><span>{{ p.name }}<small>{{ p.kind }}{{ p.dark ? ' · тёмная' : '' }}</small></span></button>
                                        </div>
                                        <div class="glass-opts__row"><label for="set-glass-motion">Движение</label><select id="set-glass-motion" v-model="s.glass_motion" class="input" style="max-width: 240px" @change="saveOne({ glass_motion: s.glass_motion })"><option v-for="(t, k) in GLASS_MOTIONS" :key="k" :value="k">{{ t }}</option></select></div>
                                        <div class="glass-opts__row"><label for="set-glass-wp">Фоновый рисунок</label><select id="set-glass-wp" v-model="s.glass_wallpaper" class="input" style="max-width: 240px" @change="saveOne({ glass_wallpaper: s.glass_wallpaper })"><option value="auto">Как у палитры</option><option value="none">Без рисунка</option><option v-for="(w, k) in GLASS_WALLPAPERS" :key="k" :value="k">{{ w.name }}</option></select></div>
                                        <div class="glass-opts__row"><label for="set-glass-wps">Сила рисунка: {{ s.glass_wallpaper_strength }}%</label><input id="set-glass-wps" v-model.number="s.glass_wallpaper_strength" type="range" min="0" max="30" @change="saveOne({ glass_wallpaper_strength: Number(s.glass_wallpaper_strength) })"></div>
                                        <div class="glass-opts__row"><label for="set-glass-den">Плотность стекла: {{ s.glass_density }}%</label><input id="set-glass-den" v-model.number="s.glass_density" type="range" min="68" max="95" @change="saveOne({ glass_density: Number(s.glass_density) })"></div>
                                        <p class="hint" style="margin: 0">Список писем и текст письма остаются непрозрачными, стекло только в обрамлении. Выпадающие списки пока системные.</p>
                                    </div>
                                    <span class="hint" style="margin: 0">У каждой схемы свои светлая и тёмная темы; настройка общая для веб-почты и приложения</span>
                                </div>
                                <div class="field">
                                    <label for="set-density">Плотность списка писем</label>
                                    <!-- Применяется сразу, как и тема: человек выбирает глазами. -->
                                    <select id="set-density" v-model="s.density" class="input" @change="saveOne({ density: s.density })"><option value="roomy">Просторная</option><option value="normal">Обычная</option><option value="compact">Плотная — без первых строк письма</option></select>
                                    <span class="hint" style="margin: 0">Применяется и сохраняется сразу</span>
                                </div>
                                <div class="field">
                                    <label>Отмена отправки</label>
                                    <select v-model="s.undo_seconds" class="input" aria-label="Окно отмены отправки"><option :value="0">Выключена</option><option :value="5">5 секунд</option><option :value="10">10 секунд</option><option :value="20">20 секунд</option><option :value="30">30 секунд</option></select>
                                </div>
                                <div class="field">
                                    <label>Картинки из интернета в письмах</label>
                                    <select v-model="s.show_images" class="input" aria-label="Картинки из интернета в письмах"><option value="ask">Показывать по кнопке</option><option value="always">Показывать всегда</option></select>
                                </div>
                            </div>
                            <label class="toggle"><input v-model="s.unread_highlight" type="checkbox"><span class="toggle__track" />Подсвечивать непрочитанные цветом: полоска слева и тема</label>
                            <div v-if="s.unread_highlight" class="field__row" style="align-items: center; gap: 10px; padding-left: 44px">
                                <span class="hint" style="margin: 0">Цвет подсветки</span>
<!-- Без своего цвета тема непрочитанного — цветом текста, только жирным (гамма «А»);
                                     цветной остаётся лишь полоска слева. -->
                                <input type="color" :value="s.unread_color || (s.scheme === 'classic' ? '#2F6FEB' : '#C94E00')" aria-label="Цвет непрочитанных писем" title="Цвет непрочитанных писем" style="width: 44px; height: 30px; padding: 2px; border: 1px solid var(--border); border-radius: 8px; background: var(--surface); cursor: pointer" @input="s.unread_color = $event.target.value">
                                <span class="hint" style="margin: 0"><span :style="{ display: 'inline-block', width: '3px', height: '14px', verticalAlign: 'middle', marginRight: '8px', background: s.unread_color || 'var(--accent)' }" /><b :style="{ color: s.unread_color || 'var(--text)' }">Так будет выглядеть тема непрочитанного</b></span>
                                <button v-if="s.unread_color" class="btn btn--sm" type="button" @click="s.unread_color = ''">Как в теме</button>
                            </div>
                            <!-- 198: тот же переключатель в разделе «Горячие клавиши» сохранялся сразу,
                                 а здесь ждал кнопки «Сохранить». Теперь одинаково. -->
                            <!-- Простой вид: меньше кнопок на панели письма и в меню правой кнопки (mail/uiMode). -->
                            <label class="toggle" title="Меньше кнопок на панели письма и в меню правой кнопки; галочки у писем — при наведении"><input :checked="uiSimple" type="checkbox" @change="setUiSimple($event.target.checked)"><span class="toggle__track" />Простой вид <span class="hint" style="margin: 0">— меньше кнопок, редкое убрано в «Ещё»; сохраняется сразу</span></label>
                            <label class="toggle"><input v-model="s.shortcuts" type="checkbox" @change="saveOne({ shortcuts: s.shortcuts })"><span class="toggle__track" />Горячие клавиши <span class="hint" style="margin: 0">— сохраняется сразу</span></label>
                            <label class="toggle"><input v-model="s.reply_all" type="checkbox"><span class="toggle__track" />По умолчанию отвечать всем</label>
                            <!-- Настройки «отмечать ли прочитанным в общих папках» больше нет: с 29.09.2026 флаг там у каждого свой (Dovecot INDEXPVT). -->
                            <label class="toggle"><input v-model="s.ask_rule_on_move" type="checkbox"><span class="toggle__track" />При переносе письма из «Входящих» в папку предлагать правило для отправителя</label>
                            <!-- Обращение №62: разбирать почту подряд, как в Яндексе. По умолчанию — как было. -->
                            <div class="field" style="max-width: 460px">
                                <label for="set-after-remove">После удаления, переноса или «Спама» открытого письма</label>
                                <select id="set-after-remove" v-model="s.after_remove" class="input">
                                    <option value="list">Показывать список</option>
                                    <option value="next">Открывать следующее письмо (ниже в списке)</option>
                                    <option value="prev">Открывать предыдущее письмо (выше в списке)</option>
                                </select>
                            </div>
                            <label class="toggle" title="При входе в почту и при переходе в папку справа сразу открывается первое письмо. Только на компьютере; письмо не помечается прочитанным, пока его не откроют щелчком."><input v-model="s.open_first" type="checkbox"><span class="toggle__track" />Сразу открывать первое письмо папки <span class="hint" style="margin: 0">— на компьютере; прочитанным не помечается</span></label>
                            <!-- Обращение №54: панель при наведении, как в Outlook. Только по желанию: у всех она была и мешала. -->
                            <label class="toggle" title="При наведении мышью на письмо справа появляются кнопки: архив, удалить, флажок, прочитано. Появляются с задержкой, дату не закрывают; удаление — с «Отменить» или с подтверждением, как обычно. На телефоне не показываются."><input v-model="s.row_actions" type="checkbox"><span class="toggle__track" />Кнопки действий при наведении на письмо в списке <span class="hint" style="margin: 0">— архив, удалить, флажок, прочитано</span></label>
                            <label class="toggle"><input v-model="s.notify_browser" type="checkbox" @change="askNotify"><span class="toggle__track" />Уведомления браузера о новых письмах и напоминаниях <span class="hint" style="margin: 0">— сохраняется сразу</span></label>
                            <p v-if="notifyState" class="hint" style="margin: 0">{{ notifyState }}</p>
                            <div class="field">
                                <label for="set-quick">Быстрые ответы (каждый с новой строки, не больше {{ MAX_QUICK }})</label>
                                <textarea id="set-quick" v-model="quickText" class="input" rows="4" />
                                <span class="hint" style="margin: 0" :style="quickOver ? { color: 'var(--no)' } : null">{{ quickOver ? `Лишних строк: ${quickOver} — сохранятся только первые ${MAX_QUICK}, уберите лишние` : `Сейчас ${quickReplies.length} из ${MAX_QUICK}` }}</span>
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

                    <!-- Телефон и программы: та же страница, что доступна с экрана входа -->
                    <template v-if="section === 'devices'">
                        <div class="card mset__section">
                            <h2>Телефон и программы</h2>
                            <p class="hint" style="margin-top: 0">Почта, календарь и контакты на iPhone/Android, а также Outlook и другие программы. На отдельной странице — готовый профиль для iPhone с QR-кодом, сертификат сервера и параметры для ручной настройки.</p>
                            <p><a class="btn btn--primary" href="/mail/setup"><Icon name="mobile" :size="16" /> Открыть страницу подключения</a></p>
                            <!-- 202, 203: те же параметры были ещё и в «Безопасности», причём с другими
                                 хостами и другим портом SMTP, а для чужого домена показывали mail.innotec.su.
                                 Теперь они одни на всё приложение и приходят с сервера. -->
                            <div class="mset__cols">
                                <div class="kv"><span>Входящие (IMAP)</span><b class="mono">{{ hosts.imap }} : {{ hosts.imapPort }}, SSL/TLS</b></div>
                                <div class="kv"><span>Исходящие (SMTP)</span><b class="mono">{{ hosts.smtp }} : {{ hosts.smtpPort }}, SSL/TLS</b></div>
                                <div class="kv"><span>Календарь и контакты</span><b class="mono">{{ hosts.dav }}</b></div>
                                <div class="kv"><span>Логин</span><b class="mono">{{ user }}</b></div>
                            </div>
                            <p class="hint" style="margin: 0">Outlook, Thunderbird и Android находят настройки почты сами по адресу. iPhone/iPad/Mac: <a :href="hosts.mobileconfig || `/mail/apple.mobileconfig?email=${encodeURIComponent(user)}`">установить профиль</a> — почта, контакты и календарь одним файлом. Outlook’у для контактов и календаря нужно бесплатное дополнение <a href="https://caldavsynchronizer.org/" target="_blank" rel="noopener">Outlook CalDav Synchronizer</a> с адресом выше, Android — приложение DAVx⁵. Пароль — от почты или пароль приложения.</p>
                        </div>
                    </template>

                    <!-- Подпись -->
                    <template v-if="section === 'signature'">
                        <div class="card mset__section">
                            <h2>Подпись</h2>
                            <div class="editor-box"><Editor v-model="s.signature" placeholder="Имя, должность, телефон…" :expanded="true" /></div>
                            <label class="toggle"><input v-model="s.signature_reply" type="checkbox"><span class="toggle__track" />Добавлять подпись в ответах и пересылках</label>
                            <div><button class="btn btn--primary" type="button" :disabled="busy" @click="saveSettings({ signature: s.signature, signature_reply: s.signature_reply })">Сохранить</button></div>
                        </div>
                    </template>

                    <!-- Автоответ -->
                    <template v-if="section === 'autoreply'">
                        <form class="card mset__section" @submit.prevent="saveAutoreply">
                            <h2>Автоответ <span class="grow" /><label class="toggle"><input v-model="autoreply.enabled" type="checkbox"><span class="toggle__track" />{{ autoreply.enabled ? 'Включён' : 'Выключен' }}</label></h2>
                            <div class="mset__cols">
                                <div class="field"><label for="ar-from">С (необязательно)</label><input id="ar-from" v-model="autoreply.from" class="input" type="date"></div>
                                <div class="field"><label for="ar-to">По (необязательно)</label><input id="ar-to" v-model="autoreply.to" class="input" type="date"></div>
                            </div>
                            <div class="field"><label for="ar-subject">Тема</label><input id="ar-subject" v-model="autoreply.subject" class="input" required></div>
                            <div class="field"><label for="ar-body">Текст</label><textarea id="ar-body" v-model="autoreply.body" class="input" rows="5" required /></div>
                            <div class="field" style="max-width: 260px"><label>Отвечать одному адресу не чаще, чем раз в</label>
                                <select v-model="autoreply.days" class="input" aria-label="Отвечать одному адресу не чаще, чем раз в"><option :value="1">день</option><option :value="3">3 дня</option><option :value="7">неделю</option></select></div>
                            <div><button class="btn btn--primary" type="submit" :disabled="busy">Сохранить</button></div>
                            <p class="hint" style="margin: 0">Автоответ работает на сервере: отвечает и когда веб-почта закрыта. Рассылкам и спаму сервер не отвечает.</p>
                        </form>
                    </template>

                    <!-- Правила -->
                    <template v-if="section === 'rules'">
                        <div class="card mset__section">
                            <h2>Правила <span class="chip chip--off">{{ rules.length }}</span>
                                <span class="seg seg--sm" style="margin-left: 12px"><button type="button" class="seg__item" :class="{ 'seg__item--on': rulesTab === 'builder' }" @click="rulesTab = 'builder'">Конструктор</button><button type="button" class="seg__item" :class="{ 'seg__item--on': rulesTab === 'custom' }" @click="rulesTab = 'custom'">Свой скрипт<span v-if="sieveCustom.trim()" class="seg__count">✓</span></button></span>
                                <span class="grow" /><template v-if="rulesTab === 'builder'"><button v-if="rules.length" class="btn btn--sm" type="button" :disabled="applying" title="Прогнать правила по письмам, которые уже во «Входящих»" @click="applyRules"><Icon :name="applying ? 'refresh' : 'move'" :size="14" />{{ applying ? 'Раскладываю…' : 'Разложить Входящие' }}</button><button class="btn btn--sm btn--primary" type="button" @click="newRule"><Icon name="plus" :size="14" />Новое правило</button></template></h2>
                            <!-- Свой скрипт Sieve: для тех, кому конструктора мало. Проверяется Dovecot до сохранения. -->
                            <template v-if="rulesTab === 'custom'">
                                <p class="hint" style="margin: 0 0 8px">Скрипт на языке Sieve (RFC 5228, Dovecot Pigeonhole) выполняется после правил конструктора. Строки <code>require</code> можно писать как обычно — они сольются с нашими. Что искать: «sieve fileinto», «sieve variables», «dovecot pigeonhole».</p>
                                <textarea v-model="sieveCustom" class="input mono" rows="14" spellcheck="false" placeholder='require ["fileinto", "variables"];
if address :domain :is "from" "example.ru" {
  if address :localpart :matches "from" "*" { set "who" "${1}"; }
  fileinto :create "Пример/${who}";
  stop;
}' @input="sieveCheck = null" />
                                <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-top: 8px">
                                    <button class="btn btn--primary" type="button" :disabled="busy" @click="saveCustom">Сохранить</button>
                                    <button class="btn" type="button" :disabled="busy" @click="checkCustom">Проверить</button>
                                    <span v-if="sieveCheck" :class="sieveCheck.ok ? 'hint' : 'error'" style="margin: 0; color: var(--ok-ink)" :style="sieveCheck.ok ? '' : 'color: var(--no)'">{{ sieveCheck.ok ? '✓ скрипт разобран сервером, ошибок нет' : sieveCheck.error }}</span>
                                </div>
                                <p v-if="sieveCheck && !sieveCheck.ok && sieveCheck.script" class="hint" style="margin: 8px 0 0">Строки в ошибке — по общему скрипту (правила конструктора идут первыми). <button type="button" class="linklike" @click="sieveCheck = { ...sieveCheck, showAll: !sieveCheck.showAll }">{{ sieveCheck.showAll ? 'скрыть общий скрипт' : 'показать общий скрипт' }}</button></p>
                                <pre v-if="sieveCheck && sieveCheck.showAll" class="mono" style="font-size: 12px; white-space: pre-wrap; background: var(--surface-2); padding: 10px 12px; border-radius: 8px; max-height: 320px; overflow: auto">{{ sieveCheck.script }}</pre>
                            </template>
                            <div v-if="rulesTab === 'builder' && !rules.length" class="empty">Правил пока нет. Например: письма от бухгалтерии — в папку «Счета» и с меткой «Срочно».</div>
                            <div v-for="(r, i) in rules" v-show="rulesTab === 'builder'" :key="r.id" class="rule">
                                <label class="toggle"><input v-model="r.enabled" type="checkbox" @change="pushRules"><span class="toggle__track" /></label>
                                <div><small>Если</small>{{ r.conditions?.length ? r.conditions.map(describeCond).join(r.match === 'any' ? ' или ' : ' и ') : 'любое письмо' }}</div>
                                <div><small>То</small>{{ r.actions.map(describeAct).join(', ') }}{{ r.stop ? ', остановить' : '' }}<span v-if="r.refine?.length" class="chip chip--acc" style="margin-left: 8px" :title="r.refine.map((x, k) => `${k + 1}. ${(x.conditions || []).map(describeCond).join(x.match === 'any' ? ' или ' : ' и ') || 'иначе'} → ${(x.actions || []).map(describeAct).join(', ')}`).join('\n')">{{ r.refine.length }} {{ plural(r.refine.length, 'уточнение', 'уточнения', 'уточнений') }}</span></div>
                                <div style="display: flex; gap: 2px">
                                    <button class="ib ib--sm" type="button" title="Выше" :disabled="i === 0" @click="moveRule(i, -1)" aria-label="Выше"><Icon name="up" :size="14" /></button>
                                    <button class="ib ib--sm" type="button" title="Ниже" :disabled="i === rules.length - 1" @click="moveRule(i, 1)" aria-label="Ниже"><Icon name="down" :size="14" /></button>
                                    <button class="ib ib--sm" type="button" title="Изменить" @click="editing = { refine: [], ...JSON.parse(JSON.stringify(r)) }" aria-label="Изменить"><Icon name="edit" :size="14" /></button>
                                    <button class="ib ib--sm ib--danger" type="button" title="Удалить" @click="removeRule(r.id)" aria-label="Удалить"><Icon name="trash" :size="14" /></button>
                                </div>
                            </div>
                            <p v-if="rulesTab === 'builder'" class="hint" style="margin: 0">Правила выполняются на сервере по порядку — работают и для телефона, и для почтовой программы. «Разложить Входящие» применяет их к уже полученным письмам (условия по отправителю, получателю и теме; действия — папка, метка, флажок, прочитано, удалить).</p>
                        </div>

                        <form v-if="editing" class="card mset__section" @submit.prevent="saveRule">
                            <h2>{{ rules.some((x) => x.id === editing.id) ? 'Правило' : 'Новое правило' }}</h2>
                            <div class="field"><label>Название (необязательно)</label><input v-model="editing.name" class="input" placeholder="Счета от Сибстроя"></div>
                            <RuleParts :part="editing" :fields="FIELDS" :ops="OPS" :actions="ACTIONS" :folders="folders" :labels="labels" :ops-for="opsFor" :on-field-change="onFieldChange" :max-conditions="MAX_CONDITIONS" :max-actions="MAX_ACTIONS" />
                            <!-- Уточнения (просьба Носкова): вместо 20–30 правил на одну компанию — одно с ветками внутри.
                                 Проверяются по порядку раньше основных действий; подошло — выполняется только оно. -->
                            <div class="field">
                                <label>Уточнения <span class="hint" style="margin: 0; font-weight: 400">— для писем, подошедших под правило: проверяются по порядку, срабатывает первое подошедшее, остальным достаются действия выше</span></label>
                                <div v-for="(ref, ri) in (editing.refine || [])" :key="ri" class="rule__refine">
                                    <div class="rule__refine-hd"><span class="tag">уточнение {{ ri + 1 }}</span><span class="grow" /><button class="ib ib--sm" type="button" title="Убрать уточнение" aria-label="Убрать уточнение" @click="editing.refine.splice(ri, 1)"><Icon name="x" :size="14" /></button></div>
                                    <RuleParts :part="ref" compact :fields="FIELDS" :ops="OPS" :actions="ACTIONS" :folders="folders" :labels="labels" :ops-for="opsFor" :on-field-change="onFieldChange" :max-conditions="MAX_CONDITIONS" :max-actions="MAX_ACTIONS" />
                                </div>
                                <button v-if="(editing.refine || []).length < MAX_REFINE" type="button" class="linklike" style="font-size: 13px; align-self: start" @click="addRefine(editing)">+ уточнение</button>
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
                                    <!-- 191: «Входящие» и «Отправленные» показывались дважды — свои и общего
                                         ящика — без единого намёка, чьи именно. -->
                                    <span class="grow">{{ f.name }}<span v-if="f.owner" class="chip chip--off" style="margin-left: 8px">ящик {{ f.ownerName || f.owner }}</span> <span class="sub">· {{ f.total }} {{ plural(f.total, 'письмо', 'письма', 'писем') }}{{ f.unread ? ', ' + f.unread + ' не прочитано' : '' }}</span></span>
                                    <!-- Правило, которое кладёт письма в эту папку (обращение №38): раньше за ним
                                         надо было идти в «Правила» и искать папку в списке из десятков. -->
                                    <button v-if="canRule(f)" class="ib ib--sm" type="button" :title="'Правило: класть письма в «' + f.name + '»'" :aria-label="'Правило для папки ' + f.name" @click="ruleForFolder(f)"><Icon name="filter" :size="14" /></button>
                                    <!-- Вложенную папку можно создать и внутри системной: у людей структура живёт
                                         внутри «Входящих» (так было в Kerio), а новая папка ложилась рядом. -->
                                    <button v-if="f.role !== 'shared'" class="ib ib--sm" type="button" title="Вложенная папка" @click="dialog = { kind: 'newFolder', parent: f.path, parentName: f.name }" aria-label="Вложенная папка"><Icon name="plus" :size="14" /></button>
                                    <template v-if="f.role === 'custom'">
                                        <button class="ib ib--sm" type="button" title="Переименовать" @click="dialog = { kind: 'renameFolder', folder: f }" aria-label="Переименовать"><Icon name="edit" :size="14" /></button>
                                        <button class="ib ib--sm ib--danger" type="button" title="Удалить" @click="dialog = { kind: 'deleteFolder', folder: f }" aria-label="Удалить"><Icon name="trash" :size="14" /></button>
                                    </template>
                                    <span v-if="f.role !== 'custom'" class="chip chip--off">системная</span>
                                </div>
                            </div>
                        </div>
                        <div class="card mset__section">
                            <h2>Метки <span class="grow" /><button class="btn btn--sm btn--primary" type="button" @click="dialog = { kind: 'label' }"><Icon name="plus" :size="14" />Новая метка</button></h2>
                            <div class="mset__list">
                                <div v-for="l in labels" :key="l.id" class="mset__li">
                                    <span class="mnav__swatch mnav__swatch--round" :style="{ background: l.color }" />
                                    <span class="grow">{{ l.name }}</span>
                                    <div class="color-dots"><button v-for="c in COLORS" :key="c" type="button" :class="{ on: l.color === c }" :aria-label="'Цвет ' + c" :title="'Цвет ' + c" :style="{ background: c, width: '18px', height: '18px' }" @click="recolor(l, c)" /></div>
                                    <button class="ib ib--sm" type="button" title="Переименовать" @click="dialog = { kind: 'renameLabel', label: l }" aria-label="Переименовать"><Icon name="edit" :size="14" /></button>
                                    <button class="ib ib--sm ib--danger" type="button" title="Удалить" @click="dialog = { kind: 'deleteLabel', label: l }" aria-label="Удалить"><Icon name="trash" :size="14" /></button>
                                </div>
                                <div v-if="!labels.length" class="empty">Меток пока нет</div>
                            </div>
                            <p class="hint" style="margin: 0">Метки хранятся в самом ящике (ключевые слова IMAP), поэтому видны и в почтовых программах, которые их поддерживают.</p>
                        </div>
                    </template>

                    <!-- Безопасность -->
                    <template v-if="section === 'security'">
                        <!-- 205: до ответа сервера раздел рисовал пустые карточки и кнопку «Создать»
                             не показывал вовсе — выглядело как сломанный раздел. -->
                        <div v-if="!sec" class="card mset__section">
                            <template v-if="secError">
                                <p class="hint" style="margin: 0">Не удалось загрузить данные о защите: {{ secError }}</p>
                                <div><button class="btn" type="button" @click="loadSecurity">Повторить</button></div>
                            </template>
                            <p v-else class="hint" style="margin: 0">Загружаем данные о защите…</p>
                        </div>
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
                                <div class="field__row" style="align-items: center; gap: 10px; margin: 8px 0; flex-wrap: wrap">
                                    <span class="mono" style="font-size: 20px; letter-spacing: .1em">{{ createdPassword.plain }}</span>
                                    <button class="btn btn--sm btn--primary" type="button" @click="copyPassword">Скопировать</button>
                                    <button class="btn btn--sm" type="button" title="Убрать пароль с экрана" @click="createdPassword = null">Убрать с экрана</button>
                                </div>
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
                            <label v-if="sec && sec.rememberDays" class="mset__remember">
                                <input type="checkbox" :checked="sec.remember" @change="setRemember($event.target.checked)">
                                <span><b style="font-weight: 600">Не выходить на этом устройстве</b><span class="sub" style="display: block">{{ sec.rememberDays }} дней без повторного входа; срок продлевается, пока вы пользуетесь почтой. На чужом или общем компьютере не включайте.</span></span>
                            </label>
                            <h2>Где вы вошли <span class="grow" /><button v-if="sec && sec.sessions.some((s) => !s.me)" class="btn btn--sm" type="button" @click="kickOthers">Завершить все, кроме этого</button></h2>
                            <div class="mset__list">
                                <div v-for="s in (sec ? sec.sessions : [])" :key="s.id" class="mset__li">
                                    <Icon :name="s.kind === 'web' ? 'laptop' : 'phone'" :size="16" style="color: var(--faint)" />
                                    <div class="grow"><div>{{ s.device }}<span v-if="s.me" class="chip chip--acc" style="margin-left: 8px">это вы</span><span v-if="s.remembered" class="chip chip--ok" style="margin-left: 8px" title="Вход без пароля, пока не завершите">запомнено</span><span v-if="s.count > 1" class="chip chip--off" style="margin-left: 8px" title="Одинаковые сеансы с этого браузера и адреса; «Завершить» закроет все">{{ s.count }} {{ plural(s.count, 'сеанс', 'сеанса', 'сеансов') }}</span></div><div class="sub mono">{{ s.ip }}<template v-if="s.seen"> · {{ when(s.seen) }}</template></div></div>
                                    <button v-if="!s.me" class="btn btn--sm" type="button" @click="kickSession(s)">Завершить</button>
                                </div>
                            </div>
                            <div v-if="sec && sec.logins.length" class="grp" style="margin-top: 6px">Последние входы</div>
                            <div v-for="(l, i) in (sec ? sec.logins : [])" :key="i" class="kv"><span>{{ when(l.at, true) }} · {{ l.device }}</span><b style="font-weight: 500" :style="{ color: ['ok', 'new_device', 'remember'].includes(l.result) ? 'var(--ok)' : 'var(--no)' }">{{ { ok: 'вход', new_device: 'вход с нового устройства', remember: 'вход на запомненном устройстве', bad_password: 'неверный пароль', bad_code: 'неверный код', blocked: 'заблокировано' }[l.result] || l.result }} · <span class="mono">{{ l.ip }}</span></b></div>
                        </div>

                        <div class="card mset__section" style="max-width: 560px">
                            <h2>Пароль</h2>
                            <p class="hint" style="margin: 0">Пароль от почты выдаёт и меняет администратор. Если пароль стал известен кому-то ещё — сообщите администратору и завершите чужие сеансы выше.</p>
                        </div>
                        <!-- 202: параметры подключения были продублированы здесь с другими значениями;
                             оставляем ссылку на единственный раздел, где они живут. -->
                        <div class="card mset__section" style="max-width: 560px">
                            <h2>Почтовые программы и телефон<span class="grow" /><Link href="/mail/settings/devices" class="btn btn--sm"><Icon name="mobile" :size="14" />Параметры</Link></h2>
                            <p class="hint" style="margin: 0">Адреса серверов, профиль для iPhone и сертификат — в разделе «Телефон и программы». Для отдельного устройства создайте пароль приложения выше, чтобы не давать ему основной.</p>
                        </div>
                    </template>

                    <!-- Мои файлы -->
                    <template v-if="section === 'files'">
                        <div class="card mset__section">
                            <h2>Мои файлы <span class="grow" /><span v-if="cloudFiles && cloudFiles.enabled" class="hint">{{ size(cloudFiles.used) }} из {{ size(cloudFiles.quota) }}</span></h2>
                            <p class="hint" style="margin-top: 0">Большие вложения уходят из письма ссылкой и лежат здесь. Ссылка действует {{ cloudFiles ? cloudFiles.expireDays : 30 }} дн., файл остаётся — продлить можно в любой момент. Удалённый файл по ссылке больше не откроется.</p>
                            <p v-if="cloudFilesError" class="hint">Не удалось загрузить: {{ cloudFilesError }} <button class="btn btn--sm" type="button" @click="loadFiles">Повторить</button></p>
                            <p v-else-if="!cloudFiles" class="hint">Загружаем…</p>
                            <p v-else-if="!cloudFiles.enabled" class="hint">Хранилище файлов выключено администратором.</p>
                            <p v-else-if="!cloudFiles.files.length" class="hint">Пока ничего нет: файлы появятся здесь, когда вы отправите письмо с вложением крупнее порога.</p>
                            <div v-else class="mset__list">
                                <div v-for="f in cloudFiles.files" :key="f.token" class="mset__li">
                                    <Icon name="file" :size="16" style="color: var(--faint)" />
                                    <span class="grow"><a :href="f.url" target="_blank" rel="noopener">{{ f.name }}</a> <span class="sub">{{ size(f.size) }} · {{ f.expired ? 'срок истёк' : 'до ' + fmtDay(f.expires) }} · скачиваний: {{ f.downloads }}<template v-if="f.subject"> · письмо «{{ f.subject }}»</template></span></span>
                                    <button class="ib ib--sm" type="button" title="Скопировать ссылку" aria-label="Скопировать ссылку" @click="copyLink(f)"><Icon name="copy" :size="14" /></button>
                                    <button class="ib ib--sm" type="button" title="Продлить ссылку" aria-label="Продлить ссылку" @click="renewFile(f)"><Icon name="refresh" :size="14" /></button>
                                    <button class="ib ib--sm ib--danger" type="button" title="Удалить" aria-label="Удалить" @click="deleteFile(f)"><Icon name="trash" :size="14" /></button>
                                </div>
                            </div>
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

        <Dialog v-if="dialog && dialog.kind === 'newFolder'" :title="dialog.parentName ? 'Папка внутри «' + dialog.parentName + '»' : 'Новая папка'" :prompt="{ label: 'Название', placeholder: 'Например, Клиенты', maxlength: 80 }" confirm-label="Создать" @close="dialog = null" @confirm="confirmDialog" />
        <Dialog v-if="dialog && dialog.kind === 'renameFolder'" title="Переименовать папку" :prompt="{ label: 'Название', value: dialog.folder.name, maxlength: 80 }" confirm-label="Сохранить" @close="dialog = null" @confirm="confirmDialog" />
        <!-- 192, 193: диалог не называл ни число писем, ни вложенные папки, хотя точно такой же
             диалог в списке писем число показывает, и справка это обещает. -->
        <Dialog v-if="dialog && dialog.kind === 'deleteFolder'" :title="'Удалить папку «' + dialog.folder.name + '»?'" confirm-label="Удалить" danger @close="dialog = null" @confirm="confirmDialog">
            <p class="hint" style="margin: 0">Письма в ней ({{ dialog.folder.total || 0 }}) будут удалены навсегда.</p>
            <p v-if="childCount(dialog.folder)" class="hint" style="margin: 0; color: var(--no-ink)">Вместе с папкой удалятся вложенные: {{ childCount(dialog.folder) }} {{ plural(childCount(dialog.folder), 'папка', 'папки', 'папок') }} и все письма в них.</p>
        </Dialog>
        <Dialog v-if="dialog && dialog.kind === 'label'" title="Новая метка" :prompt="{ label: 'Название', maxlength: 80 }" confirm-label="Создать" @close="dialog = null" @confirm="confirmDialog">
            <div class="color-dots"><button v-for="c in COLORS" :key="c" type="button" :class="{ on: (dialog.color || COLORS[0]) === c }" :style="{ background: c }" @click="dialog.color = c" /></div>
        </Dialog>
        <Dialog v-if="dialog && dialog.kind === 'renameLabel'" title="Переименовать метку" :prompt="{ label: 'Название', value: dialog.label.name, maxlength: 80 }" confirm-label="Сохранить" @close="dialog = null" @confirm="confirmDialog" />
        <!-- 194: не было сказано, что с письмами ничего не случится. -->
        <Dialog v-if="dialog && dialog.kind === 'deleteLabel'" :title="'Удалить метку «' + dialog.label.name + '»?'" confirm-label="Удалить" danger @close="dialog = null" @confirm="confirmDialog">
            <p class="hint" style="margin: 0">Метка снимется со всех писем. Сами письма останутся на месте — удаляется только пометка.</p>
        </Dialog>
        <Dialog v-if="confirmBox" :title="confirmBox.title" :confirm-label="confirmBox.confirmLabel" :danger="confirmBox.danger" @close="closeAsk(false)" @confirm="closeAsk(true)">
            <p v-if="confirmBox.text" class="hint" style="margin: 0">{{ confirmBox.text }}</p>
        </Dialog>
        <Toast :toast="toast" @close="toast = null" />
    </MailLayout>
</template>
