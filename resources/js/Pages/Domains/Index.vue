<script setup>
import AppLayout from '../../Layouts/AppLayout.vue';
import Icon from '../../Components/Icon.vue';

defineProps({ domains: Array });

const COLS = 'minmax(220px, 1fr) 120px 130px minmax(200px, 1fr) 120px';

function limitLabel(limit) {
    if (limit === 0) return 'без ограничения';
    if (limit < 0) return 'создание запрещено';
    return limit;
}
</script>

<template>
    <AppLayout title="Домены" :count="domains.length">
        <template #actions>
            <button class="btn" type="button">Проверить DNS</button>
            <button class="btn btn--primary" type="button"><Icon name="plus" :size="16" />Добавить</button>
        </template>

        <div class="card card--flush">
            <div class="thead" :style="{ gridTemplateColumns: COLS }">
                <span>Домен</span><span>Сотрудников</span><span>Псевдонимов</span><span>Лимиты</span><span>Статус</span>
            </div>
            <div v-for="row in domains" :key="row.domain" class="row" :style="{ gridTemplateColumns: COLS }">
                <div>
                    <div class="row__name mono" style="font-size: 14px">{{ row.domain }}</div>
                    <div class="row__sub">{{ row.description || (row.backupmx ? 'Резервный MX' : 'Транспорт ' + row.transport) }}</div>
                </div>
                <div>{{ row.mailboxCount }}</div>
                <div>{{ row.aliasCount }}</div>
                <div class="hint" style="font-size: 13px">
                    ящиков: {{ limitLabel(row.mailboxLimit) }} · квота по умолчанию: {{ row.maxQuotaMb ? row.maxQuotaMb + ' МБ' : 'нет' }}
                </div>
                <div><span class="chip" :class="row.active ? 'chip--ok' : 'chip--off'">{{ row.active ? 'включён' : 'выключен' }}</span></div>
            </div>
        </div>
    </AppLayout>
</template>
