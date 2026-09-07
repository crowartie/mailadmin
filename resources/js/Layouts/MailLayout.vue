<script setup>
import { Link, usePage, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import Icon from '../Components/Icon.vue';

const props = defineProps({
    user: String,
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
                :class="{ 'rail__item--on': current.startsWith(s.href) }"
                :href="s.href"
                :title="s.label"
            >
                <Icon :name="s.icon" />
            </Link>
            <div class="rail__spacer" />
            <button class="rail__item" type="button" title="Выйти" style="border: none; background: none; cursor: pointer" @click="logout">
                <Icon name="x" />
            </button>
            <div class="rail__avatar" :title="user">{{ initials }}</div>
        </aside>

        <slot />
    </div>
</template>
