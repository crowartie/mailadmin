<script setup>
// Колонка папок и меток. Письма можно перетаскивать на папки.
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import Icon from '../Icon.vue';

const props = defineProps({
    folders: { type: Array, default: () => [] },
    labels: { type: Array, default: () => [] },
    folder: String,
    filter: { type: String, default: 'all' },
    outbox: { type: Number, default: 0 },
    quarantine: { type: Number, default: 0 },
    quota: { type: Object, default: null },
});
const emit = defineEmits(['go', 'compose', 'context', 'drop', 'new-folder', 'label', 'outbox']);

const system = computed(() => props.folders.filter((f) => f.role !== 'custom' && f.role !== 'shared'));
// Свои папки. Вложенные во «Входящие» (INBOX/…, так делает Outlook и переезд с Kerio) показываем под строкой-заголовком «Входящие».
const custom = computed(() => {
    const out = [];
    let header = false;
    for (const f of props.folders.filter((x) => x.role === 'custom')) {
        if (!header && f.parent === 'INBOX') {
            out.push({ path: 'INBOX', name: 'Входящие', role: 'custom', depth: 0, unread: 0, virtual: true });
            header = true;
        }
        out.push(f);
    }
    return out;
});
// Чужие папки, открытые нам: группируем по владельцу. Группа свёрнута в одну строку (имя + непрочитанные),
// раскрывается по клику и помнит состояние; внутри — «Входящие», системные, потом папки хозяина деревом.
const SROLE_ORDER = { inbox: 0, drafts: 1, sent: 2, archive: 3, lists: 4, spam: 5, trash: 6, snoozed: 7 };
const shared = computed(() => {
    const groups = [];
    for (const f of props.folders.filter((x) => x.role === 'shared')) {
        let g = groups.find((x) => x.owner === f.owner);
        if (!g) { g = { owner: f.owner, name: f.ownerName || f.owner, items: [], unread: 0 }; groups.push(g); }
        g.items.push(f);
        g.unread += f.unread || 0;
    }
    for (const g of groups) {
        g.items.sort((a, b) => {
            const ra = SROLE_ORDER[a.srole] ?? 10; const rb = SROLE_ORDER[b.srole] ?? 10;
            return ra !== rb ? ra - rb : a.path.localeCompare(b.path, 'ru');
        });
    }
    return groups;
});
const collapsed = ref((() => { try { return JSON.parse(localStorage.getItem('mail.sharedCollapsed') || '{}'); } catch { return {}; } })());
function isCollapsed(g) {
    // Раньше группа с открытой папкой не сворачивалась вовсе: клик по заголовку не давал
    // никакого эффекта, хотя настройка в хранилище менялась. Теперь решает только настройка,
    // а при переходе в папку этого ящика группа раскрывается сама (см. watch ниже).
    return collapsed.value[g.owner] !== false;   // по умолчанию свёрнуто
}
function toggleGroup(g) {
    collapsed.value = { ...collapsed.value, [g.owner]: !isCollapsed(g) };
    try { localStorage.setItem('mail.sharedCollapsed', JSON.stringify(collapsed.value)); } catch { /* приватный режим */ }
}
// Перешли в папку чужого ящика (например, по ссылке или клавишами) — раскрываем его группу,
// иначе открытая папка не видна в списке.
watch(() => props.folder, (path) => {
    const g = shared.value.find((x) => x.items.some((f) => f.path === path));
    if (g && collapsed.value[g.owner] !== false) {
        collapsed.value = { ...collapsed.value, [g.owner]: false };
        try { localStorage.setItem('mail.sharedCollapsed', JSON.stringify(collapsed.value)); } catch { /* приватный режим */ }
    }
}, { immediate: true });

const inbox = computed(() => props.folders.find((f) => f.role === 'inbox'));
const quarantineOpen = computed(() => typeof window !== 'undefined' && window.location.pathname === '/mail/quarantine');
const dropTarget = ref(null);

function isOn(f) {
    // Виртуальная строка-заголовок не подсвечивается: иначе во «Входящих» подсвечены сразу две строки.
    if (f.virtual) return false;
    return f.path === props.folder && props.filter !== 'flagged' && !props.filter.startsWith('label:');
}

function onDragOver(e, f) {
    if (e.dataTransfer.types.includes('text/x-mail-uids')) { e.preventDefault(); dropTarget.value = f.path; }
}
// Перетаскивание бросили мимо или отменили клавишей — подсветка папки-приёмника должна сняться.
// Событие dragend приходит на исходную строку письма, а не на папку, поэтому слушаем окно.
function onDragEnd() { dropTarget.value = null; }
onMounted(() => window.addEventListener('dragend', onDragEnd));
onBeforeUnmount(() => window.removeEventListener('dragend', onDragEnd));
function onDrop(e, f) {
    dropTarget.value = null;
    try {
        const data = JSON.parse(e.dataTransfer.getData('text/x-mail-uids'));
        if (data.folder !== f.path) emit('drop', data, f.path);
    } catch {}
}

// Счётчик у папки: «непрочитанных / всего» (как в Яндексе, обращение №8); без непрочитанных — просто «всего».
function counter(f) { return !f.virtual && ((f.unread || 0) > 0 || (f.total || 0) > 0); }
function counterTitle(f) { return f.unread ? `непрочитанных ${f.unread} из ${f.total}` : `всего ${f.total}`; }
// Папка открыта коллегам (общий доступ): значок рядом с именем, в подсказке — кому и с какими правами.
/**
 * Открыть папку. Щелчок по числу непрочитанных открывает её же, но с отбором «Непрочитанные»:
 * раньше внутри кнопки папки стояла вторая кнопка — недопустимая вложенность, до которой
 * к тому же было не добраться с клавиатуры. Теперь это одна кнопка, а место щелчка решает отбор.
 */
function goFolder(e, path) {
    emit('go', path, e.target?.closest?.('.mnav__unread') ? 'unread' : 'all');
}

function sharedTitle(f) {
    const who = (f.shared_with || []).map((s) => `${s.name} (${{ owner: 'владелец', editor: 'редактор' }[s.level] || 'чтение'})`);
    return 'Открыта коллегам: ' + who.join(', ');
}
</script>

<template>
    <nav class="mnav" aria-label="Папки">
        <button class="btn btn--primary btn--block" type="button" style="margin-bottom: 10px" @click="$emit('compose')">
            <Icon name="plus" :size="16" />Написать
        </button>
        <!-- Список папок прокручивается отдельно: кнопка «Написать» и квота не уезжают и не сжимаются полосой прокрутки -->
        <div class="mnav__scroll">

        <button
            v-for="f in system"
            :key="f.path"
            type="button"
            class="mnav__item"
            :class="{ 'mnav__item--on': isOn(f), 'mnav__item--drop': dropTarget === f.path }"
            @click="goFolder($event, f.path)"
            @contextmenu.prevent="$emit('context', $event, f)"
            @dragover="onDragOver($event, f)"
            @dragleave="dropTarget = null"
            @drop="onDrop($event, f)"
        >
            <span>{{ f.name }}</span>
            <span v-if="f.shared_with?.length" class="mnav__shared" :title="sharedTitle(f)"><Icon name="share" :size="13" /></span>
            <span v-if="counter(f)" class="mnav__count" :class="{ 'mnav__count--all': !f.unread }" :title="counterTitle(f)"><template v-if="f.unread"><b class="mnav__unread" title="Показать только непрочитанные">{{ f.unread }}</b><i>/ {{ f.total }}</i></template><template v-else>{{ f.total }}</template></span>
        </button>
        <button
            v-if="inbox"
            type="button"
            class="mnav__item"
            :class="{ 'mnav__item--on': filter === 'flagged' }"
            @click="$emit('go', inbox.path, 'flagged')"
        >
            <span>Важное</span>
        </button>
        <!-- Link, а не обычная ссылка: остальные строки списка папок переключаются без перезагрузки,
             и только «Карантин» перезагружал всё приложение целиком. -->
        <Link class="mnav__item" :class="{ 'mnav__item--on': quarantineOpen }" href="/mail/quarantine" title="Письма, задержанные антиспамом"><span>Карантин</span><span v-if="quarantine" class="mnav__count">{{ quarantine }}</span></Link>
        <button v-if="outbox" type="button" class="mnav__item" @click="$emit('outbox')">
            <span>Ждут отправки</span><span class="mnav__count">{{ outbox }}</span>
        </button>

        <div class="mnav__group">
            Мои папки
            <button class="ib ib--sm" type="button" title="Новая папка" @click="$emit('new-folder', null)" aria-label="Новая папка"><Icon name="plus" :size="14" /></button>
        </div>
        <button
            v-for="f in custom"
            :key="f.path"
            type="button"
            class="mnav__item"
            :class="['mnav__item--depth-' + Math.min(f.depth, 3), { 'mnav__item--on': isOn(f), 'mnav__item--drop': dropTarget === f.path }]"
            @click="goFolder($event, f.path)"
            @contextmenu.prevent="f.virtual ? null : $emit('context', $event, f)"
            @dragover="onDragOver($event, f)"
            @dragleave="dropTarget = null"
            @drop="onDrop($event, f)"
        >
            <Icon :name="f.virtual ? 'inbox' : 'folder'" :size="16" style="color: var(--faint); flex: 0 0 16px" />
            <span>{{ f.name }}</span>
            <span v-if="f.shared_with?.length" class="mnav__shared" :title="sharedTitle(f)"><Icon name="share" :size="13" /></span>
            <span v-if="counter(f)" class="mnav__count" :class="{ 'mnav__count--all': !f.unread }" :title="counterTitle(f)"><template v-if="f.unread"><b class="mnav__unread" title="Показать только непрочитанные">{{ f.unread }}</b><i>/ {{ f.total }}</i></template><template v-else>{{ f.total }}</template></span>
        </button>
        <div v-if="!custom.length" class="hint" style="padding: 4px 12px">Папки создаются здесь или из меню письма «В папку».</div>

        <template v-if="shared.length">
            <div class="mnav__group">Общие папки</div>
            <template v-for="g in shared" :key="g.owner">
                <button type="button" class="mnav__owner" :class="{ 'mnav__owner--open': !isCollapsed(g) }" :title="g.owner + (isCollapsed(g) ? ' — развернуть' : ' — свернуть')" @click="toggleGroup(g)">
                    <Icon name="chevron" :size="14" class="mnav__chev" />
                    <Icon name="users" :size="14" style="color: var(--faint); flex: 0 0 14px" />
                    <span>{{ g.name }}</span>
                    <span v-if="g.unread" class="mnav__count"><b>{{ g.unread }}</b></span>
                </button>
                <button
                    v-for="f in (isCollapsed(g) ? [] : g.items)"
                    :key="f.path"
                    type="button"
                    class="mnav__item"
                    :class="['mnav__item--depth-' + Math.min(f.depth + 1, 3), { 'mnav__item--on': isOn(f), 'mnav__item--drop': dropTarget === f.path }]"
                    @click="goFolder($event, f.path)"
                    @contextmenu.prevent="$emit('context', $event, f)"
                    @dragover="onDragOver($event, f)"
                    @dragleave="dropTarget = null"
                    @drop="onDrop($event, f)"
                >
                    <Icon name="folder" :size="16" style="color: var(--faint); flex: 0 0 16px" />
                    <span>{{ f.name }}</span>
                    <span v-if="counter(f)" class="mnav__count" :class="{ 'mnav__count--all': !f.unread }" :title="counterTitle(f)"><template v-if="f.unread"><b class="mnav__unread" title="Показать только непрочитанные">{{ f.unread }}</b><i>/ {{ f.total }}</i></template><template v-else>{{ f.total }}</template></span>
                </button>
            </template>
        </template>

        <div class="mnav__group">
            Метки
            <button class="ib ib--sm" type="button" title="Новая метка" @click="$emit('label', 'new')" aria-label="Новая метка"><Icon name="plus" :size="14" /></button>
        </div>
        <button
            v-for="l in labels"
            :key="l.id"
            type="button"
            class="mnav__item"
            :class="{ 'mnav__item--on': filter === 'label:' + l.id }"
            :title="'Письма с меткой «' + l.name + '» в текущей папке'"
            @click="$emit('go', folder || inbox?.path || 'INBOX', 'label:' + l.id)"
            @contextmenu.prevent="$emit('label', 'context', $event, l)"
        >
            <span class="mnav__swatch mnav__swatch--round" :style="{ background: l.color }" />
            <span>{{ l.name }}</span>
        </button>

        </div>
        <div v-if="quota" class="mnav__quota">
            Занято {{ quota.used }} из {{ quota.total }}
            <div><span :style="{ width: quota.percent + '%' }" /></div>
        </div>
    </nav>
</template>
