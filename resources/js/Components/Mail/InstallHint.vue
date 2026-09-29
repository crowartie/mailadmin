<script setup>
// Подсказка на iPhone и iPad: почту можно поставить на экран «Домой» — тогда она открывается
// как приложение, приходят уведомления о письмах и на иконке виден счётчик. Показывается в Safari,
// пока почта не установлена; «Позже» прячет на 30 дней, «Больше не показывать» — насовсем.
import { onMounted, ref } from 'vue';
import Icon from '../Icon.vue';
import { isIos, standalone } from '../../mail/push';

const KEY = 'mail.installHint';
const show = ref(false);

onMounted(() => {
    try {
        if (!isIos() || standalone() || window.innerWidth > 900) return;
        const v = localStorage.getItem(KEY);
        if (v === 'never') return;
        if (v && Date.now() - Number(v) < 30 * 86400000) return;
        show.value = true;
    } catch { /* без localStorage подсказку не показываем: иначе она будет вечной */ }
});
function later() { try { localStorage.setItem(KEY, String(Date.now())); } catch {} show.value = false; }
function never() { try { localStorage.setItem(KEY, 'never'); } catch {} show.value = false; }
</script>

<template>
    <div v-if="show" class="ihint" role="dialog" aria-label="Поставить почту на экран Домой">
        <div class="ihint__row">
            <Icon name="mail" :size="20" />
            <div class="ihint__text">
                <b>Поставьте почту на экран «Домой»</b>
                <span>Она откроется как приложение, будут приходить уведомления о письмах и счётчик на иконке.</span>
                <span class="ihint__how">Нажмите <Icon name="share" :size="14" /> «Поделиться» внизу Safari, затем «На экран “Домой”».</span>
            </div>
        </div>
        <div class="ihint__acts">
            <button type="button" class="linklike" @click="never">Больше не показывать</button>
            <span class="grow" />
            <button type="button" class="btn btn--sm" @click="later">Понятно</button>
        </div>
    </div>
</template>
