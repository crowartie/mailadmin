// Действия над карточкой контакта: написать письмо, позвать на встречу, «избранное»,
// копия к себе, предложение в общую книгу, удаление и импорт .vcf.
//
// Отдельно потому, что здесь всё, что меняет чужие данные или уходит наружу: копия
// в общую книгу видна всей компании, удаление необратимо, импорт добавляет разом сотни
// карточек. Такие вещи должны читаться списком, а не выискиваться среди отрисовки.
import { api } from './api';

/**
 * @param {object} ctx  общее состояние страницы контактов
 */
export function useContactActions(ctx) {
    const { books, all, open, menu, dialog, loading, editing, history, say, fail, reload, go } = ctx;

    function menuFor(e, c) {
        e.preventDefault?.();
        const r = e.currentTarget?.getBoundingClientRect?.();
        menu.value = { x: e.clientX || (r ? r.left : 100), y: e.clientY || (r ? r.bottom + 4 : 100), c };
    }
    function write(c) {
        const mail = c.email || c.emails?.[0]?.value;
        if (!mail) { say('У контакта нет адреса почты', true); return; }
        // Имя с запятой или кавычками («ООО "Ромашка", Иванов») разъезжалось на двух
        // получателей — берём ту же функцию экранирования, что и в самом письме.
        const to = c.fn && c.fn !== mail ? `${quoteName(c.fn)} <${mail}>` : mail;
        router.visit(`/mail?compose=1&to=${encodeURIComponent(to)}`);
    }
    function meeting(c) {
        const mail = c.email || c.emails?.[0]?.value;
        router.visit(`/calendar?new=1&attendees=${encodeURIComponent(mail || '')}&title=${encodeURIComponent('Встреча: ' + c.fn)}`);
    }
    async function toggleFavorite(c) {
        menu.value = null;
        if (c.readonly) { say('Сначала скопируйте контакт к себе — тогда можно и в избранное', true); return; }
        try {
            const full = await api.contact(c.book, c.uri);
            const saved = await api.updateContact(c.book, c.uri, { ...full, favorite: !full.favorite });
            const row = all.value.find((x) => x.uri === c.uri && x.book === c.book);
            if (row) row.favorite = saved.favorite;
            if (open.value?.uri === c.uri) open.value.favorite = saved.favorite;
        } catch (e) { fail(e); }
    }
    async function copyToMine(c) {
        menu.value = null;
        try {
            const saved = await api.copyContact(c.book, c.uri, 'personal');
            await reload(false);
            open.value = await api.contact(saved.book, saved.uri);
            say('Скопировано в «Мои контакты»');
        } catch (e) { fail(e); }
    }
    async function suggest(c) {
        menu.value = null;
        try {
            const r = await api.suggestContact(c.book, c.uri);
            say(r.status === 'approved' ? 'Добавлено в «Контакты компании»' : 'Отправлено администратору — появится в общей книге после одобрения');
            if (r.status === 'approved') reload();
        } catch (e) { fail(e); }
    }
    function askDelete(c) { menu.value = null; dialog.value = { kind: 'delete', c }; }
    async function confirmDialog() {
        const d = dialog.value; dialog.value = null;
        try {
            if (d.kind === 'delete') {
                await api.deleteContact(d.c.book, d.c.uri);
                all.value = all.value.filter((x) => !(x.uri === d.c.uri && x.book === d.c.book));
                if (open.value?.uri === d.c.uri) { open.value = null; mobileRead.value = false; }
                say('Контакт удалён');
            }
        } catch (e) { fail(e); }
    }
    async function importFile(e) {
        const file = e.target.files?.[0];
        e.target.value = '';
        if (!file) return;
        try {
            // Раньше импорт всегда попадал в первую доступную книгу, даже если открыта другая.
            const target = writableBooks.value.find((b) => b.uri === filter.value)?.uri || writableBooks.value[0]?.uri || 'personal';
            const into = writableBooks.value.find((b) => b.uri === target);
            const r = await api.importContacts(file, target);
            say(`Импортировано: ${r.imported}${r.skipped ? `, пропущено как уже имеющиеся: ${r.skipped}` : ''}${into ? ` — в книгу «${into.name}»` : ''}`);
            reload();
        } catch (err) { fail(err); }
    }

    return { menuFor, write, meeting, toggleFavorite, copyToMine, suggest, askDelete, confirmDialog, importFile };
}
