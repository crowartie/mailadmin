<script setup>
// Средняя колонка: поиск, фильтры, панель массовых действий, строки писем, страницы.
import { computed, ref, watch } from 'vue';
import Icon from '../Icon.vue';
import { initials, plural, when } from '../../mail/format';

const props = defineProps({
    list: { type: Object, required: true },
    folder: String,
    folderName: String,
    folderRole: String,
    filter: { type: String, default: 'all' },
    query: { type: String, default: '' },
    selected: { type: Array, default: () => [] },
    cursor: { type: Number, default: null },
    openUid: { type: Number, default: null },
    labels: { type: Array, default: () => [] },
    loading: Boolean,
});
const emit = defineEmits(['open', 'toggle', 'select-all', 'clear', 'act', 'context', 'page', 'filter', 'search', 'refresh', 'menu']);

const q = ref(props.query || '');
watch(() => props.query, (v) => { q.value = v || ''; });
const searchInput = ref(null);
const selectedSet = computed(() => new Set(props.selected));
const labelMap = computed(() => Object.fromEntries(props.labels.map((l) => [l.id, l])));
const allChecked = computed(() => props.list.messages.length > 0 && props.list.messages.every((m) => selectedSet.value.has(m.uid)));

function onDragStart(e, m) {
    const uids = selectedSet.value.has(m.uid) ? props.selected : [m.uid];
    e.dataTransfer.setData('text/x-mail-uids', JSON.stringify({ folder: props.folder, uids }));
    e.dataTransfer.effectAllowed = 'move';
}

function submitSearch() {
    emit('search', q.value.trim());
}

defineExpose({ focusSearch: () => searchInput.value?.focus() });
</script>

<template>
    <section class="mlist">
        <div class="mobile-bar">
            <button class="ib" type="button" @click="$emit('menu')"><Icon name="menu" :size="22" /></button>
            <b>{{ folderName }}</b>
            <button class="ib" type="button" title="Обновить" @click="$emit('refresh')"><Icon name="refresh" :size="20" /></button>
        </div>

        <form class="mlist__search" @submit.prevent="submitSearch">
            <Icon name="search" :size="18" />
            <input ref="searchInput" v-model="q" type="search" placeholder="Поиск по письмам" @keydown.esc="q = ''; submitSearch()">
            <button v-if="q" class="ib ib--sm" type="button" title="Очистить" @click="q = ''; submitSearch()"><Icon name="x" :size="14" /></button>
            <span v-else class="kbd">/</span>
        </form>
        <div v-if="query" class="hint" style="padding: 0 16px 8px">
            Найдено {{ list.total }} · операторы: <span class="mono">от: кому: тема: есть:вложение до:2026-09-01</span>
        </div>

        <div v-if="selected.length" class="mlist__bulk">
            <span class="cb cb--on" role="checkbox" aria-checked="true" @click="$emit('clear')"><Icon name="check" :size="12" /></span>
            <b>Выбрано {{ selected.length }}</b>
            <button class="ib ib--sm" type="button" title="Прочитано" @click="$emit('act', 'seen', selected)"><Icon name="eye" :size="16" /></button>
            <button class="ib ib--sm" type="button" title="Непрочитано" @click="$emit('act', 'unseen', selected)"><Icon name="unread" :size="16" /></button>
            <button class="ib ib--sm" type="button" title="В папку (v)" @click="$emit('context', $event, null, 'move')"><Icon name="folder" :size="16" /></button>
            <button class="ib ib--sm" type="button" title="Метка (l)" @click="$emit('context', $event, null, 'label')"><Icon name="tag" :size="16" /></button>
            <button class="ib ib--sm" type="button" title="Архив (e)" @click="$emit('act', 'archive', selected)"><Icon name="archive" :size="16" /></button>
            <button class="ib ib--sm" type="button" title="Спам (!)" @click="$emit('act', 'spam', selected)"><Icon name="spam" :size="16" /></button>
            <button class="ib ib--sm ib--danger" type="button" title="Удалить (#)" @click="$emit('act', 'delete', selected)"><Icon name="trash" :size="16" /></button>
        </div>
        <div v-else class="mlist__meta">
            <span class="cb" role="checkbox" :aria-checked="allChecked" title="Выбрать все на странице" @click="$emit('select-all')" />
            <span>{{ list.total }} {{ plural(list.total, 'письмо', 'письма', 'писем') }}</span>
            <button class="ib ib--sm" type="button" title="Обновить" @click="$emit('refresh')"><Icon name="refresh" :size="14" /></button>
            <span class="grow" />
            <span class="seg seg--sm">
                <button type="button" class="seg__item" :class="{ 'seg__item--on': filter === 'all' }" @click="$emit('filter', 'all')">Все</button>
                <button type="button" class="seg__item" :class="{ 'seg__item--on': filter === 'unread' }" @click="$emit('filter', 'unread')">Непрочитанные</button>
                <button type="button" class="seg__item" :class="{ 'seg__item--on': filter === 'attach' }" @click="$emit('filter', 'attach')">Вложения</button>
            </span>
        </div>

        <div class="mlist__rows" :style="loading ? 'opacity:.6' : ''">
            <div
                v-for="m in list.messages"
                :key="m.uid"
                class="mrow"
                :class="{
                    'mrow--unread': !m.seen,
                    'mrow--on': m.uid === openUid,
                    'mrow--cursor': m.uid === cursor,
                    'mrow--checked': selectedSet.has(m.uid),
                }"
                draggable="true"
                @click="$emit('open', m.uid, $event)"
                @contextmenu.prevent="$emit('context', $event, m.uid)"
                @dragstart="onDragStart($event, m)"
            >
                <span class="cb" :class="{ 'cb--on': selectedSet.has(m.uid) }" role="checkbox" @click.stop="$emit('toggle', m.uid, $event)">
                    <Icon v-if="selectedSet.has(m.uid)" name="check" :size="12" />
                </span>
                <span class="mrow__dot" />
                <span class="mrow__av">{{ initials(m.from.name, m.from.mail) }}</span>
                <span class="mrow__body">
                    <span class="mrow__from">
                        <b :title="m.from.mail">{{ folderRole === 'sent' || folderRole === 'drafts' ? (m.toName || m.from.name) : m.from.name }}</b>
                        <span v-if="m.answered" title="Вы ответили" style="color: var(--faint); display: inline-flex"><Icon name="reply" :size="13" /></span>
                        <span v-for="id in m.labels" :key="id">
                            <span v-if="labelMap[id]" class="lbl" :style="{ background: labelMap[id].color + '22', color: labelMap[id].color }">{{ labelMap[id].name }}</span>
                        </span>
                    </span>
                    <span class="mrow__subj">{{ m.subject }}</span>
                    <span v-if="m.preview" class="mrow__prev">{{ m.preview }}</span>
                </span>
                <span class="mrow__when">
                    <span style="display: flex; gap: 6px; align-items: center">
                        <Icon v-if="m.hasAttachments" name="clip" :size="13" />
                        <span v-if="m.flagged" class="mrow__star mrow__star--on"><Icon name="flag" :size="13" /></span>
                    </span>
                    <span>{{ when(m.date) }}</span>
                </span>
                <span class="mrow__acts">
                    <button class="ib ib--sm" type="button" title="Архив" @click.stop="$emit('act', 'archive', [m.uid])"><Icon name="archive" :size="15" /></button>
                    <button class="ib ib--sm" type="button" title="Удалить" @click.stop="$emit('act', 'delete', [m.uid])"><Icon name="trash" :size="15" /></button>
                    <button class="ib ib--sm" type="button" :title="m.flagged ? 'Снять флажок' : 'Флажок'" :class="{ 'ib--on': m.flagged }" @click.stop="$emit('act', m.flagged ? 'unflag' : 'flag', [m.uid])"><Icon name="flag" :size="15" /></button>
                    <button class="ib ib--sm" type="button" title="Отложить" @click.stop="$emit('context', $event, m.uid, 'snooze')"><Icon name="clock" :size="15" /></button>
                </span>
            </div>
            <div v-if="!list.messages.length && !loading" class="empty" style="padding-top: 60px">
                {{ query ? 'Ничего не найдено' : filter !== 'all' ? 'Таких писем нет' : 'В этой папке пусто' }}
            </div>
        </div>

        <footer v-if="list.pages > 1" class="mlist__foot">
            <button class="btn btn--sm" type="button" :disabled="list.page <= 1" @click="$emit('page', list.page - 1)">Новее</button>
            <span class="grow" style="text-align: center">{{ list.page }} / {{ list.pages }}</span>
            <button class="btn btn--sm" type="button" :disabled="list.page >= list.pages" @click="$emit('page', list.page + 1)">Старее</button>
        </footer>
    </section>
</template>
