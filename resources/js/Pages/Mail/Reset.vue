<script setup>
// Смена пароля по ссылке от администратора (пришла на личную почту).
import { useForm } from '@inertiajs/vue3';

const props = defineProps({ token: String, user: String, minLength: Number, domain: String });
const form = useForm({ password: '', password_confirmation: '' });
</script>

<template>
    <div class="auth">
        <form class="card auth__card" @submit.prevent="form.post(`/mail/reset/${token}`)">
            <div class="auth__brand">
                <div class="rail__logo" style="margin: 0">П</div>
                <div><div class="auth__title">Новый пароль</div><div class="hint">для {{ user }}</div></div>
            </div>
            <div class="field">
                <label>Пароль (не короче {{ minLength }} символов)</label>
                <input v-model="form.password" class="input" type="password" autocomplete="new-password" :minlength="minLength" required autofocus>
                <p v-if="form.errors.password" class="error">{{ form.errors.password }}</p>
            </div>
            <div class="field">
                <label>Ещё раз</label>
                <input v-model="form.password_confirmation" class="input" type="password" autocomplete="new-password" required>
            </div>
            <button class="btn btn--primary" type="submit" style="height: 44px" :disabled="form.processing">Сохранить и войти</button>
            <p class="hint" style="margin: 0">Этот же пароль понадобится в почтовой программе и на телефоне.</p>
        </form>
    </div>
</template>
