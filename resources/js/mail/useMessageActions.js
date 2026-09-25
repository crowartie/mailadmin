import { api } from './api';
import { ask as confirmAsk } from '../confirm';
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

    /**
     * Куда уходит действие: [{ folder, uids }]. Обычно — текущая папка. В поиске по всем папкам
     * строка знает свою папку: раньше действие уходило в текущую, и «Удалить» у письма из
     * «Отправленных» задело бы письмо «Входящих» с тем же номером (номера у папок свои).
     * $pin — папка строки, по которой открыли меню, или открытого письма (одно письмо).
     */
    function targets(uids, pin = null) {
        if (!ctx.list.value.everywhere) return [{ folder: ctx.folder.value, uids }];
        const out = new Map();
        for (const u of uids) {
            let folders = uids.length === 1 && pin ? [pin] : [...new Set(ctx.list.value.messages.filter((m) => m.uid === u).map((m) => m.folder).filter(Boolean))];
            if (!folders.length && ctx.open.value?.uid === u) folders = [ctx.open.value.folder];
            for (const f of folders) {
                if (!out.has(f)) out.set(f, []);
                out.get(f).push(u);
            }
        }
        return [...out].map(([folder, list]) => ({ folder, uids: list }));
    }
    /** Строка попадает под действие: номер совпал, а в поиске по всем папкам — и папка. */
    const hits = (groups) => (m) => groups.some((g) => g.uids.includes(m.uid) && (!m.folder || m.folder === g.folder));

    /** Убрать строки с экрана и поправить счётчики, не дожидаясь сервера. */
    function removeRows(uids, groups = null) {
        const set = new Set(uids);
        const hit = groups ? hits(groups) : (m) => set.has(m.uid);
        let unreadGone = 0;
        ctx.list.value.messages = ctx.list.value.messages.filter((m) => {
            if (hit(m)) {
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
        // Вся папка — это тысячи писем частями по 500: ждём дольше обычной минуты.
        const o = p.extra?.all ? { ...opts, timeout: 600000 } : opts;
        let r = null;
        let skipped = 0;
        for (const g of p.groups || [{ folder: p.folder, uids: p.uids }]) {
            r = await api.action(g.folder, g.uids, p.op, p.extra, o);
            skipped += Number(r?.skipped || 0);
        }
        if (r?.folders) ctx.folders.value = r.folders;

        return r ? { ...r, skipped } : r;
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
            .catch((e) => { if (!keepalive) { ctx.fail(e); ctx.reload(false); } });
    }

    /**
     * Письмо ждёт удаления (переноса, архива, спама) в окне «Отменить» — на экране его быть не должно,
     * что бы ни вернул сервер. Обращение №50: удалил одно, сразу второе — второе действие доводило
     * первое до сервера и перечитывало список, а в нём второе письмо ещё лежало (его удаление само
     * ждало своих секунд) и возвращалось на место.
     */
    function isPendingGone(m) {
        if (!pending || !DEFERRABLE.includes(pending.op)) return false;
        if (!ctx.list.value.everywhere && pending.folder !== ctx.folder.value) return false;

        return hits(pending.groups || [{ folder: pending.folder, uids: pending.uids }])(m);
    }

    /** «Отменить» у отложенного действия. Возвращает false, если отменять нечего. */
    function undoAct() {
        if (!pending) return false;
        clearTimeout(pending.timer);
        const p = pending;
        pending = null;
        ctx.toast.value = null;
        // Сервер ничего не делал — достаточно перечитать список и счётчики.
        ctx.reload(false);
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
    function applyToScreen(op, uids, extra, groups) {
        const rows = ctx.list.value.messages.filter(hits(groups));
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
                removeRows(uids, groups);
                break;
            default:
                break;
        }
    }

    /** Отмена выключена в настройках: удаление уходит сразу и навсегда — спрашиваем. */
    function confirmForever(uids) {
        const forever = ctx.folderInfo.value.role === 'trash';
        const what = uids.length > 1 ? `${uids.length} ${plural(uids.length, 'письмо', 'письма', 'писем')}` : 'письмо';

        return confirmAsk(forever ? `Стереть ${what} навсегда? Восстановить будет нельзя.` : `Удалить ${what}?`, { ok: forever ? 'Стереть' : 'Удалить', danger: true });
    }

    function defer(op, uids, extra, label, secs, opened = null, mobileRead = false, groups = null) {
        flushPendingAct();
        pending = { folder: ctx.folder.value, uids, groups, op, extra, seconds: secs, timer: null, opened, mobileRead };
        ctx.showToast({ text: label, actionLabel: 'Отменить', seconds: secs }, 0);
        const tick = () => {
            if (!pending) return;
            pending.seconds--;
            if (pending.seconds <= 0) {
                const p = pending;
                pending = null;
                ctx.toast.value = null;
                runAct(p).then(() => ctx.refillAfter(p.op)).catch((e) => { ctx.fail(e); ctx.reload(false); });

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
    // «Все письма папки»: какие действия можно и как о них спросить.
    const FOR_ALL = {
        seen: 'Отметить прочитанными', unseen: 'Отметить непрочитанными', flag: 'Поставить флажок', unflag: 'Снять флажок',
        delete: 'Удалить', move: 'Перенести', archive: 'Отправить в архив', spam: 'Отправить в спам',
        notspam: 'Вернуть во «Входящие»', lists: 'Перенести в «Рассылки»', label: 'Поставить метку', unlabel: 'Снять метку',
    };
    const sameSet = (a, b) => a.length === b.length && a.every((u) => b.includes(u));

    async function act(op, uids, extra = {}, deferrable = true) {
        if (!uids?.length) return;
        // Одно письмо из меню строки или из открытого письма — его папка известна точно.
        const pin = ctx.menu.value?.folder && sameSet(uids, ctx.menu.value.uids || []) ? ctx.menu.value.folder
            : (uids.length === 1 && ctx.open.value?.uid === uids[0] ? ctx.open.value.folder : null);
        const groups = targets(uids, pin);
        ctx.menu.value = null;
        // Выбраны все письма папки (а не только загруженные) — действие уходит на всю выборку сервера.
        const all = ctx.selectedAll?.value && ctx.selectedAll.value.folder === ctx.folder.value && sameSet(uids, ctx.selected.value) ? ctx.selectedAll.value : null;
        let count = uids.length;
        if (all) {
            if (!FOR_ALL[op]) { ctx.showToast({ text: 'Это действие для всей папки сразу недоступно — выберите письма', error: true }); return; }
            const verb = op === 'delete' && ctx.folderInfo.value.role === 'trash' ? 'Стереть навсегда' : FOR_ALL[op];
            const what = `${all.total} ${plural(all.total, 'письмо', 'письма', 'писем')}`;
            const destructive = ['delete', 'move', 'archive', 'spam', 'lists', 'notspam'].includes(op);
            if (!(await confirmAsk(`${verb}: все ${what} ${all.q ? 'по запросу' : 'папки «' + (ctx.folderInfo.value.name || '') + '»'}?`, { ok: verb, danger: destructive }))) return;
            extra = { ...extra, all: { filter: all.filter, q: all.q } };
            count = all.total;
            ctx.selectedAll.value = null;
        }
        // Что было открыто до действия — чтобы вернуть на экран при отмене.
        const opened = ctx.open.value && uids.includes(ctx.open.value.uid) ? ctx.open.value : null;
        const mobileRead = !!ctx.mobileRead.value;
        applyToScreen(op, uids, extra, groups);

        const label = `${NAMES[op] || ''}${count > 1 ? ` · ${count} ${plural(count, 'письмо', 'письма', 'писем')}` : ''}`;
        const secs = Number(ctx.settings.value.undo_seconds ?? 5);

        if (!secs && op === 'delete' && !all && !(await confirmForever(uids))) return;
        // Перетащили письмо мышью не в ту папку или ошиблись со «Спамом» — отмена нужна
        // так же, как при удалении. Раньше эти действия уходили на сервер сразу.
        if (deferrable && secs > 0 && DEFERRABLE.includes(op)) {
            defer(op, uids, extra, label, secs, opened, mobileRead, groups);

            return;
        }

        try {
            const r = await runAct({ folder: ctx.folder.value, uids, groups, op, extra });
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
            ctx.reload(false);
        }
    }

    return { act, removeRows, runAct, flushPendingAct, undoAct, undoToast, isPendingGone };
}
