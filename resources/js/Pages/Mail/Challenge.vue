<script setup>
import { useForm } from '@inertiajs/vue3';

const form = useForm({ code: '' });
function submit() {
    form.post('/mail/login/code');
}
</script>

<template>
    <div class="auth">
        <form class="card auth__card" @submit.prevent="submit">
            <div class="auth__brand">
                <div class="rail__logo" style="margin: 0">П</div>
                <div>
                    <div class="auth__title">Код из приложения</div>
                    <div class="hint">Второй шаг входа в почту</div>
                </div>
            </div>
            <div class="field">
                <label>Шесть цифр из приложения-аутентификатора</label>
                <input v-model="form.code" class="input auth__code" inputmode="numeric" maxlength="6" autocomplete="one-time-code" autofocus>
                <p v-if="form.errors.code" class="error">{{ form.errors.code }}</p>
            </div>
            <button class="btn btn--primary" type="submit" style="height: 44px" :disabled="form.processing">Подтвердить</button>
            <p class="hint" style="margin: 0">Потеряли телефон? Администратор может сбросить защиту в разделе «Безопасность».</p>
        </form>
    </div>
</template>
