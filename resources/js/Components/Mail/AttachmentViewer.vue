<script setup>
// Просмотр вложений без скачивания (обращение №6): картинки — через vue-easy-lightbox (зум колесом, перетаскивание,
// поворот, отражение), PDF — встроенный просмотрщик браузера во фрейме. Стрелки и ← → листают все просматриваемые
// вложения письма подряд, Esc / щелчок по фону закрывает. Остальные типы открываются скачиванием, сюда не попадают.
import { computed, defineAsyncComponent, onBeforeUnmount, onMounted, ref, watch } from 'vue';
// Библиотека грузится отдельным файлом только при первом открытии картинки — в основную сборку почты не входит.
const VueEasyLightbox = defineAsyncComponent(() => import('vue-easy-lightbox').then((m) => m.default || m));
import Icon from '../Icon.vue';
import { size } from '../../mail/format';
import { readAsDataUrl } from '../../mail/attachments';

const props = defineProps({
    items: { type: Array, required: true },   // [{ url, downloadUrl, name, type, size }]
    start: { type: Number, default: 0 },
});
const emit = defineEmits(['close']);

const box = ref(null);
let returnTo = null;   // куда вернуть фокус после закрытия
const cur = ref(Math.min(Math.max(0, props.start), props.items.length - 1));
const item = computed(() => props.items[cur.value]);
const isImage = computed(() => (item.value?.type || '').startsWith('image/'));
const hasPrev = computed(() => cur.value > 0);
const hasNext = computed(() => cur.value < props.items.length - 1);

function prev() { if (hasPrev.value) cur.value--; }
function next() { if (hasNext.value) cur.value++; }

/**
 * 153: при открытии одного вложения в память читались сразу все — на нескольких крупных
 * файлах вкладка надолго замирала. Соседние дочитываем только когда до них долистали.
 */
async function ensureLoaded(i) {
    const it = props.items[i];
    if (!it || it.url || it.error || !it.file) return;
    try {
        it.url = await readAsDataUrl(it.file);
        it.downloadUrl = it.url;
    } catch {
        // 155: отказ чтения уходил в необработанную ошибку.
        it.error = 'Файл не удалось прочитать — возможно, его переместили или удалили';
    }
}
watch(cur, (i) => ensureLoaded(i), { immediate: true });
function onKey(e) {
    // stopPropagation: иначе Escape закрывал и просмотрщик, и окно письма под ним.
    if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); emit('close'); return; }
    if (e.key === 'ArrowLeft') { prev(); return; }
    if (e.key === 'ArrowRight') { next(); return; }
    if (e.key !== 'Tab' || !box.value) return;
    // Держим фокус внутри окна просмотра: иначе Tab уводил в форму письма под затемнением,
    // где не видно, что выбрано.
    const items = [...box.value.querySelectorAll('a[href], button:not([disabled])')]
        .filter((n) => n.offsetParent !== null);
    if (!items.length) return;
    const first = items[0];
    const last = items[items.length - 1];
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
}
onMounted(() => {
    window.addEventListener('keydown', onKey);
    returnTo = document.activeElement;
    // Фокус сразу в окно просмотра, иначе клавиши листания не работают до первого щелчка
    box.value?.querySelector('button')?.focus();
});
onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKey);
    if (returnTo && document.contains(returnTo)) returnTo.focus();
});
</script>

<template>
    <div ref="box" class="aview" role="dialog" aria-modal="true" :aria-label="'Просмотр вложения: ' + item.name" @click.self="$emit('close')">
        <div class="aview__bar">
            <span class="aview__name" :title="item.name">{{ item.name }}</span>
            <span class="aview__meta"><template v-if="item.size">{{ size(item.size) }} · </template>{{ cur + 1 }} / {{ items.length }}<template v-if="item.converted"> · предпросмотр (документ переведён в PDF, оригинал — «Скачать»)</template></span>
            <span class="grow" />
            <!-- 154: у только что приложенного файла адрес — строка data:, и браузеры
                 блокируют переход по ней в новой вкладке, а без атрибута download
                 «Скачать» тоже не работало. -->
            <a v-if="item.downloadUrl" class="ib aview__ib" :href="item.downloadUrl" :download="item.name" title="Скачать" aria-label="Скачать"><Icon name="download" :size="17" /></a>
            <a v-if="item.url && !item.url.startsWith('data:')" class="ib aview__ib" :href="item.url" target="_blank" rel="noopener" title="Открыть в новой вкладке" aria-label="Открыть в новой вкладке"><Icon name="share" :size="17" /></a>
            <button class="ib aview__ib" type="button" title="Закрыть (Esc)" @click="$emit('close')" aria-label="Закрыть (Esc)"><Icon name="x" :size="18" /></button>
        </div>

        <button v-if="hasPrev" class="aview__arrow aview__arrow--l" type="button" title="Предыдущее (←)" @click="prev" aria-label="Предыдущее (←)"><Icon name="left" :size="22" /></button>
        <button v-if="hasNext" class="aview__arrow aview__arrow--r" type="button" title="Следующее (→)" @click="next" aria-label="Следующее (→)"><Icon name="right" :size="22" /></button>

        <!-- 155: при сбое чтения окно открывалось пустым кадром и падало на имени файла. -->
        <div v-if="item.error || !item.url" class="aview__msg">{{ item.error || 'Читаем файл…' }}</div>

        <!-- Картинка: библиотека рисует свою подложку и панель (зум, поворот, отражение); свои кнопки и стрелки у неё отключены -->
        <VueEasyLightbox
            v-else-if="isImage"
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
