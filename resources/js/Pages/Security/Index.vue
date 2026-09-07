<script setup>
import { Link } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';

defineProps({
    logins: Array,
    admins: Array,
    failures15m: Number,
});

const COLS = '160px minmax(200px, 1fr) 150px 160px';
</script>

<template>
    <AppLayout title="Безопасность">
        <div class="grid-2">
            <div class="card card--pad">
                <div class="card__title">Администраторы</div>
                <div v-for="a in admins" :key="a.email" class="kv">
                    <span style="color: inherit">
                        <span class="mono">{{ a.email }}</span>
                        <span v-if="a.me" class="faint"> · это вы</span>
                    </span>
                    <span style="display: flex; gap: 6px">
                        <span class="chip" :class="a.twoFactor ? 'chip--ok' : 'chip--warn'">{{ a.twoFactor ? '2FA включена' : 'без 2FA' }}</span>
                        <span v-if="!a.active" class="chip chip--off">выключен</span>
                    </span>
                </div>
                <div style="margin-top: 14px">
                    <Link class="btn" href="/security/2fa">Двухфакторная защита для моей учётной записи</Link>
                </div>
            </div>

            <div class="card card--pad">
                <div class="card__title">Защита от перебора</div>
                <div class="kv"><span>Порог</span><b>8 неудач за 15 минут с одного IP</b></div>
                <div class="kv"><span>Неудач с вашего IP сейчас</span><b>{{ failures15m }}</b></div>
                <div class="kv"><span>Баны fail2ban на почтовом сервере</span><b class="hint" style="font-weight: 400">требует агента на сервере — в плане</b></div>
            </div>
        </div>

        <div class="card card--flush">
            <div class="thead" :style="{ gridTemplateColumns: COLS }">
                <span>Когда</span><span>Кто</span><span>Откуда</span><span>Результат</span>
            </div>
            <div v-for="l in logins" :key="l.id" class="row" :style="{ gridTemplateColumns: COLS }">
                <span class="mono">{{ l.at }}</span>
                <span>
                    <span class="mono">{{ l.email }}</span>
                    <div class="row__sub" style="max-width: 420px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap">{{ l.agent }}</div>
                </span>
                <span class="mono">{{ l.ip }}</span>
                <span><span class="chip" :class="`chip--${l.kind}`">{{ l.label }}</span></span>
            </div>
            <div v-if="!logins.length" class="empty">Входов ещё не было</div>
        </div>
    </AppLayout>
</template>
