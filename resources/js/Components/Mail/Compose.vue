<script setup>
// Форма «Написать»: адресаты, тема, редактор, вложения, отправить позже, напоминание, черновик.
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import Icon from '../Icon.vue';
import { track } from '../../mail/track';
import CloudPicker from './CloudPicker.vue';
import RecipientInput from './RecipientInput.vue';
import Editor from './Editor.vue';
import Popover from './Popover.vue';
import AttachmentViewer from './AttachmentViewer.vue';
import { viewable, viewerItems, localViewable, localViewerItems } from '../../mail/attachments';
import { api, composeForm } from '../../mail/api';
import { addrString, presets, size, toLocalInput, when } from '../../mail/format';
import { ask as confirmAsk } from '../../confirm';

const props = defineProps({
    compose: { type: Object, required: true },
    identities: { type: Array, default: () => [] },
    settings: { type: Object, default: () => ({}) },
    cloud: { type: Object, default: () => ({ enabled: false, thresholdMb: 10, maxMb: 50, personal: false }) },
    limits: { type: Object, default: () => ({ messageMb: 25, maxFiles: 20 }) },
    // Письмо открыто сейчас. Свёрнутое во вкладку («Письма в работе») не закрывается, а прячется:
    // так сохраняются текст, курсор и приложенные файлы.
    active: { type: Boolean, default: true },
});
const emit = defineEmits(['close', 'send', 'toast', 'draft', 'meta']);

const c = props.compose;
const to = ref(c.to || []);
const cc = ref(c.cc || []);
const bcc = ref(c.bcc || []);
const showCc = ref(cc.value.length > 0);
const showBcc = ref(bcc.value.length > 0);
const from = ref((c.from && props.identities.some((i) => i.mail === c.from) ? c.from : '') || props.identities[0]?.mail || '');
const subject = ref(c.subject || '');
const html = ref(c.html || '');
// Файлы могут прийти извне: при «Отменить» у отправленного письма и при повторе после ошибки.
// Раньше список всегда создавался пустым, и вложения молча пропадали.
const files = ref(Array.isArray(c.files) ? [...c.files] : []);
const existing = ref(c.attachments || []);
// Письма, приложенные целиком (обращение №39): их содержимое берёт сервер, заливать нечего.
const attachedMails = ref([...(c.attachMessages || [])]);
function dropMail(i) { attachedMails.value.splice(i, 1); dirty.value = true; }
// Файлы из облака сотрудника: здесь — карточки, как вложения; ссылки на них сервер вставит при отправке.
const cloudPicked = ref(Array.isArray(c.cloudFiles) ? c.cloudFiles.map((f) => ({ path: f.path, name: f.name, size: f.size || 0 })) : []);
function dropCloud(i) { cloudPicked.value.splice(i, 1); dirty.value = true; }
const keepAttachments = ref(c.keepAttachments ?? (c.mode === 'forward'));
const draftUid = ref(c.draftUid || null);
// Черновик при каждом сохранении перекладывается под новым UID. Вложения, унаследованные
// из черновика, читаются по этому UID — поэтому ссылку на «исходное письмо» держим свежей,
// иначе отправка падала с «Исходное письмо больше не в той папке».
const srcUid = computed(() => (c.mode === 'draft' ? (draftUid.value || c.sourceUid) : c.sourceUid));
const priority = ref(!!c.priority);
const receipt = ref(!!c.receipt);
const remindDays = ref(0);
const menu = ref(null); // 'later' | 'more' | 'remind'
const menuAt = ref({ x: 0, y: 0 });
const customAt = ref(toLocalInput(new Date(Date.now() + 3600000)));
const dirty = ref(false);
const status = ref('');
const drop = ref(false);
const editor = ref(null);
const toInput = ref(null);
const ccInput = ref(null);
const bccInput = ref(null);
/** Дописать в фишки то, что набрано, но ещё не подтверждено Enter. */
function flushRecipients() {
    toInput.value?.flush();
    ccInput.value?.flush();
    bccInput.value?.flush();
}
const fileInput = ref(null);
let autosave = null;
let saving = false;      // черновик уже сохраняется — второй запрос дал бы дубль
let inflight = Promise.resolve();   // идущее сохранение: «Удалить» после закрытия ждёт его, чтобы знать номер черновика
let closed = false;      // окно закрыто штатно, при размонтировании сохранять не нужно

// Просмотр вложений прямо из окна письма: унаследованные от пересылаемого письма — с сервера, свои — из файла (data:).
const viewer = ref(null);
function openExisting(a) {
    const list = existing.value.filter(viewable);
    viewer.value = { start: Math.max(0, list.findIndex((x) => x.index === a.index)), items: viewerItems(c.sourceFolder, srcUid.value, existing.value) };
}
async function openLocal(i) {
    const list = files.value.filter(localViewable);
    const start = Math.max(0, list.indexOf(files.value[i]));
    try {
        viewer.value = { start, items: await localViewerItems(files.value, start) };
    } catch (e) {
        emit('toast', { text: 'Не удалось открыть файл: ' + (e?.message || 'ошибка чтения'), error: true });
    }
}
const MAX_FILE = (props.cloud?.maxMb || 50) * 1024 * 1024;
const CLOUD_FROM = (props.cloud?.thresholdMb || 10) * 1024 * 1024;
// Пределы сервера: число файлов проверяет ComposeController, размер письма — почтовый сервер.
// Раньше в форме не было ни того, ни другого: двадцать первый файл прикреплялся, а отправка
// падала сообщением от проверяющего механизма; десять файлов по 45 МБ роняли запрос.
const MAX_FILES = props.limits?.maxFiles || 20;
const MAX_MESSAGE = (props.limits?.messageMb || 25) * 1024 * 1024;
const viaCloud = ref(new Set());   // индексы файлов, которые уйдут ссылкой
// Считаем и унаследованные вложения крупнее порога: они тоже уйдут ссылкой.
const cloudCount = computed(() => viaCloud.value.size + cloudPicked.value.length + (keepAttachments.value ? existing.value.filter((a) => keptCloud(a)).length : 0));
function toggleCloud(i) { const s = new Set(viaCloud.value); s.has(i) ? s.delete(i) : s.add(i); viaCloud.value = s; dirty.value = true; }
// Вес письма — свои файлы плюс унаследованные от пересылаемого. Раньше предупреждение
// считало только свои, и пересылка с 40 МБ уходила молча.
const keptSize = computed(() => (keepAttachments.value ? existing.value : []).reduce((s, a) => s + (a.size || 0), 0));
const totalSize = computed(() => files.value.reduce((s, f) => s + f.size, 0) + keptSize.value);
// Файлы, которые реально уйдут внутри письма (не ссылкой через облако).
// Унаследованное вложение крупнее порога при отправке тоже уйдёт ссылкой — сервер решает по размеру.
const keptCloud = (a) => !!props.cloud?.enabled && (a.size || 0) >= CLOUD_FROM;
const inMailSize = computed(() => files.value.reduce((s, f, i) => s + (viaCloud.value.has(i) ? 0 : f.size), 0)
    + (keepAttachments.value ? existing.value : []).reduce((s, a) => s + (keptCloud(a) ? 0 : (a.size || 0)), 0));
// Файлы, которые уйдут ссылкой, в черновик не кладём: 500 МБ в IMAP при каждом автосохранении —
// это минуты ожидания и предел сервера. Такие файлы человек приложит заново, если вернётся к черновику.
const draftFiles = () => files.value.filter((f, i) => !viaCloud.value.has(i));
function dropExisting(a) {
    // Раньше унаследованные вложения снимались только все сразу: при пересылке письма
    // с десятью файлами нельзя было оставить один нужный.
    existing.value = existing.value.filter((x) => x.index !== a.index);
    if (!existing.value.length) keepAttachments.value = false;
    dirty.value = true;
}
// Проверяем все три поля: опечатка в «Копии» проходила клиентскую проверку и падала на сервере
// уже после нажатия «Отправить».
const canSend = computed(() => (to.value.length + cc.value.length + bcc.value.length) > 0
    && ![...to.value, ...cc.value, ...bcc.value].some((a) => a.bad));
// Кнопка «Отправить» выключена — объясняем чем именно: раньше нажатие просто не давало реакции.
const whyCannotSend = computed(() => {
    if (canSend.value) return 'Отправить письмо';
    if (!(to.value.length + cc.value.length + bcc.value.length)) return 'Укажите хотя бы одного получателя';
    return 'Исправьте адрес, подсвеченный красным';
});

function payload(extra = {}) {
    return {
        from: from.value,
        to: addrString(to.value),
        cc: addrString(cc.value),
        bcc: addrString(bcc.value),
        subject: subject.value,
        html: html.value,
        cloud: props.cloud?.enabled ? [...viaCloud.value] : [],
        cloudFiles: cloudPicked.value.map((f) => ({ ...f })),
        inReplyTo: c.inReplyTo,
        references: c.references,
        answeredFolder: c.answeredFolder,
        answeredUid: c.answeredUid,
        sourceFolder: c.sourceFolder,
        sourceUid: srcUid.value,
        keepAttachments: keepAttachments.value && existing.value.length > 0,
        // Снятые крестиком вложения исходного письма раньше всё равно уходили: сервер брал все.
        keepIndexes: keepAttachments.value ? existing.value.map((a) => a.index) : [],
        attachMessages: attachedMails.value.map((x) => ({ folder: x.folder, uid: x.uid, name: x.name })),
        draftUid: draftUid.value,
        priority: priority.value,
        receipt: receipt.value,
        remindDays: remindDays.value || null,
        ...extra,
    };
}

async function send(sendAt = null) {
    flushRecipients();
    if (!canSend.value) {
        emit('toast', { text: 'Укажите получателя', error: true });
        toInput.value?.focus();
        return;
    }
    if (!subject.value.trim() && !(await confirmAsk('Отправить письмо без темы?', { ok: 'Отправить' }))) return;
    const warns = [...to.value, ...cc.value, ...bcc.value].filter((a) => a.warn).map((a) => a.warn);
    if (warns.length && !(await confirmAsk(warns.join('\n') + '\n\nПисьмо, скорее всего, не дойдёт. Отправить всё равно?', { ok: 'Отправить всё равно', danger: true }))) return;
    menu.value = null;
    dirty.value = false;
    closed = true;
    clearInterval(autosave);   // иначе автосохранение успевает создать копию уже отправленного
    emit('send', { form: payload({ sendAt: sendAt ? sendAt.toISOString() : null }), files: files.value, sendAt });
}

/** Отпечаток набора вложений: пока он не менялся, заливать файлы заново не нужно. */
function filesKey() {
    return files.value.map((f) => f.name + ':' + f.size).join('|')
        + '#' + (keepAttachments.value ? existing.value.length : 0)
        + '#' + [...viaCloud.value].sort().join(',');
}
let savedFilesKey = null;
// Сколько автосохранений подряд не удалось: строку статуса внизу окна не замечали, и человек
// писал письмо минутами, пока черновик не сохранялся (разбор журнала 26.09).
let failStreak = 0;

function saveDraft(silent = false) {
    if (saving) return inflight;
    inflight = doSaveDraft(silent);
    return inflight;
}

async function doSaveDraft(silent = false) {
    if (!dirty.value && silent) return;
    if (saving) return;   // предыдущее сохранение ещё идёт
    saving = true;
    status.value = 'Сохраняю…';
    const key = filesKey();
    // Файлы не трогали и черновик уже есть — отправляем только текст, вложения сервер
    // возьмёт из прошлой версии черновика.
    const keepFiles = !!draftUid.value && key === savedFilesKey;
    try {
        const r = await api.draft(composeForm(payload(keepFiles ? { draftKeepFiles: true } : {}), keepFiles ? [] : draftFiles()));
        draftUid.value = r.draftUid;
        savedFilesKey = key;
        dirty.value = false;
        status.value = 'Черновик сохранён ' + when(new Date().toISOString());
        failStreak = 0;
        emit('draft', r);
    } catch (e) {
        // Раньше здесь была безличная строка, и человек не знал, что черновик не сохраняется
        // из-за опечатки в адресе — и продолжал писать письмо в никуда.
        status.value = 'Черновик не сохранён: ' + (e.message || 'ошибка сети');
        // 409 — исходное письмо переложили или удалили, пока писали ответ: сервер не может взять
        // его вложения. Автосохранение раз в 30 с билось об это снова и снова (по журналу — 12 раз
        // подряд у одного человека). Снимаем вложения исходного письма и говорим об этом один раз.
        if (e.status === 409 && keepAttachments.value) {
            keepAttachments.value = false;
            emit('toast', { text: e.message + ' Черновик дальше сохраняется без них.', error: true });
            return;
        }
        failStreak++;
        if (!silent || failStreak === 2) {
            emit('toast', { text: 'Черновик не сохраняется: ' + (e.message || 'нет связи с сервером') + (silent ? '. Письмо пока только в этом окне — не закрывайте его.' : ''), error: true }, silent ? 8000 : undefined);
        }
    } finally {
        saving = false;
    }
}

/**
 * Есть ли в письме что-то, ради чего стоит хранить черновик.
 * Подпись и цитата исходного письма не в счёт: они вставляются сами, и раньше
 * каждое открытие-закрытие окна оставляло черновик «(без темы)» с одной подписью.
 */
function worthSaving() {
    if (to.value.length || cc.value.length || bcc.value.length) return true;
    if (subject.value.trim()) return true;
    if (files.value.length) return true;              // приложил файл и закрыл — файл терялся
    const box = document.createElement('div');
    box.innerHTML = html.value || '';
    box.querySelectorAll('div.sig, blockquote').forEach((n) => n.remove());
    if (box.querySelector('img')) return true;        // письмо из одного вставленного снимка
    return box.textContent.replace(/\u00a0/g, ' ').trim() !== '';
}

/** Удалить черновик и закрыть окно — действие необратимое, поэтому спрашиваем. */
async function discard() {
    const something = to.value.length || subject.value.trim() || files.value.length
        || (html.value || '').replace(/<[^>]+>/g, '').trim();
    if (something && !(await confirmAsk('Удалить письмо вместе с черновиком? Восстановить его будет нельзя.', { ok: 'Удалить', danger: true }))) return;
    closed = true;
    track('compose.discard', something ? 'с текстом' : 'пустое');
    emit('close', { discard: true, draftUid: draftUid.value });
}

function close() {
    closed = true;
    const keep = worthSaving();
    track('compose.close', dirty.value && keep ? 'черновик сохранён' : 'без изменений');
    const saved = dirty.value && keep ? saveDraft(true) : inflight;
    if (keep && (dirty.value || draftUid.value || saving)) {
        // Окно закрывается без вопросов, а внизу — где письмо и «Удалить», если черновик не нужен.
        // Без этого человек не знает, потерян текст или нет.
        emit('toast', {
            text: '«' + (subject.value.trim() || 'Без темы') + '» — в «Черновиках»',
            actionLabel: 'Удалить',
            draft: () => Promise.resolve(saved).then(() => draftUid.value),
        });
    }
    emit('close');
}

// Свернули во вкладку — сохраняем сразу, не дожидаясь автосохранения: письмо должно
// оказаться в «Черновиках», даже если браузер закроют следом.
watch(() => props.active, (on, was) => {
    if (was && !on && dirty.value && worthSaving()) saveDraft(true);
});

// Что показать на вкладке: тема, кому, когда сохранено, есть ли несохранённые правки.
watch(
    () => ({
        subject: subject.value, to: to.value.length ? (to.value[0].name || to.value[0].mail) + (to.value.length > 1 ? ' и ещё ' + (to.value.length - 1) : '') : '',
        saved: status.value, dirty: dirty.value, draftUid: draftUid.value, mode: c.mode,
    }),
    (m) => emit('meta', m),
    { immediate: true, deep: true },
);

defineExpose({ close });

// Файлы из личного облака: карточками во вложения; уже выбранный второй раз не добавляется.
const picker = ref(false);
function onCloudAttach(r) {
    picker.value = false;
    const had = new Set(cloudPicked.value.map((f) => f.path));
    const add = r.links.filter((l) => !had.has(l.path)).map((l) => ({ path: l.path, name: l.name, size: l.size }));
    if (cloudPicked.value.length + add.length > 20) {
        emit('toast', { text: 'Из облака можно приложить не больше 20 файлов', error: true });
        return;
    }
    cloudPicked.value.push(...add);
    dirty.value = true;
}

function addFiles(list) {
    for (const f of list) {
        if (files.value.length >= MAX_FILES) {
            emit('toast', { text: `К письму можно приложить не больше ${MAX_FILES} файлов — остальные не добавлены. Сложите их в архив.`, error: true });
            break;
        }
        const cap = props.cloud?.enabled ? MAX_FILE : MAX_MESSAGE;
        if (f.size > cap) {
            // Текст про облако показывался, даже когда облако выключено.
            emit('toast', {
                text: props.cloud?.enabled
                    ? `«${f.name}» больше ${Math.round(MAX_FILE / 1048576)} МБ — столько не примет даже хранилище файлов`
                    : `«${f.name}» больше ${Math.round(MAX_MESSAGE / 1048576)} МБ — столько почта не принимает`,
                error: true,
            });
            continue;
        }
        // Два разных документа с одинаковым именем и размером — обычное дело (счёт из 1С
        // за разные месяцы). Раньше второй молча не добавлялся, и человек повторял попытку.
        if (files.value.some((x) => x.name === f.name && x.size === f.size)) {
            emit('toast', { text: `«${f.name}» такого же размера уже приложен — второй раз не добавляю`, error: true });
            continue;
        }
        files.value.push(f);
        if (props.cloud?.enabled && f.size >= CLOUD_FROM) { const s = new Set(viaCloud.value); s.add(files.value.length - 1); viaCloud.value = s; }
    }
    dirty.value = true;
}
function onFiles(e) { addFiles(e.target.files); e.target.value = ''; }
// Рамка «отпустите, чтобы вложить» — только для файлов из проводника. Перетаскивание текста
// внутри письма раньше отменялось обработчиком на всём окне, и фрагмент не переносился.
let dragDepth = 0;
function hasFiles(e) {
    return [...(e.dataTransfer?.types || [])].includes('Files');
}
function onDragOver(e) {
    if (!hasFiles(e)) return;            // текст внутри письма — пусть браузер делает своё
    e.preventDefault();
    drop.value = true;
}
function onDragLeave(e) {
    if (!hasFiles(e)) return;
    // dragleave срабатывает на каждом вложенном элементе — считаем вход и выход, иначе рамка мигает
    dragDepth = Math.max(0, dragDepth - 1);
    if (dragDepth === 0) drop.value = false;
}
function onDrop(e) {
    dragDepth = 0;
    if (!hasFiles(e)) return;
    e.preventDefault();
    drop.value = false;
    if (e.dataTransfer?.files?.length) addFiles(e.dataTransfer.files);
}
function removeFile(i) { files.value.splice(i, 1); const s = new Set(); viaCloud.value.forEach((k) => { if (k < i) s.add(k); else if (k > i) s.add(k - 1); }); viaCloud.value = s; dirty.value = true; }

function openMenu(kind, e) {
    const r = e.currentTarget.getBoundingClientRect();
    menuAt.value = { x: r.left, y: r.top - 8 };
    menu.value = kind;
}

function laterCustom() {
    const d = new Date(customAt.value);
    if (Number.isNaN(d.getTime()) || d < new Date()) { emit('toast', { text: 'Выберите время в будущем', error: true }); return; }
    send(d);
}

function onKey(e) {
    if (e.key === 'Escape' && !menu.value) { e.stopPropagation(); close(); return; }
    // Ctrl+Enter и Ctrl+S раньше работали только из тела письма: в полях «Кому» и «Тема»
    // нажатие ничего не делало, хотя подсказка обещала обратное.
    if (!(e.ctrlKey || e.metaKey) || e.defaultPrevented) return;
    if (e.key === 'Enter') { e.preventDefault(); send(); return; }
    if (e.code === 'KeyS') { e.preventDefault(); saveDraft(false); }
}

watch([to, cc, bcc, subject, from, keepAttachments], () => { dirty.value = true; }, { deep: true });
// Текст: правкой считаем всё, кроме подмены ссылок на картинки самими картинками при открытии
// (embedServerImages) — иначе открытый и сразу закрытый ответ оставлял черновик.
let quietHtml = false;
watch(html, () => {
    if (quietHtml) { quietHtml = false; return; }
    dirty.value = true;
});

// Подпись следует за полем «От»: у общего ящика — его собственная, у своих адресов — личная.
// Блок подписи (div.sig) заменяется целиком; текст письма, цитата и пересланное не трогаются.
function signatureFor(mail) {
    const id = props.identities.find((i) => i.shared && i.mail === mail);
    return id ? (id.signature || '') : (props.settings.signature || '');
}
watch(from, (nv, ov) => {
    if (!ov || nv === ov) return;
    // В ответе подпись добавляется только если это разрешено настройкой, но уже вставленную
    // подпись меняем всегда: иначе письмо от общего ящика уходило с личной подписью.
    const hasSig = /class="sig"/.test(html.value || '');
    if (c.mode !== 'new' && c.mode !== 'draft' && !props.settings.signature_reply && !hasSig) return;
    const s = signatureFor(nv);
    // Точечная замена в самом поле: переписывание всего письма сбрасывало курсор в начало
    // и стирало историю отмены.
    if (editor.value?.setSignature?.(s)) return;
    const box = document.createElement('div');
    box.innerHTML = html.value;
    let sig = box.querySelector('div.sig');
    if (s) {
        if (sig) { sig.innerHTML = s; } else {
            sig = document.createElement('div'); sig.className = 'sig'; sig.innerHTML = s;
            const gap = document.createElement('p'); gap.innerHTML = '<br>';
            const anchor = box.querySelector('div.quote, div.fwd');
            if (anchor) { box.insertBefore(gap, anchor); box.insertBefore(sig, anchor); } else { box.appendChild(gap); box.appendChild(sig); }
        }
    } else if (sig) {
        sig.remove();
    }
    html.value = box.innerHTML;
});

// Вкладку закрыли или свернули — сохраняем сразу, не дожидаясь очередного автосохранения.
function saveOnHide() {
    if (document.visibilityState === 'hidden' && dirty.value && worthSaving()) saveDraft(true);
}

onMounted(() => {
    dirty.value = false;
    autosave = setInterval(() => saveDraft(true), 30000);
    editor.value?.embedServerImages(() => { quietHtml = true; });
    document.addEventListener('visibilitychange', saveOnHide);
    setTimeout(() => {
        if (to.value.length) editor.value?.focusStart();
        else toInput.value?.focus();
    }, 50);
});
onBeforeUnmount(() => {
    clearInterval(autosave);
    document.removeEventListener('visibilitychange', saveOnHide);
    // Окно закрыли не кнопкой, а переключением на другое письмо («Ответить» поверх черновика):
    // раньше набранный текст пропадал без следа.
    if (!closed && dirty.value && worthSaving()) saveDraft(true);
});

const title = computed(() => ({ reply: 'Ответ', replyAll: 'Ответ всем', forward: 'Пересылка', draft: 'Черновик' }[c.mode] || 'Новое письмо'));
</script>

<template>
    <AttachmentViewer v-if="viewer" :items="viewer.items" :start="viewer.start" @close="viewer = null" />
    <div
        class="compose"
        :class="{ 'compose--drop': drop }"
        @keydown="onKey"
        @dragover="onDragOver"
        @dragleave="onDragLeave"
        @drop="onDrop"
    >
        <div class="compose__row" style="border-bottom: 1px solid var(--border); background: var(--surface-2); border-radius: 12px 12px 0 0">
            <b style="font-size: 15px">{{ title }}</b>
            <span style="flex: 1" />
            <button class="ib ib--sm" type="button" title="Закрыть — написанное сохранится в черновиках" aria-label="Закрыть окно письма" @click="close"><Icon name="x" :size="16" /></button>
        </div>

        <div class="compose__row">
            <label for="cmp-to">Кому</label>
            <RecipientInput input-id="cmp-to" ref="toInput" v-model="to" :others="[...cc, ...bcc].map((a) => a.mail)" placeholder="Имя или адрес" @note="$emit('toast', { text: $event, error: true })" />
            <span class="links">
                <button v-if="!showCc" type="button" class="linklike" @click="showCc = true">Копия</button>
                <button v-if="!showBcc" type="button" class="linklike" @click="showBcc = true">Скрытая</button>
            </span>
        </div>
        <div v-if="showCc" class="compose__row">
            <label for="cmp-cc">Копия</label>
            <RecipientInput input-id="cmp-cc" ref="ccInput" v-model="cc" :others="[...to, ...bcc].map((a) => a.mail)" @note="$emit('toast', { text: $event, error: true })" />
        </div>
        <div v-if="showBcc" class="compose__row">
            <label for="cmp-bcc">Скрытая</label>
            <RecipientInput input-id="cmp-bcc" ref="bccInput" v-model="bcc" :others="[...to, ...cc].map((a) => a.mail)" @note="$emit('toast', { text: $event, error: true })" />
        </div>
        <div v-if="identities.length > 1" class="compose__row">
            <label for="cmp-from">От кого</label>
            <select id="cmp-from" v-model="from">
                <option v-for="i in identities" :key="i.mail" :value="i.mail">{{ i.shared ? `${i.mail} — общий ящик «${i.name}»` : i.mail }}</option>
            </select>
        </div>
        <div class="compose__row">
            <label for="cmp-subject">Тема</label>
            <input id="cmp-subject" v-model="subject" placeholder="Тема письма" maxlength="998" @keydown.enter.prevent="editor?.focus()">
            <span v-if="priority" class="chip chip--warn">Важное</span>
                <span v-if="receipt" class="chip">Уведомить о прочтении</span>
        </div>

        <Editor ref="editor" v-model="html" @submit="send()" @save="saveDraft()" @toast="$emit('toast', $event)">
            <template #right>
                <label v-if="existing.length" class="toggle" style="font-size: 12.5px">
                    <input v-model="keepAttachments" type="checkbox"><span class="toggle__track" />Вложения исходного письма ({{ existing.length }})
                </label>
            </template>
        </Editor>

        <div v-if="files.length || cloudPicked.length || attachedMails.length || (keepAttachments && existing.length)" class="compose__atts">
            <!-- Письмо, приложенное целиком: уйдёт файлом .eml, получатель откроет его как письмо -->
            <span v-for="(x, i) in attachedMails" :key="'m' + x.folder + x.uid" class="att att--mail" :title="'Письмо «' + x.name + '» уйдёт вложением'">
                <span class="att__main"><Icon name="mail" :size="13" /><span class="name">{{ x.name }}</span><span class="sz">письмо</span></span>
                <button class="att__btn" type="button" title="Убрать это письмо" aria-label="Убрать это письмо" @click="dropMail(i)"><Icon name="x" :size="13" /></button>
            </span>
            <template v-if="keepAttachments">
                <span v-for="a in existing" :key="'e' + a.index" class="att" :class="{ 'att--cloud': keptCloud(a) }" :title="keptCloud(a) ? a.name + ' — крупнее порога, уйдёт ссылкой' : a.name + (viewable(a) ? ' — посмотреть' : '')">
                    <a class="att__main" :href="api.attachmentUrl(c.sourceFolder, srcUid, a.index)" @click="viewable(a) && (openExisting(a), $event.preventDefault())">
                        <Icon :name="keptCloud(a) ? 'cloud' : 'clip'" :size="13" /><span class="name">{{ a.name }}</span><span class="sz">{{ size(a.size) }}</span>
                    </a>
                    <button v-if="viewable(a)" class="att__btn" type="button" title="Посмотреть" @click="openExisting(a)" aria-label="Посмотреть"><Icon name="eye" :size="13" /></button>
                    <button class="att__btn" type="button" title="Убрать это вложение" aria-label="Убрать это вложение" @click="dropExisting(a)"><Icon name="x" :size="13" /></button>
                </span>
            </template>
            <span v-for="(f, i) in files" :key="f.name + i" class="att" :class="{ 'att--cloud': viaCloud.has(i) }" :title="viaCloud.has(i) ? 'Уйдёт ссылкой: файл ляжет на сервер, в письме будет ссылка' : f.name">
                <a v-if="localViewable(f)" class="att__main" href="#" title="Посмотреть" @click.prevent="openLocal(i)"><Icon :name="viaCloud.has(i) ? 'cloud' : 'clip'" :size="13" /><span class="name">{{ f.name }}</span><span class="sz">{{ size(f.size) }}</span></a>
                <template v-else><Icon :name="viaCloud.has(i) ? 'cloud' : 'clip'" :size="13" /><span class="name">{{ f.name }}</span><span class="sz">{{ size(f.size) }}</span></template>
                <button v-if="localViewable(f)" class="att__btn" type="button" title="Посмотреть" @click="openLocal(i)" aria-label="Посмотреть"><Icon name="eye" :size="13" /></button>
                <button v-if="cloud.enabled" type="button" :title="viaCloud.has(i) ? 'Вложить в письмо' : 'Отправить ссылкой, а не вложением'" @click="toggleCloud(i)" :aria-label="viaCloud.has(i) ? 'Вложить в письмо' : 'Отправить ссылкой, а не вложением'"><Icon :name="viaCloud.has(i) ? 'clip' : 'cloud'" :size="13" /></button>
                <button type="button" title="Убрать" @click="removeFile(i)" aria-label="Убрать"><Icon name="x" :size="13" /></button>
            </span>
            <!-- Файлы из облака: уйдут ссылкой, получатель скачает по щелчку -->
            <span v-for="(f, i) in cloudPicked" :key="'c' + f.path" class="att att--cloud" :title="f.name + ' — из облака, уйдёт ссылкой'">
                <span class="att__main"><Icon name="cloud" :size="13" /><span class="name">{{ f.name }}</span><span class="sz">{{ size(f.size) }}</span></span>
                <button class="att__btn" type="button" title="Убрать" aria-label="Убрать" @click="dropCloud(i)"><Icon name="x" :size="13" /></button>
            </span>
            <span v-if="(cloud.enabled || cloudPicked.length) && cloudCount" class="chip chip--ok" style="height: 28px"><Icon name="cloud" :size="13" /> {{ cloudCount }} {{ cloudCount === 1 ? 'файл уйдёт ссылкой' : 'файла уйдут ссылкой' }} — получатель скачает по ссылке из письма</span>
            <!-- Предупреждение показываем и при включённом облаке: часть файлов всё равно
                 уходит внутри письма, а вес считаем вместе с унаследованными. -->
            <span v-if="inMailSize > MAX_MESSAGE" class="chip chip--no" style="height: 28px">{{ size(inMailSize) }} — больше предела почты ({{ Math.round(MAX_MESSAGE / 1048576) }} МБ), письмо не уйдёт</span>
            <span v-else-if="inMailSize > MAX_MESSAGE * 0.6" class="chip chip--warn" style="height: 28px">{{ size(inMailSize) }} — большое письмо может не пройти у получателя</span>
        </div>

        <div class="compose__foot">
            <span class="split">
                <button class="btn btn--primary" type="button" :disabled="!canSend" :title="canSend ? 'Отправить (Ctrl+Enter)' : whyCannotSend" @click="send()">
                    <Icon name="send" :size="15" />Отправить
                </button>
                <button class="btn btn--primary" type="button" aria-label="Отправить позже" :title="canSend ? 'Отправить позже' : whyCannotSend" :disabled="!canSend" @click="openMenu('later', $event)">
                    <Icon name="clock" :size="16" />
                </button>
            </span>
            <button class="ib" type="button" title="Вложить файл" aria-label="Вложить файл" @click="fileInput?.click()"><Icon name="clip" :size="17" /></button>
            <input ref="fileInput" type="file" multiple hidden @change="onFiles">
            <button v-if="cloud.personal" class="ib" type="button" title="Приложить из облака — уйдёт ссылкой" aria-label="Приложить из облака" @click="picker = true"><Icon name="cloud" :size="17" /></button>
            <button class="ib" type="button" :class="{ 'ib--on': remindDays }" title="Напомнить, если не ответят" aria-label="Напомнить, если не ответят" @click="openMenu('remind', $event)">
                <Icon name="bell" :size="17" /><span v-if="remindDays">{{ remindDays }} дн.</span>
            </button>
            <button class="ib" type="button" title="Ещё" aria-label="Ещё" @click="openMenu('more', $event)"><Icon name="dots" :size="17" /></button>
            <span class="grow" />
            <span class="status">{{ status }}</span>
            <button class="ib compose__save" type="button" title="Сохранить черновик (Ctrl+S)" aria-label="Сохранить черновик" @click="saveDraft()"><Icon name="edit" :size="16" /></button>
            <button class="ib ib--danger" type="button" title="Удалить черновик и закрыть" aria-label="Удалить черновик и закрыть" @click="discard"><Icon name="trash" :size="16" /></button>
        </div>

        <CloudPicker v-if="picker" @close="picker = false" @attach="onCloudAttach" />

        <Popover v-if="menu === 'later'" :x="menuAt.x" :y="menuAt.y - 250" @close="menu = null">
            <div class="pop__title">Отправить позже</div>
            <button v-for="p in presets()" :key="p.label" class="pop__item" type="button" @click="send(p.at)">
                {{ p.label }}<span class="k">{{ p.sub }}</span>
            </button>
            <div class="pop__sep" />
            <div class="pop__form">
                <input v-model="customAt" class="input" type="datetime-local" style="height: 34px">
                <button class="btn btn--sm" type="button" @click="laterCustom">Ок</button>
            </div>
        </Popover>

        <Popover v-if="menu === 'remind'" :x="menuAt.x" :y="menuAt.y - 220" @close="menu = null">
            <div class="pop__title">Напомнить, если не ответят</div>
            <button v-for="d in [1, 2, 3, 5, 7]" :key="d" class="pop__item" :class="{ 'pop__item--on': remindDays === d }" type="button" @click="remindDays = d; menu = null">
                через {{ d }} {{ d === 1 ? 'день' : d < 5 ? 'дня' : 'дней' }}
            </button>
            <div class="pop__sep" />
            <button class="pop__item" type="button" @click="remindDays = 0; menu = null">Не напоминать</button>
        </Popover>

        <Popover v-if="menu === 'more'" :x="menuAt.x" :y="menuAt.y - 130" @close="menu = null">
            <button class="pop__item" type="button" @click="priority = !priority; menu = null"><Icon name="warn" :size="16" />{{ priority ? 'Обычная важность' : 'Пометить как важное' }}</button>
            <button class="pop__item" type="button" @click="receipt = !receipt; menu = null"><Icon name="check" :size="16" />{{ receipt ? 'Без уведомления о прочтении' : 'Запросить уведомление о прочтении' }}</button>
        </Popover>
    </div>
</template>
