// Форма события: заготовка нового, правка существующего, сборка для отправки на сервер,
// сохранение, удаление и ответ на приглашение.
//
// Самая «правиловая» часть календаря: здесь живут повторы, серии и их отдельные вхождения,
// целодневные события и приглашения. В общем файле каждое такое правило приходилось
// вылавливать среди сетки, задач и перетаскивания — отсюда и прошлые ошибки с сериями
// (вся серия переезжала на день открытой встречи) и с повторами («раз в две недели»
// превращалось в бесконечный еженедельный).
import { nextTick } from 'vue';
import { addDays, ymd } from './dates';
import { toLocalInput } from './format';
import { api } from './api';

const WEEKDAYS = ['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU'];

/**
 * @param {object} ctx
 * @param {import('vue').Ref} ctx.editing    открытая форма (или null)
 * @param {import('vue').Ref} ctx.open       открытая карточка просмотра
 * @param {import('vue').Ref} ctx.dialog     подтверждения
 * @param {import('vue').Ref} ctx.menu       контекстное меню
 * @param {import('vue').Ref} ctx.navOpen    боковая панель на телефоне
 * @param {import('vue').Ref} ctx.loading
 * @param {import('vue').Ref} ctx.events
 * @param {import('vue').Ref} ctx.writable   календари, куда можно писать
 * @param {import('vue').Ref} ctx.attendeesField  поле участников: просим дописать набранное
 * @param {(t: string, err?: boolean) => void} ctx.say
 * @param {(e: unknown) => void} ctx.fail
 * @param {() => Promise<void>} ctx.load
 * @param {() => Promise<void>} ctx.reloadCalendars
 */
export function useEventForm(ctx) {
    /** Пустая форма: через час от текущего момента, на час. */
    function blank(o = {}) {
        const s = o.start || (() => { const x = new Date(); x.setMinutes(0, 0, 0); x.setHours(x.getHours() + 1); return x; })();
        const e = o.end || new Date(s.getTime() + 60 * 60000);

        return {
            calendar: ctx.writable.value.find((c) => c.kind === 'personal')?.uri || ctx.writable.value[0]?.uri || 'personal',
            title: o.title || '', allDay: !!o.allDay, start: toLocalInput(s), end: toLocalInput(e), startDay: ymd(s), endDay: ymd(e),
            location: '', description: o.description || '', attendees: (o.attendees || []).map((m) => ({ name: '', mail: m })),
            alarm: 15, repeat: 'NONE', until: '', transparent: false,
        };
    }

    function create(o = {}) {
        ctx.open.value = null;
        ctx.editing.value = blank(o);
        ctx.navOpen.value = false;
    }

    function editEvent(e) {
        if (e.readonly) { ctx.say('Этот календарь только для чтения', true); return; }
        // У серии правим саму серию: её собственные дату и время, а не то вхождение,
        // по которому щёлкнули. Иначе вся серия переезжала на день открытой встречи.
        const ms = e.recurring && e.masterStart ? new Date(e.masterStart) : e.s;
        const me = e.recurring && e.masterEnd ? new Date(e.masterEnd) : e.e;
        ctx.editing.value = {
            ...blank(), sourceCal: e.calendar, sourceUri: e.id, calendar: e.calendar, title: e.title, allDay: e.allDay,
            start: toLocalInput(ms), end: toLocalInput(e.allDay ? addDays(me, -1) : me), startDay: ymd(ms), endDay: ymd(e.allDay ? addDays(me, -1) : me),
            location: e.location, description: e.description, attendees: (e.attendees || []).map((a) => ({ name: a.name, mail: a.mail })),
            alarm: e.alarm ?? '', repeat: e.rrule?.freq || 'NONE', until: e.rrule?.until || '', transparent: e.transparent, recurring: e.recurring,
            // Раньше при сохранении всегда строилось «каждую неделю, один день»: «раз в две
            // недели» и «десять раз» превращались в бесконечный еженедельный повтор.
            interval: e.rrule?.interval || 1, count: e.rrule?.count || '', byday: e.rrule?.byday || [],
            occurrenceStart: e.s ? toLocalInput(e.s) : '', occurrenceEnd: e.e ? toLocalInput(e.allDay ? addDays(e.e, -1) : e.e) : '',
            onlyThis: false,
        };
        ctx.open.value = null;
    }

    /** Форма → то, что понимает сервер. */
    function payload(f) {
        const p = {
            calendar: f.calendar, title: f.title, allDay: f.allDay, location: f.location, description: f.description, transparent: f.transparent,
            attendees: f.attendees.filter((a) => a.mail), alarm: f.alarm === '' ? null : Number(f.alarm),
        };
        if (f.allDay) {
            p.start = f.startDay;
            p.end = f.endDay || f.startDay;
        } else {
            p.start = new Date(f.start).toISOString();
            p.end = new Date(f.end).toISOString();
        }
        if (f.repeat !== 'NONE') {
            p.rrule = {
                freq: f.repeat,
                interval: Math.max(1, Number(f.interval) || 1),
                until: f.until || null,
                count: f.count ? Number(f.count) : null,
                byday: f.repeat === 'WEEKLY'
                    ? (f.byday?.length ? f.byday : [WEEKDAYS[(new Date(f.allDay ? f.startDay : f.start).getDay() + 6) % 7]])
                    : [],
            };
        }

        return p;
    }

    async function save() {
        const f = ctx.editing.value;
        // 229: участник, набранный и не подтверждённый Enter, молча пропадал — список
        // читался раньше, чем поле успевало сделать из текста фишку.
        ctx.attendeesField.value?.flush?.();
        await nextTick();
        if (!f.allDay && new Date(f.end) <= new Date(f.start)) { ctx.say('Окончание раньше начала', true); return; }
        // 234: у целодневных проверки не было вовсе, а сервер молча схлопывал диапазон в один день.
        if (f.allDay && f.endDay && f.endDay < f.startDay) { ctx.say('Последний день раньше первого', true); return; }
        ctx.loading.value = true;
        try {
            let saved;
            if (f.sourceUri && f.recurring && f.onlyThis) {
                // 225: отдельной встречи в серии сервер не хранит, поэтому убираем это вхождение
                // из серии и создаём вместо него самостоятельное событие — так это делают
                // и почтовые программы.
                const p = payload(f);
                delete p.rrule;
                await api.deleteEvent(f.sourceCal, f.sourceUri, f.occurrenceStart ? new Date(f.occurrenceStart).toISOString() : null);
                saved = await api.createEvent(p);
            } else {
                saved = f.sourceUri ? await api.updateEvent(f.sourceCal, f.sourceUri, payload(f)) : await api.createEvent(payload(f));
            }
            ctx.editing.value = null;
            await ctx.load();
            ctx.say(saved.attendees?.length ? 'Сохранено, приглашения отправлены' : 'Сохранено');
        } catch (e) {
            ctx.fail(e);
        } finally {
            ctx.loading.value = false;
        }
    }

    function askDelete(e) {
        ctx.menu.value = null;
        ctx.dialog.value = { kind: 'delete', e, mode: e.recurring ? 'one' : 'all' };
    }

    /** Ответ на подтверждение: удаление события или работа с календарём. */
    async function confirmDialog(value) {
        const d = ctx.dialog.value;
        ctx.dialog.value = null;
        try {
            if (d.kind === 'delete') {
                await api.deleteEvent(d.e.calendar, d.e.id, d.mode === 'one' && d.e.recurring ? d.e.start : null);
                ctx.open.value = null;
                ctx.editing.value = null;
                await ctx.load();
                ctx.say('Удалено');
            }
            if (d.kind === 'newCal') {
                await api.createCalendar(value, d.color || '#16A05C');
                await ctx.reloadCalendars();
                ctx.say('Календарь создан');
            }
            if (d.kind === 'renameCal') {
                await api.updateCalendar(d.cal.uri, { name: value });
                await ctx.reloadCalendars();
            }
            if (d.kind === 'deleteCal') {
                await api.deleteCalendar(d.cal.uri);
                await ctx.reloadCalendars();
                await ctx.load();
                ctx.say(d.cal.kind === 'shared' ? 'Отписались от календаря' : 'Календарь удалён');
            }
        } catch (e) {
            ctx.fail(e);
        }
    }

    /** Ответ на приглашение. */
    async function respond(e, status) {
        try {
            await api.respond(e.calendar, e.id, status);
            await ctx.load();
            // Панель закрывалась, и убедиться, что ответ записан, можно было только
            // открыв событие заново. Оставляем её открытой и показываем свежие данные.
            const fresh = ctx.events.value.find((x) => x.id === e.id && x.calendar === e.calendar);
            ctx.open.value = fresh || null;
            ctx.say({ ACCEPTED: 'Вы приняли приглашение', DECLINED: 'Вы отклонили приглашение', TENTATIVE: 'Ответ «под вопросом» отправлен' }[status]);
        } catch (err) {
            ctx.fail(err);
        }
    }

    return { blank, create, editEvent, payload, save, askDelete, confirmDialog, respond };
}
