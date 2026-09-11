<script setup>
// Обращения сотрудника: одна переписка — одна проблема.
// Сообщение появляется сразу, без перезагрузки страницы, а ответ администратора подтягивается сам.
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import MailLayout from '../../Layouts/MailLayout.vue';
import Icon from '../../Components/Icon.vue';
import FeedbackDialog from '../../Components/Mail/FeedbackDialog.vue';
import { api } from '../../mail/api';

const props = defineProps({
    user: String,
    settings: Object,
    tickets: Array,
    open: Object,
    kinds: Object,
});

const list = ref([...(props.tickets || [])]);
const ticket = ref(props.open ? { ...props.open } : null);
const messages = ref(props.open?.messages ? [...props.open.messages] : []);
const creating = ref(false);
const reply = ref('');
const file = ref(null);
const filePreview = ref('');
const sending = ref(false);
const error = ref('');
const scroll = ref(null);
const box = ref(null);

// Переход между переписками идёт частичной загрузкой — подхватываем новые данные в своё состояние.
watch(() => props.tickets, (v) => { list.value = [...(v || [])]; });
watch(() => props.open, (v) => {
    ticket.value = v ? { ...v } : null;
    messages.value = v?.messages ? [...v.messages] : [];
    reply.value = '';
    clearFile();
    toBottom();
});

const look = (t) => (t.status === 'closed'
    ? (t.resolution === 'done' ? { ava: 'fbchat__ava--ok', chip: 'chip--ok', icon: 'check' } : { ava: 'fbchat__ava--off', chip: 'chip--off', icon: 'x' })
    : (t.status === 'waiting' ? { ava: 'fbchat__ava--warn', chip: 'chip--warn', icon: 'reply' } : { ava: '', chip: 'chip--acc', icon: t.kind === 'idea' ? 'star' : t.kind === 'question' ? 'info' : 'warn' }));

const when = (iso) => {
    if (!iso) return '';
    const d = new Date(iso);
    return new Date().toDateString() === d.toDateString()
        ? d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' })
        : d.toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' });
};
const full = (iso) => (iso ? new Date(iso).toLocaleString('ru-RU', { day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit' }) : '');

function show(t) {
    router.get('/mail/feedback', { id: t.id }, { preserveScroll: true, preserveState: true, only: ['open', 'tickets'] });
}

function toBottom() {
    nextTick(() => { if (scroll.value) scroll.value.scrollTop = scroll.value.scrollHeight; });
}

// ── Вложение ─────────────────────────────────────────────────────
function pick(e) {
    const f = e.target.files?.[0];
    if (f) setFile(f);
    e.target.value = '';
}
function setFile(f) {
    if (f.size > 8 * 1024 * 1024) { error.value = 'Снимок больше 8 МБ — уменьшите или обрежьте.'; return; }
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

// ── Отправка: сообщение появляется сразу, страница не перезагружается ──
async function send() {
    if ((!reply.value.trim() && !file.value) || sending.value) return;
    const text = reply.value.trim();
    const keptPreview = filePreview.value;
    const draft = { id: 'tmp' + Date.now(), role: 'user', text: text || 'Снимок экрана', file: !!file.value, at: new Date().toISOString(), pending: true, preview: keptPreview };
    messages.value.push(draft);
    toBottom();
    sending.value = true;
    error.value = '';
    const fd = new FormData();
    fd.append('text', text || 'Снимок экрана');
    if (file.value) fd.append('file', file.value, file.value.name || 'screen.png');
    reply.value = '';
    file.value = null;
    filePreview.value = '';
    if (box.value) box.value.style.height = '42px';
    try {
        const r = await api.feedbackReply(ticket.value.id, fd);
        Object.assign(draft, r.message, { pending: false, preview: '' });
        if (keptPreview) URL.revokeObjectURL(keptPreview);
        applyTicket(r.ticket);
    } catch (e) {
        draft.pending = false;
        draft.failed = true;
        error.value = e.message || 'Не удалось отправить';
    } finally {
        sending.value = false;
    }
}

/** Обновить открытое обращение и его строку в списке, не перерисовывая страницу. */
function applyTicket(t) {
    if (!t) return;
    if (ticket.value && ticket.value.id === t.id) ticket.value = { ...ticket.value, ...t };
    const i = list.value.findIndex((x) => x.id === t.id);
    const last = messages.value.length ? messages.value[messages.value.length - 1] : null;
    const row = { ...(i >= 0 ? list.value[i] : {}), ...t, last: last ? { text: last.text.slice(0, 90), at: last.at, role: last.role } : null };
    if (i >= 0) list.value.splice(i, 1);
    list.value.unshift(row);
}

// ── Живое обновление: ответ администратора появляется сам ──
let timer = null;
async function poll() {
    if (document.hidden || !ticket.value || sending.value) return;
    try {
        const lastId = [...messages.value].reverse().find((m) => typeof m.id === 'number')?.id || 0;
        const r = await api.feedbackPoll(ticket.value.id, lastId);
        if (r.messages?.length) {
            messages.value.push(...r.messages);
            toBottom();
        }
        applyTicket(r.ticket);
    } catch { /* сеть моргнула — попробуем в следующий раз */ }
}
onMounted(() => { toBottom(); timer = setInterval(poll, 15000); });
onBeforeUnmount(() => { clearInterval(timer); clearFile(); });

function onKey(e) {
    if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') { e.preventDefault(); send(); }
}
function grow(e) {
    const el = e.target;
    el.style.height = '42px';
    const h = Math.min(el.scrollHeight, 140);
    el.style.height = h + 'px';
    el.style.overflowY = el.scrollHeight > 140 ? 'auto' : 'hidden';
}

const facts = computed(() => {
    const t = ticket.value;
    if (!t) return [];
    const out = [];
    if (t.page) out.push(['Страница', t.page]);
    if (t.client) out.push(['Программа', t.client]);
    if (t.context?.screen) out.push(['Экран', t.context.screen]);
    return out;
});

function backToList() {
    router.get('/mail/feedback', {}, { preserveScroll: true, preserveState: true, only: ['open', 'tickets'] });
}
</script>

<template>
    <Head title="Обращения" />
    <MailLayout :user="user" :theme="settings?.theme">
        <div class="fbpage">
          <div class="fbpage__inner">
            <div style="display: flex; align-items: center; gap: 12px; flex: 0 0 auto">
                <Link href="/mail" class="ib" title="К письмам"><Icon name="back" :size="18" /></Link>
                <div style="min-width: 0; flex: 1">
                    <h1 style="margin: 0; font-size: 22px; line-height: 1.2">Обращения</h1>
                    <p class="hint fbpage__hint" style="margin: 2px 0 0">Каждая переписка — одна проблема. Ответ администратора приходит ещё и письмом.</p>
                </div>
                <button class="btn btn--primary" type="button" @click="creating = true"><Icon name="plus" :size="16" />Сообщить о проблеме</button>
            </div>

            <div class="fbchat" :class="{ 'fbchat--list': !ticket }">
                <div class="fbchat__list">
                    <button
                        v-for="t in list"
                        :key="t.id"
                        type="button"
                        class="fbchat__item"
                        :class="{ 'fbchat__item--on': ticket && ticket.id === t.id }"
                        @click="show(t)"
                    >
                        <span class="fbchat__ava" :class="look(t).ava"><Icon :name="look(t).icon" :size="18" /></span>
                        <span class="fbchat__main">
                            <span class="fbchat__top">
                                <span class="fbchat__title">{{ t.subject }}</span>
                                <span class="fbchat__time">{{ when(t.last?.at || t.createdAt) }}</span>
                            </span>
                            <span class="fbchat__snip">{{ t.last ? (t.last.role === 'user' ? 'Вы: ' : '') + t.last.text : '—' }}</span>
                            <span class="fbchat__badges">
                                <span class="chip" :class="look(t).chip" style="height: 20px; font-size: 11px">{{ t.statusLabel }}</span>
                                <span v-if="t.newForUser" class="dot dot--no" title="Есть новый ответ" />
                            </span>
                        </span>
                    </button>

                    <div v-if="!list.length" class="fbchat__empty">
                        <div>
                            <Icon name="warn" :size="28" style="opacity: .4" />
                            <p class="hint" style="margin: 10px 0 0">Здесь появятся ваши обращения.<br>Нажмите «Сообщить о проблеме».</p>
                        </div>
                    </div>
                </div>

                <div v-if="ticket" class="fbchat__body">
                    <div class="fbchat__head">
                        <button class="ib fbchat__back" type="button" title="К списку" @click="backToList"><Icon name="back" :size="18" /></button>
                        <span class="fbchat__ava" :class="look(ticket).ava"><Icon :name="look(ticket).icon" :size="18" /></span>
                        <div style="min-width: 0; flex: 1">
                            <h2 :title="ticket.subject">{{ ticket.subject }}</h2>
                            <p class="hint" style="margin: 2px 0 0">№{{ ticket.id }} · {{ ticket.kindLabel }} · {{ full(ticket.createdAt) }}</p>
                        </div>
                        <span class="chip" :class="look(ticket).chip">{{ ticket.statusLabel }}</span>
                    </div>

                    <div v-if="facts.length" class="fbfacts">
                        <span>Приложено автоматически:</span>
                        <span v-for="[k, v] in facts" :key="k">{{ k }} <b>{{ v }}</b></span>
                    </div>

                    <div ref="scroll" class="fbchat__scroll">
                        <div
                            v-for="m in messages"
                            :key="m.id"
                            class="fbchat__b"
                            :class="[m.role === 'user' ? 'fbchat__b--me' : (m.role === 'system' ? 'fbchat__b--sys' : 'fbchat__b--them'), { 'fbchat__b--pending': m.pending, 'fbchat__b--failed': m.failed }]"
                        >
                            <div v-if="m.role === 'admin'" class="fbchat__who">Администратор</div>
                            <div>{{ m.text }}</div>
                            <a v-if="m.file && !m.pending" :href="`/mail/feedback/${ticket.id}/file/${m.id}`" target="_blank" class="fbchat__shot">
                                <img :src="`/mail/feedback/${ticket.id}/file/${m.id}`" alt="снимок экрана">
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
                        <span class="hint" style="flex: 1">Снимок будет приложен к сообщению</span>
                        <button class="ib ib--sm" type="button" title="Убрать" @click="clearFile"><Icon name="x" :size="14" /></button>
                    </div>

                    <div class="fbchat__foot" @paste="onPaste">
                        <label class="ib" title="Приложить снимок экрана" style="cursor: pointer; flex: 0 0 auto">
                            <input type="file" accept="image/*" hidden @change="pick">
                            <Icon name="clip" :size="18" />
                        </label>
                        <textarea
                            ref="box"
                            v-model="reply"
                            class="input"
                            rows="1"
                            :placeholder="ticket.status === 'closed' ? 'Проблема осталась? Напишите — обращение откроется снова' : ticket.status === 'waiting' ? 'Администратор ждёт вашего ответа' : 'Сообщение…'"
                            @keydown="onKey"
                            @input="grow"
                        />
                        <button class="btn btn--primary" type="button" :disabled="sending || (!reply.trim() && !file)" @click="send">
                            <Icon name="send" :size="16" />Отправить
                        </button>
                    </div>
                    <p v-if="error" class="error" style="margin: 0; padding: 0 14px 12px">{{ error }}</p>
                </div>

                <div v-else-if="list.length" class="fbchat__body fbchat__empty">
                    <p class="hint" style="margin: 0">Выберите обращение слева.</p>
                </div>
            </div>
          </div>
        </div>

        <FeedbackDialog v-if="creating" @close="creating = false; router.reload({ only: ['tickets'] })" />
    </MailLayout>
</template>
