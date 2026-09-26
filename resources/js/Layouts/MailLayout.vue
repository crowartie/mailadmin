<script setup>
import { Link, usePage, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import Icon from '../Components/Icon.vue';
import FeedbackDialog from '../Components/Mail/FeedbackDialog.vue';
import ConfirmHost from '../Components/ConfirmHost.vue';
import Popover from '../Components/Mail/Popover.vue';
import { initUi, setUiSimple, uiSimple } from '../mail/uiMode';

const props = defineProps({
    user: String,
    theme: { type: String, default: 'light' },
    // Цветовая схема: brand — фирменная (оранжевая), classic — синяя; работает вместе с темой.
    scheme: { type: String, default: 'brand' },
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

// Вид интерфейса (простой / подробный) — из настроек сотрудника, см. mail/uiMode.
initUi(!!page.props.uiSimple);
watch(() => page.props.uiSimple, (v) => initUi(!!v));

// Меню по инициалам: настройки, справка, обращение, тема, вид и выход. Раньше это были шесть
// значков без подписей внизу полосы — непонятно, что есть что (жалоба «перегружено»).
const meMenu = ref(null);   // { x, y } — где открыто
function openMe(e) {
    if (meMenu.value) { meMenu.value = null; return; }
    const r = e.currentTarget.getBoundingClientRect();
    meMenu.value = { x: r.right + 10, y: r.bottom - 330 };
}

// Буквы в кружке — из имени (как видят получатели), а не из адреса: «ВВ», а не «VV».
const initials = computed(() => {
    const words = String(page.props.mailName || '').trim().split(/\s+/).filter((w) => /^[\p{L}]/u.test(w));
    if (words.length && !String(page.props.mailName).includes('@')) return (words[0][0] + (words[1] ? words[1][0] : '')).toUpperCase();
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

function applyScheme(sch) {
    const real = sch === 'classic' ? 'classic' : 'brand';
    if (real === 'brand') delete document.documentElement.dataset.scheme; else document.documentElement.dataset.scheme = real;
    try { localStorage.setItem('mail.scheme', real); } catch {}
}
onMounted(() => applyScheme(props.scheme));
watch(() => props.scheme, applyScheme);

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

// Выход в два щелчка без окна браузера «Подтвердите действие»: первый показывает рядом
// красную кнопку «Выйти», второй (по ней) выходит. Не нажали — через 5 секунд кнопка прячется.
const exitAsk = ref(false);
let exitTimer = null;
function askExit() {
    exitAsk.value = !exitAsk.value;
    clearTimeout(exitTimer);
    if (exitAsk.value) exitTimer = setTimeout(() => { exitAsk.value = false; }, 5000);
}
function logout() {
    clearTimeout(exitTimer);
    exitAsk.value = false;
    router.post('/mail/logout');
}
// Щелчок мимо и Escape прячут кнопку.
function exitOutside(e) { if (exitAsk.value && !e.target.closest('.rail__exitbox, .sheet__item--exit')) exitAsk.value = false; }
function exitEsc(e) { if (e.key === 'Escape') exitAsk.value = false; }
onMounted(() => { document.addEventListener('click', exitOutside, true); document.addEventListener('keydown', exitEsc); });
onBeforeUnmount(() => { document.removeEventListener('click', exitOutside, true); document.removeEventListener('keydown', exitEsc); clearTimeout(exitTimer); });
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
            <!-- Подключить телефон и программы — одним щелчком, не через настройки. -->
            <Link class="rail__item" :class="{ 'rail__item--on': current.startsWith('/mail/setup') }" href="/mail/setup" title="Телефон и программы: подключить почту" aria-label="Телефон и программы">
                <Icon name="mobile" />
            </Link>
            <div class="rail__spacer" />
            <button class="rail__me" :class="{ 'rail__me--on': meMenu }" type="button" :title="user" :aria-expanded="!!meMenu" aria-label="Меню: настройки, справка, тема, выход" @click="openMe">
                {{ initials }}
                <span v-if="feedbackNew" class="rail__badge">{{ feedbackNew }}</span>
            </button>
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
                <Link class="sheet__item" href="/mail/setup" @click="more = false"><Icon name="mobile" :size="20" />Телефон и программы</Link>
                <!-- 270, 271: на телефоне «Сообщить о проблеме» пропадало совсем,
                     хотя справка обещает кнопку на любой странице. -->
                <button class="sheet__item" type="button" @click="more = false; feedback = true">
                    <Icon name="warn" :size="20" />Сообщить о проблеме
                    <span v-if="feedbackNew" class="chip chip--warn" style="margin-left: auto">{{ feedbackNew }}</span>
                </button>
                <button class="sheet__item" type="button" @click="toggleTheme">
                    <Icon :name="isDark ? 'sun' : 'moon'" :size="20" />{{ isDark ? 'Светлая тема' : 'Тёмная тема' }}
                </button>
                <button class="sheet__item" type="button" :aria-pressed="uiSimple" @click="setUiSimple(!uiSimple)">
                    <Icon name="eye" :size="20" />Простой вид<Icon v-if="uiSimple" name="check" :size="20" style="margin-left: auto" />
                </button>
                <!-- 334: «Выйти» стояло наравне с разделами и читалось как раздел. -->
                <!-- Первое касание — вопрос прямо на кнопке, второе — выход. -->
                <button class="sheet__item sheet__item--exit" :class="{ 'sheet__item--exit-ask': exitAsk }" type="button" @click="exitAsk ? logout() : askExit()">
                    <Icon name="logout" :size="20" />{{ exitAsk ? 'Нажмите ещё раз, чтобы выйти' : 'Выйти из почты' }}
                </button>
                <button class="sheet__item sheet__item--close" type="button" @click="more = false">Закрыть</button>
            </div>
        </div>

        <Popover v-if="meMenu" :x="meMenu.x" :y="meMenu.y" :width="250" @close="meMenu = null">
            <div class="pop__me"><b>{{ page.props.mailName || user }}</b><span>{{ user }}</span></div>
            <div class="pop__sep" />
            <Link class="pop__item" href="/mail/settings" @click="meMenu = null"><Icon name="sliders" :size="16" />Настройки</Link>
            <Link class="pop__item" href="/mail/help" @click="meMenu = null"><Icon name="info" :size="16" />Справка</Link>
            <button class="pop__item" type="button" @click="meMenu = null; feedback = true">
                <Icon name="warn" :size="16" />Сообщить о проблеме<span v-if="feedbackNew" class="chip chip--warn" style="margin-left: auto">{{ feedbackNew }}</span>
            </button>
            <div class="pop__sep" />
            <button class="pop__item" type="button" @click="toggleTheme"><Icon :name="isDark ? 'sun' : 'moon'" :size="16" />{{ isDark ? 'Светлая тема' : 'Тёмная тема' }}</button>
            <button class="pop__item" type="button" role="menuitemcheckbox" :aria-checked="uiSimple" title="Меньше кнопок на панели письма и в меню правой кнопки" @click="setUiSimple(!uiSimple)">
                <Icon name="eye" :size="16" />Простой вид<Icon v-if="uiSimple" name="check" :size="16" style="margin-left: auto; color: var(--accent-ink)" />
            </button>
            <div class="pop__sep" />
            <button class="pop__item pop__item--danger" type="button" @click="logout"><Icon name="logout" :size="16" />Выйти</button>
        </Popover>
        <FeedbackDialog v-if="feedback" @close="feedback = false" />
        <ConfirmHost />
    </div>
</template>
