<script setup>
// Подразделения: дерево слева, выбранный отдел справа (состав, адрес отдела, календарь и книга), перенос сотрудников.
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import Icon from '../../Components/Icon.vue';

const props = defineProps({
    tree: Array,
    flat: Array,
    total: Number,
    employees: Array,
    selected: Object,
    unassigned: Array,
    domain: String,
});

const editing = ref(false);
const creating = ref(false);
const picked = ref([]);          // выбранные сотрудники для переноса
const moveTo = ref('');
const search = ref('');

const form = useForm({ name: '', parent_id: null, address: '', lead: '' });
watch(() => props.selected, (s) => { editing.value = false; picked.value = []; if (s) Object.assign(form, { name: s.name, parent_id: s.parent_id, address: s.address ? s.address.replace('@' + props.domain, '') : '', lead: s.lead || '' }); }, { immediate: true });

function startCreate(parentId = null) { creating.value = true; editing.value = false; form.reset(); form.parent_id = parentId; form.clearErrors(); }
function startEdit() { editing.value = true; creating.value = false; form.clearErrors(); }
function cancel() { creating.value = false; editing.value = false; }
function save() {
    const data = { ...form.data(), address: form.address ? (form.address.includes('@') ? form.address : form.address + '@' + props.domain) : '', lead: form.lead || null };
    if (creating.value) form.transform(() => data).post('/units', { onSuccess: () => (creating.value = false) });
    else form.transform(() => data).put(`/units/${props.selected.id}`, { preserveScroll: true, onSuccess: () => (editing.value = false) });
}
function destroy() {
    if (!confirm(`Удалить подразделение «${props.selected.name}»? Сотрудники и вложенные отделы поднимутся уровнем выше, адрес отдела перестанет работать.`)) return;
    router.delete(`/units/${props.selected.id}`);
}
function toggle(u) { const i = picked.value.indexOf(u); i >= 0 ? picked.value.splice(i, 1) : picked.value.push(u); }
function move(unitId) {
    if (!picked.value.length) return;
    router.post('/units/move', { usernames: picked.value, unit_id: unitId || null }, { preserveScroll: true, onSuccess: () => { picked.value = []; moveTo.value = ''; } });
}
function addMember(username) { if (username) router.post('/units/move', { usernames: [username], unit_id: props.selected.id }, { preserveScroll: true }); }

function ini(s) { const p = (s || '').replace(/@.*/, '').split(/[\s._-]+/).filter(Boolean); return p.slice(0, 2).map((x) => x[0].toUpperCase()).join('') || '?'; }
const members = computed(() => (props.selected?.members || []).filter((m) => !search.value || `${m.name} ${m.username} ${m.title}`.toLowerCase().includes(search.value.toLowerCase())));
const flatNodes = computed(() => { const out = []; const walk = (n) => n.forEach((x) => { out.push(x); walk(x.children); }); walk(props.tree); return out; });
const candidates = computed(() => (props.employees || []).filter((e) => !(props.selected?.members || []).some((m) => m.username === e.username)));
</script>

<template>
    <AppLayout title="Подразделения" :count="`${flat.length} · ${total} сотр.`">
        <template #actions>
            <button class="btn btn--primary" type="button" @click="startCreate(null)"><Icon name="plus" :size="16" /> Подразделение</button>
        </template>

        <div class="grid-2-1" style="grid-template-columns: 300px minmax(0, 1fr)">
            <!-- Дерево -->
            <div class="card" style="padding: 10px; display: flex; flex-direction: column; gap: 2px; align-self: start">
                <Link v-for="n in flatNodes" :key="n.id" :href="`/units/${n.id}`" class="tree__item" :class="{ 'tree__item--on': selected && selected.id === n.id }" :style="{ paddingLeft: 12 + n.depth * 18 + 'px' }">
                    <Icon :name="n.children.length ? 'building' : 'users'" :size="15" />
                    <span class="ellipsis">{{ n.name }}</span>
                    <span class="tree__count">{{ n.total }}</span>
                </Link>
                <div v-if="!flatNodes.length" class="empty" style="padding: 20px 8px">Подразделений ещё нет — создайте первое или импортируйте сотрудников из CSV с колонкой «Отдел»</div>
                <Link href="/units" class="tree__item" :class="{ 'tree__item--on': !selected && !creating }" style="margin-top: 6px; border-top: 1px solid var(--border); border-radius: 0 0 8px 8px; padding-top: 12px">
                    <Icon name="user" :size="15" /><span>Без подразделения</span><span class="tree__count">{{ unassigned.length }}</span>
                </Link>
            </div>

            <!-- Правая часть -->
            <div style="display: flex; flex-direction: column; gap: 16px">
                <!-- Форма создания / редактирования -->
                <form v-if="creating || editing" class="card card--pad" @submit.prevent="save">
                    <div class="card__title">{{ creating ? 'Новое подразделение' : 'Изменить подразделение' }}</div>
                    <div class="grid-set">
                        <label class="field"><span>Название</span><input v-model="form.name" class="input" required autofocus><p v-if="form.errors.name" class="error">{{ form.errors.name }}</p></label>
                        <label class="field"><span>Входит в</span><select v-model="form.parent_id" class="input"><option :value="null">— верхний уровень —</option><option v-for="u in flat.filter((x) => !selected || x.id !== selected.id)" :key="u.id" :value="u.id">{{ ' '.repeat(u.depth * 3) }}{{ u.name }}</option></select></label>
                        <label class="field"><span>Адрес отдела</span><div class="field__row"><input v-model="form.address" class="input" placeholder="montazh" style="flex: 1"><span class="faint">@{{ domain }}</span></div><span class="hint">Письмо на этот адрес получат все сотрудники отдела и вложенных. Пусто — без адреса.</span><p v-if="form.errors.address" class="error">{{ form.errors.address }}</p></label>
                        <label class="field"><span>Руководитель</span><select v-model="form.lead" class="input"><option value="">—</option><option v-for="e in employees" :key="e.username" :value="e.username">{{ e.name }} — {{ e.username }}</option></select></label>
                    </div>
                    <div class="form-actions"><button class="btn btn--primary" type="submit" :disabled="form.processing">{{ creating ? 'Создать' : 'Сохранить' }}</button><button class="btn" type="button" @click="cancel">Отмена</button></div>
                </form>

                <!-- Выбранный отдел -->
                <template v-if="selected && !creating">
                    <div class="card card--pad" style="display: flex; align-items: flex-start; gap: 14px">
                        <div style="flex: 1; min-width: 0">
                            <div style="font-size: 20px; font-weight: 700">{{ selected.name }}</div>
                            <div class="row__sub">{{ selected.parentName ? selected.parentName + ' · ' : '' }}{{ selected.totalMembers }} сотр.{{ selected.leadName ? ' · руководитель ' + selected.leadName : '' }}</div>
                            <div class="tags" style="margin-top: 10px">
                                <span class="tag" :title="selected.address ? 'все сотрудники отдела получают письма на этот адрес' : ''"><Icon name="at" :size="13" /> {{ selected.address || 'адреса нет' }}</span>
                                <span class="tag" :class="{ 'tag--off': !selected.calendar }"><Icon name="cal" :size="13" /> календарь отдела</span>
                                <span class="tag" :class="{ 'tag--off': !selected.book }"><Icon name="book" :size="13" /> книга отдела</span>
                            </div>
                        </div>
                        <button class="btn" type="button" @click="startEdit"><Icon name="edit" :size="15" /> Изменить</button>
                        <button class="btn" type="button" @click="startCreate(selected.id)"><Icon name="plus" :size="15" /> Вложенный</button>
                        <button class="btn btn--danger" type="button" @click="destroy">Удалить</button>
                    </div>

                    <div class="card card--flush">
                        <div class="toolbar" style="padding: 12px 18px; border-bottom: 1px solid var(--border); gap: 10px">
                            <b>Сотрудники · {{ selected.members.length }}</b>
                            <input v-model="search" class="input" placeholder="найти в отделе" style="width: 220px; height: 34px">
                            <span class="grow" />
                            <select class="input" style="width: 260px; height: 34px" @change="addMember($event.target.value); $event.target.value = ''"><option value="">+ добавить сотрудника…</option><option v-for="e in candidates" :key="e.username" :value="e.username">{{ e.name }} — {{ e.username }}</option></select>
                        </div>
                        <div v-if="picked.length" class="bulkbar" style="margin: 10px 18px 0">
                            <b>Выбрано {{ picked.length }}</b>
                            <span>перенести в</span>
                            <select v-model="moveTo" class="input" style="width: 240px; height: 32px"><option value="">— без подразделения —</option><option v-for="u in flat.filter((x) => x.id !== selected.id)" :key="u.id" :value="u.id">{{ ' '.repeat(u.depth * 3) }}{{ u.name }}</option></select>
                            <button class="btn btn--sm btn--primary" type="button" @click="move(moveTo)"><Icon name="move" :size="14" /> Перенести</button>
                            <button class="btn btn--sm" type="button" @click="picked = []">Снять выбор</button>
                        </div>
                        <div v-for="m in members" :key="m.username" class="row row--click" :class="{ 'row--on': picked.includes(m.username) }" style="grid-template-columns: 24px 36px minmax(0, 1.4fr) minmax(0, 1fr) auto" @click="toggle(m.username)">
                            <input type="checkbox" class="check" :checked="picked.includes(m.username)" @click.stop="toggle(m.username)">
                            <div class="avatar" :class="{ 'avatar--off': !m.active }">{{ ini(m.name) }}</div>
                            <div style="min-width: 0"><div class="row__name">{{ m.name }}<span v-if="m.lead" class="tag" style="margin-left: 8px">руководитель</span></div><div class="row__sub mono">{{ m.username }}</div></div>
                            <div class="row__sub">{{ m.title || '—' }}</div>
                            <Link :href="`/mailboxes/${m.username}/edit`" class="btn btn--sm" @click.stop>Карточка</Link>
                        </div>
                        <div v-if="!members.length" class="empty">В отделе пока никого — добавьте сотрудника из списка выше</div>
                    </div>

                    <p class="hint">Адрес отдела — псевдоним на всех сотрудников отдела и вложенных; общий календарь и адресная книга отдела появляются у сотрудников в веб-почте и в телефоне автоматически (на запись — всем членам).</p>
                </template>

                <!-- Без подразделения -->
                <template v-if="!selected && !creating">
                    <div class="card card--flush">
                        <div class="toolbar" style="padding: 12px 18px; border-bottom: 1px solid var(--border)"><b>Без подразделения · {{ unassigned.length }}</b></div>
                        <div v-if="picked.length" class="bulkbar" style="margin: 10px 18px 0">
                            <b>Выбрано {{ picked.length }}</b><span>перенести в</span>
                            <select v-model="moveTo" class="input" style="width: 240px; height: 32px"><option value="" disabled>выберите…</option><option v-for="u in flat" :key="u.id" :value="u.id">{{ ' '.repeat(u.depth * 3) }}{{ u.name }}</option></select>
                            <button class="btn btn--sm btn--primary" type="button" :disabled="!moveTo" @click="move(moveTo)"><Icon name="move" :size="14" /> Перенести</button>
                        </div>
                        <div v-for="m in unassigned" :key="m.username" class="row row--click" :class="{ 'row--on': picked.includes(m.username) }" style="grid-template-columns: 24px 36px minmax(0, 1.4fr) minmax(0, 1fr) auto" @click="toggle(m.username)">
                            <input type="checkbox" class="check" :checked="picked.includes(m.username)" @click.stop="toggle(m.username)">
                            <div class="avatar">{{ ini(m.name) }}</div>
                            <div style="min-width: 0"><div class="row__name">{{ m.name }}</div><div class="row__sub mono">{{ m.username }}</div></div>
                            <div class="row__sub">{{ m.title || '—' }}</div>
                            <Link :href="`/mailboxes/${m.username}/edit`" class="btn btn--sm" @click.stop>Карточка</Link>
                        </div>
                        <div v-if="!unassigned.length" class="empty">Все сотрудники распределены по подразделениям</div>
                    </div>
                </template>
            </div>
        </div>
    </AppLayout>
</template>
