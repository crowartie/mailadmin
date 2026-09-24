<script setup>
import { useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({ domain: String, rememberDays: { type: Number, default: 90 } });
const page = usePage();
const flash = computed(() => {
    const f = { ...(page.props.flash || {}) };
    // Причина принудительного выхода приходит в адресе (?m=…) — см. mail/api.js.
    const m = typeof window !== 'undefined' ? new URLSearchParams(window.location.search).get('m') : null;
    if (m && !f.error) f.error = m;
    return f;
});

// «Не выходить на этом устройстве»: по умолчанию выключено — компьютер может быть общим.
const form = useForm({ login: '', password: '', remember: false });
function submit() {
    form.post('/mail/login');
}

// 6: на телефоне опечатку в пароле не видно, а пароль от почты длинный.
const showPassword = ref(false);

// 7: у русскоязычных две частые причины неудачного входа — Caps Lock и раскладка.
// Про них молчали, и человек считал, что ему сменили пароль.
const caps = ref(false);
const cyrillic = ref(false);
function watchKeys(e) {
    caps.value = typeof e.getModifierState === 'function' && e.getModifierState('CapsLock');
}
function watchInput(e) {
    cyrillic.value = /[а-яё]/i.test(e.target.value);
}
</script>

<template>
    <div class="auth">
        <form class="card auth__card" @submit.prevent="submit">
            <div class="auth__brand">
                <div class="rail__logo" style="margin: 0">П</div>
                <div>
                    <h1 class="auth__title">Почта {{ domain }}</h1>
                    <div class="hint">Вход для сотрудников</div>
                </div>
            </div>

            <p v-if="flash.error" class="error" style="margin: 0">{{ flash.error }}</p>
            <p v-if="flash.success" class="hint" style="margin: 0; color: var(--ok)">{{ flash.success }}</p>
            <!-- 2, 3: «неверный адрес или пароль» висело под полем логина, будто виноват он.
                 Показываем над обоими полями — ошибка относится к паре целиком. -->
            <p v-if="form.errors.login" class="error" style="margin: 0" role="alert">{{ form.errors.login }}</p>
            <p v-if="form.errors.password" class="error" style="margin: 0" role="alert">{{ form.errors.password }}</p>
            <div class="field">
                <label for="auth-login">Адрес или логин</label>
                <input
                    id="auth-login"
                    v-model="form.login"
                    class="input"
                    :placeholder="`ivanov или ivanov@${domain}`"
                    autocomplete="username"
                    required
                    autofocus
                    :aria-invalid="form.errors.login ? 'true' : null"
                >
            </div>
            <div class="field">
                <label for="auth-password">Пароль</label>
                <div class="field__row" style="gap: 6px">
                    <input
                        id="auth-password"
                        v-model="form.password"
                        class="input"
                        :type="showPassword ? 'text' : 'password'"
                        autocomplete="current-password"
                        required
                        :aria-invalid="form.errors.login ? 'true' : null"
                        @keyup="watchKeys"
                        @keydown="watchKeys"
                        @input="watchInput"
                    >
                    <button
                        class="btn"
                        type="button"
                        style="flex: 0 0 auto"
                        :title="showPassword ? 'Скрыть пароль' : 'Показать пароль'"
                        :aria-label="showPassword ? 'Скрыть пароль' : 'Показать пароль'"
                        :aria-pressed="showPassword ? 'true' : 'false'"
                        @click="showPassword = !showPassword"
                    >{{ showPassword ? 'Скрыть' : 'Показать' }}</button>
                </div>
                <p v-if="caps" class="hint" style="margin: 0; color: var(--warn-ink)">Включён Caps Lock — пароль вводится заглавными.</p>
                <p v-else-if="cyrillic" class="hint" style="margin: 0; color: var(--warn-ink)">В пароле русские буквы — возможно, не переключена раскладка.</p>
            </div>

            <label v-if="rememberDays" class="auth__remember">
                <input v-model="form.remember" type="checkbox">
                <span>Не выходить на этом устройстве<span class="hint" style="display: block; margin: 0">{{ rememberDays }} дней без повторного входа. Не ставьте на чужом или общем компьютере.</span></span>
            </label>
            <button class="btn btn--primary" type="submit" style="height: 44px" :disabled="form.processing">Войти</button>
            <p class="hint" style="margin: 0">Тот же пароль, что в почтовой программе и на телефоне.</p>
            <p class="hint" style="margin: 0; display: flex; gap: 14px; flex-wrap: wrap"><a href="/mail/setup">Подключить телефон или программу</a><a href="/mail/help">Справка по почте</a></p>
        </form>
    </div>
</template>
