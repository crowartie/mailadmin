<script setup>
// Предпросмотр печати внутри почты: письмо показывается так, как ляжет на бумагу,
// и только по кнопке «Печать» открывается системный диалог. Раньше кнопка сразу
// открывала отдельную вкладку и там же вызывала печать — человек не успевал
// посмотреть, что печатает.
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import Icon from '../Icon.vue';

const props = defineProps({
    message: { type: Object, required: true },   // { folder, uid, subject }
});
const emit = defineEmits(['close']);

const frame = ref(null);
const loaded = ref(false);
const src = computed(() => `/mail/print/${encodeURIComponent(props.message.folder)}/${props.message.uid}?auto=0&embed=1`);

function print() {
    const w = frame.value?.contentWindow;
    if (!w) return;
    w.focus();
    w.print();
}
function separate() {
    window.open(`/mail/print/${encodeURIComponent(props.message.folder)}/${props.message.uid}?auto=0`, '_blank');
}
function onKey(e) {
    if (e.key === 'Escape') { e.stopPropagation(); emit('close'); }
    // Ctrl+P из предпросмотра печатает письмо, а не саму почту.
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'p') { e.preventDefault(); print(); }
}
onMounted(() => document.addEventListener('keydown', onKey, true));
onBeforeUnmount(() => document.removeEventListener('keydown', onKey, true));
</script>

<template>
    <div class="overlay" @mousedown.self="$emit('close')">
        <div class="dialog dialog--print" role="dialog" aria-modal="true" aria-labelledby="print-title">
            <div class="print__bar">
                <h2 id="print-title">Печать</h2>
                <span class="print__hint desktop-only">Так письмо ляжет на бумагу</span>
                <span style="flex: 1" />
                <button class="btn btn--primary" type="button" :disabled="!loaded" @click="print"><Icon name="print" :size="15" />Печать</button>
                <button class="btn desktop-only" type="button" title="Открыть в отдельной вкладке" @click="separate">В отдельной вкладке</button>
                <button class="ib" type="button" title="Закрыть" aria-label="Закрыть предпросмотр" @click="$emit('close')">✕</button>
            </div>
            <div class="print__sheet">
                <div v-if="!loaded" class="print__wait">Готовлю письмо…</div>
                <iframe ref="frame" :src="src" title="Предпросмотр печати" @load="loaded = true" />
            </div>
        </div>
    </div>
</template>
