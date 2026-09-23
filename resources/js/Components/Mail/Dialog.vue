<script setup>
// Модальное окно: подтверждение, ввод строки, произвольное содержимое через слот.
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { ask as confirmAsk } from '../../confirm';

let seq = 0;

const props = defineProps({
    title: String,
    wide: Boolean,
    prompt: { type: Object, default: null }, // { label, value, placeholder, maxlength }
    confirmLabel: { type: String, default: 'Готово' },
    danger: Boolean,
});
const emit = defineEmits(['close', 'confirm']);
const value = ref(props.prompt?.value || '');
const input = ref(null);
const box = ref(null);
const titleId = 'dlg-' + (++seq);
// Куда вернуть фокус после закрытия: иначе он улетал в начало страницы.
let returnTo = null;
// Клик мимо окна раньше закрывал его вместе с набранным текстом.
const touched = ref(false);

function onKey(e) {
    if (e.key === 'Escape') { e.stopPropagation(); emit('close'); return; }
    // Enter подтверждает и там, где поля ввода нет: раньше окно отвечало только на мышь.
    // Но если фокус стоит на кнопке или ссылке, Enter — это нажатие именно на неё.
    const on = document.activeElement;
    const ownKey = on && ['BUTTON', 'A', 'SELECT', 'TEXTAREA'].includes(on.tagName);
    if (e.key === 'Enter' && !props.prompt && !ownKey && box.value?.contains(on)) {
        e.preventDefault();
        emit('confirm', value.value);
        return;
    }
    // Удержание фокуса внутри окна: по Tab он уходил на список писем за затемнением.
    if (e.key !== 'Tab' || !box.value) return;
    const items = [...box.value.querySelectorAll('a[href], button, input, select, textarea, [tabindex]:not([tabindex="-1"])')].filter((x) => !x.disabled && x.offsetParent !== null);
    if (!items.length) return;
    const first = items[0];
    const last = items[items.length - 1];
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
}
/** Закрытие кликом мимо окна: если что-то уже набрано, сначала переспрашиваем. */
async function onOutside() {
    if (touched.value && !(await confirmAsk('Закрыть окно? Набранное не сохранится.', { ok: 'Закрыть', danger: true }))) return;
    emit('close');
}
onMounted(() => {
    returnTo = document.activeElement;
    document.addEventListener('keydown', onKey, true);
    // Прокрутка списка за затемнением создавала впечатление, что окно не модальное.
    document.body.style.overflow = 'hidden';
    setTimeout(() => (input.value || box.value?.querySelector('button[type="submit"]'))?.focus(), 30);
});
onBeforeUnmount(() => {
    document.removeEventListener('keydown', onKey, true);
    document.body.style.overflow = '';
    if (returnTo && document.contains(returnTo)) returnTo.focus();
});
</script>

<template>
    <div class="overlay" @mousedown.self="onOutside">
        <form
            ref="box"
            class="dialog"
            :class="{ 'dialog--wide': wide }"
            role="dialog"
            aria-modal="true"
            :aria-labelledby="title ? titleId : null"
            @submit.prevent="$emit('confirm', value)"
        >
            <h2 v-if="title" :id="titleId">{{ title }}</h2>
            <div v-if="prompt" class="field">
                <label v-if="prompt.label" :for="titleId + '-in'">{{ prompt.label }}</label>
                <input :id="titleId + '-in'" ref="input" v-model="value" class="input" :placeholder="prompt.placeholder" :maxlength="prompt.maxlength || null" required @input="touched = true">
            </div>
            <slot />
            <div class="dialog__actions">
                <button class="btn" type="button" @click="$emit('close')">Отмена</button>
                <button class="btn" :class="danger ? 'btn--danger' : 'btn--primary'" type="submit">{{ confirmLabel }}</button>
            </div>
        </form>
    </div>
</template>
