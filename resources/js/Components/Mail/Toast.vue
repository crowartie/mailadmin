<script setup>
// Уведомление внизу: текст, кнопка действия (например, «Отменить») и обратный отсчёт.
defineProps({
    toast: { type: Object, default: null }, // { text, action, actionLabel, seconds, error }
});
defineEmits(['action', 'close']);
</script>

<template>
    <Transition name="flash">
        <div v-if="toast" class="toast" :class="{ 'toast--error': toast.error }" role="status">
            <span>{{ toast.text }}</span>
            <button v-if="toast.actionLabel" type="button" @click="$emit('action')">{{ toast.actionLabel }}</button>
            <span v-if="toast.seconds" class="t">{{ toast.seconds }} с</span>
            <button v-if="!toast.actionLabel" type="button" title="Закрыть" style="color: inherit" @click="$emit('close')">✕</button>
        </div>
    </Transition>
</template>
