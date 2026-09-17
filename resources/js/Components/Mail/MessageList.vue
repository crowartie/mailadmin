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
    sort: { type: String, default: 'date' },
    query: { type: String, default: '' },
    everywhere: { type: Boolean, default: false },
    selected: { type: Array, default: () => [] },
    cursor: { type: Number, default: null },
    opening: { type: Number, default: null },   // письмо, которое сейчас открывается
    highlightUnread: { type: Boolean, default: true },
    unreadColor: { type: String, default: '' },
    openUid: { type: Number, default: null },
    labels: { type: Array, default: () => [] },
    loading: Boolean,
});
const emit = defineEmits(['open', 'toggle', 'select-all', 'clear', 'act', 'context', 'page', 'filter', 'sort', 'search', 'refresh', 'menu', 'everywhere']);

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
    <section class="mlist" :class="{ 'mlist--hl': highlightUnread }" :style="unreadColor ? { '--unread-c': unreadColor } : null">
        <div class="mobile-bar">
            <button class="ib" type="button" @click="$emit('menu')"><Icon name="menu" :size="22" /></button>
            <b>{{ folderName }}</b>
            <button class="ib" type="button" title="Обновить" @click="$emit('refresh')" aria-label="Обновить"><Icon name="refresh" :size="20" /></button>
        </div>

        <!-- 362: подпись внутри поля исчезала после первого же символа, и экранный диктор
             читал поле как безымянное. -->
        <form class="mlist__search" role="search" @submit.prevent="submitSearch">
            <label for="mlist-q" class="sr-only">Поиск по письмам</label>
            <Icon name="search" :size="18" />
            <!-- 174: Escape очищал поле и сразу выполнял поиск — набранное пропадало без возврата.
                 Теперь Escape только очищает поле; поиск запускает Enter или крестик. -->
            <input id="mlist-q" ref="searchInput" v-model="q" type="search" placeholder="Поиск по письмам" @keydown.esc.prevent="q ? (q = '') : searchInput?.blur()">
            <button v-if="q" class="ib ib--sm" type="button" title="Очистить" @click="q = ''; submitSearch()" aria-label="Очистить"><Icon name="x" :size="14" /></button>
            <span v-else class="kbd">/</span>
        </form>
        <div v-if="query" class="hint" style="padding: 0 16px 8px">
            <!-- 172: подсказка была написана в третьем формате, не совпадавшем ни со справкой,
                 ни с разборщиком. Пишем ровно так, как понимает поиск. -->
            Найдено {{ list.total }}<template v-if="!everywhere"> в папке «{{ folderName }}»</template><template v-else> во всех папках</template>
            <button type="button" class="linklike" style="margin-left: 8px" @click="$emit('everywhere', !everywhere)">{{ everywhere ? 'только в этой папке' : 'искать во всех папках' }}</button>
            <br>операторы: <span class="mono">от:иванов кому:sales тема:счёт есть:вложение после:01.09.2026 до:30.09.2026</span>
        </div>

        <div v-if="selected.length" class="mlist__bulk">
            <span class="cb cb--on" role="checkbox" aria-checked="true" @click="$emit('clear')"><Icon name="check" :size="12" /></span>
            <b>Выбрано {{ selected.length }}</b>
            <button class="ib ib--sm" type="button" title="Прочитано" @click="$emit('act', 'seen', selected)" aria-label="Прочитано"><Icon name="eye" :size="16" /></button>
            <button class="ib ib--sm" type="button" title="Непрочитано" @click="$emit('act', 'unseen', selected)" aria-label="Непрочитано"><Icon name="unread" :size="16" /></button>
            <button class="ib ib--sm" type="button" title="В папку (v)" @click="$emit('context', $event, null, 'move')" aria-label="В папку (v)"><Icon name="folder" :size="16" /></button>
            <button class="ib ib--sm" type="button" title="Метка (l)" @click="$emit('context', $event, null, 'label')" aria-label="Метка (l)"><Icon name="tag" :size="16" /></button>
            <button class="ib ib--sm" type="button" title="Архив (e)" @click="$emit('act', 'archive', selected)" aria-label="Архив (e)"><Icon name="archive" :size="16" /></button>
            <button class="ib ib--sm" type="button" title="Спам (!)" @click="$emit('act', 'spam', selected)" aria-label="Спам (!)"><Icon name="spam" :size="16" /></button>
            <button class="ib ib--sm ib--danger" type="button" title="Удалить (#)" @click="$emit('act', 'delete', selected)" aria-label="Удалить (#)"><Icon name="trash" :size="16" /></button>
        </div>
        <div v-else class="mlist__meta">
            <span class="cb" :class="{ 'cb--on': allChecked }" role="checkbox" tabindex="0" :aria-checked="allChecked"
                  title="Выбрать все на странице" @click="$emit('select-all')" @keydown.enter.prevent="$emit('select-all')" @keydown.space.prevent="$emit('select-all')">
                <Icon v-if="allChecked" name="check" :size="12" />
            </span>
            <span>{{ list.total }} {{ plural(list.total, 'письмо', 'письма', 'писем') }}</span>
            <button class="ib ib--sm" type="button" title="Обновить" aria-label="Обновить список" @click="$emit('refresh')"><Icon name="refresh" :size="14" /></button>
            <span class="grow" />
            <!-- Порядок списка: раньше его нельзя было изменить вообще. -->
            <span class="mlist__sort">
                <label class="sr-only" for="mlist-sort">Порядок писем</label>
                <select id="mlist-sort" :value="sort" title="Порядок писем" @change="$emit('sort', $event.target.value)">
                    <option value="date">Сначала новые</option>
                    <option value="date-asc">Сначала старые</option>
                    <option value="from">По отправителю</option>
                    <option value="subject">По теме</option>
                    <option value="size">Сначала тяжёлые</option>
                </select>
            </span>
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
                    'mrow--on': m.uid === openUid || m.uid === opening,
                    'mrow--cursor': m.uid === cursor,
                    'mrow--checked': selectedSet.has(m.uid),
                }"
                draggable="true"
                tabindex="0"
                role="button"
                :aria-label="(m.seen ? '' : 'Непрочитанное. ') + m.from.name + '. ' + m.subject"
                @keydown.enter.prevent="$emit('open', m.uid, $event)"
                @keydown.space.prevent="$emit('toggle', m.uid, $event)"
                @click="$emit('open', m.uid, $event)"
                @contextmenu.prevent="$emit('context', $event, m.uid)"
                @dragstart="onDragStart($event, m)"
            >
                <span class="cb" :class="{ 'cb--on': selectedSet.has(m.uid) }" role="checkbox" tabindex="0"
                      :aria-checked="selectedSet.has(m.uid)" aria-label="Выбрать письмо"
                      @click.stop="$emit('toggle', m.uid, $event)"
                      @keydown.enter.stop.prevent="$emit('toggle', m.uid, $event)"
                      @keydown.space.stop.prevent="$emit('toggle', m.uid, $event)">
                    <Icon v-if="selectedSet.has(m.uid)" name="check" :size="12" />
                </span>
                <span class="mrow__dot" />
                <!-- В «Отправленных» и «Черновиках» рядом стоит имя получателя — буквы берём оттуда же,
                     иначе кружок и подпись противоречат друг другу. -->
                <span class="mrow__av">{{ (folderRole === 'sent' || folderRole === 'drafts') && m.toName ? initials(m.toName, '') : initials(m.from.name, m.from.mail) }}</span>
                <span class="mrow__body">
                    <span class="mrow__from">
                        <b :title="m.from.mail">{{ folderRole === 'sent' || folderRole === 'drafts' ? (m.toName || m.from.name) : m.from.name }}</b>
                        <span v-if="m.answered" title="Вы ответили" style="color: var(--faint); display: inline-flex"><Icon name="reply" :size="13" /></span>
                        <span v-for="id in m.labels" :key="id">
                            <span v-if="labelMap[id]" class="lbl" :style="{ background: labelMap[id].color + '22', color: labelMap[id].color }">{{ labelMap[id].name }}</span>
                        </span>
                        <!-- При поиске по всем папкам видно, откуда письмо. -->
                        <span v-if="m.folderName" class="thr" :title="'Письмо лежит в папке «' + m.folderName + '»'">{{ m.folderName }}</span>
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
                    <span class="mrow__acts-when">{{ when(m.date) }}</span>
                    <button class="ib ib--sm" type="button" title="Архив" @click.stop="$emit('act', 'archive', [m.uid])" aria-label="Архив"><Icon name="archive" :size="15" /></button>
                    <button class="ib ib--sm" type="button" title="Удалить" @click.stop="$emit('act', 'delete', [m.uid])" aria-label="Удалить"><Icon name="trash" :size="15" /></button>
                    <button class="ib ib--sm" type="button" :title="m.flagged ? 'Снять флажок' : 'Флажок'" :class="{ 'ib--on': m.flagged }" @click.stop="$emit('act', m.flagged ? 'unflag' : 'flag', [m.uid])" :aria-label="m.flagged ? 'Снять флажок' : 'Флажок'"><Icon name="flag" :size="15" /></button>
                    <button class="ib ib--sm" type="button" title="Отложить" @click.stop="$emit('context', $event, m.uid, 'snooze')" aria-label="Отложить"><Icon name="clock" :size="15" /></button>
                </span>
            </div>
            <div v-if="!list.messages.length && !loading" class="empty" style="padding-top: 60px">
                {{ query ? 'Ничего не найдено' : filter !== 'all' ? 'Таких писем нет' : 'В этой папке пусто' }}
            </div>
        </div>

        <footer v-if="list.pages > 1" class="mlist__foot">
            <button class="btn btn--sm" type="button" :disabled="loading || list.page <= 1" @click="$emit('page', list.page - 1)">Новее</button>
            <span class="grow" style="text-align: center">{{ list.page }} / {{ list.pages }}</span>
            <button class="btn btn--sm" type="button" :disabled="loading || list.page >= list.pages" @click="$emit('page', list.page + 1)">Старше</button>
        </footer>
    </section>
</template>
