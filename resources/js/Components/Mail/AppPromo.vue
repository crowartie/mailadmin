<script setup>
// Плашка «есть приложение» — для тех, кто читает почту в браузере телефона на Android.
// По журналу за неделю таких было восемь человек, и никто из них не знал о приложении (разбор 26.09).
// Закрыли крестиком — больше не показываем на этом телефоне. Для iPhone приложения пока нет — не показываем.
import { ref } from 'vue';
import Icon from '../Icon.vue';

const KEY = 'mail.appPromo.hidden';
function wanted() {
    try {
        if (localStorage.getItem(KEY)) return false;
    } catch { /* приватное окно — покажем */ }
    return /Android/i.test(navigator.userAgent || '') && window.matchMedia('(max-width: 900px)').matches;
}
const show = ref(wanted());
function hide() {
    show.value = false;
    try { localStorage.setItem(KEY, '1'); } catch { /* приватное окно */ }
}
</script>

<template>
    <div v-if="show" class="apppromo" role="note">
        <Icon name="mobile" :size="20" />
        <span class="apppromo__text"><b>Почта для Android</b> — уведомления о новых письмах, работа без сети, файлы и календарь.</span>
        <a class="btn btn--primary apppromo__go" href="/app">Установить</a>
        <button class="ib" type="button" title="Больше не показывать" aria-label="Больше не показывать" @click="hide"><Icon name="x" :size="16" /></button>
    </div>
</template>
