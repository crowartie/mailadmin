<script setup>
// Средняя колонка: поиск, фильтры, панель массовых действий, строки писем, страницы.
import { computed, ref, watch } from 'vue';
import Icon from '../Icon.vue';
import { dayGroup, hue, initials, plural, when } from '../../mail/format';

const props = defineProps({
    list: { type: Object, required: true },
    folder: String,
    folderName: String,
    folderRole: String,
    // Чужая папка, открытая только для просмотра: действий, меняющих письма, в ней нет.
    // Признак приходит с сервера (права IMAP), а не угадывается по роли папки.
    readonly: { type: Boolean, default: false },
    filter: { type: String, default: 'all' },
    sort: { type: String, default: 'date' },
    query: { type: String, default: '' },
    everywhere: { type: Boolean, default: false },
    selected: { type: Array, default: () => [] },
    cursor: { type: Number, default: null },
    opening: { type: Number, default: null },   // письмо, которое сейчас открывается
    highlightUnread: { type: Boolean, default: true },
    density: { type: String, default: 'normal' },
    unreadColor: { type: String, default: '' },
    openUid: { type: Number, default: null },
    labels: { type: Array, default: () => [] },
    loading: Boolean,
});
const emit = defineEmits(['open', 'toggle', 'select-all', 'clear', 'act', 'context', 'page', 'filter', 'sort', 'search', 'refresh', 'menu', 'everywhere']);

/**
 * Подпись группы перед строкой письма — «Сегодня», «Вчера», «Сентябрь».
 * Показываем только когда список идёт по времени: при сортировке по отправителю
 * или размеру разделители по датам врали бы.
 */
function groupLabel(i) {
    if (props.sort !== 'date' && props.sort !== 'date-asc') return '';
    const rows = props.list.messages || [];
    const now = dayGroup(rows[i]?.date);
    return i === 0 || now !== dayGroup(rows[i - 1]?.date) ? now : '';
}

// 333: режим выбора на телефоне — галочки показываются, тап по строке отмечает письмо,
// а не открывает его. На большом экране галочки видны всегда, и режим не нужен.
const selectMode = ref(false);
function toggleSelectMode() {
    selectMode.value = !selectMode.value;
    if (!selectMode.value) emit('clear');
}

// Поле поиска: «Везде» — простые слова по всему письму; остальное превращает каждое
// слово в оператор («от:Иван от:Петров»). Операторы, написанные руками, не трогаем.
const SCOPES = { from: 'от', to: 'кому', subject: 'тема', body: 'текст' };
const SCOPE_LABELS = { all: 'Везде', from: 'От кого', to: 'Кому', subject: 'Тема', body: 'В тексте' };
const SCOPE_HINTS = { all: 'Поиск по письмам', from: 'От кого: имя или адрес', to: 'Кому: имя или адрес', subject: 'Слова из темы', body: 'Слова из текста письма' };
function loadScope() { try { return SCOPES[localStorage.getItem('mail.searchScope')] ? localStorage.getItem('mail.searchScope') : 'all'; } catch (e) { return 'all'; } }
const scope = ref(loadScope());
watch(scope, (v) => { try { localStorage.setItem('mail.searchScope', v); } catch (e) { /* приватный режим */ } });

function composeQuery(text, sc) {
    if (!text || sc === 'all' || !SCOPES[sc] || /(^|\s)\S+:/.test(text)) return text;
    return text.split(/\s+/).filter(Boolean).map((w) => SCOPES[sc] + ':' + w).join(' ');
}
// Запрос, пришедший снаружи (из адреса страницы или «все письма от него»), раскладываем
// обратно на поле и слова, если он весь из операторов одного поля.
function decomposeQuery(query) {
    const words = String(query || '').trim().split(/\s+/).filter(Boolean);
    if (!words.length) return { scope: null, text: '' };
    for (const [sc, op] of Object.entries(SCOPES)) {
        const re = new RegExp('^' + op + ':(.+)$', 'i');
        if (words.every((w) => re.test(w))) return { scope: sc, text: words.map((w) => w.replace(re, '$1')).join(' ') };
    }
    return { scope: null, text: query };
}

const initial = decomposeQuery(props.query);
if (initial.scope) scope.value = initial.scope;
const q = ref(initial.text);
watch(() => props.query, (v) => { const d = decomposeQuery(v); if (d.scope) scope.value = d.scope; q.value = d.text; });
const searchInput = ref(null);
// Подсказка по операторам — по ссылке, а не всегда: с переключателем поля она нужна
// только тем, кто пишет операторы руками.
const showOps = ref(false);
const selectedSet = computed(() => new Set(props.selected));
const labelMap = computed(() => Object.fromEntries(props.labels.map((l) => [l.id, l])));
const allChecked = computed(() => props.list.messages.length > 0 && props.list.messages.every((m) => selectedSet.value.has(m.uid)));

function onDragStart(e, m) {
    const uids = selectedSet.value.has(m.uid) ? props.selected : [m.uid];
    e.dataTransfer.setData('text/x-mail-uids', JSON.stringify({ folder: props.folder, uids }));
    e.dataTransfer.effectAllowed = 'move';
}

function submitSearch() {
    emit('search', composeQuery(q.value.trim(), scope.value));
}
// Сменили поле при уже введённых словах — ищем сразу, не заставляя жать Enter ещё раз.
function changeScope() {
    if (q.value.trim() || props.query) submitSearch();
}

defineExpose({ focusSearch: () => searchInput.value?.focus() });
</script>

<template>
    <section class="mlist" :class="{ 'mlist--hl': highlightUnread, 'mlist--select': selectMode, 'mlist--roomy': density === 'roomy', 'mlist--compact': density === 'compact' }" :style="unreadColor ? { '--unread-c': unreadColor } : null">
        <div class="mobile-bar">
            <button class="ib" type="button" @click="$emit('menu')" aria-label="Папки"><Icon name="menu" :size="22" /></button>
            <b>{{ folderName }}</b>
            <!-- 333: галочки на телефоне были скрыты, долгий тап меню не открывал —
                 выделить несколько писем было нельзя вовсе. 339: кнопка обновления
                 стояла здесь и ещё раз рядом со счётчиком писем. -->
            <button class="ib" type="button" :class="{ 'ib--on': selectMode }" :title="selectMode ? 'Выйти из выбора' : 'Выбрать несколько писем'" :aria-label="selectMode ? 'Выйти из выбора' : 'Выбрать несколько писем'" @click="toggleSelectMode"><Icon name="check" :size="20" /></button>
        </div>

        <!-- 362: подпись внутри поля исчезала после первого же символа, и экранный диктор
             читал поле как безымянное. -->
        <form class="mlist__search" role="search" @submit.prevent="submitSearch">
            <label for="mlist-q" class="sr-only">Поиск по письмам</label>
            <Icon name="search" :size="18" />
            <!-- Поле поиска: люди ищут по отправителю и получателю, а о операторах «от:» и «кому:»
                 знают не все. Переключатель делает то же самое, только видимо. -->
            <select v-model="scope" class="mlist__scope" aria-label="Где искать" title="Где искать" @change="changeScope">
                <option v-for="(label, key) in SCOPE_LABELS" :key="key" :value="key">{{ label }}</option>
            </select>
            <!-- 174: Escape очищал поле и сразу выполнял поиск — набранное пропадало без возврата.
                 Теперь Escape только очищает поле; поиск запускает Enter или крестик. -->
            <input id="mlist-q" ref="searchInput" v-model="q" type="search" :placeholder="SCOPE_HINTS[scope]" @keydown.esc.prevent="q ? (q = '') : searchInput?.blur()">
            <button v-if="q" class="ib ib--sm" type="button" title="Очистить" @click="q = ''; submitSearch()" aria-label="Очистить"><Icon name="x" :size="14" /></button>
            <!-- 340: подсказка про клавишу показывалась и на телефоне, где клавиши нет. -->
            <span v-else class="kbd desktop-only">/</span>
        </form>
        <div v-if="query" class="hint" style="padding: 0 16px 8px">
            <!-- 172: подсказка была написана в третьем формате, не совпадавшем ни со справкой,
                 ни с разборщиком. Пишем ровно так, как понимает поиск. -->
            Найдено {{ list.total }}<template v-if="!everywhere"> в папке «{{ folderName }}»</template><template v-else> во всех папках, включая общие, «Спам» и «Корзину»</template>
            &#183;
            <button type="button" class="linklike" @click="$emit('everywhere', !everywhere)">{{ everywhere ? 'только в этой папке' : 'искать во всех папках' }}</button>
            <br v-if="list.skipped && list.skipped.length">
            <span v-if="list.skipped && list.skipped.length" class="hint--warn">
                <!-- Раньше папка, занятая индексацией, просто не попадала в выдачу, и поиск молча
                     показывал неполный ответ. Лучше честно назвать, где ещё не искали. -->
                Не искали в {{ list.skipped.length }} {{ list.skipped.length === 1 ? 'папке' : 'папках' }} ({{ list.skipped.join(', ') }}) — сервер достраивает индекс, повторите через минуту
            </span>
            &#183;
            <button type="button" class="linklike" @click="showOps = !showOps">{{ showOps ? 'скрыть операторы' : 'операторы…' }}</button>
            <br v-if="showOps"><span v-if="showOps" class="mono">от:иванов кому:sales тема:счёт текст:договор файл:счёт.pdf есть:вложение после:01.09.2026 до:30.09.2026</span>
        </div>

        <div v-if="selected.length" class="mlist__bulk">
            <span class="cb cb--on" role="checkbox" aria-checked="true" @click="$emit('clear')"><Icon name="check" :size="12" /></span>
            <b>Выбрано {{ selected.length }}</b>
            <button class="ib ib--sm" type="button" title="Прочитано" @click="$emit('act', 'seen', selected)" aria-label="Прочитано"><Icon name="eye" :size="16" /></button>
            <button class="ib ib--sm" type="button" title="Непрочитано" @click="$emit('act', 'unseen', selected)" aria-label="Непрочитано"><Icon name="unread" :size="16" /></button>
            <template v-if="!readonly">
                <button class="ib ib--sm" type="button" title="В папку (v)" @click="$emit('context', $event, null, 'move')" aria-label="В папку (v)"><Icon name="folder" :size="16" /></button>
                <button class="ib ib--sm" type="button" title="Метка (l)" @click="$emit('context', $event, null, 'label')" aria-label="Метка (l)"><Icon name="tag" :size="16" /></button>
                <button class="ib ib--sm" type="button" title="Архив (e)" @click="$emit('act', 'archive', selected)" aria-label="Архив (e)"><Icon name="archive" :size="16" /></button>
                <button class="ib ib--sm" type="button" title="Спам (!)" @click="$emit('act', 'spam', selected)" aria-label="Спам (!)"><Icon name="spam" :size="16" /></button>
                <button class="ib ib--sm ib--danger" type="button" title="Удалить (#)" @click="$emit('act', 'delete', selected)" aria-label="Удалить (#)"><Icon name="trash" :size="16" /></button>
            </template>
            <span v-else class="mlist__ro" title="Владелец открыл эту папку только для просмотра">только просмотр</span>
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
            <template v-for="(m, i) in list.messages" :key="m.uid">
            <div v-if="groupLabel(i)" class="mlist__day">{{ groupLabel(i) }}</div>
            <div
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
                @click="selectMode ? $emit('toggle', m.uid) : $emit('open', m.uid, $event)"
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
                <span class="mrow__av" :style="{ '--av-h': hue(folderRole === 'sent' || folderRole === 'drafts' ? (m.toMail || m.from.mail) : m.from.mail) }">{{ (folderRole === 'sent' || folderRole === 'drafts') && m.toName ? initials(m.toName, '') : initials(m.from.name, m.from.mail) }}</span>
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
                    <span class="mrow__subj" :title="m.subject">{{ m.subject }}</span>
                    <span v-if="m.preview" class="mrow__prev">{{ m.preview }}</span>
                </span>
                <span class="mrow__when">
                    <span class="mrow__when-icons">
                        <Icon v-if="m.hasAttachments" name="clip" :size="13" />
                        <span v-if="m.flagged" class="mrow__star mrow__star--on"><Icon name="flag" :size="13" /></span>
                    </span>
                    <span>{{ when(m.date) }}</span>
                </span>
            </div>
            </template>
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
