<script setup>
// Окно вопроса для ask() из confirm.js: одно на страницу, стоит в каркасе.
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { answer, confirmState as s } from '../confirm';

const okBtn = ref(null);
const cancelBtn = ref(null);
onMounted(() => { s.hosts++; });
onBeforeUnmount(() => { s.hosts--; if (s.open) answer(false); });

// Фокус: на опасном действии — на «Отмена» (случайный Enter ничего не сотрёт), иначе на «Да».
watch(() => s.open, async (open) => {
    if (!open) return;
    await nextTick();
    (s.danger ? cancelBtn.value : okBtn.value)?.focus();
});
function onKey(e) {
    if (e.key === 'Escape') { e.preventDefault(); answer(false); }
}
</script>

<template>
    <Teleport to="body">
        <div v-if="s.open" class="cfm" role="presentation" @mousedown.self="answer(false)" @keydown="onKey">
            <div class="cfm__box" role="alertdialog" aria-modal="true" :aria-label="s.title || s.text">
                <b v-if="s.title" class="cfm__title">{{ s.title }}</b>
                <p class="cfm__text">{{ s.text }}</p>
                <div class="cfm__btns">
                    <button ref="cancelBtn" class="btn" type="button" @click="answer(false)">{{ s.cancel }}</button>
                    <button ref="okBtn" class="btn" :class="s.danger ? 'cfm__danger' : 'btn--primary'" type="button" @click="answer(true)">{{ s.ok }}</button>
                </div>
            </div>
        </div>
    </Teleport>
</template>
