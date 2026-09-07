<script setup>
// Правая колонка: панель действий, цепочка писем, быстрый ответ.
import { computed, ref, watch } from 'vue';
import Icon from '../Icon.vue';
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
const labelMap = computed(() => Object.fromEntries(props.labels.map((l) => [l.id, l])));

watch(() => props.message.uid, () => { expanded.value = {}; quick.value = ''; });

const all = computed(() => [...(props.message.thread || []), props.message]);
const isLast = (m) => m === props.message;
const isOpen = (m) => isLast(m) || expanded.value[m.folder + '#' + m.uid];
function toggle(m) {
    if (isLast(m)) return;
    expanded.value[m.folder + '#' + m.uid] = !expanded.value[m.folder + '#' + m.uid];
}

function hasExternalImages(m) {
    return m.html && /<img[^>]+src=["']?(https?:)?\/\//i.test(m.html);
}

function body(m) {
    if (!m.html) return null;
    const key = m.folder + '#' + m.uid;
    if (showImages.value[key] || props.settings.show_images === 'always') {
        return m.html;
    }
    // Внешние картинки — только по кнопке: это следящие пиксели и лишний трафик.
    return m.html.replace(/(<img[^>]+)src=(["']?)(https?:)?\/\/[^"'\s>]+\2/gi, '$1data-blocked-src=""');
}

function replyTo(m) {
    return m.replyTo?.length ? m.replyTo : [m.from];
}

async function sendQuick() {
    const text = quick.value.trim();
    if (!text || sending.value) return;
    sending.value = true;
    try {
        await emit('quick', { text, message: props.message });
        quick.value = '';
    } finally {
        sending.value = false;
    }
}

function print() {
    window.print();
}

const isDraft = computed(() => props.folderRole === 'drafts');
</script>

<template>
    <div class="mread__bar">
        <button class="ib mobile-only" type="button" title="К списку" style="display: none" @click="$emit('back')"><Icon name="back" :size="18" /></button>
        <template v-if="isDraft">
            <button class="ib" type="button" @click="$emit('reply', 'draft', message)"><Icon name="edit" :size="16" />Продолжить черновик</button>
        </template>
        <template v-else>
            <button class="ib" type="button" title="Ответить (r)" @click="$emit('reply', 'reply', message)"><Icon name="reply" :size="16" />Ответить</button>
            <button class="ib" type="button" title="Ответить всем (a)" @click="$emit('reply', 'replyAll', message)"><Icon name="replyall" :size="16" />Всем</button>
            <button class="ib" type="button" title="Переслать (f)" @click="$emit('reply', 'forward', message)"><Icon name="fwd" :size="16" />Переслать</button>
            <button class="ib" type="button" title="Назначить встречу по этому письму" @click="$emit('meeting', message)"><Icon name="cal" :size="16" />Встреча</button>
        </template>
        <span class="sep" />
        <button class="ib" type="button" title="Архив (e)" @click="$emit('act', 'archive', [message.uid])"><Icon name="archive" :size="17" /></button>
        <button class="ib" type="button" title="Удалить (#)" @click="$emit('act', 'delete', [message.uid])"><Icon name="trash" :size="17" /></button>
        <button v-if="folderRole !== 'spam'" class="ib" type="button" title="Спам (!)" @click="$emit('act', 'spam', [message.uid])"><Icon name="spam" :size="17" /></button>
        <button v-else class="ib" type="button" title="Не спам" @click="$emit('act', 'notspam', [message.uid])"><Icon name="inbox" :size="17" />Не спам</button>
        <button class="ib" type="button" title="В папку (v)" @click="$emit('context', $event, message.uid, 'move')"><Icon name="folder" :size="17" /></button>
        <button class="ib" type="button" title="Метка (l)" @click="$emit('context', $event, message.uid, 'label')"><Icon name="tag" :size="17" /></button>
        <button class="ib" type="button" title="Отложить (z)" @click="$emit('context', $event, message.uid, 'snooze')"><Icon name="clock" :size="17" /></button>
        <button class="ib" type="button" :class="{ 'ib--on': message.flagged }" title="Флажок (s)" @click="$emit('act', message.flagged ? 'unflag' : 'flag', [message.uid])"><Icon name="flag" :size="17" /></button>
        <span class="grow" />
        <button class="ib" type="button" title="Печать" @click="print"><Icon name="print" :size="17" /></button>
        <button class="ib" type="button" title="Ещё" @click="$emit('context', $event, message.uid, 'more')"><Icon name="dots" :size="17" /></button>
    </div>

    <div class="mread__scroll">
        <div class="mread__title">
            <h1>{{ message.subject }}</h1>
            <span v-for="id in message.labels" :key="id">
                <span v-if="labelMap[id]" class="lbl" :style="{ background: labelMap[id].color + '22', color: labelMap[id].color, height: 22 }">{{ labelMap[id].name }}</span>
            </span>
            <span v-if="all.length > 1" class="thr">{{ all.length }} в цепочке</span>
        </div>

        <article v-for="m in all" :key="m.folder + '#' + m.uid" class="msg" :class="{ 'msg--col': !isOpen(m) }">
            <div class="msg__hd" @click="toggle(m)">
                <div class="msg__av">{{ initials(m.from.name, m.from.mail) }}</div>
                <div class="msg__who">
                    <div class="msg__from" :title="m.from.mail">
                        <b>{{ m.from.name }}</b>
                        <span v-if="m.from.name !== m.from.mail" class="mono msg__mail" style="color: var(--muted)">{{ m.from.mail }}</span>
                    </div>
                    <div v-if="isOpen(m)" class="msg__to" :title="addrList(m.to) + (m.cc?.length ? ' · копия: ' + addrList(m.cc) : '')">
                        кому: {{ addrList(m.to) || '—' }}<span v-if="m.cc?.length"> · копия: {{ addrList(m.cc) }}</span>
                    </div>
                    <div v-else class="msg__snip">{{ (m.text || '').slice(0, 140) }}</div>
                </div>
                <div class="msg__when" :title="when(m.date, true)">{{ when(m.date, true) }}</div>
                <div v-if="isOpen(m) && !isDraft" class="msg__acts" @click.stop>
                    <button class="ib ib--sm" type="button" title="Ответить" @click="$emit('reply', 'reply', m)"><Icon name="reply" :size="16" /></button>
                    <button class="ib ib--sm" type="button" title="Переслать" @click="$emit('reply', 'forward', m)"><Icon name="fwd" :size="16" /></button>
                    <a class="ib ib--sm" :href="api.rawUrl(m.folder, m.uid)" title="Скачать .eml"><Icon name="download" :size="16" /></a>
                </div>
            </div>

            <template v-if="isOpen(m)">
                <div v-if="m.attachments?.filter((a) => !a.inline).length" class="msg__atts">
                    <a
                        v-for="a in m.attachments.filter((a) => !a.inline)"
                        :key="a.index"
                        class="att"
                        :href="api.attachmentUrl(m.folder, m.uid, a.index)"
                        :title="a.name + ' · ' + a.type"
                    >
                        <Icon name="clip" :size="13" /><span class="name">{{ a.name }}</span><span class="sz">{{ size(a.size) }}</span>
                    </a>
                </div>
                <div v-if="hasExternalImages(m) && !showImages[m.folder + '#' + m.uid] && settings.show_images !== 'always'" class="msg__notice">
                    <Icon name="img" :size="16" />
                    Картинки из интернета скрыты
                    <a style="cursor: pointer; font-weight: 600" @click="showImages[m.folder + '#' + m.uid] = true">Показать</a>
                </div>
                <div v-if="m.html" class="msg__body" v-html="body(m)" />
                <div v-else class="msg__body"><pre class="msg__text">{{ m.text || '' }}</pre></div>
                <div v-if="m.listUnsubscribe && isLast(m)" style="padding: 0 20px 14px 72px">
                    <button class="chip chip--btn" type="button" @click="$emit('unsubscribe', m)"><Icon name="unsub" :size="13" />Отписаться от рассылки</button>
                </div>
            </template>
        </article>

        <div v-if="!isDraft && folderRole !== 'spam'" class="quick">
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
                <button class="btn btn--sm" type="button" title="Открыть полный ответ" @click="$emit('reply', 'reply', message, quick)"><Icon name="edit" :size="14" /></button>
                <button class="btn btn--sm btn--primary" type="button" :disabled="!quick.trim() || sending" @click="sendQuick"><Icon name="send" :size="14" />Отправить</button>
            </div>
        </div>
    </div>
</template>
