<script setup>
// Правая колонка: панель действий, цепочка писем, быстрый ответ.
import { computed, ref, watch } from 'vue';
import Icon from '../Icon.vue';
import AttachmentViewer from './AttachmentViewer.vue';
import { viewable, viewerItems } from '../../mail/attachments';
import { api } from '../../mail/api';
import { addrList, initials, size, when } from '../../mail/format';

const props = defineProps({
    message: { type: Object, required: true },
    folder: String,
    folderRole: String,
    labels: { type: Array, default: () => [] },
    settings: { type: Object, default: () => ({}) },
    user: String,
});
const emit = defineEmits(['act', 'reply', 'quick', 'context', 'back', 'unsubscribe', 'meeting']);

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
function openAttachment(m, a) {
    const list = m.attachments.filter(viewable);
    viewer.value = { start: Math.max(0, list.findIndex((x) => x.index === a.index)), items: viewerItems(m.folder, m.uid, m.attachments) };
}
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

function body(m) {
    if (!m.html) return null;
    return imagesShown(m) ? m.html.replace(/\sdata-blocked-(src|srcset|background)=/gi, ' $1=') : m.html;
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
    // Печатная форма — отдельная страница без интерфейса (тема, поля, вложения, текст, переписка).
    const m = props.message;
    if (!m) return;
    window.open(`/mail/print/${encodeURIComponent(m.folder)}/${m.uid}`, '_blank');
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

        <article v-for="m in all" :key="m.folder + '#' + m.uid" class="msg" :class="{ 'msg--col': !isOpen(m) }">
            <div class="msg__hd" @click="toggle(m)">
                <div class="msg__av">{{ initials(m.from.name, m.from.mail) }}</div>
                <div class="msg__who">
                    <div class="msg__from" :title="m.from.mail">
                        <b>{{ m.from.name }}</b>
                        <span v-if="m.from.name !== m.from.mail" class="mono msg__mail" style="color: var(--muted)">{{ m.from.mail }}</span>
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
                <div v-if="m.attachments?.filter((a) => !a.inline).length" class="msg__atts">
                    <!-- Чип вложения: имя — просмотр (если умеем) или скачивание; справа явные кнопки «посмотреть» и «скачать» -->
                    <span v-for="a in m.attachments.filter((a) => !a.inline)" :key="a.index" class="att" :class="{ 'att--view': viewable(a) }">
                        <a class="att__main" :href="api.attachmentUrl(m.folder, m.uid, a.index)" :title="a.name + ' · ' + a.type" @click="viewable(a) && (openAttachment(m, a), $event.preventDefault())">
                            <Icon name="clip" :size="13" /><span class="name">{{ a.name }}</span><span class="sz">{{ size(a.size) }}</span>
                        </a>
                        <button v-if="viewable(a)" class="att__btn" type="button" title="Посмотреть" @click="openAttachment(m, a)" aria-label="Посмотреть"><Icon name="eye" :size="14" /></button>
                        <a class="att__btn" :href="api.attachmentUrl(m.folder, m.uid, a.index)" title="Скачать" aria-label="Скачать"><Icon name="download" :size="14" /></a>
                    </span>
                    <!-- Несколько вложений — одним архивом (обращение №11) -->
                    <a
                        v-if="m.attachments.filter((a) => !a.inline).length > 1"
                        class="att att--all"
                        :href="api.attachmentsZipUrl(m.folder, m.uid)"
                        title="Все вложения одним ZIP-архивом"
                    >
                        <Icon name="download" :size="13" /><span class="name">Скачать все ({{ m.attachments.filter((a) => !a.inline).length }})</span>
                    </a>
                </div>
                <div v-if="hasExternalImages(m)" class="msg__notice">
                    <Icon name="img" :size="16" />
                    <template v-if="imagesShown(m)">
                        Картинки из интернета показаны — отправитель узнал, что письмо открыли
                        <button v-if="settings.show_images !== 'always'" type="button" class="linklike" style="font-weight: 600" @click="showImages[key(m)] = false">Скрыть</button>
                    </template>
                    <template v-else>
                        Картинки из интернета скрыты
                        <button type="button" class="linklike" style="font-weight: 600" @click="showImages[key(m)] = true">Показать</button>
                    </template>
                </div>
                <div v-if="m.html" class="msg__body" v-html="body(m)" />
                <div v-else class="msg__body"><pre class="msg__text">{{ m.text || '' }}</pre></div>
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
</template>
