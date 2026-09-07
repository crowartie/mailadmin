<script setup>
import { useForm } from '@inertiajs/vue3';

const form = useForm({ email: '', password: '' });
function submit() {
    form.post('/login');
}
</script>

<template>
    <div class="auth">
        <form class="card auth__card" @submit.prevent="submit">
            <div class="auth__brand">
                <div class="rail__logo" style="margin: 0">П</div>
                <div>
                    <div class="auth__title">Почта innotec.su</div>
                    <div class="hint">Панель администратора</div>
                </div>
            </div>

            <div class="field">
                <label>Адрес</label>
                <input v-model="form.email" class="input" type="email" placeholder="admin@innotec.su" autocomplete="username" autofocus>
                <p v-if="form.errors.email" class="error">{{ form.errors.email }}</p>
            </div>
            <div class="field">
                <label>Пароль</label>
                <input v-model="form.password" class="input" type="password" autocomplete="current-password">
            </div>

            <button class="btn btn--primary" type="submit" style="height: 44px" :disabled="form.processing">Войти</button>
            <p class="hint" style="margin: 0">Пароль администратора не совпадает с почтовым — это отдельная учётная запись.</p>
        </form>
    </div>
</template>
