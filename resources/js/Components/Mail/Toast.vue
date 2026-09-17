<script setup>
// Уведомление внизу: текст, кнопка действия (например, «Отменить») и обратный отсчёт.
defineProps({
    toast: { type: Object, default: null }, // { text, action, actionLabel, seconds, error }
});
defineEmits(['action', 'close']);
</script>

<template>
    <Transition name="flash">
        <!-- Ошибку экранный диктор должен прочитать сразу, обычное сообщение — в свой черёд.
             Крестик нужен и у сообщения с кнопкой: раньше такое нельзя было убрать руками. -->
        <div
            v-if="toast"
            class="toast"
            :class="{ 'toast--error': toast.error }"
            :role="toast.error ? 'alert' : 'status'"
            :aria-live="toast.error ? 'assertive' : 'polite'"
        >
            <span>{{ toast.text }}</span>
            <button v-if="toast.actionLabel" type="button" @click="$emit('action')">{{ toast.actionLabel }}</button>
            <span v-if="toast.seconds" class="t">{{ toast.seconds }} с</span>
            <button type="button" title="Закрыть" aria-label="Закрыть уведомление" style="color: inherit" @click="$emit('close')">✕</button>
        </div>
    </Transition>
</template>
