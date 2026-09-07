<script setup>
defineEmits(['close']);

const groups = [
    ['Навигация', [['j k', 'следующее / предыдущее письмо'], ['Enter', 'открыть'], ['u', 'к списку'], ['g i', 'Входящие'], ['g s', 'Отправленные'], ['g d', 'Черновики'], ['/', 'поиск']]],
    ['Письмо', [['r', 'ответить'], ['a', 'ответить всем'], ['f', 'переслать'], ['e', 'архив'], ['#', 'удалить'], ['s', 'флажок'], ['i', 'прочитано / нет'], ['z', 'отложить']]],
    ['Разбор', [['v', 'в папку'], ['l', 'метка'], ['!', 'спам'], ['x', 'выбрать'], ['* a', 'выбрать все'], ['Esc', 'снять выбор']]],
    ['Написать', [['c', 'новое письмо'], ['Ctrl Enter', 'отправить'], ['Ctrl S', 'черновик'], ['Ctrl B', 'жирный (I — курсив, U — подчёркнутый)'], ['Ctrl K', 'ссылка'], ['?', 'эта подсказка']]],
];
</script>

<template>
    <div class="overlay" @mousedown.self="$emit('close')">
        <div class="dialog dialog--wide">
            <h2 style="display: flex; align-items: center; gap: 10px">
                Горячие клавиши
                <span class="chip chip--off" style="font-weight: 500">как в Gmail</span>
                <span style="flex: 1" />
                <button class="ib" type="button" @click="$emit('close')">✕</button>
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
        </div>
    </div>
</template>
