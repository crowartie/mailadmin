<script setup>
// «Обращения» — заявки сотрудников об ошибках, предложения и вопросы.
// Состояние (где обращение) и итог (чем закончилось) разделены: закрыть можно только с итогом,
// поэтому в списке всегда видно, почему обращение закрыли, а не просто «закрыто».
// Ответы и действия уходят обычными запросами — страница обновляет себя сама, без перерисовки.
import { Link, router } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import Icon from '../../Components/Icon.vue';
import { http } from '../../admin/http';

const props = defineProps({
    rows: Array,
    open: Object,
    filter: String,
    search: String,
    filters: Object,
    counts: Object,
    dict: Object,
    me: String,
});

const list = ref([...(props.rows || [])]);
const ticket = ref(props.open ? { ...props.open } : null);
const messages = ref(props.open?.messages ? [...props.open.messages] : []);
const q = ref(props.search || '');
const reply = ref('');
const file = ref(null);
const filePreview = ref('');
const sending = ref(false);
const error = ref('');
const note = ref('');
const closing = ref(null);
const closeNote = ref('');
const duplicateOf = ref('');
const showErrors = ref(false);
const scroll = ref(null);
const box = ref(null);

watch(() => props.rows, (v) => { list.value = [...(v || [])]; });
watch(() => props.open, (v) => {
    ticket.value = v ? { ...v } : null;
    messages.value = v?.messages ? [...v.messages] : [];
    reply.value = '';
    closing.value = null;
    closeNote.value = '';
    duplicateOf.value = '';
    showErrors.value = false;
    clearFile();
    toBottom();
});

let timer = null;
watch(q, () => {
    clearTimeout(timer);
    timer = setTimeout(() => router.get('/feedback', { filter: props.filter, search: q.value || undefined, id: ticket.value?.id }, { preserveState: true, preserveScroll: true, replace: true }), 300);
});

const look = (t) => (t.status === 'closed'
    ? (t.resolution === 'done' ? { ava: 'fbchat__ava--ok', chip: 'chip--ok', icon: 'check' } : { ava: 'fbchat__ava--off', chip: 'chip--off', icon: 'x' })
    : (t.status === 'waiting' ? { ava: 'fbchat__ava--warn', chip: 'chip--warn', icon: 'reply' } : { ava: '', chip: 'chip--acc', icon: t.kind === 'idea' ? 'star' : t.kind === 'question' ? 'info' : 'warn' }));
const chip = (t) => look(t).chip;

const when = (iso) => (iso ? new Date(iso).toLocaleString('ru-RU', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : '');
const ago = (iso) => {
    if (!iso) return '';
    const m = Math.round((Date.now() - new Date(iso)) / 60000);
    if (m < 1) return 'только что';
    if (m < 60) return m + ' мин назад';
    if (m < 1440) return Math.round(m / 60) + ' ч назад';
    return Math.round(m / 1440) + ' дн назад';
};

function url(patch = {}) {
    const p = { filter: props.filter, ...(q.value ? { search: q.value } : {}), ...(ticket.value ? { id: ticket.value.id } : {}), ...patch };
    return '/feedback?' + new URLSearchParams(Object.fromEntries(Object.entries(p).filter(([, v]) => v !== undefined && v !== null && v !== ''))).toString();
}

function toBottom() {
    nextTick(() => { if (scroll.value) scroll.value.scrollTop = scroll.value.scrollHeight; });
}

/** Обновить обращение в открытой карточке и в списке, не перезагружая страницу. */
function applyTicket(t) {
    if (!t) return;
    if (ticket.value && ticket.value.id === t.id) ticket.value = { ...ticket.value, ...t };
    const i = list.value.findIndex((x) => x.id === t.id);
    const last = messages.value.length ? messages.value[messages.value.length - 1] : null;
    const row = { ...(i >= 0 ? list.value[i] : {}), ...t, last: last ? { text: String(last.text).slice(0, 90), at: last.at, role: last.role } : null };
    if (i >= 0) list.value.splice(i, 1, row); else list.value.unshift(row);
}

function flash(text) {
    note.value = text;
    setTimeout(() => { if (note.value === text) note.value = ''; }, 4000);
}

// ── Вложение к ответу ────────────────────────────────────────────
function pick(e) {
    const f = e.target.files?.[0];
    if (f) setFile(f);
    e.target.value = '';
}
function setFile(f) {
    if (f.size > 8 * 1024 * 1024) { error.value = 'Снимок больше 8 МБ.'; return; }
    file.value = f;
    filePreview.value = URL.createObjectURL(f);
    error.value = '';
}
function clearFile() {
    if (filePreview.value) URL.revokeObjectURL(filePreview.value);
    file.value = null;
    filePreview.value = '';
}
function onPaste(e) {
    const item = [...(e.clipboardData?.items || [])].find((i) => i.type.startsWith('image/'));
    if (item) { const f = item.getAsFile(); if (f) { setFile(f); e.preventDefault(); } }
}

// ── Ответ: появляется сразу ──────────────────────────────────────
async function send(ask = false) {
    if ((!reply.value.trim() && !file.value) || sending.value) return;
    const text = reply.value.trim();
    const keptPreview = filePreview.value;
    const draft = { id: 'tmp' + Date.now(), role: 'admin', author: props.me || 'админ', text: text || 'Снимок экрана', file: !!file.value, at: new Date().toISOString(), pending: true, preview: keptPreview };
    messages.value.push(draft);
    toBottom();
    sending.value = true;
    error.value = '';
    const fd = new FormData();
    fd.append('text', text || 'Снимок экрана');
    if (ask) fd.append('ask', '1');
    if (file.value) fd.append('file', file.value, file.value.name || 'screen.png');
    reply.value = '';
    file.value = null;
    filePreview.value = '';
    try {
        const r = await http('POST', `/feedback/${ticket.value.id}/reply`, fd);
        Object.assign(draft, r.message, { pending: false, preview: '' });
        if (keptPreview) URL.revokeObjectURL(keptPreview);
        applyTicket(r.ticket);
        flash(r.flash);
    } catch (e) {
        draft.pending = false;
        draft.failed = true;
        error.value = e.message;
    } finally {
        sending.value = false;
    }
}

async function act(path, body) {
    error.value = '';
    try {
        const r = await http('POST', path, body);
        if (r.message) { messages.value.push(r.message); toBottom(); }
        applyTicket(r.ticket);
        flash(r.flash);
    } catch (e) {
        error.value = e.message;
    }
}

const take = () => act(`/feedback/${ticket.value.id}`, { status: 'open', assign: true });
const priority = (key) => act(`/feedback/${ticket.value.id}`, { priority: key });
const reopen = () => act(`/feedback/${ticket.value.id}/reopen`, {});

async function close() {
    await act(`/feedback/${ticket.value.id}/close`, {
        resolution: closing.value,
        text: closeNote.value.trim() || undefined,
        duplicate_of: closing.value === 'duplicate' && duplicateOf.value ? Number(duplicateOf.value) : undefined,
    });
    closing.value = null;
    closeNote.value = '';
    duplicateOf.value = '';
}

function del() {
    if (confirm(`Удалить обращение №${ticket.value.id} вместе с перепиской?`)) router.delete(`/feedback/${ticket.value.id}`);
}

// ── Живое обновление открытой переписки ──────────────────────────
let poller = null;
async function poll() {
    if (document.hidden || !ticket.value || sending.value) return;
    try {
        const lastId = [...messages.value].reverse().find((m) => typeof m.id === 'number')?.id || 0;
        const r = await http('GET', `/feedback/${ticket.value.id}/poll?after=${lastId}`);
        if (r.messages?.length) { messages.value.push(...r.messages); toBottom(); }
        applyTicket(r.ticket);
    } catch { /* попробуем в следующий раз */ }
}
onMounted(() => { toBottom(); poller = setInterval(poll, 15000); });
onBeforeUnmount(() => { clearInterval(poller); clearFile(); });

function onKey(e) {
    if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') { e.preventDefault(); send(false); }
}

const errors = computed(() => ticket.value?.context?.errors || []);
</script>

<template>
    <AppLayout title="Обращения" :count="counts.active">
        <template #actions>
            <div class="seg">
                <Link v-for="(label, key) in filters" :key="key" class="seg__item" :class="{ 'seg__item--on': filter === key }" :href="url({ filter: key, id: undefined })">
                    {{ label }}<span v-if="counts[key]" class="seg__count">{{ counts[key] }}</span>
                </Link>
            </div>
            <input v-model="q" class="input input--w" style="width: 240px" type="search" placeholder="Номер, тема или сотрудник">
        </template>

        <div class="grid-2-1" style="grid-template-columns: minmax(300px, 380px) minmax(0, 1fr)">
            <div class="card card--flush fbchat__list fbchat__list--plain" style="max-height: calc(100vh - 176px)">
                <Link
                    v-for="t in list"
                    :key="t.id"
                    class="fbchat__item"
                    :class="{ 'fbchat__item--on': ticket && ticket.id === t.id }"
                    :href="url({ id: t.id })"
                    preserve-scroll
                >
                    <span class="fbchat__ava" :class="look(t).ava"><Icon :name="look(t).icon" :size="18" /></span>
                    <span class="fbchat__main">
                        <span class="fbchat__top">
                            <span class="fbchat__title">{{ t.subject }}</span>
                            <span class="fbchat__time" :title="when(t.createdAt)">{{ ago(t.lastReplyAt || t.createdAt) }}</span>
                        </span>
                        <span class="fbchat__snip">№{{ t.id }} · {{ t.userName || t.user }} · {{ t.kindLabel }}</span>
                        <span class="fbchat__badges">
                            <span class="chip" :class="look(t).chip" style="height: 20px; font-size: 11px">{{ t.statusLabel }}</span>
                            <span v-if="t.priority === 'high'" class="chip chip--no" style="height: 20px; font-size: 11px">срочно</span>
                            <span v-if="t.newForAdmin" class="dot dot--no" title="Не прочитано" />
                        </span>
                    </span>
                </Link>
                <div v-if="!list.length" class="empty">Обращений нет. Сотрудники пишут из веб-почты кнопкой «Сообщить о проблеме».</div>
            </div>

            <!-- Одна карточка: шапка, что снялось само, переписка, ответ и действия -->
            <div v-if="ticket" class="card card--flush fbadmin__chat">
                <div class="fbchat__head">
                    <span class="fbchat__ava" :class="look(ticket).ava"><Icon :name="look(ticket).icon" :size="18" /></span>
                    <div style="min-width: 0; flex: 1">
                        <h2 :title="ticket.subject">№{{ ticket.id }} · {{ ticket.subject }}</h2>
                        <p class="hint" style="margin: 2px 0 0">
                            {{ ticket.userName || ticket.user }} · {{ ticket.user }} · {{ ticket.kindLabel }} · {{ when(ticket.createdAt) }}
                            <span v-if="ticket.assignedTo"> · у {{ ticket.assignedTo }}</span>
                        </p>
                    </div>
                    <span class="chip" :class="chip(ticket)">{{ ticket.statusLabel }}</span>
                </div>

                <div class="fbfacts">
                    <span>Снято автоматически:</span>
                    <span>Страница <b>{{ ticket.page || '—' }}</b></span>
                    <span>Адрес <b class="mono">{{ ticket.pageUrl || '—' }}</b></span>
                    <span>Программа <b>{{ ticket.client || '—' }}</b></span>
                    <span v-if="ticket.context.screen">Экран <b>{{ ticket.context.screen }}<template v-if="ticket.context.viewport">, окно {{ ticket.context.viewport }}</template></b></span>
                    <span>В сети <b class="mono">{{ ticket.ip }}</b></span>
                    <button v-if="errors.length" type="button" class="fb__more" style="font-size: 12px" @click="showErrors = !showErrors">
                        <Icon :name="showErrors ? 'down' : 'chevron'" :size="13" />Ошибки на странице ({{ errors.length }})
                    </button>
                </div>
                <div v-if="showErrors && errors.length" class="fbfacts" style="display: block">
                    <div v-for="(e, i) in errors" :key="i" class="mono" style="font-size: 11.5px">{{ e.text }}</div>
                </div>

                <div ref="scroll" class="fbchat__scroll" style="min-height: 220px; max-height: 42vh">
                    <div
                        v-for="m in messages"
                        :key="m.id"
                        class="fbchat__b"
                        :class="[m.role === 'admin' ? 'fbchat__b--me' : (m.role === 'system' ? 'fbchat__b--sys' : 'fbchat__b--them'), { 'fbchat__b--pending': m.pending, 'fbchat__b--failed': m.failed }]"
                    >
                        <div v-if="m.role === 'user'" class="fbchat__who">{{ ticket.userName || ticket.user }}</div>
                        <div v-else-if="m.role === 'admin'" class="fbchat__who">{{ m.author }}</div>
                        <div>{{ m.text }}</div>
                        <a v-if="m.file && !m.pending" :href="`/feedback/${ticket.id}/file/${m.id}`" target="_blank" class="fbchat__shot">
                            <img :src="`/feedback/${ticket.id}/file/${m.id}`" alt="снимок экрана">
                        </a>
                        <div v-else-if="m.preview" class="fbchat__shot"><img :src="m.preview" alt="снимок экрана"></div>
                        <div class="fbchat__at">
                            <span v-if="m.pending">отправляется…</span>
                            <span v-else-if="m.failed">не отправлено</span>
                            <span v-else>{{ when(m.at) }}</span>
                        </div>
                    </div>
                </div>

                <div v-if="filePreview" class="fbchat__attach">
                    <img :src="filePreview" alt="снимок экрана">
                    <span class="hint" style="flex: 1">Снимок будет приложен к ответу</span>
                    <button class="ib ib--sm" type="button" title="Убрать" @click="clearFile"><Icon name="x" :size="14" /></button>
                </div>

                <div class="fbchat__foot" style="flex-direction: column; align-items: stretch; gap: 10px" @paste="onPaste">
                    <textarea
                        ref="box"
                        v-model="reply"
                        class="input"
                        rows="2"
                        style="resize: vertical; min-height: 62px; height: auto"
                        placeholder="Ответ сотруднику — уйдёт письмом и появится у него в «Обращениях». Ctrl+Enter — отправить"
                        @keydown="onKey"
                    />
                    <div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center">
                        <label class="ib" title="Приложить снимок экрана" style="cursor: pointer">
                            <input type="file" accept="image/*" hidden @change="pick">
                            <Icon name="clip" :size="18" />
                        </label>
                        <button v-if="ticket.status === 'new' || !ticket.assignedTo" class="btn btn--sm" type="button" @click="take"><Icon name="check" :size="14" />Взять в работу</button>
                        <span v-if="note" class="chip chip--ok">{{ note }}</span>
                        <span style="flex: 1" />
                        <button class="btn" type="button" :disabled="sending || (!reply.trim() && !file)" @click="send(true)"><Icon name="reply" :size="15" />Уточнить и ждать</button>
                        <button class="btn btn--primary" type="button" :disabled="sending || (!reply.trim() && !file)" @click="send(false)"><Icon name="send" :size="15" />Ответить</button>
                    </div>
                    <p v-if="error" class="error" style="margin: 0">{{ error }}</p>
                </div>

                <div class="fbadmin__bar">
                    <template v-if="ticket.status !== 'closed'">
                        <span class="hint">Закрыть с итогом:</span>
                        <div class="seg">
                            <button v-for="(label, key) in dict.resolutions" :key="key" type="button" class="seg__item" :class="{ 'seg__item--on': closing === key }" @click="closing = closing === key ? null : key">{{ label }}</button>
                        </div>
                    </template>
                    <template v-else>
                        <span class="hint">Закрыто {{ when(ticket.closedAt) }}: {{ ticket.statusLabel.toLowerCase() }}<span v-if="ticket.duplicateOf">, повтор №{{ ticket.duplicateOf }}</span></span>
                        <button class="btn btn--sm" type="button" @click="reopen"><Icon name="refresh" :size="14" />Вернуть в работу</button>
                    </template>
                    <span style="flex: 1" />
                    <span class="hint">Важность:</span>
                    <div class="seg">
                        <button v-for="(label, key) in dict.priorities" :key="key" type="button" class="seg__item" :class="{ 'seg__item--on': ticket.priority === key }" @click="priority(key)">{{ label }}</button>
                    </div>
                    <button class="btn btn--sm btn--danger" type="button" title="Удалить обращение" @click="del"><Icon name="trash" :size="15" /></button>
                </div>

                <div v-if="closing" class="fbadmin__bar" style="flex-direction: column; align-items: stretch">
                    <div v-if="closing === 'duplicate'" class="field">
                        <label>Номер обращения, повтором которого это является</label>
                        <input v-model="duplicateOf" class="input" type="number" min="1" placeholder="например, 12" style="max-width: 200px">
                    </div>
                    <div class="field">
                        <label>Что написать сотруднику <span class="hint">— необязательно, но лучше объяснить</span></label>
                        <textarea v-model="closeNote" class="input" rows="2" style="resize: vertical; min-height: 62px; height: auto" :placeholder="closing === 'done' ? 'Исправлено, обновите страницу' : closing === 'not_a_bug' ? 'Так и задумано: письма из рассылок складываются в отдельную папку' : 'Пока сделать не сможем: причина'" />
                    </div>
                    <div style="display: flex; justify-content: flex-end; gap: 8px">
                        <button class="btn" type="button" @click="closing = null">Отмена</button>
                        <button class="btn btn--primary" type="button" @click="close">Закрыть: {{ dict.resolutions[closing].toLowerCase() }}</button>
                    </div>
                </div>
            </div>

            <div v-else class="card">
                <p class="hint" style="margin: 0">
                    Выберите обращение слева. Сотрудник пишет из веб-почты кнопкой «Сообщить о проблеме»; страницу, браузер
                    и ошибки на странице мы прикладываем автоматически, поэтому его не нужно расспрашивать об этом отдельно.
                </p>
            </div>
        </div>
    </AppLayout>
</template>
