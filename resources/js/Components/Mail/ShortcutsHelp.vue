<script setup>
// Единственное окно почты, которое не закрывалось по Escape: обработчик клавиш в списке
// писем выходит раньше, если окно открыто, поэтому слушаем сами.
import { onBeforeUnmount, onMounted, ref } from 'vue';

const emit = defineEmits(['close']);
const box = ref(null);
let returnTo = null;
function onKey(e) {
    if (e.key === 'Escape') { e.stopPropagation(); emit('close'); }
}
onMounted(() => {
    returnTo = document.activeElement;
    document.addEventListener('keydown', onKey, true);
    document.body.style.overflow = 'hidden';
    setTimeout(() => box.value?.querySelector('button')?.focus(), 30);
});
onBeforeUnmount(() => {
    document.removeEventListener('keydown', onKey, true);
    document.body.style.overflow = '';
    if (returnTo && document.contains(returnTo)) returnTo.focus();
});

// 313: подсказка была беднее справки и расходилась с ней — не было g a, g t, o и Delete,
// а строка «j / k» разбивалась по пробелам и рисовала несуществующую клавишу «/».
const groups = [
    ['Навигация', [['j', 'следующее письмо'], ['k', 'предыдущее письмо'], ['Enter', 'открыть'], ['o', 'открыть'], ['u', 'к списку'],
        ['g i', 'Входящие'], ['g s', 'Отправленные'], ['g d', 'Черновики'], ['g a', 'Архив'], ['g t', 'Корзина'], ['/', 'поиск']]],
    ['Письмо', [['r', 'ответить'], ['a', 'ответить всем'], ['f', 'переслать'], ['e', 'архив'], ['#', 'удалить'], ['Delete', 'удалить'],
        ['s', 'флажок'], ['i', 'прочитано / нет'], ['z', 'отложить']]],
    ['Разбор', [['v', 'в папку'], ['l', 'метка'], ['!', 'спам'], ['x', 'выбрать'], ['* a', 'выбрать все'], ['Esc', 'снять выбор']]],
    ['Написать', [['c', 'новое письмо'], ['Ctrl Enter', 'отправить'], ['Ctrl S', 'черновик'], ['Ctrl B', 'жирный (I — курсив, U — подчёркнутый)'], ['Ctrl K', 'ссылка'], ['?', 'эта подсказка']]],
];
</script>

<template>
    <div class="overlay" @mousedown.self="$emit('close')">
        <div ref="box" class="dialog dialog--wide" role="dialog" aria-modal="true" aria-labelledby="keys-help-title">
            <h2 id="keys-help-title" style="display: flex; align-items: center; gap: 10px">
                Горячие клавиши
                <span class="chip chip--off" style="font-weight: 500">как в Gmail</span>
                <span style="flex: 1" />
                <button class="ib" type="button" title="Закрыть" aria-label="Закрыть подсказку" @click="$emit('close')">✕</button>
            </h2>
            <div class="keys">
                <div v-for="[title, items] in groups" :key="title">
                    <div class="keys__group">{{ title }}</div>
                    <div v-for="[combo, desc] in items" :key="combo" class="keys__row">
                        <span><span v-for="k in combo.split(' ')" :key="k" class="kbd">{{ k }}</span></span>
                        <span>{{ desc }}</span>
                    </div>
                </div>
            </div>
        <p class="hint" style="margin: 12px 0 0"><a href="/mail/help">Полная справка по почте →</a></p>
        </div>
    </div>
</template>
