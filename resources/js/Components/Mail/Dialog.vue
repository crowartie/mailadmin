<script setup>
// Модальное окно: подтверждение, ввод строки, произвольное содержимое через слот.
import { onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps({
    title: String,
    wide: Boolean,
    prompt: { type: Object, default: null }, // { label, value, placeholder }
    confirmLabel: { type: String, default: 'Готово' },
    danger: Boolean,
});
const emit = defineEmits(['close', 'confirm']);
const value = ref(props.prompt?.value || '');
const input = ref(null);

function onKey(e) {
    if (e.key === 'Escape') { e.stopPropagation(); emit('close'); }
}
onMounted(() => {
    document.addEventListener('keydown', onKey, true);
    setTimeout(() => input.value?.focus(), 30);
});
onBeforeUnmount(() => document.removeEventListener('keydown', onKey, true));
</script>

<template>
    <div class="overlay" @mousedown.self="$emit('close')">
        <form class="dialog" :class="{ 'dialog--wide': wide }" @submit.prevent="$emit('confirm', value)">
            <h2 v-if="title">{{ title }}</h2>
            <div v-if="prompt" class="field">
                <label v-if="prompt.label">{{ prompt.label }}</label>
                <input ref="input" v-model="value" class="input" :placeholder="prompt.placeholder" required>
            </div>
            <slot />
            <div class="dialog__actions">
                <button class="btn" type="button" @click="$emit('close')">Отмена</button>
                <button class="btn" :class="danger ? 'btn--danger' : 'btn--primary'" type="submit">{{ confirmLabel }}</button>
            </div>
        </form>
    </div>
</template>
