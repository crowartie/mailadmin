<script setup>
// Колонка папок и меток. Письма можно перетаскивать на папки.
import { computed, ref } from 'vue';
import Icon from '../Icon.vue';

const props = defineProps({
    folders: { type: Array, default: () => [] },
    labels: { type: Array, default: () => [] },
    folder: String,
    filter: { type: String, default: 'all' },
    outbox: { type: Number, default: 0 },
    quota: { type: Object, default: null },
});
const emit = defineEmits(['go', 'compose', 'context', 'drop', 'new-folder', 'label', 'outbox']);

const system = computed(() => props.folders.filter((f) => f.role !== 'custom' && f.role !== 'shared'));
const custom = computed(() => props.folders.filter((f) => f.role === 'custom'));
// Чужие папки, открытые нам: группируем по владельцу.
const shared = computed(() => {
    const groups = [];
    for (const f of props.folders.filter((x) => x.role === 'shared')) {
        let g = groups.find((x) => x.owner === f.owner);
        if (!g) { g = { owner: f.owner, name: f.ownerName || f.owner, items: [] }; groups.push(g); }
        g.items.push(f);
    }
    return groups;
});
const inbox = computed(() => props.folders.find((f) => f.role === 'inbox'));
const dropTarget = ref(null);

function isOn(f) {
    return f.path === props.folder && props.filter !== 'flagged' && !props.filter.startsWith('label:');
}

function onDragOver(e, f) {
    if (e.dataTransfer.types.includes('text/x-mail-uids')) { e.preventDefault(); dropTarget.value = f.path; }
}
function onDrop(e, f) {
    dropTarget.value = null;
    try {
        const data = JSON.parse(e.dataTransfer.getData('text/x-mail-uids'));
        if (data.folder !== f.path) emit('drop', data, f.path);
    } catch {}
}
</script>

<template>
    <nav class="mnav" aria-label="Папки">
        <button class="btn btn--primary btn--block" type="button" style="margin-bottom: 10px" @click="$emit('compose')">
            <Icon name="plus" :size="16" />Написать
        </button>

        <button
            v-for="f in system"
            :key="f.path"
            type="button"
            class="mnav__item"
            :class="{ 'mnav__item--on': isOn(f), 'mnav__item--drop': dropTarget === f.path }"
            @click="$emit('go', f.path, 'all')"
            @contextmenu.prevent="$emit('context', $event, f)"
            @dragover="onDragOver($event, f)"
            @dragleave="dropTarget = null"
            @drop="onDrop($event, f)"
        >
            <span>{{ f.name }}</span>
            <span v-if="f.role === 'drafts' && f.total" class="mnav__count">{{ f.total }}</span>
            <span v-else-if="f.unread" class="mnav__count">{{ f.unread }}</span>
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
        <button v-if="outbox" type="button" class="mnav__item" @click="$emit('outbox')">
            <span>Ждут отправки</span><span class="mnav__count">{{ outbox }}</span>
        </button>

        <div class="mnav__group">
            Мои папки
            <button class="ib ib--sm" type="button" title="Новая папка" @click="$emit('new-folder', null)"><Icon name="plus" :size="14" /></button>
        </div>
        <button
            v-for="f in custom"
            :key="f.path"
            type="button"
            class="mnav__item"
            :class="['mnav__item--depth-' + Math.min(f.depth, 3), { 'mnav__item--on': isOn(f), 'mnav__item--drop': dropTarget === f.path }]"
            @click="$emit('go', f.path, 'all')"
            @contextmenu.prevent="$emit('context', $event, f)"
            @dragover="onDragOver($event, f)"
            @dragleave="dropTarget = null"
            @drop="onDrop($event, f)"
        >
            <Icon name="folder" :size="16" style="color: var(--faint); flex: 0 0 16px" />
            <span>{{ f.name }}</span>
            <span v-if="f.unread" class="mnav__count">{{ f.unread }}</span>
        </button>
        <div v-if="!custom.length" class="hint" style="padding: 4px 12px">Папки создаются здесь или из меню письма «В папку».</div>

        <template v-if="shared.length">
            <div class="mnav__group">Общие папки</div>
            <template v-for="g in shared" :key="g.owner">
                <div class="mnav__owner" :title="g.owner"><Icon name="users" :size="14" style="color: var(--faint); flex: 0 0 14px" /><span>{{ g.name }}</span></div>
                <button
                    v-for="f in g.items"
                    :key="f.path"
                    type="button"
                    class="mnav__item"
                    :class="['mnav__item--depth-' + Math.min(f.depth + 1, 3), { 'mnav__item--on': isOn(f), 'mnav__item--drop': dropTarget === f.path }]"
                    @click="$emit('go', f.path, 'all')"
                    @contextmenu.prevent="$emit('context', $event, f)"
                    @dragover="onDragOver($event, f)"
                    @dragleave="dropTarget = null"
                    @drop="onDrop($event, f)"
                >
                    <Icon name="folder" :size="16" style="color: var(--faint); flex: 0 0 16px" />
                    <span>{{ f.name }}</span>
                    <span v-if="f.unread" class="mnav__count">{{ f.unread }}</span>
                </button>
            </template>
        </template>

        <div class="mnav__group">
            Метки
            <button class="ib ib--sm" type="button" title="Новая метка" @click="$emit('label', 'new')"><Icon name="plus" :size="14" /></button>
        </div>
        <button
            v-for="l in labels"
            :key="l.id"
            type="button"
            class="mnav__item"
            :class="{ 'mnav__item--on': filter === 'label:' + l.id }"
            @click="$emit('go', inbox?.path || 'INBOX', 'label:' + l.id)"
            @contextmenu.prevent="$emit('label', 'context', $event, l)"
        >
            <span class="mnav__swatch mnav__swatch--round" :style="{ background: l.color }" />
            <span>{{ l.name }}</span>
        </button>

        <div v-if="quota" class="mnav__quota">
            Занято {{ quota.used }} из {{ quota.total }}
            <div><span :style="{ width: quota.percent + '%' }" /></div>
        </div>
    </nav>
</template>
