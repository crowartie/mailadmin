import { nextTick } from 'vue';
import { hotkey } from './format';

/**
 * Горячие клавиши списка писем — как в Gmail: j/k по списку, e в архив, # удалить,
 * r ответить, c написать, g+i перейти во «Входящие», * a выбрать все.
 *
 * Клавиши определяются по физической клавише, а не по букве (см. hotkey): иначе в русской
 * раскладке «о» вместо j и «с» вместо c ничего не делали, а это почти весь набор.
 *
 * @param {object} ctx settings, list, cursor, open, selected, menu, compose, dialog, help,
 *                     mobileRead, listRef
 *                     + act, openMessage, toggle, startCompose, go, rolePath
 */
export function useHotkeys(ctx) {
    // Клавиши с приставкой: «g» и следом буква папки, «*» и следом a или n.
    let goPrefix = false;
    let goTimer = null;
    let selectPrefix = false;
    let selectTimer = null;
    const PREFIX_MS = 1200;

    const FOLDERS = { i: 'inbox', s: 'sent', d: 'drafts', t: 'trash', a: 'archive' };
    const MENUS = { z: 'snooze', v: 'move', l: 'label' };

    /** Подвести список к строке под курсором: без этого j/k уводят курсор за пределы экрана. */
    function revealCursor() {
        nextTick(() => {
            const el = document.querySelector('.mrow--cursor');
            if (!el) return;
            const r = el.getBoundingClientRect();
            if (r.top < 70 || r.bottom > window.innerHeight - 60) {
                el.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            }
        });
    }

    /** Ввод идёт в поле — клавиши списка молчат, Escape просто снимает фокус. */
    function typing(t) {
        return t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.isContentEditable);
    }

    function step(ids, idx, delta) {
        const n = ids[Math.min(ids.length - 1, Math.max(0, idx + delta))];
        if (n == null) return;
        ctx.cursor.value = n;
        revealCursor();
        if (ctx.open.value) ctx.openMessage(n);
    }

    /** Меню открывается у строки под курсором, а не в заранее заданной точке экрана:
     *  после изменения ширины колонок такая точка попадала в чужую колонку. */
    function openMenuAtCursor(kind, uids) {
        const el = document.querySelector('.mrow--cursor') || document.querySelector('.mlist');
        const r = el ? el.getBoundingClientRect() : { left: 320, bottom: 160 };
        ctx.menu.value = {
            kind, uids,
            x: Math.round(r.left + 40),
            y: Math.round(Math.min(r.bottom, window.innerHeight - 120)),
        };
    }

    function onKey(e) {
        if (!ctx.settings.value.shortcuts) return;
        if (ctx.compose.value || ctx.dialog.value || ctx.help.value) return;
        if (typing(e.target)) {
            if (e.key === 'Escape') e.target.blur();

            return;
        }
        if (e.ctrlKey || e.metaKey || e.altKey) return;

        const key = hotkey(e);
        const ids = ctx.list.value.messages.map((m) => m.uid);
        const cur = ctx.cursor.value ?? ctx.open.value?.uid ?? null;
        const idx = ids.indexOf(cur);
        const target = ctx.selected.value.length ? ctx.selected.value : (cur != null ? [cur] : []);
        const row = ctx.list.value.messages.find((m) => m.uid === cur);

        if (selectPrefix) {
            selectPrefix = false;
            clearTimeout(selectTimer);
            if (key === 'a') { ctx.selected.value = ids; e.preventDefault(); }
            if (key === 'n') { ctx.selected.value = []; e.preventDefault(); }

            return;
        }
        if (goPrefix) {
            goPrefix = false;
            clearTimeout(goTimer);
            if (FOLDERS[key] && ctx.rolePath(FOLDERS[key])) { ctx.go(ctx.rolePath(FOLDERS[key])); e.preventDefault(); }

            return;
        }

        switch (key) {
            case 'g':
                goPrefix = true;
                goTimer = setTimeout(() => { goPrefix = false; }, PREFIX_MS);
                break;
            case 'j': case 'ArrowDown':
                if (ctx.menu.value) return;
                e.preventDefault();
                step(ids, idx, 1);
                break;
            case 'k': case 'ArrowUp':
                if (ctx.menu.value) return;
                e.preventDefault();
                step(ids, idx, -1);
                break;
            case 'Enter': case 'o':
                if (cur != null) ctx.openMessage(cur);
                break;
            case 'u':
                ctx.open.value = null;
                ctx.mobileRead.value = false;
                break;
            case 'x':
                if (cur != null) ctx.toggle(cur);
                break;
            case 'e': ctx.act('archive', target); break;
            case '#': case 'Delete': ctx.act('delete', target); break;
            // Ориентир — письмо под курсором, а если его нет (после «выбрать все»), первое
            // выделенное: раньше эти две клавиши в таком случае просто ничего не делали.
            case 's': {
                const r = row || ctx.list.value.messages.find((m) => target.includes(m.uid));
                if (r) ctx.act(r.flagged ? 'unflag' : 'flag', target);
                break;
            }
            case 'i': {
                const r = row || ctx.list.value.messages.find((m) => target.includes(m.uid));
                if (r) ctx.act(r.seen ? 'unseen' : 'seen', target);
                break;
            }
            case '!': ctx.act('spam', target); break;
            case 'r': if (ctx.open.value) ctx.startCompose(ctx.settings.value.reply_all ? 'replyAll' : 'reply', ctx.open.value); break;
            case 'a': if (ctx.open.value) ctx.startCompose('replyAll', ctx.open.value); break;
            case 'f': if (ctx.open.value) ctx.startCompose('forward', ctx.open.value); break;
            case 'c': ctx.startCompose('new'); break;
            case 'z': case 'v': case 'l':
                if (target.length) openMenuAtCursor(MENUS[key], target);
                break;
            case '/':
                e.preventDefault();
                ctx.listRef.value?.focusSearch();
                break;
            case '?': ctx.help.value = true; break;
            case '*':
                selectPrefix = true;
                clearTimeout(selectTimer);
                selectTimer = setTimeout(() => { selectPrefix = false; }, PREFIX_MS);
                e.preventDefault();
                break;
            case 'Escape':
                if (ctx.menu.value) ctx.menu.value = null;
                else if (ctx.selected.value.length) ctx.selected.value = [];
                else { ctx.open.value = null; ctx.mobileRead.value = false; }
                break;
            default:
                break;
        }
    }

    return { onKey, revealCursor };
}
