<script setup>
// Обращения сотрудника в виде переписок: одна переписка — одна проблема.
// Слева список, справа диалог с администратором; отвечать можно прямо здесь, как в мессенджере.
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, nextTick, ref, watch } from 'vue';
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

const creating = ref(false);
const reply = ref('');
const sending = ref(false);
const error = ref('');
const scroll = ref(null);

// Значок переписки: по итогу, если закрыто, иначе по состоянию.
const look = (t) => (t.status === 'closed'
    ? (t.resolution === 'done' ? { ava: 'fbchat__ava--ok', chip: 'chip--ok', icon: 'check' } : { ava: 'fbchat__ava--off', chip: 'chip--off', icon: 'x' })
    : (t.status === 'waiting' ? { ava: 'fbchat__ava--warn', chip: 'chip--warn', icon: 'reply' } : { ava: '', chip: 'chip--acc', icon: t.kind === 'idea' ? 'star' : t.kind === 'question' ? 'info' : 'warn' }));

const when = (iso) => {
    if (!iso) return '';
    const d = new Date(iso);
    const today = new Date().toDateString() === d.toDateString();
    return today ? d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' })
        : d.toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' });
};
const full = (iso) => (iso ? new Date(iso).toLocaleString('ru-RU', { day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit' }) : '');

function show(t) {
    router.get('/mail/feedback', { id: t.id }, { preserveScroll: true, preserveState: true, only: ['open', 'tickets'] });
}

function toBottom() {
    nextTick(() => { if (scroll.value) scroll.value.scrollTop = scroll.value.scrollHeight; });
}
watch(() => props.open?.messages?.length, toBottom, { immediate: true });

async function send() {
    if (!reply.value.trim() || sending.value) return;
    sending.value = true;
    error.value = '';
    try {
        const fd = new FormData();
        fd.append('text', reply.value.trim());
        await api.feedbackReply(props.open.id, fd);
        reply.value = '';
        router.reload({ only: ['open', 'tickets'] });
        toBottom();
    } catch (e) {
        error.value = e.message || 'Не удалось отправить';
    } finally {
        sending.value = false;
    }
}

// Ctrl+Enter отправляет — привычно по любому мессенджеру.
function onKey(e) {
    if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') { e.preventDefault(); send(); }
}

// Поле ввода растёт под текст, как в мессенджере.
function grow(e) {
    const el = e.target;
    el.style.height = '42px';
    const h = Math.min(el.scrollHeight, 140);
    el.style.height = h + 'px';
    el.style.overflowY = el.scrollHeight > 140 ? 'auto' : 'hidden';
}
watch(reply, (v) => { if (!v) document.querySelectorAll('.fbchat__foot textarea').forEach((el) => { el.style.height = '42px'; }); });

const facts = computed(() => {
    const t = props.open;
    if (!t) return [];
    const out = [];
    if (t.page) out.push(['Страница', t.page]);
    if (t.client) out.push(['Программа', t.client]);
    if (t.context?.screen) out.push(['Экран', t.context.screen]);
    return out;
});
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
                    <p class="hint" style="margin: 2px 0 0">Каждая переписка — одна проблема. Ответ администратора приходит ещё и письмом.</p>
                </div>
                <button class="btn btn--primary" type="button" @click="creating = true"><Icon name="plus" :size="16" />Сообщить о проблеме</button>
            </div>

            <div class="fbchat" :class="{ 'fbchat--list': !open }">
                <div class="fbchat__list">
                    <button
                        v-for="t in tickets"
                        :key="t.id"
                        type="button"
                        class="fbchat__item"
                        :class="{ 'fbchat__item--on': open && open.id === t.id }"
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

                    <div v-if="!tickets.length" class="fbchat__empty">
                        <div>
                            <Icon name="warn" :size="28" style="opacity: .4" />
                            <p class="hint" style="margin: 10px 0 0">Здесь появятся ваши обращения.<br>Нажмите «Сообщить о проблеме».</p>
                        </div>
                    </div>
                </div>

                <div v-if="open" class="fbchat__body">
                    <div class="fbchat__head">
                        <span class="fbchat__ava" :class="look(open).ava"><Icon :name="look(open).icon" :size="18" /></span>
                        <div style="min-width: 0; flex: 1">
                            <h2 :title="open.subject">{{ open.subject }}</h2>
                            <p class="hint" style="margin: 2px 0 0">№{{ open.id }} · {{ open.kindLabel }} · {{ full(open.createdAt) }}</p>
                        </div>
                        <span class="chip" :class="look(open).chip">{{ open.statusLabel }}</span>
                    </div>

                    <div v-if="facts.length" class="fbfacts">
                        <span>Приложено автоматически:</span>
                        <span v-for="[k, v] in facts" :key="k">{{ k }} <b>{{ v }}</b></span>
                    </div>

                    <div ref="scroll" class="fbchat__scroll">
                        <div
                            v-for="m in open.messages"
                            :key="m.id"
                            class="fbchat__b"
                            :class="m.role === 'user' ? 'fbchat__b--me' : (m.role === 'system' ? 'fbchat__b--sys' : 'fbchat__b--them')"
                        >
                            <div v-if="m.role === 'admin'" class="fbchat__who">Администратор</div>
                            <div>{{ m.text }}</div>
                            <a v-if="m.file" :href="`/mail/feedback/${open.id}/file/${m.id}`" target="_blank" class="fbchat__shot">
                                <img :src="`/mail/feedback/${open.id}/file/${m.id}`" alt="снимок экрана">
                            </a>
                            <div class="fbchat__at">{{ when(m.at) }}</div>
                        </div>
                    </div>

                    <div class="fbchat__foot">
                        <textarea
                            v-model="reply"
                            class="input"
                            rows="1"
                            :placeholder="open.status === 'closed' ? 'Проблема осталась? Напишите — обращение откроется снова' : open.status === 'waiting' ? 'Администратор ждёт вашего ответа' : 'Добавить к обращению…'"
                            @keydown="onKey"
                            @input="grow"
                        />
                        <button class="btn btn--primary" type="button" :disabled="sending || !reply.trim()" @click="send">
                            <Icon name="send" :size="16" />{{ sending ? 'Отправка…' : 'Отправить' }}
                        </button>
                    </div>
                    <p v-if="error" class="error" style="margin: 0; padding: 0 14px 12px">{{ error }}</p>
                </div>

                <div v-else-if="tickets.length" class="fbchat__body fbchat__empty">
                    <p class="hint" style="margin: 0">Выберите обращение слева.</p>
                </div>
            </div>
          </div>
        </div>

        <FeedbackDialog v-if="creating" @close="creating = false; router.reload({ only: ['tickets'] })" />
    </MailLayout>
</template>
