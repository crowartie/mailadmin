// Перенос и растягивание события мышью по сетке календаря.
//
// Курсор-крестик над сеткой обещал перетаскивание, а его не было вовсе: чтобы сдвинуть
// встречу на час, приходилось открывать форму и править время руками. Тянем по сетке
// с шагом в четверть часа; событие двигается за курсором, сохраняется по отпусканию.
//
// Отдельно потому, что это единственное место, где страница слушает мышь глобально:
// подписки на window легко пережить страницу, и держать их рядом с формой события
// и задачами — значит каждый раз проверять, не забыли ли отписаться.
import { ref } from 'vue';
import { HOUR } from './useCalendarLayout';
import { api } from './api';

const STEP = 15;   // минут — шаг сетки при переносе

/**
 * @param {object} ctx
 * @param {import('vue').Ref} ctx.events  список событий (правим на месте для мгновенного отклика)
 * @param {import('vue').Ref} ctx.view    текущий вид: перенос по дням есть только в неделе
 * @param {(t: string, err?: boolean) => void} ctx.say
 * @param {(e: unknown) => void} ctx.fail
 * @param {() => Promise<void>} ctx.load  перечитать события после сохранения
 */
export function useEventDrag(ctx) {
    const drag = ref(null);

    /** Тянут ли прямо сейчас именно это событие. */
    function dragging(e) {
        const d = drag.value;

        return !!(d && d.moved && d.id === e.id && d.calendar === e.calendar);
    }

    function startDrag(ev, item, mode) {
        const e = item.e;
        if (ev.button !== 0) return;
        if (e.readonly) { ctx.say('Этот календарь только для чтения', true); return; }
        if (e.recurring) { ctx.say('У повторяющегося события время меняется в правке — откройте его', true); return; }
        ev.preventDefault();
        drag.value = {
            id: e.id, calendar: e.calendar, mode, moved: false,
            x0: ev.clientX, y0: ev.clientY,
            s0: new Date(e.s), e0: new Date(e.e),
            s: new Date(e.s), end: new Date(e.e),
            colW: ev.currentTarget.closest('.cal__col')?.getBoundingClientRect().width || 0,
            src: e,
        };
        window.addEventListener('pointermove', onDrag);
        window.addEventListener('pointerup', endDrag, { once: true });
    }

    function onDrag(ev) {
        const d = drag.value;
        if (!d) return;
        const dy = ev.clientY - d.y0;
        const dx = ev.clientX - d.x0;
        const dmin = Math.round((dy / HOUR) * 60 / STEP) * STEP;
        // Перенос на соседний день — по горизонтали, целыми колонками (только в виде недели).
        const dday = d.mode === 'move' && d.colW > 0 && ctx.view.value === 'week' ? Math.round(dx / d.colW) : 0;
        if (Math.abs(dy) > 3 || dday !== 0) d.moved = true;
        if (d.mode === 'move') {
            d.s = new Date(d.s0.getTime() + dmin * 60000 + dday * 86400000);
            d.end = new Date(d.e0.getTime() + dmin * 60000 + dday * 86400000);
        } else {
            const end = new Date(d.e0.getTime() + dmin * 60000);
            // Короче четверти часа встреча быть не может — иначе её не ухватить обратно.
            d.end = end.getTime() - d.s0.getTime() < STEP * 60000 ? new Date(d.s0.getTime() + STEP * 60000) : end;
            d.s = d.s0;
        }
        drag.value = { ...d };
    }

    async function endDrag() {
        window.removeEventListener('pointermove', onDrag);
        const d = drag.value;
        drag.value = null;
        if (!d || !d.moved) return;   // это был обычный щелчок — открыть событие
        if (+d.s === +d.s0 && +d.end === +d.e0) return;
        const e = d.src;
        // Показываем новое время сразу: иначе событие прыгает на старое место и обратно.
        ctx.events.value = ctx.events.value.map((x) => (x.id === e.id && x.calendar === e.calendar ? { ...x, s: d.s, e: d.end } : x));
        try {
            await api.updateEvent(e.calendar, e.id, {
                calendar: e.calendar,
                title: e.title,
                allDay: false,
                location: e.location || '',
                description: e.description || '',
                transparent: !!e.transparent,
                attendees: (e.attendees || []).filter((a) => a.mail).map((a) => ({ name: a.name, mail: a.mail })),
                alarm: e.alarm ?? null,
                start: d.s.toISOString(),
                end: d.end.toISOString(),
            });
            ctx.say((e.attendees || []).some((a) => a.mail) ? 'Время изменено, участники извещены' : 'Время изменено');
        } catch (err) {
            ctx.fail(err);
        }
        await ctx.load();
    }

    /** Вызывать при уходе со страницы: подписка на window живёт дольше компонента. */
    function stopDrag() {
        window.removeEventListener('pointermove', onDrag);
    }

    return { drag, dragging, startDrag, stopDrag };
}
