<script setup>
import { Link, usePage, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import Icon from '../Components/Icon.vue';
import FeedbackDialog from '../Components/Mail/FeedbackDialog.vue';

const props = defineProps({
    user: String,
    theme: { type: String, default: 'light' },
});

const page = usePage();
const current = computed(() => page.url.split('?')[0]);
// Новые ответы по обращениям — число на значке «Сообщить о проблеме»; обновляется само.
const feedbackOwn = ref(null);
const feedbackNew = computed(() => (feedbackOwn.value === null ? Number(page.props.feedbackNew || 0) : feedbackOwn.value));
let feedbackTimer = null;
async function checkFeedback() {
    if (document.hidden || !props.user) return;
    try {
        const r = await fetch('/mail/api/feedback/unread', { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
        if (r.ok) feedbackOwn.value = (await r.json()).unread;
    } catch { /* не страшно */ }
}
onMounted(() => { feedbackTimer = setInterval(checkFeedback, 60000); });
onBeforeUnmount(() => clearInterval(feedbackTimer));

// Почта, календарь и контакты — один интерфейс; рельс переключает разделы.
const services = computed(() => [
    { href: '/mail', icon: 'mail', label: 'Почта' },
    { href: '/calendar', icon: 'cal', label: 'Календарь' },
    { href: '/contacts', icon: 'users', label: 'Контакты' },
    ...(page.props.cloudPersonal ? [{ href: '/cloud', icon: 'cloud', label: 'Облако' }] : []),
]);

// «Ещё» на телефоне: в нижней панели помещается только четыре пункта.
const more = ref(false);

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

// «Сообщить о проблеме» доступно с любой страницы: так и узнаём, где именно не сработало.
const feedback = ref(false);

function logout() {
    if (!window.confirm('Выйти из почты?')) return;
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
            <button v-if="user" class="rail__item" type="button" :title="feedbackNew ? 'Есть ответ по обращению' : 'Сообщить о проблеме'" style="border: none; background: none; cursor: pointer; position: relative" @click="feedback = true">
                <Icon name="warn" />
                <span v-if="feedbackNew" class="rail__badge">{{ feedbackNew }}</span>
            </button>
            <button class="rail__item" type="button" title="Тёмная / светлая тема" style="border: none; background: none; cursor: pointer" @click="toggleTheme" aria-label="Тёмная / светлая тема">
                <Icon :name="isDark ? 'sun' : 'moon'" />
            </button>
            <button v-if="user" class="rail__item" type="button" title="Выйти" style="border: none; background: none; cursor: pointer" @click="logout" aria-label="Выйти">
                <Icon name="logout" />
            </button>
            <div class="rail__avatar" :title="user">{{ initials }}</div>
        </aside>

        <slot />

        <!-- Телефон: боковая полоса скрыта, разделы — в нижней панели.
             Пунктов ровно четыре: семь не помещались по ширине, и подписи «Контакты»,
             «Настройки», «Проблема» слипались в сплошную строку. Остальное — в «Ещё». -->
        <nav v-if="user" class="tabbar" aria-label="Разделы">
            <Link v-for="s in services" :key="s.href" class="tabbar__item" :class="{ 'tabbar__item--on': current.startsWith(s.href) && !current.startsWith('/mail/settings') && !current.startsWith('/mail/help') }" :href="s.href">
                <Icon :name="s.icon" :size="22" /><span>{{ s.label }}</span>
            </Link>
            <button class="tabbar__item" type="button" style="position: relative" :class="{ 'tabbar__item--on': more || current.startsWith('/mail/settings') || current.startsWith('/mail/help') }" :aria-expanded="more" @click="more = true">
                <Icon name="dots" :size="22" /><span>Ещё</span>
                <span v-if="feedbackNew" class="rail__badge" style="top: 4px; right: 18px">{{ feedbackNew }}</span>
            </button>
        </nav>

        <!-- «Ещё»: настройки, справка, обращение, тема и выход. -->
        <div v-if="more" class="sheet" @click.self="more = false">
            <div class="sheet__panel" role="dialog" aria-label="Ещё">
                <Link class="sheet__item" href="/mail/settings" @click="more = false"><Icon name="sliders" :size="20" />Настройки</Link>
                <Link class="sheet__item" href="/mail/help" @click="more = false"><Icon name="info" :size="20" />Справка</Link>
                <!-- 270, 271: на телефоне «Сообщить о проблеме» пропадало совсем,
                     хотя справка обещает кнопку на любой странице. -->
                <button class="sheet__item" type="button" @click="more = false; feedback = true">
                    <Icon name="warn" :size="20" />Сообщить о проблеме
                    <span v-if="feedbackNew" class="chip chip--warn" style="margin-left: auto">{{ feedbackNew }}</span>
                </button>
                <button class="sheet__item" type="button" @click="toggleTheme">
                    <Icon :name="isDark ? 'sun' : 'moon'" :size="20" />{{ isDark ? 'Светлая тема' : 'Тёмная тема' }}
                </button>
                <!-- 334: «Выйти» стояло наравне с разделами и читалось как раздел. -->
                <button class="sheet__item sheet__item--exit" type="button" @click="logout"><Icon name="logout" :size="20" />Выйти из почты</button>
                <button class="sheet__item sheet__item--close" type="button" @click="more = false">Закрыть</button>
            </div>
        </div>

        <FeedbackDialog v-if="feedback" @close="feedback = false" />
    </div>
</template>
