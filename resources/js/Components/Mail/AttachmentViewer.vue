<script setup>
// Просмотр вложений без скачивания (обращение №6): картинки — через vue-easy-lightbox (зум колесом, перетаскивание,
// поворот, отражение), PDF — встроенный просмотрщик браузера во фрейме. Стрелки и ← → листают все просматриваемые
// вложения письма подряд, Esc / щелчок по фону закрывает. Остальные типы открываются скачиванием, сюда не попадают.
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import VueEasyLightbox from 'vue-easy-lightbox';
import Icon from '../Icon.vue';
import { size } from '../../mail/format';

const props = defineProps({
    items: { type: Array, required: true },   // [{ url, downloadUrl, name, type, size }]
    start: { type: Number, default: 0 },
});
const emit = defineEmits(['close']);

const cur = ref(Math.min(Math.max(0, props.start), props.items.length - 1));
const item = computed(() => props.items[cur.value]);
const isImage = computed(() => (item.value?.type || '').startsWith('image/'));
const hasPrev = computed(() => cur.value > 0);
const hasNext = computed(() => cur.value < props.items.length - 1);

function prev() { if (hasPrev.value) cur.value--; }
function next() { if (hasNext.value) cur.value++; }
function onKey(e) {
    if (e.key === 'Escape') { e.preventDefault(); emit('close'); }
    else if (e.key === 'ArrowLeft') prev();
    else if (e.key === 'ArrowRight') next();
}
onMounted(() => window.addEventListener('keydown', onKey));
onBeforeUnmount(() => window.removeEventListener('keydown', onKey));
</script>

<template>
    <div class="aview" @click.self="$emit('close')">
        <div class="aview__bar">
            <span class="aview__name" :title="item.name">{{ item.name }}</span>
            <span class="aview__meta">{{ size(item.size) }} · {{ cur + 1 }} / {{ items.length }}</span>
            <span class="grow" />
            <a class="ib aview__ib" :href="item.downloadUrl" title="Скачать"><Icon name="download" :size="17" /></a>
            <a class="ib aview__ib" :href="item.url" target="_blank" rel="noopener" title="Открыть в новой вкладке"><Icon name="share" :size="17" /></a>
            <button class="ib aview__ib" type="button" title="Закрыть (Esc)" @click="$emit('close')"><Icon name="x" :size="18" /></button>
        </div>

        <button v-if="hasPrev" class="aview__arrow aview__arrow--l" type="button" title="Предыдущее (←)" @click="prev"><Icon name="left" :size="22" /></button>
        <button v-if="hasNext" class="aview__arrow aview__arrow--r" type="button" title="Следующее (→)" @click="next"><Icon name="right" :size="22" /></button>

        <!-- Картинка: библиотека рисует свою подложку и панель (зум, поворот, отражение); свои кнопки и стрелки у неё отключены -->
        <VueEasyLightbox
            v-if="isImage"
            :key="item.url"
            :visible="true"
            :imgs="[{ src: item.url, title: item.name }]"
            :index="0"
            :esc-disabled="true"
            :mask-closable="true"
            :teleport="false"
            @hide="$emit('close')"
        >
            <template #close-btn><span /></template>
            <template #prev-btn><span /></template>
            <template #next-btn><span /></template>
        </VueEasyLightbox>

        <!-- PDF: встроенный просмотрщик браузера (у него свои зум, поиск и печать) -->
        <iframe v-else :key="item.url" class="aview__frame" :src="item.url" :title="item.name" />
    </div>
</template>
