<script setup>
import { Link, usePage, router } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';
import Icon from '../Components/Icon.vue';

const props = defineProps({
    user: String,
    theme: { type: String, default: 'light' },
});

const page = usePage();
const current = computed(() => page.url.split('?')[0]);

// Почта, календарь и контакты — один интерфейс; рельс переключает разделы.
const services = [
    { href: '/mail', icon: 'mail', label: 'Почта' },
    { href: '/calendar', icon: 'cal', label: 'Календарь' },
    { href: '/contacts', icon: 'users', label: 'Контакты' },
];

const initials = computed(() => {
    const local = (props.user || '').split('@')[0];
    return local.slice(0, 2).toUpperCase() || '·';
});

// Тема: из настроек пользователя; локальная копия — чтобы не мигало до загрузки.
const isDark = ref(false);
function applyTheme(t) {
    let real = t;
    if (t === 'system') real = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    document.documentElement.dataset.theme = real;
    isDark.value = real === 'dark';
    try { localStorage.setItem('mail.theme', real); } catch {}
}
onMounted(() => applyTheme(props.theme));
watch(() => props.theme, applyTheme);

function toggleTheme() {
    const next = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
    applyTheme(next);
    fetch('/mail/api/settings', {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '') },
        body: JSON.stringify({ theme: next }),
        credentials: 'same-origin',
    }).catch(() => {});
}

function logout() {
    router.post('/mail/logout');
}
</script>

<template>
    <div class="app">
        <aside class="rail" aria-label="Сервисы">
            <div class="rail__logo">П</div>
            <Link
                v-for="s in services"
                :key="s.href"
                class="rail__item"
                :class="{ 'rail__item--on': current.startsWith(s.href) && !current.startsWith('/mail/settings') }"
                :href="s.href"
                :title="s.label"
            >
                <Icon :name="s.icon" />
            </Link>
            <div class="rail__spacer" />
            <Link class="rail__item" :class="{ 'rail__item--on': current.startsWith('/mail/settings') }" href="/mail/settings" title="Настройки">
                <Icon name="sliders" />
            </Link>
            <Link class="rail__item" :class="{ 'rail__item--on': current.startsWith('/mail/help') }" href="/mail/help" title="Справка">
                <Icon name="info" />
            </Link>
            <button class="rail__item" type="button" title="Тёмная / светлая тема" style="border: none; background: none; cursor: pointer" @click="toggleTheme">
                <Icon :name="isDark ? 'sun' : 'moon'" />
            </button>
            <button v-if="user" class="rail__item" type="button" title="Выйти" style="border: none; background: none; cursor: pointer" @click="logout">
                <Icon name="logout" />
            </button>
            <div class="rail__avatar" :title="user">{{ initials }}</div>
        </aside>

        <slot />
    </div>
</template>
