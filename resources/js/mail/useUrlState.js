/**
 * Адрес страницы = папка + отбор + поиск + порядок + номер страницы.
 *
 * Зачем это нужно: без номера страницы обновление на седьмой странице возвращало
 * на первую, а переход в другую папку писался поверх одной записи истории — и кнопка
 * «Назад» уводила из почты целиком вместо возврата в предыдущую папку.
 *
 * @param {object} ctx folder, filter, query, sort, list, open, mobileRead + load, rolePath
 */
export function useUrlState(ctx) {
    function currentUrl() {
        const p = new URLSearchParams();
        if (ctx.filter.value !== 'all') p.set('filter', ctx.filter.value);
        if (ctx.query.value) p.set('q', ctx.query.value);
        if ((ctx.list.value.page || 1) > 1) p.set('page', String(ctx.list.value.page));
        if (ctx.sort.value !== 'date') p.set('sort', ctx.sort.value);
        const qs = p.toString();

        return `/mail/folder/${encodeURIComponent(ctx.folder.value)}${qs ? '?' + qs : ''}`;
    }

    function changed() {
        return currentUrl() !== window.location.pathname + window.location.search;
    }

    /** Тот же экран, новое состояние: переписываем запись истории. */
    function syncUrl() {
        if (changed()) window.history.replaceState({ mail: true }, '', currentUrl());
    }

    /** Переход в другую папку — новая запись истории, чтобы «Назад» возвращал в прежнюю. */
    function pushUrl() {
        if (changed()) window.history.pushState({ mail: true }, '', currentUrl());
    }

    /** Нажали «Назад» или «Вперёд» — восстанавливаем состояние из адреса. */
    function onPopState() {
        const m = window.location.pathname.match(/^\/mail\/folder\/(.+)$/);
        const sp = new URLSearchParams(window.location.search);
        ctx.folder.value = m ? decodeURIComponent(m[1]) : (ctx.rolePath('inbox') || 'INBOX');
        ctx.filter.value = sp.get('filter') || 'all';
        ctx.query.value = sp.get('q') || '';
        ctx.sort.value = sp.get('sort') || 'date';
        ctx.open.value = null;
        ctx.mobileRead.value = false;
        ctx.load(Number(sp.get('page')) || 1);
    }

    return { currentUrl, syncUrl, pushUrl, onPopState };
}
