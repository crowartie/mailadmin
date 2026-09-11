<script setup>
// «Обращения» — заявки сотрудников об ошибках, предложения и вопросы.
// Состояние (где обращение) и итог (чем закончилось) разделены: закрыть можно только с итогом,
// поэтому в списке всегда видно, почему обращение закрыли, а не просто «закрыто».
import { Link, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import Icon from '../../Components/Icon.vue';

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

const q = ref(props.search || '');
let timer = null;
watch(q, () => {
    clearTimeout(timer);
    timer = setTimeout(() => router.get('/feedback', { filter: props.filter, search: q.value || undefined, id: props.open?.id }, { preserveState: true, preserveScroll: true, replace: true }), 300);
});

const reply = ref('');
const closing = ref(null);      // выбранный итог перед закрытием
const closeNote = ref('');
const duplicateOf = ref('');
const showErrors = ref(false);

watch(() => props.open?.id, () => { reply.value = ''; closing.value = null; closeNote.value = ''; duplicateOf.value = ''; showErrors.value = false; });

function url(patch = {}) {
    const p = { filter: props.filter, ...(q.value ? { search: q.value } : {}), ...(props.open ? { id: props.open.id } : {}), ...patch };
    return '/feedback?' + new URLSearchParams(Object.fromEntries(Object.entries(p).filter(([, v]) => v !== undefined && v !== null && v !== ''))).toString();
}
const post = (path, data = {}) => router.post(path, data, { preserveScroll: true, preserveState: false });

function send(ask = false) {
    if (!reply.value.trim()) return;
    post(`/feedback/${props.open.id}/reply`, { text: reply.value.trim(), ask });
    reply.value = '';
}
function close() {
    post(`/feedback/${props.open.id}/close`, {
        resolution: closing.value,
        text: closeNote.value.trim() || undefined,
        duplicate_of: closing.value === 'duplicate' && duplicateOf.value ? Number(duplicateOf.value) : undefined,
    });
}
function del() {
    if (confirm(`Удалить обращение №${props.open.id} вместе с перепиской?`)) router.delete(`/feedback/${props.open.id}`);
}

const look = (t) => (t.status === 'closed'
    ? (t.resolution === 'done' ? { ava: 'fbchat__ava--ok', chip: 'chip--ok', icon: 'check' } : { ava: 'fbchat__ava--off', chip: 'chip--off', icon: 'x' })
    : (t.status === 'waiting' ? { ava: 'fbchat__ava--warn', chip: 'chip--warn', icon: 'reply' } : { ava: '', chip: 'chip--acc', icon: t.kind === 'idea' ? 'star' : t.kind === 'question' ? 'info' : 'warn' }));
const chip = (t) => look(t).chip;
const when = (iso) => (iso ? new Date(iso).toLocaleString('ru-RU', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : '');
const ago = (iso) => {
    if (!iso) return '';
    const m = Math.round((Date.now() - new Date(iso)) / 60000);
    if (m < 60) return m + ' мин назад';
    if (m < 1440) return Math.round(m / 60) + ' ч назад';
    return Math.round(m / 1440) + ' дн назад';
};
const errors = computed(() => props.open?.context?.errors || []);
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
                    v-for="t in rows"
                    :key="t.id"
                    class="fbchat__item"
                    :class="{ 'fbchat__item--on': open && open.id === t.id }"
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
                <div v-if="!rows.length" class="empty">Обращений нет. Сотрудники пишут из веб-почты кнопкой «Сообщить о проблеме».</div>
            </div>

            <!-- Одна карточка: шапка, что снялось само, переписка, ответ и действия -->
            <div v-if="open" class="card card--flush fbadmin__chat">
                <div class="fbchat__head">
                    <span class="fbchat__ava" :class="look(open).ava"><Icon :name="look(open).icon" :size="18" /></span>
                    <div style="min-width: 0; flex: 1">
                        <h2 :title="open.subject">№{{ open.id }} · {{ open.subject }}</h2>
                        <p class="hint" style="margin: 2px 0 0">
                            {{ open.userName || open.user }} · {{ open.user }} · {{ open.kindLabel }} · {{ when(open.createdAt) }}
                            <span v-if="open.assignedTo"> · у {{ open.assignedTo }}</span>
                        </p>
                    </div>
                    <span class="chip" :class="chip(open)">{{ open.statusLabel }}</span>
                </div>

                <div class="fbfacts">
                    <span>Снято автоматически:</span>
                    <span>Страница <b>{{ open.page || '—' }}</b></span>
                    <span>Адрес <b class="mono">{{ open.pageUrl || '—' }}</b></span>
                    <span>Программа <b>{{ open.client || '—' }}</b></span>
                    <span v-if="open.context.screen">Экран <b>{{ open.context.screen }}<template v-if="open.context.viewport">, окно {{ open.context.viewport }}</template></b></span>
                    <span>В сети <b class="mono">{{ open.ip }}</b></span>
                    <button v-if="errors.length" type="button" class="fb__more" style="font-size: 12px" @click="showErrors = !showErrors">
                        <Icon :name="showErrors ? 'down' : 'chevron'" :size="13" />Ошибки на странице ({{ errors.length }})
                    </button>
                </div>
                <div v-if="showErrors && errors.length" class="fbfacts" style="display: block">
                    <div v-for="(e, i) in errors" :key="i" class="mono" style="font-size: 11.5px">{{ e.text }}</div>
                </div>

                <div class="fbchat__scroll" style="min-height: 220px; max-height: 42vh">
                    <div
                        v-for="m in open.messages"
                        :key="m.id"
                        class="fbchat__b"
                        :class="m.role === 'admin' ? 'fbchat__b--me' : (m.role === 'system' ? 'fbchat__b--sys' : 'fbchat__b--them')"
                    >
                        <div v-if="m.role === 'user'" class="fbchat__who">{{ open.userName || open.user }}</div>
                        <div v-else-if="m.role === 'admin'" class="fbchat__who">{{ m.author }}</div>
                        <div>{{ m.text }}</div>
                        <a v-if="m.file" :href="`/feedback/${open.id}/file/${m.id}`" target="_blank" class="fbchat__shot">
                            <img :src="`/feedback/${open.id}/file/${m.id}`" alt="снимок экрана">
                        </a>
                        <div class="fbchat__at">{{ when(m.at) }}</div>
                    </div>
                </div>

                <div class="fbchat__foot" style="flex-direction: column; align-items: stretch; gap: 10px">
                    <textarea v-model="reply" class="input" rows="2" style="resize: vertical; min-height: 62px; height: auto" placeholder="Ответ сотруднику — уйдёт письмом и появится у него в «Обращениях»" />
                    <div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center">
                        <button v-if="open.status === 'new' || !open.assignedTo" class="btn btn--sm" type="button" @click="post(`/feedback/${open.id}`, { status: 'open', assign: true })"><Icon name="check" :size="14" />Взять в работу</button>
                        <span style="flex: 1" />
                        <button class="btn" type="button" :disabled="!reply.trim()" @click="send(true)"><Icon name="reply" :size="15" />Уточнить и ждать</button>
                        <button class="btn btn--primary" type="button" :disabled="!reply.trim()" @click="send(false)"><Icon name="send" :size="15" />Ответить</button>
                    </div>
                </div>

                <div class="fbadmin__bar">
                    <template v-if="open.status !== 'closed'">
                        <span class="hint">Закрыть с итогом:</span>
                        <div class="seg">
                            <button v-for="(label, key) in dict.resolutions" :key="key" type="button" class="seg__item" :class="{ 'seg__item--on': closing === key }" @click="closing = closing === key ? null : key">{{ label }}</button>
                        </div>
                    </template>
                    <template v-else>
                        <span class="hint">Закрыто {{ when(open.closedAt) }}: {{ open.statusLabel.toLowerCase() }}<span v-if="open.duplicateOf">, повтор №{{ open.duplicateOf }}</span></span>
                        <button class="btn btn--sm" type="button" @click="post(`/feedback/${open.id}/reopen`)"><Icon name="refresh" :size="14" />Вернуть в работу</button>
                    </template>
                    <span style="flex: 1" />
                    <span class="hint">Важность:</span>
                    <div class="seg">
                        <button v-for="(label, key) in dict.priorities" :key="key" type="button" class="seg__item" :class="{ 'seg__item--on': open.priority === key }" @click="post(`/feedback/${open.id}`, { priority: key })">{{ label }}</button>
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
