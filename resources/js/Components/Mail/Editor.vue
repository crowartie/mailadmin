<script setup>
// HTML-редактор на contenteditable. Панель оформления (обращение №53) — два ряда под текстом, как в Gmail:
// символы (шрифт, размер, B I U S, цвет, выделение, ссылка, картинка, смайлик) и абзац (выравнивание, списки,
// отступы, цитата, таблица, линия, код, заголовок). Показывается по prop expanded — кнопка «A» у «Отправить».
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import Icon from '../Icon.vue';

const props = defineProps({
    modelValue: { type: String, default: '' },
    placeholder: { type: String, default: 'Текст письма…' },
    compact: Boolean,
    // Панель оформления раскрыта (кнопка «A» в подвале письма). Простой вид её не урезает:
    // раз человек сам нажал «A», ему нужна панель целиком.
    expanded: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue', 'submit', 'save', 'toast']);
const el = ref(null);
const state = ref({ bold: false, italic: false, underline: false, strike: false, align: 'left', block: 'p', font: '', size: '' });

// ── Наборы панели. Значения — то, что понимают почтовые программы получателей: три семейства шрифтов
// с запасными, размеры именами CSS (получатель увидит те же ступени), десять цветов.
const FONTS = [['', 'Обычный'], ['Georgia, "Times New Roman", serif', 'С засечками'], ['"Courier New", Consolas, monospace', 'Моноширинный']];
const SIZES = [['small', 'Мелкий'], ['', 'Средний'], ['large', 'Крупный'], ['xx-large', 'Очень крупный']];
const BLOCKS = [['p', 'Обычный текст'], ['h1', 'Заголовок 1'], ['h2', 'Заголовок 2'], ['h3', 'Заголовок 3']];
const COLORS = ['#2B3036', '#C62828', '#C94E00', '#9A6700', '#1F7A4D', '#1D5FD1', '#6B3FA0', '#0F766E', '#646B76', '#FFFFFF'];
const MARKS = ['#FFF3B0', '#FFD9C2', '#D7F5E1', '#DCE8FF', '#EAD9FF', '#E6E4E0'];
const EMOJI = ['🙂', '😊', '😀', '😉', '👍', '👌', '🙏', '👏', '🤝', '✅', '❗', '❓', '⭐', '🔥', '💡', '📌', '📎', '📅', '📞', '✉️', '🎉', '☕', '🚀', '⚠️'];
const pop = ref(null);   // открытая всплывашка: color | mark | table | emoji | null
const tableHover = ref([0, 0]);
function togglePop(name) { pop.value = pop.value === name ? null : name; }
function closePop(e) { if (pop.value && !e.target.closest?.('.fmt__pop, .fmt__popbtn')) pop.value = null; }
onMounted(() => document.addEventListener('mousedown', closePop));
onBeforeUnmount(() => document.removeEventListener('mousedown', closePop));

// Цвет, выделение, шрифт и размер — через CSS (span style=…), а не через <font>: <font> устарел и
// в некоторых программах теряется. Для остальных команд остаётся привычная разметка <b>, <i>, <u>.
function cssCmd(name, value) {
    el.value.focus();
    document.execCommand('styleWithCSS', false, true);
    try { document.execCommand(name, false, value); } finally { document.execCommand('styleWithCSS', false, false); }
    sync();
    refresh();
    pop.value = null;
}
function setFont(v) { cssCmd('fontName', v || 'inherit'); }
function setSize(v) {
    // execCommand fontSize понимает только 1–7 и делает <font size>; при styleWithCSS Chrome переводит
    // 1–7 в именованные размеры CSS: 1 x-small, 2 small, 3 medium, 4 large, 5 x-large, 6 xx-large, 7 xxx-large.
    const n = { small: 2, '': 3, large: 4, 'xx-large': 6 }[v] ?? 3;
    cssCmd('fontSize', String(n));
}
function setColor(c) { cssCmd('foreColor', c === '#2B3036' ? 'inherit' : c); }
function setMark(c) { cssCmd('hiliteColor', c === '' ? 'transparent' : c); }
function setBlock(v) { cmd('formatBlock', v); }
function insertHtml(html) { el.value.focus(); document.execCommand('insertHTML', false, html); sync(); pop.value = null; }
function insertTable(rows, cols) {
    // Рамки — прямо в атрибутах стиля: у получателя нет наших таблиц стилей, а без рамок таблица «рассыпается».
    const td = 'border: 1px solid #cfcbc4; padding: 4px 8px; min-width: 40px';
    const row = '<tr>' + '<td style="' + td + '"><br></td>'.repeat(cols) + '</tr>';
    insertHtml('<table style="border-collapse: collapse; margin: 6px 0">' + row.repeat(rows) + '</table><p><br></p>');
}
function insertEmoji(e) { insertHtml(e + ' '); }

// Пустое тело письма определяем по содержимому, а не правилом :empty: шаблон ответа
// начинается с пустого абзаца, элемент формально не пуст, и подсказка не показывалась.
const blank = ref(true);
function checkBlank() {
    const node = el.value;
    if (!node) { blank.value = true; return; }
    if (node.querySelector('img, blockquote, table, div.sig, div.quote, div.fwd')) { blank.value = false; return; }
    blank.value = node.textContent.replace(/\u00a0/g, ' ').trim() === '';
}

function sync() {
    emit('update:modelValue', el.value.innerHTML);
    checkBlank();
}

function cmd(name, value = null) {
    el.value.focus();
    document.execCommand(name, false, value);
    sync();
    refresh();
}

function link() {
    let url = window.prompt('Адрес ссылки', 'https://');
    if (!url || url === 'https://') return;
    url = url.trim();
    // «www.site.ru» без схемы браузер считает относительной ссылкой — она ведёт внутрь почты.
    if (!/^[a-z][a-z0-9+.-]*:/i.test(url)) url = 'https://' + url.replace(/^\/+/, '');
    const sel = window.getSelection();
    if (!sel || sel.isCollapsed) {
        // Ничего не выделено: раньше команда просто ничего не делала. Вставляем саму ссылку текстом.
        el.value.focus();
        const safe = url.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
        document.execCommand('insertHTML', false, `<a href="${safe}">${safe}</a>&nbsp;`);
        sync();
        return;
    }
    cmd('createLink', url);
}

function refresh() {
    const q = (n) => { try { return document.queryCommandState(n); } catch { return false; } };
    const v = (n) => { try { return String(document.queryCommandValue(n) || ''); } catch { return ''; } };
    const block = v('formatBlock').toLowerCase();
    const font = v('fontName').replace(/["']/g, '').toLowerCase();
    // Размер: у выделенного текста смотрим вычисленный font-size — queryCommandValue('fontSize') отдаёт 1–7 и только для <font>.
    let size = '';
    const node = window.getSelection()?.anchorNode;
    const elem = node ? (node.nodeType === 1 ? node : node.parentElement) : null;
    if (elem && el.value?.contains(elem)) {
        const fs = elem.closest('[style*="font-size"]');
        const m = fs && el.value.contains(fs) ? /font-size:\s*([a-z-]+)/i.exec(fs.getAttribute('style') || '') : null;
        size = m ? m[1].toLowerCase() : '';
        if (size === 'medium') size = '';
    }
    state.value = {
        bold: q('bold'), italic: q('italic'), underline: q('underline'), strike: q('strikeThrough'),
        align: q('justifyCenter') ? 'center' : q('justifyRight') ? 'right' : 'left',
        block: /^h[1-3]$/.test(block) ? block : 'p',
        font: font.includes('georgia') ? FONTS[1][0] : font.includes('courier') ? FONTS[2][0] : '',
        size: ['small', 'large', 'xx-large'].includes(size) ? size : '',
    };
}

function onKey(e) {
    if (!(e.ctrlKey || e.metaKey)) return;
    // По физической клавише: в русской раскладке e.key даёт «ы» и «л» вместо «s» и «k».
    if (e.key === 'Enter') { e.preventDefault(); emit('submit'); return; }
    if (e.code === 'KeyS') { e.preventDefault(); emit('save'); return; }
    if (e.code === 'KeyK') { e.preventDefault(); link(); return; }
    // Как в Gmail: Ctrl+Shift+7/8 — списки, Ctrl+Shift+L/E/R — выравнивание, Ctrl+\ — убрать оформление.
    if (e.shiftKey && e.code === 'Digit7') { e.preventDefault(); cmd('insertOrderedList'); return; }
    if (e.shiftKey && e.code === 'Digit8') { e.preventDefault(); cmd('insertUnorderedList'); return; }
    if (e.shiftKey && e.code === 'KeyL') { e.preventDefault(); cmd('justifyLeft'); return; }
    if (e.shiftKey && e.code === 'KeyE') { e.preventDefault(); cmd('justifyCenter'); return; }
    if (e.shiftKey && e.code === 'KeyR') { e.preventDefault(); cmd('justifyRight'); return; }
    if (e.code === 'Backslash') { e.preventDefault(); cmd('removeFormat'); }
}

// Что оставляем при вставке из Word, Excel и с сайтов: смысл разметки без чужого оформления.
const KEEP = new Set(['A', 'B', 'STRONG', 'I', 'EM', 'U', 'S', 'STRIKE', 'BR', 'P', 'DIV', 'SPAN',
    'UL', 'OL', 'LI', 'BLOCKQUOTE', 'TABLE', 'THEAD', 'TBODY', 'TR', 'TD', 'TH',
    'H1', 'H2', 'H3', 'H4', 'H5', 'H6', 'PRE', 'CODE', 'HR']);

/**
 * Почистить вставляемую разметку: раньше всё сводилось к простому тексту, и из Word
 * пропадали ссылки, списки и таблицы. Теперь остаётся структура, а шрифты, цвета,
 * классы и служебные теги Office — нет. Разбираем через DOMParser: скрипты в нём не выполняются.
 */
function cleanHtml(raw) {
    const doc = new DOMParser().parseFromString(raw, 'text/html');
    const walk = (node) => {
        [...node.children].forEach(walk);
        const tag = node.tagName;
        if (!KEEP.has(tag)) {
            // Тег не нужен, а текст внутри нужен: разворачиваем содержимое на место тега.
            node.replaceWith(...node.childNodes);
            return;
        }
        [...node.attributes].forEach((a) => {
            const n = a.name.toLowerCase();
            const ok = (tag === 'A' && n === 'href' && /^(https?:|mailto:|tel:)/i.test(a.value))
                || (tag === 'TD' || tag === 'TH') && (n === 'colspan' || n === 'rowspan');
            if (!ok) node.removeAttribute(a.name);
        });
        if (tag === 'A') node.setAttribute('target', '_blank');
        if (tag === 'SPAN' && !node.attributes.length) node.replaceWith(...node.childNodes);
    };
    [...doc.body.children].forEach(walk);
    return doc.body.innerHTML.replace(/<!--[\s\S]*?-->/g, '').replace(/\u00a0/g, ' ');
}

function onPaste(e) {
    // Картинка из буфера (снимок экрана, логотип) — вставляем как картинку.
    const img = [...(e.clipboardData?.files || [])].find((f) => f.type.startsWith('image/'));
    if (img) { e.preventDefault(); insertImage(img); return; }
    const rich = e.clipboardData?.getData('text/html');
    if (rich && rich.trim()) {
        e.preventDefault();
        document.execCommand('insertHTML', false, cleanHtml(rich));
        sync();
        emit('toast', { text: 'Вставлено с оформлением. Кнопка «Убрать форматирование» снимет его' });
        return;
    }
    const text = e.clipboardData?.getData('text/plain');
    if (text) {
        e.preventDefault();
        document.execCommand('insertText', false, text);
        sync();
    }
}

// Картинка в тексте (подпись с логотипом и т. п.): встраивается как data: — при отправке сервер превращает её
// во вложение письма (cid), чтобы показывали все почтовые программы. Ограничение — 400 КБ на картинку.
const fileInput = ref(null);
function pickImage() { fileInput.value?.click(); }
function onImageFile(e) { const f = e.target.files?.[0]; e.target.value = ''; if (f) insertImage(f); }
function insertImage(file) {
    if (!file.type.startsWith('image/')) return;
    if (file.size > 400 * 1024) {
        emit('toast', { text: 'Картинка больше 400 КБ — уменьшите её: для подписи хватает ширины 300–400 точек', error: true });
        return;
    }
    const r = new FileReader();
    r.onload = () => { el.value.focus(); document.execCommand('insertHTML', false, `<img src="${r.result}" alt="" style="max-width: 100%; height: auto">`); sync(); };
    r.readAsDataURL(file);
}

onMounted(() => {
    el.value.innerHTML = props.modelValue || '';
    checkBlank();
});
watch(() => props.modelValue, (v) => {
    if (el.value && el.value.innerHTML !== v) el.value.innerHTML = v || '';
    checkBlank();
});

// Картинка из текста исходного письма — ссылка на наш же сервер (см. MessageBody).
const SERVER_IMG = /^\/mail\/api\/message\/.+\/attachment\/\d+/;
const asDataUrl = (blob) => new Promise((ok, no) => { const fr = new FileReader(); fr.onload = () => ok(fr.result); fr.onerror = no; fr.readAsDataURL(blob); });

defineExpose({
    focus: () => el.value?.focus(),
    /**
     * Подставить сами картинки вместо ссылок на них. Ссылка в письме не годится: получатель её
     * не откроет, а у черновика после сохранения меняется номер, и картинка в окне ломалась.
     * Возвращает, сколько подставлено; что не вышло — встроит сервер при сохранении (MailBuilder).
     */
    embedServerImages: async (beforeSync) => {
        const root = el.value;
        if (!root) return 0;
        const imgs = [...root.querySelectorAll('img')].filter((i) => SERVER_IMG.test(i.getAttribute('src') || ''));
        let n = 0;
        await Promise.all(imgs.map(async (img) => {
            try {
                const r = await fetch(img.getAttribute('src'), { credentials: 'same-origin' });
                if (!r.ok) return;
                const b = await r.blob();
                if (!b.type.startsWith('image/') || b.size > 10_000_000) return;
                img.setAttribute('src', await asDataUrl(b));
                n++;
            } catch { /* останется ссылкой — встроит сервер */ }
        }));
        if (n) { beforeSync?.(); sync(); }

        return n;
    },
    /**
     * Заменить только блок подписи, не переписывая поле целиком: смена отправителя
     * переписывала весь текст, курсор прыгал в начало, а история отмены (Ctrl+Z) стиралась.
     */
    setSignature: (sigHtml) => {
        const root = el.value;
        if (!root) return false;
        let sig = root.querySelector('div.sig');
        if (sigHtml) {
            if (sig) {
                sig.innerHTML = sigHtml;
            } else {
                sig = document.createElement('div');
                sig.className = 'sig';
                sig.innerHTML = sigHtml;
                const gap = document.createElement('p');
                gap.innerHTML = '<br>';
                const anchor = root.querySelector('div.quote, div.fwd');
                if (anchor) { root.insertBefore(gap, anchor); root.insertBefore(sig, anchor); } else { root.appendChild(gap); root.appendChild(sig); }
            }
        } else if (sig) {
            sig.remove();
        }
        sync();

        return true;
    },
    focusStart: () => {
        el.value?.focus();
        const sel = window.getSelection(); const range = document.createRange();
        range.setStart(el.value, 0); range.collapse(true); sel.removeAllRanges(); sel.addRange(range);
    },
});
</script>

<template>
    <div v-if="$slots.right" class="compose__tools compose__tools--top">
        <span class="grow" />
        <slot name="right" />
    </div>
    <div
        ref="el"
        class="compose__editor"
        contenteditable="true"
        role="textbox"
        aria-multiline="true"
        aria-label="Текст письма"
        :class="{ 'compose__editor--blank': blank }"
        :data-placeholder="placeholder"
        spellcheck="true"
        @input="sync"
        @keyup="refresh"
        @mouseup="refresh"
        @keydown="onKey"
        @paste="onPaste"
    />
    <!-- Панель оформления — под текстом, над «Отправить». Нажатие мышью по кнопке уводит фокус из поля,
         и выделение схлопывается: команда применялась бы к месту курсора. Гасим перевод фокуса
         (@mousedown.prevent) — выделение остаётся; select и всплывашки исключены, им фокус нужен. -->
    <div v-if="expanded" class="compose__tools compose__tools--fmt" data-testid="fmt">
        <div class="fmt fmt__row" role="toolbar" aria-label="Оформление: шрифт и начертание" @mousedown="$event.target.closest('select, .fmt__pop') || $event.preventDefault()">
            <select class="fmt__sel" :value="state.font" aria-label="Шрифт" title="Шрифт" @change="setFont($event.target.value)"><option v-for="[v, n] in FONTS" :key="n" :value="v">{{ n }}</option></select>
            <select class="fmt__sel" :value="state.size" aria-label="Размер" title="Размер текста" @change="setSize($event.target.value)"><option v-for="[v, n] in SIZES" :key="n" :value="v">{{ n }}</option></select>
            <span class="v" />
            <button type="button" :class="{ on: state.bold }" title="Жирный (Ctrl+B)" aria-label="Жирный" @click="cmd('bold')"><Icon name="bold" :size="15" /></button>
            <button type="button" :class="{ on: state.italic }" title="Курсив (Ctrl+I)" aria-label="Курсив" @click="cmd('italic')"><Icon name="italic" :size="15" /></button>
            <button type="button" :class="{ on: state.underline }" title="Подчёркнутый (Ctrl+U)" aria-label="Подчёркнутый" @click="cmd('underline')"><Icon name="underline" :size="15" /></button>
            <button type="button" :class="{ on: state.strike }" title="Зачёркнутый" aria-label="Зачёркнутый" @click="cmd('strikeThrough')"><Icon name="strike" :size="15" /></button>
            <span class="v" />
            <span class="fmt__wrap">
                <button type="button" class="fmt__popbtn" :class="{ on: pop === 'color' }" title="Цвет текста" aria-label="Цвет текста" aria-haspopup="true" @click="togglePop('color')"><Icon name="fontcolor" :size="15" /></button>
                <div v-if="pop === 'color'" class="fmt__pop" role="menu" aria-label="Цвет текста">
                    <button v-for="c in COLORS" :key="c" type="button" class="fmt__swatch" :style="{ background: c }" :title="c === '#2B3036' ? 'Обычный' : c" :aria-label="'Цвет ' + c" @click="setColor(c)" />
                </div>
            </span>
            <span class="fmt__wrap">
                <button type="button" class="fmt__popbtn" :class="{ on: pop === 'mark' }" title="Выделение маркером" aria-label="Выделение маркером" aria-haspopup="true" @click="togglePop('mark')"><Icon name="marker" :size="15" /></button>
                <div v-if="pop === 'mark'" class="fmt__pop" role="menu" aria-label="Выделение">
                    <button v-for="c in MARKS" :key="c" type="button" class="fmt__swatch" :style="{ background: c }" :title="c" :aria-label="'Выделить ' + c" @click="setMark(c)" />
                    <button type="button" class="fmt__swatch fmt__swatch--none" title="Без выделения" aria-label="Без выделения" @click="setMark('')"><Icon name="x" :size="12" /></button>
                </div>
            </span>
            <span class="v" />
            <button type="button" title="Ссылка (Ctrl+K)" aria-label="Вставить ссылку" @click="link"><Icon name="link" :size="15" /></button>
            <button type="button" title="Картинка (файл или вставка из буфера)" aria-label="Вставить картинку" @click="pickImage"><Icon name="img" :size="15" /></button>
            <input ref="fileInput" type="file" accept="image/*" style="display: none" @change="onImageFile">
            <span class="fmt__wrap">
                <button type="button" class="fmt__popbtn" :class="{ on: pop === 'emoji' }" title="Смайлик" aria-label="Смайлик" aria-haspopup="true" @click="togglePop('emoji')"><Icon name="smile" :size="15" /></button>
                <div v-if="pop === 'emoji'" class="fmt__pop fmt__pop--emoji" role="menu" aria-label="Смайлики">
                    <button v-for="e in EMOJI" :key="e" type="button" class="fmt__emoji" :aria-label="e" @click="insertEmoji(e)">{{ e }}</button>
                </div>
            </span>
            <span class="grow" />
            <button type="button" title="Убрать форматирование (Ctrl+\)" aria-label="Убрать форматирование" @click="cmd('removeFormat')"><Icon name="eraser" :size="15" /></button>
        </div>
        <div class="fmt fmt__row" role="toolbar" aria-label="Оформление: абзац" @mousedown="$event.target.closest('select, .fmt__pop') || $event.preventDefault()">
            <button type="button" :class="{ on: state.align === 'left' }" title="По левому краю (Ctrl+Shift+L)" aria-label="По левому краю" @click="cmd('justifyLeft')"><Icon name="alignl" :size="15" /></button>
            <button type="button" :class="{ on: state.align === 'center' }" title="По центру (Ctrl+Shift+E)" aria-label="По центру" @click="cmd('justifyCenter')"><Icon name="alignc" :size="15" /></button>
            <button type="button" :class="{ on: state.align === 'right' }" title="По правому краю (Ctrl+Shift+R)" aria-label="По правому краю" @click="cmd('justifyRight')"><Icon name="alignr" :size="15" /></button>
            <span class="v" />
            <button type="button" title="Список (Ctrl+Shift+8)" aria-label="Маркированный список" @click="cmd('insertUnorderedList')"><Icon name="ul" :size="15" /></button>
            <button type="button" title="Нумерованный список (Ctrl+Shift+7)" aria-label="Нумерованный список" @click="cmd('insertOrderedList')"><Icon name="ol" :size="15" /></button>
            <button type="button" title="Уменьшить отступ" aria-label="Уменьшить отступ" @click="cmd('outdent')"><Icon name="outdent" :size="15" /></button>
            <button type="button" title="Увеличить отступ" aria-label="Увеличить отступ" @click="cmd('indent')"><Icon name="indent" :size="15" /></button>
            <span class="v" />
            <button type="button" title="Цитата" aria-label="Цитата" @click="cmd('formatBlock', 'blockquote')"><Icon name="quote" :size="15" /></button>
            <span class="fmt__wrap">
                <button type="button" class="fmt__popbtn" :class="{ on: pop === 'table' }" title="Таблица" aria-label="Вставить таблицу" aria-haspopup="true" @click="togglePop('table'); tableHover = [0, 0]"><Icon name="table" :size="15" /></button>
                <div v-if="pop === 'table'" class="fmt__pop fmt__pop--table" role="menu" aria-label="Размер таблицы">
                    <div class="fmt__grid" @mouseleave="tableHover = [0, 0]">
                        <template v-for="r in 8" :key="r"><button v-for="c in 8" :key="c" type="button" class="fmt__cell" :class="{ on: r <= tableHover[0] && c <= tableHover[1] }" :aria-label="`${r} × ${c}`" @mouseenter="tableHover = [r, c]" @focus="tableHover = [r, c]" @click="insertTable(r, c)" /></template>
                    </div>
                    <div class="fmt__gridhint">{{ tableHover[0] ? `${tableHover[0]} × ${tableHover[1]}` : 'строк × столбцов' }}</div>
                </div>
            </span>
            <button type="button" title="Горизонтальная линия" aria-label="Горизонтальная линия" @click="cmd('insertHorizontalRule')"><Icon name="hr" :size="15" /></button>
            <button type="button" :class="{ on: state.block === 'pre' }" title="Моноширинный блок (код, номера)" aria-label="Моноширинный блок" @click="cmd('formatBlock', 'pre')"><Icon name="code" :size="15" /></button>
            <span class="v" />
            <select class="fmt__sel" :value="state.block" aria-label="Заголовок" title="Заголовок" @change="setBlock($event.target.value)"><option v-for="[v, n] in BLOCKS" :key="v" :value="v">{{ n }}</option></select>
        </div>
    </div>
</template>
