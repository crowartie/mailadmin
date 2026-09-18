import { api } from './api';

/**
 * Живое обновление списка: опрос сервера, счётчик в заголовке вкладки,
 * уведомления браузера о новых письмах и напоминаниях календаря.
 *
 * Опрос молчит, пока человек пишет письмо или держит открытым меню: перезагрузка
 * списка в этот момент сбрасывала бы выбор прямо под руками.
 *
 * @param {object} ctx состояние страницы: folders, folder, list, settings, compose, menu,
 *                     и действия load, openMessage, showToast
 */
export function useLiveUpdates(ctx) {
    let lastUidnext = null;
    let lastPoll = 0;

    // В приватном окне и при запрете данных сайта обращение к хранилищу бросает исключение —
    // без защиты страница почты не отрисовывалась вовсе.
    const shownReminders = new Set((() => {
        try {
            return JSON.parse(localStorage.getItem('mail.reminders.shown') || '[]');
        } catch {
            return [];
        }
    })());

    function canNotify() {
        return ctx.settings.value.notify_browser && typeof Notification !== 'undefined' && Notification.permission === 'granted';
    }

    function notify(title, body, tag, onclick) {
        if (!canNotify()) return;
        try {
            const n = new Notification(title, { body, tag, icon: '/favicon.ico' });
            n.onclick = () => { window.focus(); onclick?.(); n.close(); };
            setTimeout(() => n.close(), 15000);
        } catch {
            /* уведомления запрещены на ходу */
        }
    }

    async function poll() {
        if (document.visibilityState !== 'visible' && Date.now() - lastPoll < 60000) return;
        if (ctx.compose.value || ctx.menu.value) return;
        lastPoll = Date.now();
        try {
            const st = await api.status(ctx.folder.value);
            const inbox = ctx.folders.value.find((f) => f.role === 'inbox');
            // Счётчик во вкладке пересчитывается сам: он вычисляется из этого же числа.
            if (inbox && inbox.unread !== st.inboxUnseen) inbox.unread = st.inboxUnseen;
            const cur = ctx.folders.value.find((f) => f.path === ctx.folder.value);
            if (cur) { cur.unread = st.folder.unseen; cur.total = st.folder.messages; }
            if (lastUidnext !== null && st.folder.uidnext > lastUidnext) {
                const prev = lastUidnext;
                // Тихая перезагрузка: обычная сбрасывала галочки и на секунду гасила список,
                // а письмо приходит как раз тогда, когда человек отмечает пачку.
                await ctx.load(ctx.list.value.page, true, true);
                // Сервер отдаёт признак «прочитано» (seen); поля unread в ответе нет никогда,
                // поэтому список новых всегда получался пустым и уведомления не приходили.
                const fresh = (ctx.list.value.messages || []).filter((m) => m.uid >= prev && !m.seen);
                fresh.slice(0, 3).forEach((m) => notify(
                    m.from?.name || m.from?.mail || 'Новое письмо',
                    m.subject || '(без темы)',
                    'mail-' + m.uid,
                    () => ctx.openMessage(m.uid),
                ));
                if (fresh.length > 3) notify('Новые письма', `и ещё ${fresh.length - 3}`, 'mail-more');
            }
            lastUidnext = st.folder.uidnext;
            for (const r of st.reminders || []) {
                if (shownReminders.has(r.key)) continue;
                shownReminders.add(r.key);
                try {
                    localStorage.setItem('mail.reminders.shown', JSON.stringify([...shownReminders].slice(-200)));
                } catch {
                    /* приватное окно */
                }
                const t = r.allDay ? 'сегодня' : new Date(r.start).toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
                notify(
                    'Напоминание: ' + r.title,
                    (r.allDay ? 'Весь день' : 'В ' + t) + (r.location ? ' · ' + r.location : ''),
                    'rem-' + r.key,
                    () => { window.location.href = '/calendar'; },
                );
                ctx.showToast({ text: 'Напоминание: ' + r.title + ' — ' + t }, 8000);
            }
        } catch {
            /* сеть моргнула — следующий опрос через минуту */
        }
    }

    /** После смены папки прежний UIDNEXT ничего не значит: в новой папке своя нумерация. */
    function resetUidnext() {
        lastUidnext = null;
    }

    return { poll, notify, resetUidnext };
}
