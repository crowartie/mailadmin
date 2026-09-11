<script setup>
import { Link, usePage, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import Icon from '../Components/Icon.vue';

const props = defineProps({
    title: String,
    count: [String, Number],
    searchPlaceholder: { type: String, default: 'Найти сотрудника, адрес, письмо…' },
});

const page = usePage();
const current = computed(() => page.url.split('?')[0]);
const counts = computed(() => page.props.nav?.counts ?? {});
const health = computed(() => page.props.nav?.health ?? { kind: 'ok', text: 'Все службы работают' });

// Сообщение после действия: зелёное — успех, красное — ошибка (живёт дольше, чтобы успеть прочитать).
const flash = computed(() => page.props.flash?.success ?? page.props.flash?.error ?? null);
const flashError = computed(() => !page.props.flash?.success && Boolean(page.props.flash?.error));
const flashVisible = ref(false);
let flashTimer = null;
watch(flash, (value) => {
    flashVisible.value = Boolean(value);
    clearTimeout(flashTimer);
    if (value) flashTimer = setTimeout(() => (flashVisible.value = false), flashError.value ? 9000 : 4000);
}, { immediate: true });

// Разделы — как на макете: люди отдельно от сервера.
const groups = [
    { items: [
        { href: '/', icon: 'home', label: 'Обзор' },
        { href: '/feedback', icon: 'warn', label: 'Обращения', count: 'feedback' },
    ] },
    { title: 'Люди', items: [
        { href: '/mailboxes', icon: 'users', label: 'Сотрудники', count: 'mailboxes' },
        { href: '/units', icon: 'list', label: 'Подразделения', count: 'units' },
        { href: '/aliases', icon: 'at', label: 'Псевдонимы', count: 'aliases' },
        { href: '/maillists', icon: 'mail', label: 'Рассылки', count: 'maillists' },
        { href: '/rules', icon: 'filter', label: 'Правила', count: 'rules' },
        { href: '/company-contacts', icon: 'book', label: 'Контакты компании' },
    ] },
    { title: 'Сервер', items: [
        { href: '/domains', icon: 'globe', label: 'Домены', count: 'domains' },
        { href: '/queue', icon: 'queue', label: 'Очередь', count: 'queue' },
        { href: '/logs', icon: 'log', label: 'Журналы' },
        { href: '/reports', icon: 'file', label: 'Отчёты' },
        { href: '/security', icon: 'shield', label: 'Безопасность' },
        { href: '/settings', icon: 'gear', label: 'Настройки' },
    ] },
];

const isActive = (href) => (href === '/' ? current.value === '/' : current.value.startsWith(href));

const search = ref('');
function submitSearch() {
    if (search.value.trim()) router.get('/mailboxes', { search: search.value.trim() });
}

const me = computed(() => page.props.auth?.user ?? null);
const mailUrl = computed(() => page.props.mailUrl || '');
const meInitials = computed(() => {
    const src = me.value?.name || me.value?.email || '';
    const parts = src.replace(/@.*/, '').split(/[\s._-]+/).filter(Boolean);
    return parts.slice(0, 2).map((p) => p[0].toUpperCase()).join('') || 'А';
});
function logout() {
    router.post('/logout');
}
</script>

<template>
    <div class="app">
        <aside class="rail" aria-label="Сервисы">
            <div class="rail__logo">П</div>
            <a class="rail__item" :href="mailUrl + '/mail'" title="Почта"><Icon name="mail" /></a>
            <a class="rail__item" :href="mailUrl + '/calendar'" title="Календарь"><Icon name="cal" /></a>
            <a class="rail__item" :href="mailUrl + '/contacts'" title="Контакты"><Icon name="users" /></a>
            <Link class="rail__item rail__item--on" href="/" title="Администрирование"><Icon name="gear" /></Link>
            <div class="rail__spacer" />
            <button class="rail__item" type="button" title="Выйти" style="border: none; background: none; cursor: pointer" @click="logout"><Icon name="x" /></button>
            <Link class="rail__avatar" href="/security/2fa" :title="me ? `${me.email} · двухфакторная защита` : ''" style="text-decoration: none">{{ meInitials }}</Link>
        </aside>

        <nav class="nav" aria-label="Разделы">
            <template v-for="(group, gi) in groups" :key="gi">
                <div v-if="group.title" class="nav__group">{{ group.title }}</div>
                <Link
                    v-for="item in group.items"
                    :key="item.href"
                    :href="item.href"
                    class="nav__item"
                    :class="{ 'nav__item--on': isActive(item.href) }"
                >
                    <Icon :name="item.icon" :size="18" />
                    <span>{{ item.label }}</span>
                    <span v-if="item.count && counts[item.count] != null" class="nav__count">{{ counts[item.count] }}</span>
                </Link>
            </template>
        </nav>

        <div class="content">
            <header class="topbar">
                <form class="topbar__search" @submit.prevent="submitSearch">
                    <Icon name="search" :size="18" />
                    <input v-model="search" type="search" :placeholder="searchPlaceholder">
                </form>
                <div class="topbar__grow" />
                <div class="topbar__status">
                    <span class="dot" :class="`dot--${health.kind}`" />
                    {{ health.text }}<template v-if="me"> · {{ me.email }}</template>
                </div>
            </header>

            <transition name="flash">
                <div v-if="flashVisible" class="flash" :class="{ 'flash--error': flashError }">{{ flash }}</div>
            </transition>

            <main class="page">
                <div class="page-head">
                    <h1>{{ title }}</h1>
                    <span v-if="count !== undefined && count !== null" class="page-head__count">{{ count }}</span>
                    <div class="page-head__grow" />
                    <slot name="actions" />
                </div>
                <slot />
            </main>
        </div>

        <slot name="overlay" />
    </div>
</template>
