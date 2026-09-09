<script setup>
// Отчёты сервера: Logwatch, скрипты iRedMail, сводки и уведомления приложения — в виде текстовых файлов.
import { Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import Icon from '../../Components/Icon.vue';

const props = defineProps({
    kinds: Array,
    kind: String,
    rows: Array,
    open: Object,
    systemDir: String,
    systemDirOk: Boolean,
});

function url(kind, file) { return '/reports?' + new URLSearchParams({ ...(kind ? { kind } : {}), ...(file ? { file } : {}) }).toString(); }
function show(r) { router.get(url(props.kind, r.file) + (props.kind ? '' : '&kind=' + r.kind), {}, { preserveScroll: true, preserveState: true, only: ['open'] }); }
function del(r) {
    if (!confirm(`Удалить отчёт ${r.title} от ${r.date}?`)) return;
    router.delete(`/reports/${r.kind}/${r.file}`, { preserveScroll: true });
}
const kb = (b) => (b > 1048576 ? (b / 1048576).toFixed(1) + ' МБ' : b > 1024 ? Math.round(b / 1024) + ' КБ' : b + ' Б');
const wrap = ref(true);
</script>

<template>
    <AppLayout title="Отчёты">
        <template #actions>
            <span v-if="!systemDirOk" class="chip chip--warn">каталог {{ systemDir }} недоступен — системные отчёты не собираются (deploy/setup-reports.sh)</span>
        </template>

        <div class="grid-2-1" style="grid-template-columns: 240px minmax(0, 1fr)">
            <div class="card card--flush">
                <Link :href="url(null)" class="row row--click" :class="{ 'row--on': !kind }" style="grid-template-columns: minmax(0, 1fr) auto"><span>Все отчёты</span><span class="row__sub">{{ kinds.reduce((s, k) => s + k.count, 0) }}</span></Link>
                <Link v-for="k in kinds" :key="k.kind" :href="url(k.kind)" class="row row--click" :class="{ 'row--on': kind === k.kind }" style="grid-template-columns: minmax(0, 1fr) auto"><span>{{ k.title }}</span><span class="row__sub">{{ k.count }}</span></Link>
                <p class="hint" style="padding: 12px 16px; margin: 0">Раньше всё это приходило письмами на postmaster. Теперь cron-скрипты сервера и Logwatch пишут сюда, письма администратору дублируются здесь же. Хранится 180 дней.</p>
            </div>

            <div>
                <div v-if="open" class="card card--flush" style="margin-bottom: 14px">
                    <div class="thead" style="grid-template-columns: minmax(0, 1fr) auto; align-items: center">
                        <span style="text-transform: none; letter-spacing: 0; font-size: 14px; color: var(--text)"><b>{{ open.title }}</b> · {{ open.file.replace('.txt', '').replace('_', ' ').replace(/(\d\d)(\d\d)$/, '$1:$2') }}</span>
                        <span style="display: flex; gap: 6px">
                            <button class="btn btn--sm" type="button" @click="wrap = !wrap">{{ wrap ? 'Без переносов' : 'С переносами' }}</button>
                            <a class="btn btn--sm" :href="`/reports/${open.kind}/${open.file}/download`"><Icon name="download" :size="14" />Скачать</a>
                            <Link class="btn btn--sm btn--icon" :href="url(kind)" title="Закрыть" preserve-scroll><Icon name="x" :size="14" /></Link>
                        </span>
                    </div>
                    <pre class="log" :style="{ margin: 0, padding: '14px 16px', maxHeight: '70vh', overflow: 'auto', fontSize: '12.5px', whiteSpace: wrap ? 'pre-wrap' : 'pre' }">{{ open.text }}</pre>
                    <p v-if="open.truncated" class="hint" style="padding: 8px 16px; margin: 0">Показаны первые 400 000 символов — полный текст в скачанном файле.</p>
                </div>

                <div class="card card--flush">
                    <div class="thead" style="grid-template-columns: 130px minmax(0, 1fr) 80px auto"><span>Когда</span><span>Отчёт</span><span>Размер</span><span /></div>
                    <div v-for="r in rows" :key="r.kind + r.file" class="row row--click" :class="{ 'row--on': open && open.kind === r.kind && open.file === r.file }" style="grid-template-columns: 130px minmax(0, 1fr) 80px auto" @click="show(r)">
                        <span class="mono">{{ r.date }}</span>
                        <span>{{ r.title }}<span class="row__sub" style="display: block">{{ r.kind }}/{{ r.file }}</span></span>
                        <span class="row__sub">{{ kb(r.size) }}</span>
                        <span class="row__actions" @click.stop>
                            <a class="btn btn--sm btn--icon" :href="`/reports/${r.kind}/${r.file}/download`" title="Скачать"><Icon name="download" :size="16" /></a>
                            <button class="btn btn--sm btn--icon btn--danger" type="button" title="Удалить" @click="del(r)"><Icon name="trash" :size="16" /></button>
                        </span>
                    </div>
                    <div v-if="!rows.length" class="empty">Отчётов пока нет. Logwatch и сводка приходят раз в сутки, копия баз — ночью.</div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
