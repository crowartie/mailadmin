<script setup>
// «Сообщить о проблеме»: одно поле для человека, всё остальное собираем сами.
// Снимок экрана можно вставить из буфера (Ctrl+V) — так быстрее, чем сохранять файл и искать его.
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import Icon from '../Icon.vue';
import { api } from '../../mail/api';
import { context } from '../../mail/diag';

const emit = defineEmits(['close']);

const kinds = [
    { key: 'bug', label: 'Не работает', hint: 'Опишите, что вы делали и что пошло не так. Если видели сообщение об ошибке — приложите снимок экрана.' },
    { key: 'idea', label: 'Предложение', hint: 'Что было бы удобнее сделать иначе?' },
    { key: 'question', label: 'Вопрос', hint: 'Спросите про почту — ответит администратор.' },
];
const kind = ref('bug');
const text = ref('');
const file = ref(null);
const filePreview = ref('');
const sending = ref(false);
const error = ref('');
const done = ref(0);
const showDetails = ref(false);

const ctx = context();
const hint = computed(() => kinds.find((k) => k.key === kind.value).hint);

function pick(e) {
    const f = e.target.files?.[0];
    if (f) setFile(f);
}
function setFile(f) {
    if (f.size > 8 * 1024 * 1024) { error.value = 'Снимок больше 8 МБ — уменьшите или обрежьте.'; return; }
    file.value = f;
    filePreview.value = URL.createObjectURL(f);
    error.value = '';
}
function onPaste(e) {
    const item = [...(e.clipboardData?.items || [])].find((i) => i.type.startsWith('image/'));
    if (item) { const f = item.getAsFile(); if (f) { setFile(f); e.preventDefault(); } }
}
function clearFile() {
    file.value = null;
    filePreview.value = '';
}

async function send() {
    if (text.value.trim().length < 3 || sending.value) return;
    sending.value = true;
    error.value = '';
    try {
        const fd = new FormData();
        fd.append('text', text.value.trim());
        fd.append('kind', kind.value);
        fd.append('context', JSON.stringify(ctx));
        if (file.value) fd.append('file', file.value, file.value.name || 'screen.png');
        const r = await api.feedback(fd);
        done.value = r.id;
    } catch (e) {
        error.value = e.message || 'Не удалось отправить';
    } finally {
        sending.value = false;
    }
}

function onKey(e) { if (e.key === 'Escape') { e.stopPropagation(); emit('close'); } }
onMounted(() => document.addEventListener('keydown', onKey, true));
onBeforeUnmount(() => {
    document.removeEventListener('keydown', onKey, true);
    if (filePreview.value) URL.revokeObjectURL(filePreview.value);
});
</script>

<template>
    <div class="overlay" @mousedown.self="$emit('close')">
        <div class="dialog" style="width: 560px" @paste="onPaste">
            <template v-if="!done">
                <div style="display: flex; align-items: center; gap: 12px">
                    <h2 style="flex: 1">Сообщить о проблеме</h2>
                    <a href="/mail/feedback" class="btn btn--sm"><Icon name="mail" :size="14" />Мои обращения</a>
                </div>

                <div class="seg">
                    <button v-for="k in kinds" :key="k.key" type="button" class="seg__item" :class="{ 'seg__item--on': kind === k.key }" @click="kind = k.key">{{ k.label }}</button>
                </div>

                <div class="field">
                    <textarea
                        v-model="text"
                        class="input"
                        rows="6"
                        :placeholder="hint"
                        style="resize: vertical; line-height: 1.45"
                        autofocus
                    />
                </div>

                <div v-if="filePreview" class="fb__shot">
                    <img :src="filePreview" alt="снимок экрана">
                    <button class="ib ib--sm" type="button" title="Убрать снимок" @click="clearFile"><Icon name="x" :size="14" /></button>
                </div>
                <label v-else class="fb__attach">
                    <input type="file" accept="image/*" hidden @change="pick">
                    <Icon name="img" :size="16" />Приложить снимок экрана<span class="hint" style="margin-left: auto">или вставьте из буфера, Ctrl+V</span>
                </label>

                <button type="button" class="fb__more" @click="showDetails = !showDetails">
                    <Icon :name="showDetails ? 'down' : 'chevron'" :size="14" />
                    К обращению приложим: страница, браузер, версия почты{{ ctx.errors.length ? `, ошибки на странице (${ctx.errors.length})` : '' }}
                </button>
                <div v-if="showDetails" class="fb__ctx">
                    <div><span>Страница</span><b>{{ ctx.page }}</b></div>
                    <div><span>Адрес</span><b class="mono">{{ ctx.url }}</b></div>
                    <div><span>Программа</span><b>{{ ctx.client }}</b></div>
                    <div><span>Экран</span><b>{{ ctx.screen }}, окно {{ ctx.viewport }}</b></div>
                    <div v-if="ctx.errors.length"><span>Ошибки</span><b class="mono" style="font-size: 11.5px">{{ ctx.errors.map((e) => e.text).join(' · ') }}</b></div>
                </div>

                <p v-if="error" class="error" style="margin: 0">{{ error }}</p>

                <div class="dialog__actions">
                    <button class="btn" type="button" @click="$emit('close')">Отмена</button>
                    <button class="btn btn--primary" type="button" :disabled="sending || text.trim().length < 3" @click="send">
                        {{ sending ? 'Отправляем…' : 'Отправить' }}
                    </button>
                </div>
            </template>

            <template v-else>
                <h2>Обращение №{{ done }} принято</h2>
                <p class="hint" style="margin: 0">
                    Администратор увидит его вместе со страницей и браузером — описывать это не нужно.
                    Ответ придёт письмом, а переписка будет в разделе «Мои обращения».
                </p>
                <div class="dialog__actions">
                    <a class="btn" href="/mail/feedback">Мои обращения</a>
                    <button class="btn btn--primary" type="button" @click="$emit('close')">Закрыть</button>
                </div>
            </template>
        </div>
    </div>
</template>
