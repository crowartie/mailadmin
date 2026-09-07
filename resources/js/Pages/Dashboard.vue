<script setup>
import AppLayout from '../Layouts/AppLayout.vue';

defineProps({
    stats: Object,
    services: Array,
    storage: Object,
    today: String,
});

function gb(bytes) {
    return (bytes / 1073741824).toFixed(1).replace('.0', '');
}
</script>

<template>
    <AppLayout title="Обзор" :count="today">
        <div class="tiles">
            <div class="tile">
                <div class="tile__value">{{ stats.mailboxes }}</div>
                <div class="tile__label">Сотрудников</div>
                <div class="tile__sub">из них {{ stats.active }} активных</div>
            </div>
            <div class="tile">
                <div class="tile__value">{{ stats.domains }}</div>
                <div class="tile__label">Доменов</div>
            </div>
            <div class="tile">
                <div class="tile__value">{{ stats.aliases }}</div>
                <div class="tile__label">Псевдонимов</div>
            </div>
            <div class="tile">
                <div class="tile__value">{{ stats.forwardings }}</div>
                <div class="tile__label">Пересылок</div>
            </div>
            <div class="tile">
                <div class="tile__value">{{ stats.rules }}</div>
                <div class="tile__label">Правил у сотрудников</div>
            </div>
        </div>

        <div class="grid-2">
            <div class="card card--pad">
                <div class="card__title">Службы</div>
                <div v-for="s in services" :key="s.name" class="kv">
                    <span style="color: inherit">{{ s.name }}</span>
                    <span class="chip" :class="`chip--${s.kind}`">{{ s.status }}</span>
                </div>
            </div>

            <div class="card card--pad">
                <div class="card__title">Хранилище почты</div>
                <div class="kv"><span>Занято всеми ящиками</span><b>{{ gb(storage.usedBytes) }} ГБ</b></div>
                <div class="kv"><span>Писем</span><b>{{ storage.messages.toLocaleString('ru-RU') }}</b></div>
                <div class="kv"><span>Самый большой ящик</span><b class="mono" style="font-weight: 400">{{ storage.top || '—' }}</b></div>
            </div>
        </div>
    </AppLayout>
</template>
