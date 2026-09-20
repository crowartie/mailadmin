import { api } from './api';
import { plural } from './format';

/**
 * Действия над письмами и окно отмены.
 *
 * Порядок такой: сначала меняется экран, потом идёт запрос к серверу — интерфейс не ждёт
 * IMAP. Удаление, перенос, архив, «Спам» и «Рассылки» уходят на сервер не сразу, а через
 * N секунд (Настройки → Общие): всё это время «Отменить» просто возвращает список, ничего
 * не отменяя на сервере, потому что там ещё ничего и не произошло.
 *
 * @param {object} ctx list, folder, folders, selected, open, mobileRead, menu, toast,
 *                     settings, folderInfo + showToast, fail, load, refillAfter, bump, undoSend
 */
export function useMessageActions(ctx) {
    /** Отложенное действие: { folder, uids, op, extra, seconds, timer }. */
    let pending = null;

    const NAMES = {
        delete: 'Удалено',
        archive: 'В архиве',
        spam: 'Помечено как спам',
        move: 'Перемещено',
        lists: 'В рассылки',
        snooze: 'Отложено',
        unsnooze: 'Возвращено во «Входящие»',
        notspam: 'Возвращено во Входящие',
        remind: 'Напомню, если не ответят',
    };

    /** Убрать строки с экрана и поправить счётчики, не дожидаясь сервера. */
    function removeRows(uids) {
        const set = new Set(uids);
        let unreadGone = 0;
        ctx.list.value.messages = ctx.list.value.messages.filter((m) => {
            if (set.has(m.uid)) {
                if (!m.seen) unreadGone++;

                return false;
            }

            return true;
        });
        ctx.list.value.total = Math.max(0, ctx.list.value.total - uids.length);
        ctx.bump(ctx.folder.value, -unreadGone, -uids.length);
        ctx.selected.value = ctx.selected.value.filter((u) => !set.has(u));
        if (ctx.open.value && set.has(ctx.open.value.uid)) {
            ctx.open.value = null;
            ctx.mobileRead.value = false;
        }
    }

    async function runAct(p, opts = {}) {
        const r = await api.action(p.folder, p.uids, p.op, p.extra, opts);
        if (r?.folders) ctx.folders.value = r.folders;

        return r;
    }

    /** Довести отложенное действие до сервера немедленно (ушли со страницы, сделали другое). */
    function flushPendingAct(keepalive = false) {
        if (!pending) return;
        clearTimeout(pending.timer);
        const p = pending;
        pending = null;
        // Сообщение с таймером без действия за ним зависало навсегда — убираем вместе с действием.
        if (ctx.toast.value?.actionLabel === 'Отменить' && ctx.toast.value?.seconds) ctx.toast.value = null;
        runAct(p, keepalive ? { keepalive: true } : {})
            .then(() => { if (!keepalive) ctx.refillAfter(p.op); })
            // При уходе со страницы показывать уже нечего, в остальных случаях молчать нельзя:
            // письмо пропадало с экрана, хотя на сервере ничего не произошло.
            .catch((e) => { if (!keepalive) { ctx.fail(e); ctx.load(ctx.list.value.page, true); } });
    }

    /** «Отменить» у отложенного действия. Возвращает false, если отменять нечего. */
    function undoAct() {
        if (!pending) return false;
        clearTimeout(pending.timer);
        const p = pending;
        pending = null;
        ctx.toast.value = null;
        // Сервер ничего не делал — достаточно перечитать список и счётчики.
        ctx.load(ctx.list.value.page, true);
        // Удалённое письмо было открыто — показываем его снова, а не «Выберите письмо слева».
        if (p.opened) {
            ctx.open.value = p.opened;
            ctx.mobileRead.value = p.mobileRead;
        }
        ctx.showToast({ text: 'Отменено' }, 2000);

        return true;
    }

    /** Кнопка «Отменить» одна на всё: сначала действие над письмом, потом отправка. */
    function undoToast() {
        if (undoAct()) return;
        ctx.undoSend();
    }

    /** Экран меняем сразу; что именно поменять — зависит от действия. */
    function applyToScreen(op, uids, extra) {
        const rows = ctx.list.value.messages.filter((m) => uids.includes(m.uid));
        const opened = ctx.open.value && uids.includes(ctx.open.value.uid) ? ctx.open.value : null;
        switch (op) {
            case 'seen':
                rows.forEach((m) => { if (!m.seen) { m.seen = true; ctx.bump(ctx.folder.value, -1); } });
                break;
            case 'unseen':
                rows.forEach((m) => { if (m.seen) { m.seen = false; ctx.bump(ctx.folder.value, 1); } });
                if (opened) opened.seen = false;
                break;
            case 'flag':
                rows.forEach((m) => { m.flagged = true; });
                if (opened) opened.flagged = true;
                break;
            case 'unflag':
                rows.forEach((m) => { m.flagged = false; });
                if (opened) opened.flagged = false;
                break;
            case 'label':
                rows.forEach((m) => { if (!m.labels.includes(extra.label)) m.labels.push(extra.label); });
                if (opened && !opened.labels.includes(extra.label)) opened.labels.push(extra.label);
                break;
            case 'unlabel':
                rows.forEach((m) => { m.labels = m.labels.filter((l) => l !== extra.label); });
                if (opened) opened.labels = opened.labels.filter((l) => l !== extra.label);
                break;
            case 'delete': case 'move': case 'archive': case 'spam':
            case 'notspam': case 'lists': case 'snooze': case 'unsnooze':
                removeRows(uids);
                break;
            default:
                break;
        }
    }

    /** Отмена выключена в настройках: удаление уходит сразу и навсегда — спрашиваем. */
    function confirmForever(uids) {
        const forever = ctx.folderInfo.value.role === 'trash';
        const what = uids.length > 1 ? `${uids.length} ${plural(uids.length, 'письмо', 'письма', 'писем')}` : 'письмо';

        return window.confirm(forever ? `Стереть ${what} навсегда? Восстановить будет нельзя.` : `Удалить ${what}?`);
    }

    function defer(op, uids, extra, label, secs, opened = null, mobileRead = false) {
        flushPendingAct();
        pending = { folder: ctx.folder.value, uids, op, extra, seconds: secs, timer: null, opened, mobileRead };
        ctx.showToast({ text: label, actionLabel: 'Отменить', seconds: secs }, 0);
        const tick = () => {
            if (!pending) return;
            pending.seconds--;
            if (pending.seconds <= 0) {
                const p = pending;
                pending = null;
                ctx.toast.value = null;
                runAct(p).then(() => ctx.refillAfter(p.op)).catch((e) => { ctx.fail(e); ctx.load(ctx.list.value.page, true); });

                return;
            }
            // Обновляем только своё сообщение внизу: если его уже сменило другое
            // («Черновик сохранён»), чужое не трогаем.
            if (ctx.toast.value?.actionLabel === 'Отменить') ctx.toast.value = { ...ctx.toast.value, seconds: pending.seconds };
            pending.timer = setTimeout(tick, 1000);
        };
        pending.timer = setTimeout(tick, 1000);
    }

    /** Действия, которые не должны срабатывать мгновенно и без возврата. */
    const DEFERRABLE = ['delete', 'archive', 'spam', 'move', 'lists'];

    /**
     * $deferrable = false — сделать сразу, без окна отмены. Так зовёт решение по
     * отправителю: письмо уже уехало в папку, и следом спрашивается «только это письмо
     * или все от адреса», а окно отмены поверх этого вопроса только мешало бы.
     * Раньше этот довод объявлялся, но не читался, и отмена показывалась всё равно.
     */
    async function act(op, uids, extra = {}, deferrable = true) {
        if (!uids?.length) return;
        ctx.menu.value = null;
        // Что было открыто до действия — чтобы вернуть на экран при отмене.
        const opened = ctx.open.value && uids.includes(ctx.open.value.uid) ? ctx.open.value : null;
        const mobileRead = !!ctx.mobileRead.value;
        applyToScreen(op, uids, extra);

        const label = `${NAMES[op] || ''}${uids.length > 1 ? ` · ${uids.length} ${plural(uids.length, 'письмо', 'письма', 'писем')}` : ''}`;
        const secs = Number(ctx.settings.value.undo_seconds ?? 5);

        if (!secs && op === 'delete' && !confirmForever(uids)) return;
        // Перетащили письмо мышью не в ту папку или ошиблись со «Спамом» — отмена нужна
        // так же, как при удалении. Раньше эти действия уходили на сервер сразу.
        if (deferrable && secs > 0 && DEFERRABLE.includes(op)) {
            defer(op, uids, extra, label, secs, opened, mobileRead);

            return;
        }

        try {
            const r = await runAct({ folder: ctx.folder.value, uids, op, extra });
            // Сервер сообщает, со сколькими письмами получилось: раньше из двадцати выделенных
            // могло отложиться девятнадцать, и сообщение всё равно было победным.
            const skipped = Number(r?.skipped || 0);
            if (NAMES[op]) {
                ctx.showToast({
                    text: skipped ? `${label} · ${skipped} ${plural(skipped, 'письмо', 'письма', 'писем')} пропущено: нет Message-ID` : label,
                }, skipped ? 6000 : 2500);
            }
            ctx.refillAfter(op);
        } catch (e) {
            ctx.fail(e);
            ctx.load(ctx.list.value.page, true);
        }
    }

    return { act, removeRows, runAct, flushPendingAct, undoAct, undoToast };
}
