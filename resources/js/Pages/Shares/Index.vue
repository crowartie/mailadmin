<script setup>
// Общий доступ: все открытые папки всех ящиков — по ящикам и по сотрудникам, смена уровня, закрытие, доложить права.
import { computed, ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import Icon from '../../Components/Icon.vue';
import { http } from '../../admin/http';
import { ask as confirmAsk } from '../../confirm';

const props = defineProps({ rows: Array, candidates: Array, owners: { type: Array, default: () => [] }, levels: Object });

const rows = ref(props.rows);
const view = ref('owners');   // owners | people
const q = ref('');
const busy = ref(false);
const flash = ref(null);
const add = ref({ owner: '', folder: 'INBOX', with: '', level: 'reader' });
// Папки выбранного ящика — для списка «какую папку». Раньше можно было открыть только «Входящие».
const ownerFolders = ref([]);
const foldersLoading = ref(false);
watch(() => add.value.owner, async (owner) => {
    ownerFolders.value = [];
    add.value.folder = 'INBOX';
    if (!owner) return;
    foldersLoading.value = true;
    try {
        const r = await http('GET', `/mailboxes/${encodeURIComponent(owner)}/shares`);
        if (add.value.owner === owner) ownerFolders.value = (r.folders || []).map((f) => ({ path: f.path, name: f.name, depth: f.depth || 0 }));
    } catch (e) { say(e.message, true); } finally { foldersLoading.value = false; }
});
// Уровни зависят от папки: владельцем — только по «Входящим» (это весь ящик и право писать от его имени).
const addLevels = computed(() => (add.value.folder === 'INBOX' ? ['reader', 'editor', 'owner'] : ['reader', 'editor']));
watch(addLevels, (list) => { if (!list.includes(add.value.level)) add.value.level = 'reader'; });
const addHint = computed(() => {
    const f = add.value.folder; const l = add.value.level;
    if (f === '*') return l === 'reader' ? 'Все папки ящика — только читать: без права писать от имени ящика и что-либо удалять.' : 'Все папки ящика — читать, раскладывать и удалять письма, но не писать от имени ящика.';
    if (f === 'INBOX') return l === 'owner' ? 'Владелец: все папки ящика и право писать от его имени.' : l === 'editor' ? 'Редактор «Входящих» получает и системные папки: «Отправленные», «Черновики», «Спам», «Корзину», «Архив».' : 'Читатель видит только «Входящие».';
    return 'Откроется только эта папка.';
});

const ROLE_ORDER = { inbox: 0, drafts: 1, sent: 2, archive: 3, lists: 4, spam: 5, trash: 6 };
const filtered = computed(() => rows.value.filter((r) => !q.value || `${r.owner} ${r.ownerName} ${r.with} ${r.withName} ${r.folderName}`.toLowerCase().includes(q.value.toLowerCase())));
const byOwner = computed(() => {
    const out = [];
    for (const r of filtered.value) {
        let o = out.find((x) => x.owner === r.owner);
        if (!o) { o = { owner: r.owner, name: r.ownerName, error: null, folders: [] }; out.push(o); }
        if (r.role === 'error') { o.error = r.level; continue; }
        let f = o.folders.find((x) => x.folder === r.folder);
        if (!f) { f = { folder: r.folder, name: r.folderName, role: r.role, people: [] }; o.folders.push(f); }
        f.people.push(r);
    }
    for (const o of out) o.folders.sort((a, b) => (ROLE_ORDER[a.role] ?? 10) - (ROLE_ORDER[b.role] ?? 10) || a.name.localeCompare(b.name, 'ru'));
    return out;
});
const byPerson = computed(() => {
    const out = [];
    for (const r of filtered.value) {
        if (r.role === 'error') continue;
        let p = out.find((x) => x.with === r.with);
        if (!p) { p = { with: r.with, name: r.withName, items: [] }; out.push(p); }
        p.items.push(r);
    }
    out.sort((a, b) => a.name.localeCompare(b.name, 'ru'));
    return out;
});
const owners = computed(() => [...new Set(rows.value.map((r) => r.owner))]);
// «Чей ящик»: все ящики, включая служебные (props.owners); имя не owners — оно уже занято строкой выше.
const ownerChoices = computed(() => (props.owners.length ? props.owners : props.candidates));

function say(text, error = false) { flash.value = { text, error }; setTimeout(() => { flash.value = null; }, 4500); }
async function reload() { const r = await http('GET', '/shares/json'); rows.value = r.rows; }
async function setLevel(r, level) {
    busy.value = true;
    try { await http('POST', `/mailboxes/${encodeURIComponent(r.owner)}/shares`, { folder: r.folder, with: r.with, level }); await reload(); say('Уровень изменён'); }
    catch (e) { say(e.message, true); } finally { busy.value = false; }
}
async function remove(r) {
    if (!(await confirmAsk(`Закрыть «${r.folderName}» ящика ${r.owner} для ${r.withName}?`, { ok: 'Закрыть доступ', danger: true }))) return;
    busy.value = true;
    try { await http('DELETE', `/mailboxes/${encodeURIComponent(r.owner)}/shares`, { folder: r.folder, with: r.with }); await reload(); say('Доступ закрыт'); }
    catch (e) { say(e.message, true); } finally { busy.value = false; }
}
async function grant() {
    if (!add.value.owner || !add.value.with) return;
    busy.value = true;
    try {
        await http('POST', `/mailboxes/${encodeURIComponent(add.value.owner)}/shares`, { folder: add.value.folder, with: add.value.with, level: add.value.level });
        await reload();
        say(add.value.folder === '*' ? 'Доступ ко всем папкам выдан' : 'Доступ выдан');
        add.value.with = '';
    }
    catch (e) { say(e.message, true); } finally { busy.value = false; }
}
async function sync() {
    busy.value = true;
    try { const r = await http('POST', '/shares/sync'); rows.value = r.rows; say(r.output ? r.output.split('\n').pop() : 'Права проверены'); }
    catch (e) { say(e.message, true); } finally { busy.value = false; }
}
function levelOptions(r) { const base = r.role === 'inbox' ? ['reader', 'editor', 'owner'] : ['reader', 'editor']; return base.includes(r.level) ? base : [...base, r.level]; }
</script>

<template>
    <AppLayout title="Общий доступ" :count="`${byOwner.length} ящиков · ${byPerson.length} сотрудников`">
        <template #actions>
            <div class="seg">
                <button type="button" class="seg__item" :class="{ 'seg__item--on': view === 'owners' }" @click="view = 'owners'">По ящикам</button>
                <button type="button" class="seg__item" :class="{ 'seg__item--on': view === 'people' }" @click="view = 'people'">По сотрудникам</button>
            </div>
            <input v-model="q" class="input" style="width: 220px" type="search" placeholder="Ящик, сотрудник, папка">
            <button class="btn" type="button" :disabled="busy" title="Доложить права на папки, появившиеся после выдачи" @click="sync"><Icon name="refresh" :size="16" />Доложить права</button>
        </template>
        <transition name="flash"><div v-if="flash" class="flash" :class="{ 'flash--error': flash.error }">{{ flash.text }}</div></transition>

        <div class="card card--pad" style="margin-bottom: 16px">
            <div class="group-title">Открыть доступ</div>
            <div class="field__row" style="flex-wrap: wrap">
                <select v-model="add.owner" class="input" aria-label="Чей ящик" style="width: 240px; height: 34px"><option value="" disabled>чей ящик…</option><option v-for="c in ownerChoices" :key="'o' + c.mail" :value="c.mail">{{ c.name }} — {{ c.mail }}</option></select>
                <select v-model="add.with" class="input" aria-label="Кому дать доступ" style="width: 240px; height: 34px" :disabled="!add.owner"><option value="" disabled>кому…</option><option v-for="c in candidates.filter((x) => x.mail !== add.owner)" :key="'w' + c.mail" :value="c.mail">{{ c.name }} — {{ c.mail }}</option></select>
                <!-- Папка — после выбора «кому»: сначала решаем, кому открываем, потом что. -->
                <template v-if="add.owner && add.with">
                    <select v-model="add.folder" class="input" aria-label="Какую папку" style="width: 220px; height: 34px" :disabled="foldersLoading">
                        <option value="*">Все папки</option>
                        <option value="INBOX">Входящие (весь ящик)</option>
                        <option v-for="f in ownerFolders.filter((x) => x.path.toUpperCase() !== 'INBOX')" :key="f.path" :value="f.path">{{ '  '.repeat(f.depth) }}{{ f.name }}</option>
                    </select>
                    <select v-model="add.level" class="input" aria-label="Уровень доступа" style="width: 130px; height: 34px"><option v-for="k in addLevels" :key="k" :value="k">{{ levels[k] }}</option></select>
                </template>
                <button class="btn btn--primary" type="button" :disabled="busy || !add.owner || !add.with" @click="grant">Открыть</button>
            </div>
            <p class="hint">{{ add.owner && add.with ? addHint : 'Выберите, чей ящик и кому открыть доступ, — дальше можно выбрать папку: одну, «Входящие» (доступ к ящику) или все папки сразу.' }}</p>
        </div>

        <template v-if="view === 'owners'">
            <div v-for="o in byOwner" :key="o.owner" class="card card--flush" style="margin-bottom: 16px">
                <div class="card__title" style="padding: 14px 18px 6px; display: flex; align-items: center; gap: 10px"><Icon name="users" :size="16" style="color: var(--faint)" />{{ o.name }} <span class="mono faint">{{ o.owner }}</span><span style="flex: 1" /><Link class="btn btn--sm" :href="`/mailboxes/${encodeURIComponent(o.owner)}/edit`">Карточка</Link></div>
                <p v-if="o.error" class="error" style="padding: 0 18px 12px">{{ o.error }}</p>
                <div v-for="f in o.folders" :key="f.folder" class="srow">
                    <span class="srow__folder"><Icon name="folder" :size="14" style="color: var(--faint)" />{{ f.name }}</span>
                    <span class="srow__people">
                        <span v-for="p in f.people" :key="p.with" class="srow__person">
                            <span :title="p.with">{{ p.withName }}</span>
                            <select class="input input--sm" :value="p.level" aria-label="Уровень доступа" :disabled="busy" @change="setLevel(p, $event.target.value)"><option v-for="l in levelOptions(p)" :key="l" :value="l">{{ levels[l] }}</option></select>
                            <button class="ib ib--sm" type="button" title="Закрыть доступ" :disabled="busy" @click="remove(p)" aria-label="Закрыть доступ"><Icon name="x" :size="13" /></button>
                        </span>
                    </span>
                </div>
            </div>
            <p v-if="!byOwner.length" class="hint" style="padding: 12px">Общих папок нет.</p>
        </template>

        <template v-else>
            <div v-for="p in byPerson" :key="p.with" class="card card--flush" style="margin-bottom: 16px">
                <div class="card__title" style="padding: 14px 18px 6px; display: flex; align-items: center; gap: 10px">{{ p.name }} <span class="mono faint">{{ p.with }}</span><span style="flex: 1" /><Link class="btn btn--sm" :href="`/mailboxes/${encodeURIComponent(p.with)}/edit`">Карточка</Link></div>
                <div v-for="r in p.items" :key="r.owner + r.folder" class="srow">
                    <span class="srow__folder"><Icon name="folder" :size="14" style="color: var(--faint)" />{{ r.ownerName }} → {{ r.folderName }}</span>
                    <span class="srow__people"><span class="srow__person">
                        <select class="input input--sm" :value="r.level" aria-label="Уровень доступа" :disabled="busy" @change="setLevel(r, $event.target.value)"><option v-for="l in levelOptions(r)" :key="l" :value="l">{{ levels[l] }}</option></select>
                        <button class="ib ib--sm" type="button" title="Закрыть доступ" :disabled="busy" @click="remove(r)" aria-label="Закрыть доступ"><Icon name="x" :size="13" /></button>
                    </span></span>
                </div>
            </div>
        </template>
    </AppLayout>
</template>

<style scoped>
.srow { display: grid; grid-template-columns: 260px minmax(0, 1fr); gap: 12px; align-items: start; padding: 8px 18px; border-top: 1px solid var(--border); }
.srow__folder { display: flex; align-items: center; gap: 8px; padding-top: 4px; font-weight: 500; }
/* Люди — ровными колонками: имя | уровень | крестик. Раньше шли сплошным потоком, и списки
   уровней стояли вразнобой, как слова в строке. */
.srow__people { display: grid; grid-template-columns: repeat(auto-fill, minmax(380px, 1fr)); gap: 6px 28px; }
.srow__person { display: grid; grid-template-columns: minmax(0, 1fr) 120px 24px; align-items: center; gap: 8px; }
.srow__person > span:first-child { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.input--sm { height: 28px; padding: 0 6px; font-size: 12.5px; width: 120px; }
</style>
