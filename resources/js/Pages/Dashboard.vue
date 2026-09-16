<script setup>
// Обзор: цифры за сутки, график писем по часам, «требует внимания», службы, активные адреса, действия админов.
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '../Layouts/AppLayout.vue';

const props = defineProps({
    today: String,
    tiles: Array,
    hours: Array,
    attention: Array,
    services: Array,
    top: Array,
    actions: Array,
});

const maxHour = computed(() => Math.max(1, ...props.hours.map((h) => h.ok + h.bad)));
const maxTop = computed(() => Math.max(1, ...props.top.map((t) => t.n)));
</script>

<template>
    <AppLayout title="Обзор" :count="today">
        <div class="tiles tiles--5">
            <div v-for="t in tiles" :key="t.label" class="tile">
                <div class="tile__value" :class="{ 'tile__value--warn': t.kind === 'warn', 'tile__value--no': t.kind === 'no' }">{{ t.value }}</div>
                <div class="tile__label">{{ t.label }}</div>
                <div class="tile__sub">{{ t.sub }}</div>
            </div>
        </div>

        <div class="grid-2-1">
            <div class="card card--pad">
                <div class="card__title" style="display: flex; align-items: center">
                    Письма за сутки
                    <span style="flex: 1" />
                    <span class="hint"><span class="dot dot--acc" /> доставлено &nbsp; <span class="dot dot--warn" /> спам и отказы</span>
                </div>
                <div v-if="hours.length" class="bars">
                    <div v-for="h in hours" :key="h.h" class="bars__col" :title="`${h.h}: доставлено ${h.ok}, спам и отказы ${h.bad}`">
                        <div class="bars__bad" :style="{ height: (h.bad / maxHour * 150) + 'px' }" />
                        <div class="bars__ok" :style="{ height: (h.ok / maxHour * 150) + 'px' }" />
                    </div>
                </div>
                <div v-else class="empty">Журнал почты недоступен приложению</div>
                <div class="bars__axis"><span v-for="h in hours.filter((_, i) => i % 6 === 0)" :key="h.h">{{ h.h }}</span><span>{{ hours[hours.length - 1]?.h }}</span></div>
            </div>

            <div class="card card--pad">
                <div class="card__title">Требует внимания</div>
                <div v-if="!attention.length" class="empty">Всё в порядке</div>
                <component :is="a.href ? Link : 'div'" v-for="(a, i) in attention" :key="i" :href="a.href || undefined" class="kv kv--link">
                    <span class="dot" :class="`dot--${a.kind}`" style="flex: 0 0 8px; margin-top: 7px" />
                    <span style="color: inherit; flex: 1">{{ a.text }}</span>
                </component>
            </div>
        </div>

        <div class="grid-3">
            <div class="card card--pad">
                <div class="card__title">Службы</div>
                <div v-for="s in services" :key="s.name" class="kv">
                    <span style="color: inherit">{{ s.name }}</span>
                    <span class="chip" :class="`chip--${s.kind}`">{{ s.status }}</span>
                </div>
            </div>
            <div class="card card--pad">
                <div class="card__title" style="display: flex; align-items: center">Самые активные адреса <span style="flex: 1" /><span class="faint">писем за сутки</span></div>
                <div v-if="!top.length" class="empty">Пока писем не было</div>
                <div v-for="t in top" :key="t.addr" class="kv">
                    <span class="mono" style="color: inherit; overflow: hidden; text-overflow: ellipsis">{{ t.addr }}</span>
                    <span style="display: flex; align-items: center; gap: 8px"><span class="bar" :style="{ width: Math.round(t.n / maxTop * 90) + 'px' }" /><b>{{ t.n }}</b></span>
                </div>
            </div>
            <div class="card card--pad">
                <div class="card__title" style="display: flex; align-items: center">Последние действия админов <span style="flex: 1" /><Link href="/logs?type=admin" class="hint">Журнал</Link></div>
                <div v-if="!actions.length" class="empty">Записей ещё нет</div>
                <div v-for="(a, i) in actions" :key="i" class="kv kv--start">
                    <span class="mono faint" style="flex: 0 0 52px">{{ a.ts }}</span>
                    <span style="color: inherit">{{ a.text }}</span>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
