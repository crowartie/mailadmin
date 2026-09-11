<script setup>
// «Мои обращения»: что сотрудник уже написал, в каком состоянии и что ответил администратор.
// Без этой страницы обращение уходит в пустоту, и второй раз человек уже не пишет.
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
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

const chip = (t) => (t.status === 'closed'
    ? (t.resolution === 'done' ? 'chip--ok' : 'chip--off')
    : (t.status === 'waiting' ? 'chip--warn' : 'chip--acc'));

const when = (iso) => (iso ? new Date(iso).toLocaleString('ru-RU', { day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit' }) : '');

function show(t) {
    router.get('/mail/feedback', { id: t.id }, { preserveScroll: true, preserveState: true });
}

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
    } catch (e) {
        error.value = e.message || 'Не удалось отправить';
    } finally {
        sending.value = false;
    }
}

const waiting = computed(() => props.open?.status === 'waiting');
</script>

<template>
    <Head title="Мои обращения" />
    <MailLayout :user="user" :theme="settings?.theme">
        <div class="mset">
            <div class="mset__head">
                <Link href="/mail" class="ib" title="К письмам"><Icon name="back" :size="18" /></Link>
                <h1>Мои обращения</h1>
                <span class="grow" />
                <button class="btn btn--primary" type="button" @click="creating = true"><Icon name="plus" :size="16" />Сообщить о проблеме</button>
            </div>

            <div class="mset__grid">
                <div class="card card--flush">
                    <div v-for="t in tickets" :key="t.id" class="row row--click" :class="{ 'row--on': open && open.id === t.id }" style="grid-template-columns: minmax(0, 1fr) auto" @click="show(t)">
                        <span style="min-width: 0">
                            <span class="row__name fb__row">№{{ t.id }} · {{ t.subject }}</span>
                            <span class="row__sub fb__row">{{ t.kindLabel }} · {{ when(t.createdAt) }}</span>
                        </span>
                        <span style="display: flex; align-items: center; gap: 6px">
                            <span v-if="t.newForUser" class="dot dot--no" title="Есть ответ" />
                            <span class="chip" :class="chip(t)">{{ t.statusLabel }}</span>
                        </span>
                    </div>
                    <div v-if="!tickets.length" class="empty">
                        Вы ещё не сообщали о проблемах. Кнопка «Сообщить о проблеме» есть и здесь, и слева в полосе значков на любой странице.
                    </div>
                </div>

                <div v-if="open" class="card">
                    <h2 class="fb__h2" style="margin: 0 0 4px" :title="open.subject">№{{ open.id }} · {{ open.subject }}</h2>
                    <p class="hint" style="margin: 0 0 14px">
                        {{ open.kindLabel }} · создано {{ when(open.createdAt) }} ·
                        <span class="chip" :class="chip(open)">{{ open.statusLabel }}</span>
                    </p>

                    <div class="fb__thread">
                        <div v-for="m in open.messages" :key="m.id" class="fb__msg" :class="'fb__msg--' + m.role">
                            <div class="fb__msg-hd">
                                <b>{{ m.role === 'user' ? 'Вы' : 'Администратор' }}</b>
                                <span class="hint">{{ when(m.at) }}</span>
                            </div>
                            <div class="fb__msg-text">{{ m.text }}</div>
                            <a v-if="m.file" :href="`/mail/feedback/${open.id}/file/${m.id}`" target="_blank" class="fb__msg-shot">
                                <img :src="`/mail/feedback/${open.id}/file/${m.id}`" alt="снимок экрана">
                            </a>
                        </div>
                    </div>

                    <p v-if="waiting" class="hint" style="margin: 14px 0 0; color: var(--warn)">Администратор ждёт вашего ответа.</p>

                    <div class="field" style="margin-top: 14px">
                        <label>{{ open.status === 'closed' ? 'Проблема осталась? Напишите — обращение откроется снова' : 'Добавить к обращению' }}</label>
                        <textarea v-model="reply" class="input" rows="3" style="resize: vertical" placeholder="Например: повторилось сегодня в 11:20, снова на той же странице" />
                    </div>
                    <p v-if="error" class="error" style="margin: 0">{{ error }}</p>
                    <div style="display: flex; justify-content: flex-end; margin-top: 10px">
                        <button class="btn btn--primary" type="button" :disabled="sending || !reply.trim()" @click="send">{{ sending ? 'Отправляем…' : 'Отправить' }}</button>
                    </div>
                </div>

                <div v-else class="card">
                    <p class="hint" style="margin: 0">
                        Выберите обращение слева, чтобы посмотреть переписку. Ответ администратора приходит письмом,
                        а здесь видно состояние: «Новое», «В работе», «Ждём ответа» или чем всё закончилось.
                    </p>
                </div>
            </div>
        </div>

        <FeedbackDialog v-if="creating" @close="creating = false; router.reload({ only: ['tickets'] })" />
    </MailLayout>
</template>
