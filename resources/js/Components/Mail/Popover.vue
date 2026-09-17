<script setup>
// Всплывающее меню у точки (x, y): держится в окне, закрывается по клику снаружи и Esc.
import { onBeforeUnmount, onMounted, ref, nextTick } from 'vue';

const props = defineProps({
    x: { type: Number, default: 0 },
    y: { type: Number, default: 0 },
    width: { type: Number, default: null },
});
const emit = defineEmits(['close']);
const el = ref(null);
const style = ref({ left: props.x + 'px', top: props.y + 'px', visibility: 'hidden' });

function place() {
    const box = el.value?.getBoundingClientRect();
    if (!box) return;
    let left = props.x; let top = props.y;
    if (left + box.width > window.innerWidth - 8) left = Math.max(8, window.innerWidth - box.width - 8);
    if (top + box.height > window.innerHeight - 8) top = Math.max(8, window.innerHeight - box.height - 8);
    style.value = { left: left + 'px', top: top + 'px', visibility: 'visible', ...(props.width ? { minWidth: props.width + 'px' } : {}) };
}

function onDoc(e) {
    if (el.value && !el.value.contains(e.target)) emit('close');
}
function onKey(e) {
    if (e.key === 'Escape') { e.stopPropagation(); emit('close'); return; }
    // Меню объявлено меню — значит, по нему ходят стрелками, а не только мышью.
    if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp' && e.key !== 'Home' && e.key !== 'End') return;
    const items = [...(el.value?.querySelectorAll('button:not(:disabled), a[href], input') || [])].filter((x) => x.offsetParent !== null);
    if (!items.length) return;
    e.preventDefault();
    e.stopPropagation();
    const i = items.indexOf(document.activeElement);
    const next = e.key === 'Home' ? 0
        : e.key === 'End' ? items.length - 1
        : e.key === 'ArrowDown' ? (i + 1) % items.length
        : (i <= 0 ? items.length : i) - 1;
    items[next].focus();
}

// Меню считалось от точки клика один раз: прокрутил список — и оно висело на старом месте
// рядом с уже другим письмом. Закрываем его при прокрутке и пересчитываем при смене размера.
function onScroll() { emit('close'); }
onMounted(async () => {
    await nextTick();
    place();
    setTimeout(() => {
        document.addEventListener('mousedown', onDoc);
        document.addEventListener('keydown', onKey, true);
        document.addEventListener('scroll', onScroll, true);
        window.addEventListener('resize', place);
    }, 0);
});
onBeforeUnmount(() => {
    document.removeEventListener('mousedown', onDoc);
    document.removeEventListener('keydown', onKey, true);
    document.removeEventListener('scroll', onScroll, true);
    window.removeEventListener('resize', place);
});
</script>

<template>
    <div ref="el" class="pop" :style="style" role="menu">
        <slot />
    </div>
</template>
