<script setup>
// Форма «Написать»: адресаты, тема, редактор, вложения, отправить позже, напоминание, черновик.
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import Icon from '../Icon.vue';
import RecipientInput from './RecipientInput.vue';
import Editor from './Editor.vue';
import Popover from './Popover.vue';
import { api, composeForm } from '../../mail/api';
import { addrString, presets, size, toLocalInput, when } from '../../mail/format';

const props = defineProps({
    compose: { type: Object, required: true },
    identities: { type: Array, default: () => [] },
    settings: { type: Object, default: () => ({}) },
    cloud: { type: Object, default: () => ({ enabled: false, thresholdMb: 10, maxMb: 50 }) },
});
const emit = defineEmits(['close', 'send', 'toast', 'draft']);

const c = props.compose;
const to = ref(c.to || []);
const cc = ref(c.cc || []);
const bcc = ref(c.bcc || []);
const showCc = ref(cc.value.length > 0);
const showBcc = ref(bcc.value.length > 0);
const from = ref(c.from || props.identities[0]?.mail || '');
const subject = ref(c.subject || '');
const html = ref(c.html || '');
const files = ref([]);
const existing = ref(c.attachments || []);
const keepAttachments = ref(c.keepAttachments ?? (c.mode === 'forward'));
const draftUid = ref(c.draftUid || null);
const priority = ref(false);
const receipt = ref(false);
const remindDays = ref(0);
const menu = ref(null); // 'later' | 'more' | 'remind'
const menuAt = ref({ x: 0, y: 0 });
const customAt = ref(toLocalInput(new Date(Date.now() + 3600000)));
const dirty = ref(false);
const status = ref('');
const drop = ref(false);
const editor = ref(null);
const toInput = ref(null);
const fileInput = ref(null);
let autosave = null;

const MAX_FILE = (props.cloud?.maxMb || 50) * 1024 * 1024;
const CLOUD_FROM = (props.cloud?.thresholdMb || 10) * 1024 * 1024;
const viaCloud = ref(new Set());   // индексы файлов, которые уйдут ссылкой
const cloudCount = computed(() => viaCloud.value.size);
function toggleCloud(i) { const s = new Set(viaCloud.value); s.has(i) ? s.delete(i) : s.add(i); viaCloud.value = s; dirty.value = true; }
const totalSize = computed(() => files.value.reduce((s, f) => s + f.size, 0));
const canSend = computed(() => (to.value.length + cc.value.length + bcc.value.length) > 0 && !to.value.some((a) => a.bad));

function payload(extra = {}) {
    return {
        from: from.value,
        to: addrString(to.value),
        cc: addrString(cc.value),
        bcc: addrString(bcc.value),
        subject: subject.value,
        html: html.value,
        cloud: props.cloud?.enabled ? [...viaCloud.value] : [],
        inReplyTo: c.inReplyTo,
        references: c.references,
        answeredFolder: c.answeredFolder,
        answeredUid: c.answeredUid,
        sourceFolder: c.sourceFolder,
        sourceUid: c.sourceUid,
        keepAttachments: keepAttachments.value && existing.value.length > 0,
        draftUid: draftUid.value,
        priority: priority.value,
        receipt: receipt.value,
        remindDays: remindDays.value || null,
        ...extra,
    };
}

function send(sendAt = null) {
    if (!canSend.value) {
        emit('toast', { text: 'Укажите получателя', error: true });
        toInput.value?.focus();
        return;
    }
    if (!subject.value.trim() && !window.confirm('Отправить письмо без темы?')) return;
    menu.value = null;
    dirty.value = false;
    emit('send', { form: payload({ sendAt: sendAt ? sendAt.toISOString() : null }), files: files.value, sendAt });
}

async function saveDraft(silent = false) {
    if (!dirty.value && silent) return;
    status.value = 'Сохраняю…';
    try {
        const r = await api.draft(composeForm(payload(), files.value));
        draftUid.value = r.draftUid;
        dirty.value = false;
        status.value = 'Черновик сохранён ' + when(new Date().toISOString());
        emit('draft', r);
    } catch (e) {
        status.value = 'Не удалось сохранить черновик';
        if (!silent) emit('toast', { text: e.message, error: true });
    }
}

function close() {
    if (dirty.value && (to.value.length || subject.value || html.value.replace(/<[^>]+>/g, '').trim())) {
        saveDraft(true);
    }
    emit('close');
}

function addFiles(list) {
    for (const f of list) {
        if (f.size > MAX_FILE) { emit('toast', { text: `«${f.name}» больше ${Math.round(MAX_FILE / 1048576)} МБ — не влезет ни в письмо, ни в облако`, error: true }); continue; }
        if (!files.value.some((x) => x.name === f.name && x.size === f.size)) {
            files.value.push(f);
            if (props.cloud?.enabled && f.size >= CLOUD_FROM) { const s = new Set(viaCloud.value); s.add(files.value.length - 1); viaCloud.value = s; }
        }
    }
    dirty.value = true;
}
function onFiles(e) { addFiles(e.target.files); e.target.value = ''; }
function onDrop(e) { drop.value = false; if (e.dataTransfer?.files?.length) addFiles(e.dataTransfer.files); }
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
    if (e.key === 'Escape' && !menu.value) { e.stopPropagation(); close(); }
}

watch([to, cc, bcc, subject, html, from, keepAttachments], () => { dirty.value = true; }, { deep: true });

onMounted(() => {
    dirty.value = false;
    autosave = setInterval(() => saveDraft(true), 30000);
    setTimeout(() => {
        if (to.value.length) editor.value?.focusStart();
        else toInput.value?.focus();
    }, 50);
});
onBeforeUnmount(() => clearInterval(autosave));

const title = computed(() => ({ reply: 'Ответ', replyAll: 'Ответ всем', forward: 'Пересылка', draft: 'Черновик' }[c.mode] || 'Новое письмо'));
</script>

<template>
    <div
        class="compose"
        :class="{ 'compose--drop': drop }"
        @keydown="onKey"
        @dragover.prevent="drop = true"
        @dragleave="drop = false"
        @drop.prevent="onDrop"
    >
        <div class="compose__row" style="border-bottom: 1px solid var(--border); background: var(--surface-2); border-radius: 12px 12px 0 0">
            <b style="font-size: 15px">{{ title }}</b>
            <span style="flex: 1" />
            <button class="ib ib--sm" type="button" title="Закрыть (черновик сохранится)" @click="close"><Icon name="x" :size="16" /></button>
        </div>

        <div class="compose__row">
            <label>Кому</label>
            <RecipientInput ref="toInput" v-model="to" placeholder="Имя или адрес" />
            <span class="links">
                <a v-if="!showCc" @click="showCc = true">Копия</a>
                <a v-if="!showBcc" @click="showBcc = true">Скрытая</a>
            </span>
        </div>
        <div v-if="showCc" class="compose__row">
            <label>Копия</label>
            <RecipientInput v-model="cc" />
        </div>
        <div v-if="showBcc" class="compose__row">
            <label>Скрытая</label>
            <RecipientInput v-model="bcc" />
        </div>
        <div v-if="identities.length > 1" class="compose__row">
            <label>От кого</label>
            <select v-model="from">
                <option v-for="i in identities" :key="i.mail" :value="i.mail">{{ i.mail }}</option>
            </select>
        </div>
        <div class="compose__row">
            <label>Тема</label>
            <input v-model="subject" placeholder="Тема письма" @keydown.enter.prevent="editor?.focus()">
            <span v-if="priority" class="chip chip--warn">Важное</span>
        </div>

        <Editor ref="editor" v-model="html" @submit="send()" @save="saveDraft()">
            <template #right>
                <label v-if="existing.length" class="toggle" style="font-size: 12.5px">
                    <input v-model="keepAttachments" type="checkbox"><span class="toggle__track" />Вложения исходного письма ({{ existing.length }})
                </label>
            </template>
        </Editor>

        <div v-if="files.length || (keepAttachments && existing.length)" class="compose__atts">
            <template v-if="keepAttachments">
                <span v-for="a in existing" :key="'e' + a.index" class="att" :title="a.name">
                    <Icon name="clip" :size="13" /><span class="name">{{ a.name }}</span><span class="sz">{{ size(a.size) }}</span>
                </span>
            </template>
            <span v-for="(f, i) in files" :key="f.name + i" class="att" :class="{ 'att--cloud': viaCloud.has(i) }" :title="viaCloud.has(i) ? 'Уйдёт ссылкой через облако' : f.name">
                <Icon :name="viaCloud.has(i) ? 'cloud' : 'clip'" :size="13" /><span class="name">{{ f.name }}</span><span class="sz">{{ size(f.size) }}</span>
                <button v-if="cloud.enabled" type="button" :title="viaCloud.has(i) ? 'Вложить в письмо' : 'Отправить ссылкой через облако'" @click="toggleCloud(i)"><Icon :name="viaCloud.has(i) ? 'clip' : 'cloud'" :size="13" /></button>
                <button type="button" title="Убрать" @click="removeFile(i)"><Icon name="x" :size="13" /></button>
            </span>
            <span v-if="cloud.enabled && cloudCount" class="chip chip--ok" style="height: 28px"><Icon name="cloud" :size="13" /> {{ cloudCount }} {{ cloudCount === 1 ? 'файл уйдёт ссылкой' : 'файла уйдут ссылкой' }} — получатель откроет их в облаке</span>
            <span v-else-if="totalSize > 20 * 1048576" class="chip chip--warn" style="height: 28px">{{ size(totalSize) }} — большое письмо может не пройти у получателя</span>
        </div>

        <div class="compose__foot">
            <span class="split">
                <button class="btn btn--primary" type="button" :disabled="!canSend" title="Ctrl+Enter" @click="send()">
                    <Icon name="send" :size="15" />Отправить
                </button>
                <button class="btn btn--primary" type="button" title="Отправить позже" :disabled="!canSend" @click="openMenu('later', $event)">
                    <Icon name="clock" :size="16" />
                </button>
            </span>
            <button class="ib" type="button" title="Вложить файл" @click="fileInput?.click()"><Icon name="clip" :size="17" /></button>
            <input ref="fileInput" type="file" multiple hidden @change="onFiles">
            <button class="ib" type="button" :class="{ 'ib--on': remindDays }" title="Напомнить, если не ответят" @click="openMenu('remind', $event)">
                <Icon name="bell" :size="17" /><span v-if="remindDays">{{ remindDays }} дн.</span>
            </button>
            <button class="ib" type="button" title="Ещё" @click="openMenu('more', $event)"><Icon name="dots" :size="17" /></button>
            <span class="grow" />
            <span class="status">{{ status }}</span>
            <button class="ib" type="button" title="Сохранить черновик (Ctrl+S)" @click="saveDraft()"><Icon name="edit" :size="16" /></button>
            <button class="ib ib--danger" type="button" title="Удалить черновик и закрыть" @click="dirty = false; $emit('close', { discard: true, draftUid })"><Icon name="trash" :size="16" /></button>
        </div>

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
