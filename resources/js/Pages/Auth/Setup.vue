<script setup>
import { useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';

defineProps({
    enabled: Boolean,
    secret: String,
    qr: String,
});

const enable = useForm({ code: '' });
const disable = useForm({ password: '' });
</script>

<template>
    <AppLayout title="Двухфакторная защита">
        <div v-if="!enabled" class="form-layout">
            <form class="card form-card" @submit.prevent="enable.post('/security/2fa')">
                <div class="group-title">Шаг 1 — приложение</div>
                <p class="hint" style="margin: 0">Откройте Google Authenticator, Яндекс Ключ или любое приложение с одноразовыми кодами и отсканируйте QR. Если камеры нет — введите ключ руками.</p>
                <div style="display: flex; gap: 24px; align-items: center">
                    <img :src="qr" alt="QR-код для приложения" width="220" height="220" style="border: 1px solid #E3E8EF; border-radius: 12px">
                    <div class="mono" style="font-size: 14px; letter-spacing: .06em; line-height: 1.8">{{ secret }}</div>
                </div>

                <div class="divider" />
                <div class="group-title">Шаг 2 — подтверждение</div>
                <div class="field" style="width: 260px">
                    <label>Код из приложения</label>
                    <input v-model="enable.code" class="input auth__code" inputmode="numeric" maxlength="6" autocomplete="one-time-code">
                    <p v-if="enable.errors.code" class="error">{{ enable.errors.code }}</p>
                </div>
                <div><button class="btn btn--primary" type="submit" :disabled="enable.processing">Включить защиту</button></div>
            </form>

            <div class="card card--pad">
                <div class="card__title">Зачем</div>
                <p class="hint" style="margin: 0; line-height: 1.5">Админка управляет всей почтой компании. Пароль можно подобрать или подсмотреть; код с телефона — нет. После включения при каждом входе будут спрашивать и пароль, и код.</p>
            </div>
        </div>

        <div v-else class="form-layout">
            <form class="card form-card" @submit.prevent="disable.delete('/security/2fa')">
                <div class="group-title">Защита включена</div>
                <p class="hint" style="margin: 0">Чтобы выключить, подтвердите паролем. Выключать стоит только для смены телефона — и сразу включить обратно.</p>
                <div class="field" style="width: 300px">
                    <label>Пароль администратора</label>
                    <input v-model="disable.password" class="input" type="password" autocomplete="current-password">
                    <p v-if="disable.errors.password" class="error">{{ disable.errors.password }}</p>
                </div>
                <div><button class="btn btn--danger" type="submit" :disabled="disable.processing">Выключить защиту</button></div>
            </form>
        </div>
    </AppLayout>
</template>
