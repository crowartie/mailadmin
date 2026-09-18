// Что показывать и откуда это брать: выбранный диапазон, заголовок, загрузка событий
// и списка календарей, тихое обновление.
//
// Отдельно потому, что это единственная часть календаря, которая ходит на сервер за
// событиями, и единственная, где заведён повторяющийся таймер. Когда всё это лежало
// вперемешку с сеткой и формой, «почему календарь дёргается» и «почему видно не то»
// приходилось искать по всему файлу.
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { DAYS_FULL, MONTHS, MONTHS_N, addDays, day0, hex6, monday, parseDay } from './dates';
import { api } from './api';

/** Как часто перечитывать события: чужие правки и приглашения должны появляться сами. */
const REFRESH_MS = 300000;

/**
 * @param {object} ctx
 * @param {import('vue').Ref} ctx.view     day | week | month | agenda
 * @param {import('vue').Ref} ctx.anchor   опорная дата вида
 * @param {import('vue').Ref} ctx.editing  открытая форма: при ней не обновляем
 * @param {import('vue').Ref} ctx.dialog   открытое подтверждение: тоже не обновляем
 * @param {(e: unknown) => void} ctx.fail
 */
export function useCalendarData(ctx) {
    const events = ref([]);
    const calendars = ref([]);
    const loading = ref(false);

    /** Границы показанного: от них зависит и запрос, и раскладка. */
    const range = computed(() => {
        const a = ctx.anchor.value;
        if (ctx.view.value === 'day') return { from: day0(a), to: addDays(a, 1) };
        if (ctx.view.value === 'week') return { from: monday(a), to: addDays(monday(a), 7) };
        if (ctx.view.value === 'month') {
            const first = new Date(a.getFullYear(), a.getMonth(), 1);
            const from = monday(first);

            return { from, to: addDays(from, 42) };
        }

        return { from: day0(a), to: addDays(a, 30) };
    });

    const days = computed(() => {
        const out = [];
        const n = ctx.view.value === 'day' ? 1 : ctx.view.value === 'week' ? 7 : ctx.view.value === 'month' ? 42 : 30;
        for (let i = 0; i < n; i++) out.push(addDays(range.value.from, i));

        return out;
    });

    const heading = computed(() => {
        const a = ctx.anchor.value;
        const r = range.value;
        if (ctx.view.value === 'day') return `${a.getDate()} ${MONTHS[a.getMonth()]}, ${DAYS_FULL[(a.getDay() + 6) % 7]}`;
        if (ctx.view.value === 'month') return `${MONTHS_N[a.getMonth()]} ${a.getFullYear()}`;
        const last = addDays(r.to, -1);
        if (ctx.view.value === 'agenda') return `${a.getDate()} ${MONTHS[a.getMonth()]} — ${last.getDate()} ${MONTHS[last.getMonth()]}`;

        return r.from.getMonth() === last.getMonth()
            ? `${r.from.getDate()} — ${last.getDate()} ${MONTHS[last.getMonth()]}`
            : `${r.from.getDate()} ${MONTHS[r.from.getMonth()]} — ${last.getDate()} ${MONTHS[last.getMonth()]}`;
    });

    async function load() {
        loading.value = true;
        try {
            const list = await api.events(range.value.from.toISOString(), range.value.to.toISOString());
            events.value = list.map((e) => ({
                ...e,
                color: hex6(e.color),
                s: e.allDay ? parseDay(e.start) : new Date(e.start),
                e: e.allDay ? parseDay(e.end) : new Date(e.end),
            }));
        } catch (e) {
            ctx.fail(e);
        } finally {
            loading.value = false;
        }
    }

    async function reloadCalendars() {
        try {
            calendars.value = (await api.calendars()).map((c) => ({ ...c, color: hex6(c.color) }));
        } catch (e) {
            ctx.fail(e);
        }
    }

    watch([ctx.view, ctx.anchor], () => {
        localStorage.setItem('cal.view', ctx.view.value);
        load();
    });

    // Тихое обновление: новые приглашения и чужие правки должны появляться сами, как
    // в списке писем. Форму и открытое подтверждение не трогаем — иначе выдернем из-под рук.
    let timer = null;
    onMounted(() => {
        timer = setInterval(() => {
            if (document.hidden || ctx.editing.value || ctx.dialog.value) return;
            load();
        }, REFRESH_MS);
    });
    onBeforeUnmount(() => clearInterval(timer));

    return { events, calendars, loading, range, days, heading, load, reloadCalendars };
}
