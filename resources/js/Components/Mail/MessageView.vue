<script setup>
// Правая колонка: панель действий, цепочка писем, быстрый ответ.
import { computed, ref, watch } from 'vue';
import Icon from '../Icon.vue';
import Popover from './Popover.vue';
import AttachmentViewer from './AttachmentViewer.vue';
import AttachedMail from './AttachedMail.vue';
import { isEmpty, isEml, isImg, isOffice, viewable, viewerItems } from '../../mail/attachments';
import { api } from '../../mail/api';
import { addrList, initials, size, when } from '../../mail/format';

const props = defineProps({
    message: { type: Object, required: true },
    folder: String,
    folderRole: String,
    labels: { type: Array, default: () => [] },
    settings: { type: Object, default: () => ({}) },
    user: String,
    held: Boolean,   // письмо закреплено вкладкой «под рукой»
});
const emit = defineEmits(['act', 'reply', 'quick', 'context', 'back', 'unsubscribe', 'meeting', 'search', 'print', 'toast', 'hold']);

// Карточка адресата — как в Mail.ru: по щелчку на имени всплывают адрес и действия.
const card = ref(null);   // { x, y, name, mail }
function openCard(e, a) {
    if (!a?.mail) return;
    const r = e.currentTarget.getBoundingClientRect();
    card.value = { x: r.left, y: r.bottom + 4, name: a.name && a.name !== a.mail ? a.name : '', mail: a.mail };
}
async function copyAddress() {
    const mail = card.value?.mail;
    card.value = null;
    try {
        await navigator.clipboard.writeText(mail);
        emit('toast', { text: 'Адрес скопирован' });
    } catch (e) {
        emit('toast', { text: 'Не удалось скопировать — выделите адрес и нажмите Ctrl+C', error: true });
    }
}
function writeTo() {
    const a = { name: card.value.name, mail: card.value.mail };
    card.value = null;
    emit('reply', 'new', { to: [a] });
}
function findAll() {
    const mail = card.value.mail;
    card.value = null;
    // Вся переписка — письма от него и ему во всех папках, а не только входящие от него.
    emit('search', 'переписка:' + mail);
}
function inContacts() {
    const mail = card.value.mail;
    card.value = null;
    window.location.href = '/contacts?q=' + encodeURIComponent(mail);
}

const expanded = ref({});
const quick = ref('');
const sending = ref(false);
const showImages = ref({});
const allAddrs = ref({});   // 69: у каких писем показан весь список получателей
const failed = ref({});     // 74: какие письма цепочки не догрузились

const key = (m) => m.folder + '#' + m.uid;

// 76: подсказка обещает клавишу, только если горячие клавиши включены.
const keysOn = computed(() => props.settings.shortcuts !== false);
const tip = (text, k) => (keysOn.value ? `${text} (${k})` : text);
// 381: значки помечены aria-hidden, поэтому кнопке нужно собственное имя —
// иначе панель действий читается как набор безымянных кнопок.
const btn = (text, k) => ({ title: tip(text, k), 'aria-label': text });

// 68: автоматические адреса. Ответ на них не прочитает никто, а быстрый ответ
// выглядит как обычный разговор — предупреждаем прямо в месте ответа.
const NOREPLY = /^(no[-_.]?reply|do[-_.]?not[-_.]?reply|mailer[-_.]?daemon|bounce[sd]?|postmaster|nobody)$/i;
const noReply = computed(() => {
    const mail = replyTo(props.message)[0]?.mail || '';
    return NOREPLY.test(mail.split('@')[0] || '');
});

// 69: сорок адресатов в одну строку превращали шапку письма в простыню.
const TO_SHOWN = 3;
function toShown(m) {
    const list = [...(m.to || []), ...(m.cc || [])];
    return allAddrs.value[key(m)] ? list.length : Math.min(TO_SHOWN, list.length);
}
function toRest(m) {
    return Math.max(0, [...(m.to || []), ...(m.cc || [])].length - toShown(m));
}
function toText(m) {
    const to = (m.to || []).slice(0, toShown(m));
    const left = toShown(m) - to.length;
    const cc = left > 0 ? (m.cc || []).slice(0, left) : [];
    return addrList(to) + (cc.length ? ' · копия: ' + addrList(cc) : '');
}

// Просмотр вложений (обращение №6): картинки и PDF открываются поверх письма, остальное — скачивается.
const viewer = ref(null);   // { items, start }
// Письмо, приложенное к письму: открываем его как письмо (обращение №39).
const attachedMail = ref(null);   // { folder, uid, index, name }
function openAttachedMail(m, a) {
    attachedMail.value = { folder: m.folder, uid: m.uid, index: a.index, name: a.name };
}
function openAttachment(m, a) {
    const list = m.attachments.filter(viewable);
    viewer.value = { start: Math.max(0, list.findIndex((x) => x.index === a.index)), items: viewerItems(m.folder, m.uid, m.attachments) };
}
// Файлы из своего хранилища (ссылки в теле письма) — карточками рядом с вложениями.
const cloudFiles = (m) => (m.cloudFiles || []);
const cloudLive = (m) => cloudFiles(m).filter((f) => !f.expired);
// Продлить из письма можно свой большой файл; ссылку на файл из облака — в разделе «Облако».
const renewable = (f) => f.mine && !f.cloud;
// В ZIP идут все живые файлы; файлы из облака сервер качает из Nextcloud — не больше 2 ГБ за раз.
const ZIP_CLOUD_MAX = 2 * 1073741824;
const cloudZipped = (m) => {
    const live = cloudLive(m);
    return live.filter((f) => f.cloud).reduce((s, f) => s + (f.size || 0), 0) > ZIP_CLOUD_MAX ? [] : live;
};
const fmtDay = (d) => (d ? new Date(d + 'T00:00:00').toLocaleDateString('ru-RU') : '');
function cloudNote(m) {
    const live = cloudLive(m);
    if (!live.length) return 'Срок ссылок истёк' + (cloudFiles(m).some(renewable) ? ' — продлите их кнопкой у файла' : ' — попросите отправителя продлить');
    const until = live.map((f) => f.expires).filter(Boolean).sort()[0];
    return 'Файлы лежат на сервере почты' + (until ? ', ссылки действуют до ' + fmtDay(until) : '') + (live.some(renewable) ? ' — можно продлить' : '');
}
function openCloudFile(m, f) {
    const list = cloudLive(m).filter((x) => x.preview);
    viewer.value = {
        start: Math.max(0, list.findIndex((x) => x.token === f.token)),
        items: list.map((x) => ({
            url: isOffice(x) ? api.filePreviewUrl(x.token) : api.fileContentUrl(x.token), downloadUrl: x.url,
            name: x.name, type: isImg(x) ? x.type : 'application/pdf', size: x.size, converted: isOffice(x),
        })),
    };
}
async function renewFile(f) {
    try {
        const r = await api.fileRenew(f.token);
        Object.assign(f, r);
        emit('toast', { text: 'Ссылка на «' + f.name + '» продлена до ' + fmtDay(r.expires) });
    } catch (e) {
        emit('toast', { text: e.message || 'Не удалось продлить ссылку', error: true });
    }
}
// Вложения письма без картинок из тела — то, что человек видит списком.
const atts = (m) => (m.attachments || []).filter((a) => !a.inline);
// Целые (непустые) — их и считает «Скачать все», пустым в архиве делать нечего.
const attsOk = (m) => atts(m).filter((a) => !isEmpty(a));
const attsEmpty = (m) => atts(m).filter(isEmpty);

const labelMap = computed(() => Object.fromEntries(props.labels.map((l) => [l.id, l])));

watch(() => props.message.uid, () => { expanded.value = {}; quick.value = ''; });

// Вся переписка, новые сверху (как в Kerio); открытое письмо стоит на своём месте по дате.
// Сравниваем по времени, а не по строке: у писем разные часовые пояса в ISO-дате.
const ts = (m) => (m.date ? new Date(m.date).getTime() || 0 : 0);
const all = computed(() => [...(props.message.thread || []), props.message].sort((a, b) => ts(b) - ts(a)));
const isLast = (m) => m === props.message;
// Открытое письмо по умолчанию раскрыто, но его тоже можно свернуть щелчком по шапке — чтобы не мешало
// читать остальные письма цепочки (обращение №5, «как в mail.ru»). Остальные по умолчанию свёрнуты.
const isOpen = (m) => {
    const v = expanded.value[m.folder + '#' + m.uid];
    return isLast(m) ? v !== false : !!v;
};
function toggle(m) {
    const key = m.folder + '#' + m.uid;
    expanded.value[key] = !isOpen(m);
    if (isLast(m)) return;
    // Письма цепочки приходят «лёгкими» (заголовки и превью); тело и вложения — при первом раскрытии.
    if (expanded.value[key] && m.light && !m.loading) {
        load(m);
    }
}
/** Догрузить тело письма цепочки. Раньше сбой сети молча оставлял пустое письмо. */
function load(m) {
    m.loading = true;
    failed.value[key(m)] = '';
    api.message(m.folder, m.uid, true)
        .then((d) => { Object.assign(m, d, { light: false, loading: false, thread: [] }); })
        .catch((e) => { m.loading = false; failed.value[key(m)] = e.message || 'не удалось загрузить'; });
}

// Прячет внешние ссылки сервер (MailStore::blockRemote) — и src, и srcset, и background.
// Здесь только возвращаем их обратно: раньше клиент ловил один src у img, а остальное грузилось молча.
function hasExternalImages(m) {
    return !!m.html && /\sdata-blocked-(src|srcset|background)=/i.test(m.html);
}
function imagesShown(m) {
    return !!showImages.value[key(m)] || props.settings.show_images === 'always';
}
// Своё письмо (в «Отправленных» или черновик): предупреждать, что «отправитель узнал об открытии»,
// нечего — отправитель и есть читающий. Обращение №46: полоска сбивала с толку в «Отправленных».
function ownLetter(m) {
    return ['sent', 'drafts'].includes(m.folderRole || props.folderRole);
}

function body(m) {
    if (!m.html) return null;
    const html = withoutLinksBlock(m);
    return imagesShown(m) ? html.replace(/\sdata-blocked-(src|srcset|background)=/gi, ' $1=') : html;
}

// Блок «К этому письму приложены ссылки…» нужен внешним получателям. Здесь те же файлы уже
// стоят карточками над письмом — блок прячем, если все его ссылки есть среди карточек.
// Только при показе: в самом письме, в ответе и пересылке блок остаётся.
const LINKS_TITLE = 'К этому письму приложены ссылки на следующие файлы';
const stripped = new WeakMap();
function withoutLinksBlock(m) {
    const cards = cloudFiles(m);
    if (!cards.length || !m.html.includes(LINKS_TITLE)) return m.html;
    const hit = stripped.get(m);
    if (hit && hit.src === m.html) return hit.out;
    const tokens = new Set(cards.map((f) => f.token));
    const doc = new DOMParser().parseFromString(m.html, 'text/html');
    let changed = false;
    for (const box of [...doc.querySelectorAll('div')]) {
        // Корень блока — div, первый элемент которого — сам заголовок (текст без вложенных тегов).
        const head = box.firstElementChild;
        if (!head || head.children.length || !head.textContent.trim().startsWith(LINKS_TITLE) || box.closest('blockquote')) continue;
        const links = [...box.querySelectorAll('a[href]')];
        const ours = links.length && links.every((a) => {
            const t = (a.getAttribute('href') || '').match(/^https?:\/\/[^/]+\/([A-Za-z0-9_-]{20,64})(?:[/?#]|$)/);
            return t && tokens.has(t[1]);
        });
        if (ours) { box.remove(); changed = true; }
    }
    const out = changed ? doc.body.innerHTML : m.html;
    stripped.set(m, { src: m.html, out });
    return out;
}

function replyTo(m) {
    return m.replyTo?.length ? m.replyTo : [m.from];
}

/**
 * Быстрый ответ. emit не возвращает промис, поэтому ждём ответа через done():
 * раньше поле очищалось сразу, и при сбое отправки набранный текст пропадал.
 */
function sendQuick() {
    const text = quick.value.trim();
    if (!text || sending.value) return;
    sending.value = true;
    emit('quick', {
        text,
        message: props.message,
        done: (ok) => { sending.value = false; if (ok) quick.value = ''; },
    });
}

function print() {
    // Предпросмотр печати открывает страница — окном поверх почты, а не отдельной вкладкой.
    if (props.message) emit('print', props.message);
}

const isDraft = computed(() => props.folderRole === 'drafts');
</script>

<template>
    <div class="mread__bar">
        <button class="ib mobile-only" type="button" title="К списку" aria-label="К списку писем" style="display: none" @click="$emit('back')"><Icon name="back" :size="18" /></button>
        <template v-if="isDraft">
            <button class="ib" type="button" @click="$emit('reply', 'draft', message)"><Icon name="edit" :size="16" />Продолжить черновик</button>
        </template>
        <template v-else>
            <button class="ib ib--keep" type="button" :title="noReply ? 'Отправитель — автоматический адрес, ответ, скорее всего, никто не прочитает' : tip('Ответить', 'r')" @click="$emit('reply', settings.reply_all ? 'replyAll' : 'reply', message)"><Icon name="reply" :size="16" />Ответить</button>
            <!-- 341: на телефоне одиннадцать кнопок не помещались в строку, панель
                 переносилась, и флажок оказывался один на второй строке. Кнопки пореже
                 помечены desktop-only и на узком экране живут в меню «Ещё». -->
            <button class="ib desktop-only" type="button" v-bind="btn('Ответить всем', 'a')" @click="$emit('reply', 'replyAll', message)"><Icon name="replyall" :size="16" />Всем</button>
            <button class="ib" type="button" v-bind="btn('Переслать', 'f')" @click="$emit('reply', 'forward', message)"><Icon name="fwd" :size="16" />Переслать</button>
            <button v-if="folderRole === 'sent'" class="ib desktop-only" type="button" title="Изменить как новое: открыть копию письма с теми же получателями, темой, текстом и вложениями" @click="$emit('reply', 'again', message)"><Icon name="edit" :size="16" />Как новое</button>
            <button class="ib ib--wide desktop-only" type="button" title="Назначить встречу по этому письму" @click="$emit('meeting', message)"><Icon name="cal" :size="16" />Встреча</button>
        </template>
        <span class="sep" />
        <button class="ib" type="button" v-bind="btn('Архив', 'e')" @click="$emit('act', 'archive', [message.uid])"><Icon name="archive" :size="17" /></button>
        <button class="ib" type="button" v-bind="btn('В папку', 'v')" @click="$emit('context', $event, message.uid, 'move')"><Icon name="folder" :size="17" /></button>
        <button class="ib desktop-only" type="button" v-bind="btn('Метка', 'l')" @click="$emit('context', $event, message.uid, 'label')"><Icon name="tag" :size="17" /></button>
        <button class="ib desktop-only" type="button" v-bind="btn('Отложить', 'z')" @click="$emit('context', $event, message.uid, 'snooze')"><Icon name="clock" :size="17" /></button>
        <button class="ib" type="button" :class="{ 'ib--on': message.flagged }" v-bind="btn('Флажок', 's')" @click="$emit('act', message.flagged ? 'unflag' : 'flag', [message.uid])"><Icon name="flag" :size="17" /></button>
        <span class="grow" />
        <button v-if="!isDraft" class="ib desktop-only" type="button" :class="{ 'ib--on': held }" :aria-pressed="held"
            :title="held ? 'Убрать из вкладок внизу' : 'Держать под рукой: вкладка внизу, письмо перед глазами, пока пишете другое'"
            :aria-label="held ? 'Убрать из вкладок' : 'Держать под рукой'" @click="$emit('hold', message)"><Icon name="pin" :size="17" /></button>
        <button class="ib desktop-only" type="button" title="Печать" aria-label="Печать" @click="print"><Icon name="print" :size="17" /></button>
        <button class="ib" type="button" title="Ещё" aria-label="Ещё действия" @click="$emit('context', $event, message.uid, 'more')"><Icon name="dots" :size="17" /></button>
        <!-- Опасные действия — отдельной группой у правого края, подальше от «Ответить»: иначе промахи по корзинке (обращение №12). -->
        <span class="sep" />
        <button v-if="folderRole !== 'spam'" class="ib" type="button" v-bind="btn('Спам', '!')" @click="$emit('act', 'spam', [message.uid])"><Icon name="spam" :size="17" /></button>
        <button v-else class="ib" type="button" title="Не спам" @click="$emit('act', 'notspam', [message.uid])"><Icon name="inbox" :size="17" />Не спам</button>
        <button class="ib ib--danger" type="button" v-bind="btn('Удалить', '#')" @click="$emit('act', 'delete', [message.uid])"><Icon name="trash" :size="17" />Удалить</button>
    </div>

    <div class="mread__scroll">
        <div class="mread__title">
            <h1>{{ message.subject }}</h1>
            <span v-for="id in message.labels" :key="id">
                <span v-if="labelMap[id]" class="lbl" :style="{ background: labelMap[id].color + '22', color: labelMap[id].color, height: '22px' }">{{ labelMap[id].name }}</span>
            </span>
            <span v-if="all.length > 1" class="thr">{{ all.length }} в цепочке</span>
            <!-- 391: длинная переписка показывается не целиком — раньше остальные письма
                 просто отсутствовали, без всякой пометки. -->
            <span v-if="message.threadHidden" class="thr" :title="'В переписке есть ещё письма — найдите их поиском по теме'">показаны не все: ещё {{ message.threadHidden }}</span>
        </div>

        <Popover v-if="card" :x="card.x" :y="card.y" @close="card = null">
            <div class="pop__card">
                <div class="msg__av">{{ initials(card.name, card.mail) }}</div>
                <div class="pop__card-who">
                    <b v-if="card.name">{{ card.name }}</b>
                    <span class="mono">{{ card.mail }}</span>
                </div>
            </div>
            <button class="pop__item" type="button" @click="copyAddress"><Icon name="copy" :size="15" />Копировать адрес</button>
            <button class="pop__item" type="button" @click="writeTo"><Icon name="edit" :size="15" />Написать письмо</button>
            <button class="pop__item" type="button" @click="findAll"><Icon name="search" :size="15" />Вся переписка</button>
            <button class="pop__item" type="button" @click="inContacts"><Icon name="users" :size="15" />В контактах</button>
        </Popover>

        <article v-for="m in all" :key="m.folder + '#' + m.uid" class="msg" :class="{ 'msg--col': !isOpen(m) }">
            <div class="msg__hd" @click="toggle(m)">
                <div class="msg__av">{{ initials(m.from.name, m.from.mail) }}</div>
                <div class="msg__who">
                    <!-- Имя отправителя — кнопка: карточка с адресом и действиями (скопировать,
                         написать, найти все письма, в контактах), как в Mail.ru. -->
                    <div class="msg__from" :title="m.from.mail">
                        <button type="button" class="msg__who-btn" @click.stop="openCard($event, m.from)">
                            <b>{{ m.from.name }}</b>
                            <span v-if="m.from.name !== m.from.mail" class="mono msg__mail" style="color: var(--muted)">{{ m.from.mail }}</span>
                        </button>
                    </div>
                    <!-- 69: показываем первых троих, остальных — по щелчку; сорок адресатов
                         раньше выдавливали текст письма далеко вниз. -->
                    <div v-if="isOpen(m)" class="msg__to" :title="addrList(m.to) + (m.cc?.length ? ' · копия: ' + addrList(m.cc) : '')">
                        кому: {{ toText(m) || '—' }}<button v-if="toRest(m)" type="button" class="linklike" style="margin-left: 6px" @click.stop="allAddrs[key(m)] = true">и ещё {{ toRest(m) }}</button><button v-else-if="allAddrs[key(m)]" type="button" class="linklike" style="margin-left: 6px" @click.stop="allAddrs[key(m)] = false">свернуть</button>
                    </div>
                    <div v-else class="msg__snip">{{ (m.text || '').slice(0, 140) }}</div>
                    <!-- 392: письма из «Корзины» и «Спама» раньше в переписку не попадали
                         вовсе. Теперь попадают, но видно, где они лежат. -->
                    <span v-if="m.folderRole === 'trash' || m.folderRole === 'spam'" class="chip chip--warn msg__where">в папке «{{ m.folderName || (m.folderRole === 'trash' ? 'Корзина' : 'Спам') }}»</span>
                    <span v-else-if="m.bySubject" class="chip msg__where" title="Письмо склеено с перепиской по теме и собеседнику: отправитель не проставил ссылку на предыдущее письмо">по теме</span>
                </div>
                <!-- 84: на телефоне длинная дата отбирала всю ширину у имени отправителя. -->
                <div class="msg__when" :title="when(m.date, true)"><span class="msg__when-full">{{ when(m.date, true) }}</span><span class="msg__when-short">{{ when(m.date) }}</span></div>
                <div v-if="isOpen(m) && !isDraft" class="msg__acts" @click.stop>
                    <button class="ib ib--sm" type="button" title="Ответить" @click="$emit('reply', 'reply', m)" aria-label="Ответить"><Icon name="reply" :size="16" /></button>
                    <button class="ib ib--sm" type="button" title="Переслать" @click="$emit('reply', 'forward', m)" aria-label="Переслать"><Icon name="fwd" :size="16" /></button>
                    <a class="ib ib--sm" :href="api.rawUrl(m.folder, m.uid)" title="Скачать .eml" aria-label="Скачать .eml"><Icon name="download" :size="16" /></a>
                </div>
            </div>

            <template v-if="isOpen(m)">
                <div v-if="m.loading" class="msg__notice"><Icon name="refresh" :size="16" />Загружаем письмо…</div>
                <div v-else-if="failed[key(m)]" class="msg__notice">
                    <Icon name="warn" :size="16" />Письмо не загрузилось: {{ failed[key(m)] }}
                    <button type="button" class="linklike" style="font-weight: 600" @click.stop="load(m)">Повторить</button>
                </div>
                <div v-if="atts(m).length" class="msg__atts">
                    <!-- Чип вложения: имя — просмотр (если умеем) или скачивание; справа явные кнопки «посмотреть» и «скачать» -->
                    <span v-for="a in atts(m)" :key="a.index" class="att" :class="{ 'att--view': viewable(a), 'att--empty': isEmpty(a) }">
                        <!-- Пустое вложение скачивать не даём: файла нет, а нулевой байт в папке
                             «Загрузки» выглядит как поломка почты. Поэтому здесь не ссылка. -->
                        <span v-if="isEmpty(a)" class="att__main" :title="a.name + ' · файл не дошёл: отправитель объявил вложение, но не догрузил его'">
                            <Icon name="warn" :size="13" /><span class="name">{{ a.name }}</span><span class="sz">файл не дошёл</span>
                        </span>
                        <a v-else class="att__main" :href="api.attachmentUrl(m.folder, m.uid, a.index)" :title="isEml(a) ? a.name + ' · письмо во вложении — откроется в почте' : a.name + ' · ' + a.type" @click="(viewable(a) && (openAttachment(m, a), $event.preventDefault())) || (isEml(a) && (openAttachedMail(m, a), $event.preventDefault()))">
                            <Icon :name="isEml(a) ? 'mail' : 'clip'" :size="13" /><span class="name">{{ a.name }}</span><span class="sz">{{ size(a.size) }}</span>
                        </a>
                        <button v-if="viewable(a)" class="att__btn" type="button" title="Посмотреть" @click="openAttachment(m, a)" aria-label="Посмотреть"><Icon name="eye" :size="14" /></button>
                        <button v-if="isEml(a) && !isEmpty(a)" class="att__btn" type="button" title="Открыть письмо" aria-label="Открыть письмо" @click="openAttachedMail(m, a)"><Icon name="mail" :size="14" /></button>
                        <a v-if="!isEmpty(a)" class="att__btn" :href="api.attachmentUrl(m.folder, m.uid, a.index)" title="Скачать" aria-label="Скачать"><Icon name="download" :size="14" /></a>
                    </span>
                    <!-- Несколько вложений — одним архивом (обращение №11) -->
                    <a
                        v-if="attsOk(m).length > 1"
                        class="att att--all"
                        :href="api.attachmentsZipUrl(m.folder, m.uid)"
                        title="Все вложения одним ZIP-архивом"
                    >
                        <Icon name="download" :size="13" /><span class="name">Скачать все ({{ attsOk(m).length }})</span>
                    </a>
                </div>
                <!-- Файлы, ушедшие ссылкой через своё хранилище: смотреть, скачать, продлить (свои) -->
                <div v-if="cloudFiles(m).length" class="msg__atts msg__cloud">
                    <span v-for="f in cloudFiles(m)" :key="f.token" class="att att--cloud" :class="{ 'att--view': f.preview && !f.expired, 'att--gone': f.expired }" :title="f.expired ? f.name + ' · срок ссылки истёк' : f.name + ' · ссылка до ' + fmtDay(f.expires)">
                        <a v-if="!f.expired" class="att__main" :href="f.url" @click="f.preview && (openCloudFile(m, f), $event.preventDefault())">
                            <Icon name="cloud" :size="13" /><span class="name">{{ f.name }}</span><span class="sz">{{ size(f.size) }}</span>
                        </a>
                        <span v-else class="att__main"><Icon name="cloud" :size="13" /><span class="name">{{ f.name }}</span><span class="sz">срок истёк</span></span>
                        <button v-if="f.preview && !f.expired" class="att__btn" type="button" title="Посмотреть" aria-label="Посмотреть" @click="openCloudFile(m, f)"><Icon name="eye" :size="14" /></button>
                        <a v-if="!f.expired" class="att__btn" :href="f.url" title="Скачать" aria-label="Скачать"><Icon name="download" :size="14" /></a>
                        <button v-if="renewable(f)" class="att__btn" type="button" title="Продлить ссылку" aria-label="Продлить ссылку" @click="renewFile(f)"><Icon name="refresh" :size="14" /></button>
                    </span>
                    <a v-if="cloudZipped(m).length > 1" class="att att--all" :href="api.cloudZipUrl(m.folder, m.uid)" title="Все файлы из облака одним ZIP-архивом">
                        <Icon name="download" :size="13" /><span class="name">Скачать все ({{ cloudZipped(m).length }})</span>
                    </a>
                    <span class="msg__cloud-note">{{ cloudNote(m) }}</span>
                </div>
                <!-- Объясняем, что произошло, и что с этим делать: иначе пустой файл читается
                     как «почта потеряла вложение». -->
                <div v-if="attsEmpty(m).length" class="msg__notice">
                    <Icon name="warn" :size="16" />
                    <template v-if="attsEmpty(m).length === atts(m).length">Вложения пришли пустыми</template>
                    <template v-else>{{ attsEmpty(m).length }} из {{ atts(m).length }} вложений пришли пустыми</template>
                    — отправитель их не догрузил, чаще всего из-за плохой связи на телефоне. Попросите прислать файлы заново.
                </div>
                <div v-if="hasExternalImages(m) && !(ownLetter(m) && imagesShown(m))" class="msg__notice">
                    <Icon name="img" :size="16" />
                    <template v-if="imagesShown(m)">
                        Картинки из интернета показаны — их сервер мог отметить, что письмо открыли
                        <button v-if="settings.show_images !== 'always'" type="button" class="linklike" style="font-weight: 600" @click="showImages[key(m)] = false">Скрыть</button>
                    </template>
                    <template v-else>
                        Картинки из интернета скрыты
                        <button type="button" class="linklike" style="font-weight: 600" @click="showImages[key(m)] = true">Показать</button>
                    </template>
                </div>
                <!-- Внутренняя обёртка ограничивает ширину: письма верстают под 600–640 px,
                     и во всю ширину панели они разъезжаются. -->
                <div v-if="m.html" class="msg__body"><div class="msg__body-inner" v-html="body(m)" /></div>
                <div v-else class="msg__body"><div class="msg__body-inner"><pre class="msg__text">{{ m.text || '' }}</pre></div></div>
                <div v-if="m.listUnsubscribe && isLast(m)" style="padding: 0 20px 14px 72px">
                    <button class="chip chip--btn" type="button" @click="$emit('unsubscribe', m)"><Icon name="unsub" :size="13" />Отписаться от рассылки</button>
                </div>
            </template>
        </article>

        <div v-if="!isDraft && folderRole !== 'spam' && noReply" class="quick">
            <!-- 68: быстрые ответы на no-reply уходили в никуда, но выглядели как обычный разговор. -->
            <div class="msg__notice" style="margin: 0">
                <Icon name="warn" :size="16" />
                Письмо пришло с автоматического адреса <span class="mono">{{ replyTo(message)[0]?.mail }}</span> — ответ, скорее всего, никто не прочитает.
                <button type="button" class="linklike" style="font-weight: 600" @click="$emit('reply', 'reply', message)">Всё равно ответить</button>
            </div>
        </div>
        <div v-else-if="!isDraft && folderRole !== 'spam'" class="quick">
            <div v-if="settings.quick_replies?.length" class="quick__chips">
                <button v-for="qr in settings.quick_replies" :key="qr" class="chip chip--btn" type="button" @click="quick = qr">{{ qr }}</button>
            </div>
            <div class="quick__row">
                <input
                    v-model="quick"
                    class="input"
                    style="height: 36px"
                    :placeholder="'Ответить ' + (replyTo(message)[0]?.name || replyTo(message)[0]?.mail || '') + '…'"
                    @keydown.enter.prevent="sendQuick"
                >
                <button class="btn btn--sm" type="button" title="Открыть полный ответ: тема, копия, вложения, форматирование" aria-label="Открыть полный ответ" @click="$emit('reply', 'reply', message, quick)"><Icon name="edit" :size="14" />Полный ответ</button>
                <button class="btn btn--sm btn--primary" type="button" :disabled="!quick.trim() || sending" @click="sendQuick"><Icon name="send" :size="14" />Отправить</button>
            </div>
    </div>
    </div>
    <AttachmentViewer v-if="viewer" :items="viewer.items" :start="viewer.start" @close="viewer = null" />
    <AttachedMail v-if="attachedMail" :source="attachedMail" @close="attachedMail = null" />
</template>
