<script setup>
// Админка: общая книга «Контакты компании» и предложения сотрудников.
import { Link, router, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import Icon from '../../Components/Icon.vue';

const props = defineProps({
    cards: Array,
    suggestions: Array,
    employees: Number,
    filters: Object,
    editing: Object,
});

const search = ref(props.filters.search ?? '');
const creating = ref(false);
let timer = null;
watch(search, () => { clearTimeout(timer); timer = setTimeout(() => router.get('/company-contacts', { search: search.value || undefined }, { preserveState: true, replace: true }), 300); });

function blank(c = null) {
    return {
        first: c?.first ?? '', last: c?.last ?? '', middle: c?.middle ?? '', org: c?.org ?? '', department: c?.department ?? '', title: c?.title ?? '',
        emails: c?.emails?.length ? c.emails.map((e) => ({ ...e })) : [{ value: '', type: 'work' }],
        phones: c?.phones?.length ? c.phones.map((p) => ({ ...p })) : [{ value: '', type: 'work' }],
        addresses: c?.addresses ?? [], birthday: c?.birthday ?? '', url: c?.url ?? '', note: c?.note ?? '', groupsText: (c?.groups ?? []).join(', '),
    };
}
const form = useForm(blank(props.editing));
watch(() => props.editing, (c) => { Object.assign(form, blank(c)); form.clearErrors(); });

function submit() {
    const send = (f) => f.transform((d) => ({ ...d, groups: d.groupsText.split(',').map((s) => s.trim()).filter(Boolean), emails: d.emails.filter((e) => e.value.trim()), phones: d.phones.filter((p) => p.value.trim()) }));
    if (props.editing) send(form).put(`/company-contacts/${props.editing.uri}`, { onSuccess: () => { creating.value = false; } });
    else send(form).post('/company-contacts', { onSuccess: () => { creating.value = false; form.reset(); } });
}
function close() {
    creating.value = false;
    if (props.editing) router.get('/company-contacts', { search: search.value || undefined }, { preserveState: true });
}
function destroy(c) {
    if (!confirm(`Удалить «${c.fn}» из общей книги?`)) return;
    router.delete(`/company-contacts/${c.uri}`);
}
function initialsOf(c) { return (c.fn || '?').split(/[\s@._-]+/).filter(Boolean).slice(0, 2).map((p) => p[0].toUpperCase()).join('') || '?'; }
const COLS = '36px minmax(0, 1fr) minmax(0, 1fr) 200px 90px';
</script>

<template>
    <AppLayout title="Контакты компании" :count="cards.length" search-placeholder="Найти контакт…">
        <template #actions>
            <input v-model="search" class="input" style="width: 240px" type="search" placeholder="Имя, компания, адрес">
            <a class="btn" href="/mail/api/contacts/export?book=company" title="Выгрузить .vcf"><Icon name="download" :size="16" />Экспорт</a>
            <button class="btn btn--primary" type="button" @click="creating = true; Object.assign(form, blank())"><Icon name="plus" :size="16" />Добавить</button>
        </template>

        <div v-if="suggestions.length" class="card" style="padding: 16px 20px; margin-bottom: 16px">
            <div class="group-title" style="margin-bottom: 8px">Предложено сотрудниками · {{ suggestions.length }}</div>
            <div v-for="s in suggestions" :key="s.id" class="row" style="grid-template-columns: 36px minmax(0, 1fr) minmax(0, 1fr) auto; padding: 8px 0">
                <span class="avatar">{{ initialsOf(s) }}</span>
                <div><b>{{ s.fn }}</b><div class="row__sub">{{ [s.title, s.org].filter(Boolean).join(' · ') || s.email }}</div></div>
                <div class="row__sub">от {{ s.user }}<template v-if="s.note"> · «{{ s.note }}»</template></div>
                <div style="display: flex; gap: 6px">
                    <Link class="btn btn--sm btn--primary" :href="`/company-contacts/suggestions/${s.id}/approve`" method="post" as="button">В общую</Link>
                    <Link class="btn btn--sm" :href="`/company-contacts/suggestions/${s.id}/reject`" method="post" as="button">Отклонить</Link>
                </div>
            </div>
        </div>

        <div v-if="creating || editing" class="card card--form" style="padding: 20px 24px; margin-bottom: 16px">
            <form @submit.prevent="submit">
                <div class="pane__title" style="display: flex; align-items: center; gap: 10px"><b>{{ editing ? 'Правка контакта' : 'Новый контакт в общей книге' }}</b><span style="flex: 1" /><button class="btn btn--sm" type="button" @click="close">Закрыть</button></div>
                <div class="form-layout" style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; margin-top: 12px">
                    <div class="field"><label>Фамилия</label><input v-model="form.last" class="input"></div>
                    <div class="field"><label>Имя</label><input v-model="form.first" class="input"></div>
                    <div class="field"><label>Отчество</label><input v-model="form.middle" class="input"></div>
                    <div class="field"><label>Организация</label><input v-model="form.org" class="input"></div>
                    <div class="field"><label>Должность</label><input v-model="form.title" class="input"></div>
                    <div class="field"><label>Группы <span class="faint">через запятую</span></label><input v-model="form.groupsText" class="input" placeholder="Клиенты, VIP"></div>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-top: 8px">
                    <div class="field"><label>Почта</label>
                        <div v-for="(e, i) in form.emails" :key="'e' + i" style="display: grid; grid-template-columns: 1fr 120px 32px; gap: 6px; margin-bottom: 6px">
                            <input v-model="e.value" class="input" type="email"><select v-model="e.type" class="input"><option value="work">рабочая</option><option value="home">личная</option></select>
                            <button class="btn btn--sm" type="button" @click="form.emails.splice(i, 1)">✕</button>
                        </div>
                        <button class="btn btn--sm" type="button" @click="form.emails.push({ value: '', type: 'work' })">+ адрес</button>
                    </div>
                    <div class="field"><label>Телефоны</label>
                        <div v-for="(p, i) in form.phones" :key="'p' + i" style="display: grid; grid-template-columns: 1fr 120px 32px; gap: 6px; margin-bottom: 6px">
                            <input v-model="p.value" class="input" type="tel"><select v-model="p.type" class="input"><option value="work">рабочий</option><option value="cell">мобильный</option><option value="fax">факс</option></select>
                            <button class="btn btn--sm" type="button" @click="form.phones.splice(i, 1)">✕</button>
                        </div>
                        <button class="btn btn--sm" type="button" @click="form.phones.push({ value: '', type: 'cell' })">+ телефон</button>
                    </div>
                </div>
                <div class="field" style="margin-top: 8px"><label>Заметка</label><textarea v-model="form.note" class="input" rows="2" /></div>
                <div class="form-actions" style="margin-top: 12px; display: flex; gap: 8px">
                    <button class="btn btn--primary" type="submit" :disabled="form.processing">Сохранить</button>
                    <button class="btn" type="button" @click="close">Отмена</button>
                    <span v-if="form.hasErrors" class="error">{{ Object.values(form.errors)[0] }}</span>
                </div>
            </form>
        </div>

        <div class="card card--flush">
            <div class="thead" :style="{ gridTemplateColumns: COLS }">
                <span /><span>Контакт</span><span>Почта и телефон</span><span>Группы</span><span />
            </div>
            <div v-for="c in cards" :key="c.uri" class="row" :style="{ gridTemplateColumns: COLS }">
                <span class="avatar">{{ initialsOf(c) }}</span>
                <div style="min-width: 0"><b>{{ c.fn }}</b><div class="row__sub">{{ [c.title, c.org].filter(Boolean).join(' · ') || '—' }}</div></div>
                <div style="min-width: 0" class="row__sub">
                    <div v-for="e in c.emails" :key="e.value" class="mono">{{ e.value }}</div>
                    <div v-for="p in c.phones" :key="p.value">{{ p.value }}</div>
                </div>
                <div class="tags"><span v-for="g in c.groups" :key="g" class="tag">{{ g }}</span></div>
                <div style="display: flex; gap: 4px; justify-content: flex-end">
                    <Link class="btn btn--sm" :href="`/company-contacts?edit=${encodeURIComponent(c.uri)}`" preserve-state title="Изменить"><Icon name="edit" :size="14" /></Link>
                    <button class="btn btn--sm" type="button" title="Удалить" @click="destroy(c)"><Icon name="trash" :size="14" /></button>
                </div>
            </div>
            <div v-if="!cards.length" class="empty">В общей книге пока пусто. Книга «Сотрудники» ({{ employees }}) заполняется сама при создании ящиков.</div>
        </div>
        <p class="hint" style="margin-top: 12px">Обе общие книги видят все сотрудники: в веб-почте и на телефоне по CardDAV. Сотрудники могут предложить свой контакт в общую — он появится здесь на одобрение.</p>
    </AppLayout>
</template>
