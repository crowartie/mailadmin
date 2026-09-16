<script setup>
// Рассылки mlmmj: список; выбранная рассылка открывается панелью: подписчики, кто может писать, модерация, архив.
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import Icon from '../../Components/Icon.vue';
import Toggle from '../../Components/Toggle.vue';

const props = defineProps({
    lists: Array,
    open: Object,
    options: Object,
    domains: Array,
    employees: Array,
    ctl: Boolean,
});

const creating = ref(false);
const tab = ref('subs');
const seg = ref('all');
const newSubs = ref('');
watch(() => props.open, () => { tab.value = props.open?.moderation?.length ? 'mod' : 'subs'; newSubs.value = ''; });

const create = useForm({ local_part: '', domain: props.domains?.[0] || '', name: '', description: '', options: { only_subscriber_can_post: true }, subscribers: [] });
const edit = useForm({ name: props.open?.name || '', description: props.open?.description || '', subject_prefix: props.open?.subject_prefix || '', options: { ...(props.open?.options || {}) }, moderators: [...(props.open?.moderators || [])] });
watch(() => props.open, (o) => { if (o) Object.assign(edit, { name: o.name, description: o.description || '', subject_prefix: o.subject_prefix || '', options: { ...o.options }, moderators: [...o.moderators] }); });

const rows = computed(() => (props.lists || []).filter((l) => seg.value === 'all' || (seg.value === 'pending' ? l.pending > 0 : seg.value === 'off' ? !l.active : true)));
const pendingTotal = computed(() => (props.lists || []).reduce((a, l) => a + (l.pending || 0), 0));
function when(iso) { if (!iso) return '—'; const d = new Date(iso); return d.toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' }) + ' ' + d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' }); }
function who(l) { if (l.options?.only_moderator_can_post) return 'только модераторы'; if (l.options?.only_subscriber_can_post) return 'только подписчики'; return 'кто угодно'; }

function post(url, data = {}) { router.post(url, data, { preserveScroll: true }); }
function saveEdit() { edit.put(`/maillists/${props.open.address}`, { preserveScroll: true }); }
function destroy() { if (confirm(`Удалить рассылку ${props.open.address} вместе с архивом?`)) router.delete(`/maillists/${props.open.address}`); }
function toggleModerator(mail) { const i = edit.moderators.indexOf(mail); i >= 0 ? edit.moderators.splice(i, 1) : edit.moderators.push(mail); saveEdit(); }
function addSubs() { if (!newSubs.value.trim()) return; post(`/maillists/${props.open.address}/subscribe`, { emails: newSubs.value }); newSubs.value = ''; }
function pickEmployee(e) { if (e.target.value) { newSubs.value = (newSubs.value ? newSubs.value + ', ' : '') + e.target.value; e.target.value = ''; } }
</script>

<template>
    <AppLayout title="Рассылки" :count="lists.length">
        <template #actions>
            <div class="seg">
                <button type="button" class="seg__item" :class="{ 'seg__item--on': seg === 'all' }" @click="seg = 'all'">Все</button>
                <button type="button" class="seg__item" :class="{ 'seg__item--on': seg === 'pending' }" @click="seg = 'pending'">На модерации<template v-if="pendingTotal"> · {{ pendingTotal }}</template></button>
                <button type="button" class="seg__item" :class="{ 'seg__item--on': seg === 'off' }" @click="seg = 'off'">Выключенные</button>
            </div>
            <button class="btn btn--primary" type="button" @click="creating = true"><Icon name="plus" :size="16" /> Рассылка</button>
        </template>

        <div v-if="!ctl" class="attn attn--no" style="margin-bottom: 14px"><Icon name="warn" /> Служебная обёртка недоступна — состав рассылок не прочитать.</div>

        <div class="card card--flush">
            <div class="thead" style="grid-template-columns: minmax(0, 1.5fr) minmax(0, 1fr) 110px 150px 120px 110px"><span>Адрес</span><span>Название</span><span>Подписчиков</span><span>Кто пишет</span><span>Ждут модератора</span><span>Состояние</span></div>
            <div v-for="l in rows" :key="l.address" class="row row--click" :class="{ 'row--on': open && open.address === l.address }" style="grid-template-columns: minmax(0, 1.5fr) minmax(0, 1fr) 110px 150px 120px 110px" @click="router.get(`/maillists/${l.address}`, {}, { preserveState: true, preserveScroll: true })">
                <span class="mono ellipsis">{{ l.address }}</span>
                <span class="ellipsis">{{ l.name }}<span v-if="l.description" class="row__sub"> — {{ l.description }}</span></span>
                <span>{{ l.subscribers }}</span>
                <span class="row__sub">{{ open && open.address === l.address ? who(open) : '—' }}</span>
                <span><span v-if="l.pending" class="chip chip--warn">{{ l.pending }}</span><span v-else class="faint">нет</span></span>
                <span><span class="chip" :class="l.active ? 'chip--ok' : 'chip--off'">{{ l.active ? 'работает' : 'выключена' }}</span></span>
            </div>
            <div v-if="!rows.length" class="empty">Рассылок нет. Рассылка — это адрес, письмо на который получают все подписчики; для отделов удобнее «адрес отдела» в разделе «Подразделения».</div>
        </div>
        <p class="hint">Письма в рассылку доставляет mlmmj с темой в квадратных скобках; архив хранится на сервере и виден здесь. Внешние адреса (не с этого сервера) подписывать можно.</p>

        <template #overlay>
            <!-- Создание -->
            <transition name="drawer">
                <div v-if="creating">
                    <div class="drawer-backdrop" @click="creating = false" />
                    <aside class="drawer" role="dialog">
                        <header class="drawer__head"><div class="grow"><h2>Новая рассылка</h2></div><button class="btn btn--sm btn--icon" type="button" @click="creating = false"><Icon name="x" :size="18" /></button></header>
                        <form id="create-list" class="drawer__body" @submit.prevent="create.post('/maillists', { onSuccess: () => (creating = false) })">
                            <div class="field__row">
                                <div class="field" style="flex: 1"><label>Адрес</label><input v-model="create.local_part" class="input" placeholder="tender" required pattern="[A-Za-z0-9][A-Za-z0-9._-]*"><p v-if="create.errors.local_part" class="error">{{ create.errors.local_part }}</p></div>
                                <span class="field__at">@</span>
                                <div class="field" style="width: 200px"><label>Домен</label><select v-model="create.domain" class="input"><option v-for="d in domains" :key="d" :value="d">{{ d }}</option></select></div>
                            </div>
                            <div class="field"><label>Название</label><input v-model="create.name" class="input" placeholder="Тендерный комитет" required></div>
                            <div class="field"><label>Описание</label><input v-model="create.description" class="input" placeholder="для чего рассылка"></div>
                            <div class="group-title">Кто может писать</div>
                            <Toggle v-model="create.options.only_subscriber_can_post" label="Только подписчики" />
                            <Toggle v-model="create.options.only_moderator_can_post" label="Только модераторы (новости, приказы)" />
                            <Toggle v-model="create.options.moderated" label="Каждое письмо подтверждает модератор" />
                            <div class="group-title">Подписчики</div>
                            <select class="input" multiple size="8" @change="create.subscribers = Array.from($event.target.selectedOptions).map((o) => o.value)"><option v-for="e in employees" :key="e.username" :value="e.username">{{ e.name }} — {{ e.username }}</option></select>
                            <p class="hint">Ctrl — выбрать нескольких. Внешние адреса можно добавить после создания.</p>
                        </form>
                        <footer class="drawer__foot"><button class="btn btn--primary" type="submit" form="create-list" :disabled="create.processing">Создать</button><button class="btn" type="button" @click="creating = false">Отмена</button></footer>
                    </aside>
                </div>
            </transition>

            <!-- Карточка рассылки -->
            <transition name="drawer">
                <div v-if="open && !creating">
                    <div class="drawer-backdrop" @click="router.get('/maillists', {}, { preserveState: true, preserveScroll: true })" />
                    <aside class="drawer" role="dialog" style="width: 640px">
                        <header class="drawer__head">
                            <div class="avatar avatar--lg"><Icon name="mail" :size="20" /></div>
                            <div class="grow"><h2>{{ open.name }}</h2><div class="row__sub mono">{{ open.address }}</div></div>
                            <span class="chip" :class="open.active ? 'chip--ok' : 'chip--off'">{{ open.active ? 'работает' : 'выключена' }}</span>
                            <button class="btn btn--sm btn--icon" type="button" @click="router.get('/maillists', {}, { preserveState: true, preserveScroll: true })"><Icon name="x" :size="18" /></button>
                        </header>
                        <div class="tabs">
                            <button v-for="[k, l] in [['subs', `Подписчики · ${open.subscribers.length}`], ['rules', 'Кто пишет'], ['mod', `Модерация${open.moderation.length ? ' · ' + open.moderation.length : ''}`], ['archive', 'Архив']]" :key="k" type="button" class="tabs__item" :class="{ 'tabs__item--on': tab === k }" @click="tab = k">{{ l }}</button>
                        </div>
                        <div class="drawer__body">
                            <template v-if="tab === 'subs'">
                                <div class="field__row" style="align-items: flex-start">
                                    <textarea v-model="newSubs" class="input" rows="2" style="flex: 1; height: auto" placeholder="адреса через запятую или с новой строки — свои и внешние"></textarea>
                                    <button class="btn btn--primary" type="button" @click="addSubs">Подписать</button>
                                </div>
                                <select class="input" style="margin-top: 8px" @change="pickEmployee"><option value="">+ выбрать сотрудника…</option><option v-for="e in employees.filter((x) => !open.subscribers.some((s) => s.mail === x.username))" :key="e.username" :value="e.username">{{ e.name }} — {{ e.username }}</option></select>
                                <div class="divider" />
                                <div v-for="s in open.subscribers" :key="s.mail" class="kv kv--start" style="align-items: center">
                                    <span style="flex: 1; min-width: 0"><span style="color: inherit">{{ s.name || s.mail }}</span><span v-if="s.name" class="mono faint" style="margin-left: 6px">{{ s.mail }}</span><span v-if="s.external" class="tag" style="margin-left: 6px">внешний</span><span v-if="s.moderator" class="tag" style="margin-left: 6px">модератор</span></span>
                                    <button class="btn btn--sm" type="button" @click="post(`/maillists/${open.address}/unsubscribe`, { email: s.mail })">Отписать</button>
                                </div>
                                <p v-if="!open.subscribers.length" class="empty" style="text-align: left; padding: 16px 0">Подписчиков нет</p>
                            </template>

                            <template v-if="tab === 'rules'">
                                <div class="field"><label>Название</label><input v-model="edit.name" class="input"></div>
                                <div class="field"><label>Описание</label><input v-model="edit.description" class="input"></div>
                                <div class="field" style="width: 240px"><label>Префикс темы</label><input v-model="edit.subject_prefix" class="input" placeholder="[tender]"></div>
                                <div class="group-title">Правила</div>
                                <Toggle v-for="(label, key) in options" :key="key" v-model="edit.options[key]" :label="label" />
                                <div class="group-title">Модераторы</div>
                                <p class="hint">Подтверждают письма, ждущие модерации, и могут писать при ограничении «только модераторы». Отметьте среди подписчиков:</p>
                                <div v-for="s in open.subscribers.filter((x) => !x.external)" :key="s.mail" class="kv" style="cursor: pointer" @click="toggleModerator(s.mail)"><span style="color: inherit">{{ s.name || s.mail }}</span><span class="chip" :class="edit.moderators.includes(s.mail) ? 'chip--ok' : 'chip--off'">{{ edit.moderators.includes(s.mail) ? 'модератор' : 'нет' }}</span></div>
                                <div class="form-actions" style="margin-top: 14px"><button class="btn btn--primary" type="button" :disabled="edit.processing" @click="saveEdit">Сохранить</button><button class="btn" type="button" @click="router.put(`/maillists/${open.address}`, { active: !open.active }, { preserveScroll: true })">{{ open.active ? 'Выключить' : 'Включить' }}</button></div>
                            </template>

                            <template v-if="tab === 'mod'">
                                <p class="hint">Письма, которые ждут решения. «Пропустить» отправит подписчикам, «Отклонить» удалит.</p>
                                <div v-for="m in open.moderation" :key="m.id" class="kv kv--start" style="align-items: center">
                                    <span style="flex: 1; min-width: 0"><span style="color: inherit; display: block" class="ellipsis">{{ m.subject || '(без темы)' }}</span><span class="row__sub">{{ m.from }} · {{ when(m.when) }}</span></span>
                                    <span style="display: flex; gap: 6px"><button class="btn btn--sm btn--primary" type="button" @click="post(`/maillists/${open.address}/moderate`, { id: m.id, action: 'approve' })">Пропустить</button><button class="btn btn--sm" type="button" @click="post(`/maillists/${open.address}/moderate`, { id: m.id, action: 'reject' })">Отклонить</button></span>
                                </div>
                                <p v-if="!open.moderation.length" class="empty" style="text-align: left; padding: 16px 0">Ничего не ждёт модерации</p>
                            </template>

                            <template v-if="tab === 'archive'">
                                <div v-for="a in open.archive" :key="a.file" class="kv kv--start"><span style="flex: 1; min-width: 0"><span style="color: inherit; display: block" class="ellipsis">{{ a.subject || '(без темы)' }}</span><span class="row__sub">{{ a.from }} · {{ when(a.when) }}</span></span></div>
                                <p v-if="!open.archive.length" class="empty" style="text-align: left; padding: 16px 0">Архив пуст{{ open.options.disable_archive ? ' — архив выключен в правилах' : '' }}</p>
                            </template>
                        </div>
                        <footer class="drawer__foot">
                            <span class="hint" style="margin: 0">Владелец: {{ open.owners.join(', ') || '—' }}</span>
                            <span class="grow" />
                            <button class="btn btn--danger" type="button" @click="destroy">Удалить</button>
                        </footer>
                    </aside>
                </div>
            </transition>
        </template>
    </AppLayout>
</template>
