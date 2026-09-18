// Форма карточки контакта: заготовка, правка, сохранение и фотография.
//
// Здесь же проверки, которых сервер не делает: карточка без имени и без единого способа
// связи раньше сохранялась и появлялась в списке как «Без имени», а снять фотографию было
// нечем — можно было только выбрать другую.
import { api } from './api';

/**
 * @param {object} ctx  общее состояние страницы контактов
 */
export function useContactForm(ctx) {
    const { writableBooks, filter, open, editing, mobileRead, navOpen, loading, history, say, fail, reload } = ctx;

    function blank(book = null) {
        const target = book && writableBooks.value.some((b) => b.uri === book) ? book : (writableBooks.value[0]?.uri || 'personal');
        return { book: target, first: '', last: '', middle: '', nick: '', org: '', department: '', title: '', emails: [{ value: '', type: 'work' }], phones: [{ value: '', type: 'cell' }], addresses: [], birthday: '', url: '', note: '', groups: [], favorite: false, groupsText: '' };
    }
    function create() {
        open.value = null;
        editing.value = blank(filter.value !== 'all' && filter.value !== 'favorites' && !filter.value.startsWith('group:') ? filter.value : null);
        if (filter.value.startsWith('group:')) { editing.value.groups = [filter.value.slice(6)]; editing.value.groupsText = filter.value.slice(6); }
        mobileRead.value = true;
        navOpen.value = false;
    }
    function edit(c) {
        editing.value = {
            ...c, sourceBook: c.book, sourceUri: c.uri,
            emails: c.emails?.length ? c.emails.map((e) => ({ ...e })) : [{ value: '', type: 'work' }],
            phones: c.phones?.length ? c.phones.map((p) => ({ ...p })) : [{ value: '', type: 'cell' }],
            addresses: (c.addresses || []).map((a) => ({ ...a })),
            groups: [...(c.groups || [])], groupsText: (c.groups || []).join(', '),
        };
    }
    async function save() {
        const f = editing.value;
        // Ни одно поле не было обязательным, и в списке появлялось «Без имени».
        const hasName = [f.first, f.last, f.middle, f.nick, f.org].some((x) => String(x || '').trim());
        const hasContact = (f.emails || []).some((e) => String(e.value || '').trim())
            || (f.phones || []).some((p) => String(p.value || '').trim());
        if (!hasName && !hasContact) {
            say('Заполните хотя бы имя, организацию, адрес почты или телефон', true);

            return;
        }
        const payload = {
            book: f.book, first: f.first, last: f.last, middle: f.middle, nick: f.nick, org: f.org, department: f.department, title: f.title,
            emails: f.emails.filter((e) => e.value.trim()), phones: f.phones.filter((p) => p.value.trim()), addresses: f.addresses,
            birthday: f.birthday, url: f.url, note: f.note, groups: f.groupsText.split(',').map((s) => s.trim()).filter(Boolean), favorite: !!f.favorite,
        };
        if (f.photo !== undefined) payload.photo = f.photo;
        loading.value = true;
        try {
            const saved = f.sourceUri ? await api.updateContact(f.sourceBook, f.sourceUri, payload) : await api.createContact(payload);
            editing.value = null;
            await reload(false);
            if (f.fromHistory) { history.value = history.value.filter((h) => h.email !== f.fromHistory); }
            open.value = await api.contact(saved.book, saved.uri);
            say('Сохранено');
        } catch (e) { fail(e); } finally { loading.value = false; }
    }
    /** Убрать фото: раньше был только выбор файла, снять его было нечем. */
    function dropPhoto() {
        if (editing.value) editing.value.photo = '';
    }
    function onPhoto(e) {
        const file = e.target.files?.[0];
        if (!file) return;
        if (file.size > 1500000) { say('Фото больше 1,5 МБ — уменьшите его', true); return; }
        const r = new FileReader();
        r.onload = () => { editing.value.photo = r.result; };
        r.readAsDataURL(file);
    }

    return { blank, create, edit, save, dropPhoto, onPhoto };
}
