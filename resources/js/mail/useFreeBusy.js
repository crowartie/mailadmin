// Занятость участников: полоска под временем события, показывающая, кто и когда уже занят,
// и предупреждение о наложении.
//
// Жило в Calendar.vue вперемешку с сеткой, формой и задачами. Отдельно это понятнее: здесь
// всё зависит ровно от одного — от открытой формы события. Пока формы нет, ничего не
// запрашивается и не считается.
import { computed, ref, watch } from 'vue';
import { addDays, day0 } from './dates';
import { api } from './api';

/**
 * @param {import('vue').Ref} editing  открытая форма события (или null)
 * @param {string} me                  адрес самого сотрудника: своё наложение — не конфликт
 */
export function useFreeBusy(editing, me) {
    const freebusy = ref({});
    let timer = null;

    // Спрашиваем сервер не на каждое нажатие клавиши: адрес дописывают по букве,
    // а запрос занятости — не самый дешёвый.
    watch(() => editing.value && [editing.value.attendees.map((a) => a.mail).join(','), editing.value.start, editing.value.allDay], () => {
        clearTimeout(timer);
        freebusy.value = {};
        const f = editing.value;
        if (!f || f.allDay || !f.attendees.some((a) => a.mail)) return;
        timer = setTimeout(async () => {
            const day = day0(new Date(f.start));
            try {
                freebusy.value = await api.freebusy([me, ...f.attendees.map((a) => a.mail)], day.toISOString(), addDays(day, 1).toISOString());
            } catch {
                // Занятость — подсказка, а не условие сохранения: молча обходимся без неё.
                freebusy.value = {};
            }
        }, 300);
    }, { deep: true });

    /** Полоски занятости одного участника в сутках события, в процентах ширины. */
    function busyBlocks(mail) {
        const f = editing.value;
        if (!f) return [];
        const day = day0(new Date(f.start));

        return (freebusy.value[mail] || []).map((b) => {
            const s = Math.max(0, (new Date(b.start) - day) / 60000);
            const e = Math.min(1440, (new Date(b.end) - day) / 60000);

            return { left: (s / 1440 * 100) + '%', width: (Math.max(e - s, 8) / 1440 * 100) + '%' };
        });
    }

    /** Кто из участников занят в выбранное время. */
    const conflict = computed(() => {
        const f = editing.value;
        if (!f || f.allDay) return [];
        const s = new Date(f.start);
        const e = new Date(f.end);

        // null — занятость неизвестна (внешний адрес): это не повод объявлять время занятым.
        return Object.entries(freebusy.value)
            .filter(([mail, list]) => mail !== me.toLowerCase() && (list || []).some((b) => new Date(b.start) < e && new Date(b.end) > s))
            .map(([mail]) => mail);
    });

    /** Само событие на той же полоске — чтобы было видно, куда оно попадает. */
    const eventWindow = computed(() => {
        const f = editing.value;
        if (!f || f.allDay) return null;
        const day = day0(new Date(f.start));
        const s = (new Date(f.start) - day) / 60000;
        const e = (new Date(f.end) - day) / 60000;

        return { left: (Math.max(0, s) / 1440 * 100) + '%', width: (Math.max(8, Math.min(1440, e) - Math.max(0, s)) / 1440 * 100) + '%' };
    });

    /** Вызывать при уходе со страницы: иначе отложенный запрос уйдёт в никуда. */
    function stopFreeBusy() {
        clearTimeout(timer);
    }

    return { freebusy, busyBlocks, conflict, eventWindow, stopFreeBusy };
}
