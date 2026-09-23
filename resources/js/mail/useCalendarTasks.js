// Задачи календаря (VTODO): список, добавление, отметка «сделано», правка и удаление.
//
// Жили внутри Calendar.vue вместе с сеткой недели, формой события и занятостью участников.
// Из-за этого поправить, скажем, подпись срока было нельзя, не листая тысячу строк, и было
// непонятно, что задачи ничего не знают ни о сетке, ни о выбранной неделе. Теперь это видно
// из списка доводов: им нужен только способ сказать об ошибке.
import { computed, ref, watch } from 'vue';
import { api } from './api';
import { ask as confirmAsk } from '../confirm';

/**
 * @param {(e: unknown) => void} fail  показать ошибку человеку
 */
export function useCalendarTasks(fail) {
    const tasks = ref([]);
    const newTask = ref('');
    const newTaskDue = ref('');
    const showDone = ref(false);
    const editTask = ref(null);
    const today = new Date().toISOString().slice(0, 10);

    const openTasks = computed(() => tasks.value.filter((t) => !t.done));
    const visibleTasks = computed(() => (showDone.value ? tasks.value : openTasks.value));

    // Со временем или только дата: при включении добавляем 09:00, при выключении — обрезаем.
    const taskWithTime = ref(false);
    watch(taskWithTime, (on) => {
        const t = editTask.value;
        if (!t) return;
        if (on && t.due && t.due.length <= 10) t.due = t.due + 'T09:00';
        if (!on && t.due && t.due.length > 10) t.due = t.due.slice(0, 10);
    });
    watch(editTask, (t) => { taskWithTime.value = !!(t && t.due && t.due.length > 10); });

    async function loadTasks() {
        try {
            tasks.value = await api.tasks();
        } catch (e) {
            fail(e);
        }
    }

    async function addTask() {
        if (!newTask.value.trim()) return;
        try {
            const t = await api.createTask({ calendar: 'personal', title: newTask.value.trim(), due: newTaskDue.value || null, done: false });
            tasks.value = [t, ...tasks.value];
            newTask.value = '';
            newTaskDue.value = '';
        } catch (e) {
            fail(e);
        }
    }

    async function toggleTask(t) {
        // Галочка рисуется по :checked, а не по модели, поэтому при неудачном сохранении
        // задача оставалась на экране выполненной. Возвращаем состояние сами.
        const was = t.done;
        try {
            const u = await api.updateTask(t.calendar, t.id, { done: !t.done });
            tasks.value = tasks.value.map((x) => (x.id === t.id && x.calendar === t.calendar ? u : x));
        } catch (e) {
            t.done = was;
            tasks.value = [...tasks.value];
            fail(e);
        }
    }

    async function saveTask() {
        const t = editTask.value;
        try {
            const u = await api.updateTask(t.calendar, t.id, { title: t.title, due: t.due || null, description: t.description || '', priority: t.priority || 0 });
            tasks.value = tasks.value.map((x) => (x.id === t.id && x.calendar === t.calendar ? u : x));
            editTask.value = null;
        } catch (e) {
            fail(e);
        }
    }

    async function removeTask(t) {
        // У события и контакта подтверждение есть, а задача удалялась одним кликом и без отмены.
        if (!(await confirmAsk(`Удалить задачу «${t.title}»? Восстановить её будет нельзя.`, { ok: 'Удалить', danger: true }))) return;
        try {
            await api.deleteTask(t.calendar, t.id);
            tasks.value = tasks.value.filter((x) => !(x.id === t.id && x.calendar === t.calendar));
        } catch (e) {
            fail(e);
        }
    }

    /** Срок задачи словами: «сегодня», «завтра», «вчера» или дата. */
    function dueLabel(due) {
        const d = due.slice(0, 10);
        if (d === today) return 'сегодня';
        const t = new Date(d + 'T00:00:00');
        const diff = Math.round((t - new Date(today + 'T00:00:00')) / 86400000);
        if (diff === 1) return 'завтра';
        if (diff === -1) return 'вчера';

        return t.toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' }) + (due.length > 10 ? ' ' + due.slice(11) : '');
    }

    return {
        tasks, newTask, newTaskDue, showDone, editTask, taskWithTime, today,
        openTasks, visibleTasks,
        loadTasks, addTask, toggleTask, saveTask, removeTask, dueLabel,
    };
}
