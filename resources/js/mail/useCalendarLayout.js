// Раскладка календаря: какие события попадают в день, как они расставляются по колонкам
// при пересечении, что показывать в клетке месяца и как собирается повестка.
//
// Это самая «геометрическая» часть календаря и единственная, где легко ошибиться незаметно:
// событие уезжает на пиксель, наложение рисуется внахлёст, многодневное событие пропадает
// из повестки. Отдельно её видно целиком, и меняется она независимо от загрузки, формы
// события и задач.
import { computed } from 'vue';
import { addDays, day0, parseDay, ymd } from './dates';

/** Высота часа в сетке, px. */
export const HOUR = 48;

/**
 * @param {import('vue').Ref} visibleEvents  события видимых календарей
 * @param {import('vue').Ref} range          показанный диапазон {from, to}
 * @param {import('vue').Ref} now            текущее время (для полоски «сейчас»)
 */
export function useCalendarLayout(visibleEvents, range, now) {
    const hours = Array.from({ length: 24 }, (_, i) => i);

    /** События дня без «весь день». */
    function dayEvents(d) {
        const start = day0(d);
        const end = addDays(start, 1);

        return visibleEvents.value.filter((e) => !e.allDay && e.s < end && e.e > start);
    }

    /** События дня, занимающие весь день. */
    function allDayEvents(d) {
        const start = day0(d);
        const end = addDays(start, 1);

        return visibleEvents.value.filter((e) => e.allDay && e.s < end && e.e > start);
    }

    /** Колонки для пересекающихся событий одного дня. */
    function layout(d) {
        const start = day0(d);
        const end = addDays(start, 1);
        const items = dayEvents(d).map((e) => {
            const s = Math.max(0, (Math.max(e.s, start) - start) / 60000);
            const en = Math.min(1440, (Math.min(e.e, end) - start) / 60000);

            return { e, top: s, height: Math.max(22, (en - s) / 60 * HOUR), sMin: s, eMin: Math.max(en, s + 25), col: 0, cols: 1 };
        }).sort((a, b) => a.sMin - b.sMin || b.eMin - a.eMin);

        // Кластеры пересечений → распределение по колонкам.
        let cluster = [];
        let clusterEnd = -1;
        const flush = () => {
            const colsEnd = [];
            cluster.forEach((it) => {
                let c = colsEnd.findIndex((endMin) => endMin <= it.sMin);
                if (c < 0) { c = colsEnd.length; colsEnd.push(0); }
                colsEnd[c] = it.eMin;
                it.col = c;
            });
            cluster.forEach((it) => { it.cols = colsEnd.length; });
            cluster = [];
        };
        items.forEach((it) => {
            if (cluster.length && it.sMin >= clusterEnd) flush();
            cluster.push(it);
            clusterEnd = Math.max(clusterEnd, it.eMin);
        });
        if (cluster.length) flush();

        return items.map((it) => ({
            ...it,
            style: {
                top: (it.top / 60 * HOUR) + 'px',
                height: it.height + 'px',
                left: `calc(${(100 / it.cols) * it.col}% + 2px)`,
                width: `calc(${100 / it.cols}% - 4px)`,
                background: it.e.color + '22',
                borderLeftColor: it.e.color,
                color: 'var(--text)',
            },
        }));
    }

    /** Все события дня для клетки месяца: сначала «весь день», потом по времени. */
    function monthCell(d) {
        const start = day0(d);
        const end = addDays(start, 1);

        return visibleEvents.value.filter((e) => e.s < end && e.e > start).sort((a, b) => (b.allDay - a.allDay) || (a.s - b.s));
    }

    const nowTop = computed(() => (now.value.getHours() * 60 + now.value.getMinutes()) / 60 * HOUR);

    const agendaGroups = computed(() => {
        const m = new Map();
        const from = day0(range.value.from);
        const to = day0(range.value.to);
        // Раньше событие попадало только в день своего начала: командировка с понедельника
        // по пятницу в повестке была видна один раз, в понедельник.
        visibleEvents.value.forEach((e) => {
            let d = day0(e.s) < from ? new Date(from) : day0(e.s);
            const last = e.allDay ? addDays(day0(e.e), -1) : day0(e.e);
            for (let i = 0; i < 62 && d <= last && d < to; i++) {
                const k = ymd(d);
                if (!m.has(k)) m.set(k, []);
                m.get(k).push(e);
                d = addDays(d, 1);
            }
        });

        return [...m.entries()].sort(([a], [b]) => a.localeCompare(b)).map(([k, list]) => ({ day: parseDay(k), list }));
    });

    return { HOUR, hours, dayEvents, allDayEvents, layout, monthCell, nowTop, agendaGroups };
}
