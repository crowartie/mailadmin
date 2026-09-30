<script setup>
// Таблица Excel/ODS/CSV как таблица (просьба 30.09.2026): раньше вложение показывалось PDF'ом на листах А4.
// Данные готовит сервер (SheetPreview): значения так, как их видно в Excel, ширины, высоты, объединения,
// закрепление, шрифт, заливка, границы. Здесь — сетка с буквами столбцов и номерами строк, листы внизу,
// масштаб. Большие листы дорисовываются по мере прокрутки.
import { computed, onMounted, ref, watch } from 'vue';
import Icon from '../Icon.vue';

const props = defineProps({
    src: { type: String, required: true },   // адрес sheet.json
});
const emit = defineEmits(['pdf']);

const data = ref(null);
const error = ref('');
const cur = ref(0);
const zoom = ref(1);
const limit = ref(300);
const scroller = ref(null);

const HEAD_W = 44;   // столбец номеров строк
const HEAD_H = 22;   // строка букв столбцов

async function load() {
    error.value = '';
    data.value = null;
    try {
        const r = await fetch(props.src, { credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
        const j = await r.json().catch(() => ({}));
        if (!r.ok) throw new Error(j.message || 'Не удалось открыть таблицу');
        data.value = j;
        const vis = visibleSheets(j);
        cur.value = vis.includes(j.active) ? j.active : (vis[0] ?? 0);
    } catch (e) {
        error.value = e.message || 'Не удалось открыть таблицу';
    }
}
onMounted(load);
watch(() => props.src, load);
watch(cur, () => { limit.value = 300; if (scroller.value) { scroller.value.scrollTop = 0; scroller.value.scrollLeft = 0; } });

const visibleSheets = (d) => (d?.sheets || []).map((s, i) => (s.hidden ? -1 : i)).filter((i) => i >= 0);
const tabs = computed(() => visibleSheets(data.value).map((i) => ({ i, name: data.value.sheets[i].name, tab: data.value.sheets[i].tab })));
const sheet = computed(() => data.value?.sheets?.[cur.value] || null);

/** «A», …, «Z», «AA» — как в Excel. */
function letter(n) {
    let s = '';
    while (n > 0) { const m = (n - 1) % 26; s = String.fromCharCode(65 + m) + s; n = Math.floor((n - 1) / 26); }
    return s;
}

// Стиль ячейки → CSS, один раз на каждый стиль книги.
const styleCss = computed(() => (data.value?.styles || []).map((s) => {
    const css = {};
    if (s.b) css.fontWeight = '700';
    if (s.i) css.fontStyle = 'italic';
    const deco = [s.u && 'underline', s.x && 'line-through'].filter(Boolean).join(' ');
    if (deco) css.textDecoration = deco;
    if (s.fs) css.fontSize = s.fs + 'pt';
    if (s.fc) css.color = s.fc;
    if (s.fn) css.fontFamily = `"${s.fn}", Calibri, Arial, sans-serif`;
    if (s.bg) css.background = s.bg;
    if (s.bt) css.borderTop = s.bt;
    if (s.br) css.borderRight = s.br;
    if (s.bbo) css.borderBottom = s.bbo;
    if (s.bl) css.borderLeft = s.bl;
    if (s.va) css.verticalAlign = s.va;
    if (s.w) css.whiteSpace = 'pre-wrap';
    if (s.in) css.paddingLeft = (3 + s.in * 9) + 'px';
    return css;
}));

// Всё, что зависит только от листа: видимые столбцы, ширины, ячейки, объединения.
const layout = computed(() => {
    const s = sheet.value;
    if (!s) return null;
    const hc = new Set(s.hiddenCols); const hr = new Set(s.hiddenRows);
    const cols = []; for (let c = 1; c <= s.cols; c++) if (!hc.has(c)) cols.push(c);
    const rows = []; for (let r = 1; r <= s.rows; r++) if (!hr.has(r)) rows.push(r);
    const width = (c) => (s.widths[c] ?? s.defW);
    const height = (r) => (s.heights[r] ?? null);
    const key = (r, c) => r * 256 + c;
    const cells = new Map();
    for (const [r, c, text, st, t] of s.cells) cells.set(key(r, c), { text, st, t });
    const span = new Map(); const covered = new Set();
    for (const [r1, c1, r2, c2] of s.merges) {
        const rs = rows.filter((r) => r >= r1 && r <= r2).length;
        const cs = cols.filter((c) => c >= c1 && c <= c2).length;
        if (!rs || !cs) continue;
        span.set(key(r1, c1), { rs, cs });
        for (let r = r1; r <= r2; r++) for (let c = c1; c <= c2; c++) if (r !== r1 || c !== c1) covered.add(key(r, c));
    }
    // Закреплённые строки и столбцы — «липкие»: их отступы считаем от шапки.
    const [fr, fc] = s.freeze || [0, 0];
    const stickyTop = new Map(); let top = HEAD_H;
    for (const r of rows) { if (r > fr) break; stickyTop.set(r, top); top += height(r) ?? s.defH; }
    const stickyLeft = new Map(); let left = HEAD_W;
    for (const c of cols) { if (c > fc) break; stickyLeft.set(c, left); left += width(c); }
    return { cols, rows, width, height, key, cells, span, covered, stickyTop, stickyLeft, total: HEAD_W + cols.reduce((a, c) => a + width(c), 0) };
});

// Строки, которые рисуем сейчас: большие листы дорисовываются при прокрутке.
const shown = computed(() => {
    const L = layout.value;
    if (!L) return [];
    const st = styleCss.value;
    return L.rows.slice(0, limit.value).map((r) => {
        const tds = [];
        for (let i = 0; i < L.cols.length; i++) {
            const c = L.cols[i];
            const k = L.key(r, c);
            if (L.covered.has(k)) continue;
            const cell = L.cells.get(k);
            const sp = L.span.get(k);
            const s = cell ? (data.value.styles[cell.st] || {}) : {};
            const css = cell ? { ...st[cell.st] } : {};
            // Выравнивание по умолчанию, как в Excel: числа — вправо, ИСТИНА/ЛОЖЬ — по центру, текст — влево.
            css.textAlign = s.ha || (cell?.t === 'n' ? 'right' : cell?.t === 'b' ? 'center' : 'left');
            // Текст без переноса «выливается» на пустые соседние ячейки справа, как в Excel.
            const spill = cell && cell.text && !s.w && !sp && css.textAlign === 'left' && !L.cells.get(L.key(r, L.cols[i + 1]))?.text;
            const sticky = L.stickyTop.has(r) || L.stickyLeft.has(c);
            if (sticky) {
                css.position = 'sticky';
                if (L.stickyTop.has(r)) css.top = L.stickyTop.get(r) + 'px';
                if (L.stickyLeft.has(c)) css.left = L.stickyLeft.get(c) + 'px';
                css.zIndex = L.stickyTop.has(r) && L.stickyLeft.has(c) ? 3 : 2;
                if (!css.background) css.background = '#fff';
            }
            tds.push({ k, text: cell?.text ?? '', css, rs: sp?.rs || 1, cs: sp?.cs || 1, spill });
        }
        const h = L.height(r);
        return { r, h, tds, sticky: L.stickyTop.has(r) };
    });
});

function onScroll() {
    const el = scroller.value;
    const L = layout.value;
    if (!el || !L || limit.value >= L.rows.length) return;
    if (el.scrollTop + el.clientHeight > el.scrollHeight - 600) limit.value = Math.min(L.rows.length, limit.value + 300);
}
function setZoom(z) { zoom.value = Math.min(2, Math.max(0.5, Math.round(z * 10) / 10)); }
function onWheel(e) {
    if (!e.ctrlKey) return;
    e.preventDefault();
    setZoom(zoom.value + (e.deltaY < 0 ? 0.1 : -0.1));
}
</script>

<template>
    <div class="sheet">
        <p v-if="error" class="sheet__msg">{{ error }} <button class="btn btn--sm" type="button" @click="$emit('pdf')">Как при печати</button></p>
        <p v-else-if="!data" class="sheet__msg">Открываю таблицу…</p>
        <template v-else>
            <div v-if="data.truncated || sheet?.drawings" class="sheet__note">
                <template v-if="data.truncated">Показаны первые 5000 строк и 200 столбцов — полностью таблица откроется в Excel после скачивания. </template>
                <template v-if="sheet?.drawings">На листе есть рисунки или диаграммы — их видно в режиме <button type="button" class="linklike" @click="$emit('pdf')">«Как при печати»</button>.</template>
            </div>
            <div ref="scroller" class="sheet__scroll" @scroll.passive="onScroll" @wheel="onWheel">
                <p v-if="sheet && !sheet.cells.length" class="sheet__msg">Лист пустой</p>
                <table v-else-if="layout" class="sheet__grid" :class="{ 'sheet__grid--nogrid': sheet.grid === false }"
                       :style="{ width: layout.total + 'px', zoom, fontFamily: `'${data.font.name}', Calibri, Arial, sans-serif`, fontSize: data.font.size + 'pt' }">
                    <colgroup>
                        <col :style="{ width: 44 + 'px' }">
                        <col v-for="c in layout.cols" :key="c" :style="{ width: layout.width(c) + 'px' }">
                    </colgroup>
                    <thead>
                        <tr>
                            <th class="sheet__corner" />
                            <th v-for="c in layout.cols" :key="c" class="sheet__ch" :style="layout.stickyLeft.has(c) ? { left: layout.stickyLeft.get(c) + 'px', zIndex: 5 } : null">{{ letter(c) }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in shown" :key="row.r" :style="row.h ? { height: row.h + 'px' } : { height: sheet.defH + 'px' }">
                            <th class="sheet__rh" :style="row.sticky ? { top: layout.stickyTop.get(row.r) + 'px', zIndex: 4 } : null">{{ row.r }}</th>
                            <td v-for="td in row.tds" :key="td.k" :rowspan="td.rs > 1 ? td.rs : null" :colspan="td.cs > 1 ? td.cs : null" :style="td.css"><div class="sheet__c" :class="{ 'sheet__c--spill': td.spill }">{{ td.text }}</div></td>
                        </tr>
                    </tbody>
                </table>
                <p v-if="layout && limit < layout.rows.length" class="sheet__more">Прокрутите вниз — дорисую ещё строки ({{ limit }} из {{ layout.rows.length }})</p>
            </div>
            <div class="sheet__bar">
                <div class="sheet__tabs" role="tablist" aria-label="Листы">
                    <button v-for="t in tabs" :key="t.i" type="button" role="tab" class="sheet__tab" :class="{ on: t.i === cur }" :aria-selected="t.i === cur"
                            :style="t.tab ? { boxShadow: `inset 0 -3px 0 ${t.tab}` } : null" @click="cur = t.i">{{ t.name }}</button>
                </div>
                <span class="grow" />
                <button class="ib" type="button" title="Мельче (Ctrl + колесо)" aria-label="Мельче" @click="setZoom(zoom - 0.1)"><Icon name="minus" :size="15" /></button>
                <button class="sheet__zoom" type="button" title="Как было" @click="setZoom(1)">{{ Math.round(zoom * 100) }}%</button>
                <button class="ib" type="button" title="Крупнее (Ctrl + колесо)" aria-label="Крупнее" @click="setZoom(zoom + 0.1)"><Icon name="plus" :size="15" /></button>
            </div>
        </template>
    </div>
</template>
