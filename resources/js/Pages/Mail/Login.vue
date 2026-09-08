<script setup>
import { useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({ domain: String });
const page = usePage();
const flash = computed(() => page.props.flash || {});

const form = useForm({ login: '', password: '' });
function submit() {
    form.post('/mail/login');
}
</script>

<template>
    <div class="auth">
        <form class="card auth__card" @submit.prevent="submit">
            <div class="auth__brand">
                <div class="rail__logo" style="margin: 0">П</div>
                <div>
                    <div class="auth__title">Почта {{ domain }}</div>
                    <div class="hint">Вход для сотрудников</div>
                </div>
            </div>

            <p v-if="flash.error" class="error" style="margin: 0">{{ flash.error }}</p>
            <p v-if="flash.success" class="hint" style="margin: 0; color: var(--ok)">{{ flash.success }}</p>
            <div class="field">
                <label>Адрес или логин</label>
                <input v-model="form.login" class="input" :placeholder="`ivanov или ivanov@${domain}`" autocomplete="username" autofocus>
                <p v-if="form.errors.login" class="error">{{ form.errors.login }}</p>
            </div>
            <div class="field">
                <label>Пароль</label>
                <input v-model="form.password" class="input" type="password" autocomplete="current-password">
            </div>

            <button class="btn btn--primary" type="submit" style="height: 44px" :disabled="form.processing">Войти</button>
            <p class="hint" style="margin: 0">Тот же пароль, что в почтовой программе и на телефоне. <a href="/mail/help">Справка по почте</a></p>
        </form>
    </div>
</template>
