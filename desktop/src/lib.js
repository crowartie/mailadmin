'use strict';
// Чистая логика оболочки «Почта для Windows»: без Electron, чтобы проверять её node --test (test/unit).
// Всё, что решает «куда можно ходить», «что открыть в браузере», «какой значок показать», — здесь.

const LOCAL_HOSTS = new Set(['localhost', '127.0.0.1', '[::1]']);

/** Адрес сервера → происхождение (origin) без пути; http допускается только для локальной разработки и тестов. */
function originOf(input) {
    let u;
    try { u = new URL(String(input)); } catch { return null; }
    if (u.protocol !== 'https:' && !(u.protocol === 'http:' && LOCAL_HOSTS.has(u.hostname))) return null;
    return u.origin;
}

/**
 * Что человек ввёл на первом запуске → адреса сервера, которые стоит проверить по очереди.
 * «ivan@example.ru» → mail.example.ru, затем example.ru (как в приложении для Android);
 * «mail.example.ru» или ссылка → https-адрес этого узла. Пароль здесь не нужен и не спрашивается.
 */
function serverCandidates(input) {
    const s = String(input || '').trim();
    if (!s) return [];
    if (s.includes('@') && !/^[a-z]+:\/\//i.test(s)) {
        const domain = s.split('@').pop().trim().toLowerCase();
        if (!/^[a-z0-9-]+(\.[a-z0-9-]+)+$/i.test(domain)) return [];
        return ['https://mail.' + domain, 'https://' + domain];
    }
    let url = s;
    if (/^http:\/\//i.test(url)) {
        // http для чужих узлов молча переводим на https — пароль по сети в открытом виде не идёт никогда.
        const o = originOf(url);
        return o ? [o] : [originOf('https://' + url.slice(7))].filter(Boolean);
    }
    if (!/^https:\/\//i.test(url)) url = 'https://' + url;
    const o = originOf(url);
    if (!o) return [];
    const host = new URL(o).hostname;
    return host.includes('.') || LOCAL_HOSTS.has(host) ? [o] : [];
}

/**
 * Куда ведёт ссылка относительно сервера почты:
 *  internal — страница самой почты (открываем в окне приложения);
 *  external — чужой сайт (в браузере по умолчанию);
 *  mailto   — «написать письмо» (окно письма в приложении);
 *  block    — всё прочее (file:, javascript:, чужие схемы) — никуда.
 */
function classifyUrl(url, server) {
    let u;
    try { u = new URL(String(url)); } catch { return 'block'; }
    if (u.protocol === 'mailto:') return 'mailto';
    if (u.protocol === 'blob:') {
        try { return new URL(u.pathname).origin === server ? 'internal' : 'block'; } catch { return 'block'; }
    }
    if (u.protocol === 'about:' && u.href === 'about:blank') return 'internal';
    if (u.protocol !== 'https:' && u.protocol !== 'http:') return 'block';
    return u.origin === server ? 'internal' : 'external';
}

/** «mailto:a@b.ru,c@d.ru?subject=…» → путь окна нового письма; null, если адресов нет. */
function mailtoPath(mailto) {
    let u;
    try { u = new URL(String(mailto)); } catch { return null; }
    if (u.protocol !== 'mailto:') return null;
    const to = decodeURIComponent(u.pathname || '')
        .split(/[,;]/).map((a) => a.trim()).filter((a) => /^[^\s@<>"]+@[^\s@<>"]+$/.test(a));
    const cc = (u.searchParams.get('to') || '').split(/[,;]/).map((a) => a.trim()).filter((a) => /^[^\s@<>"]+@[^\s@<>"]+$/.test(a));
    const all = [...to, ...cc];
    if (!all.length) return null;
    return '/mail?compose=1&to=' + encodeURIComponent(all.join(', '));
}

/** Первый аргумент «mailto:…» из командной строки (так Windows передаёт ссылку приложению). */
function findMailtoArg(argv) {
    return (argv || []).find((a) => /^mailto:/i.test(String(a))) || null;
}

/** «(3) Входящие — Почта» → 3; заголовок без числа — null (число не известно, а не «ноль»). */
function unreadFromTitle(title) {
    const m = /^\((\d{1,6})\)\s/.exec(String(title || ''));
    return m ? Number(m[1]) : null;
}

function plural(n, one, few, many) {
    const a = Math.abs(n) % 100; const b = a % 10;
    if (a > 10 && a < 20) return many;
    if (b === 1) return one;
    if (b >= 2 && b <= 4) return few;
    return many;
}

/** Подсказка у значка в трее. */
function trayTooltip(unread) {
    return unread > 0 ? `Почта — ${unread} ${plural(unread, 'непрочитанное', 'непрочитанных', 'непрочитанных')}` : 'Почта';
}

/** Файл кружка с числом поверх значка на панели задач: badge-1.png … badge-9.png, badge-9plus.png. */
function badgeFile(unread) {
    if (!(unread > 0)) return null;
    return unread <= 9 ? `badge-${unread}.png` : 'badge-9plus.png';
}

/** Разрешения странице почты: уведомления и буфер обмена. Чужим страницам — ничего. */
const ALLOWED_PERMISSIONS = new Set(['notifications', 'clipboard-sanitized-write', 'clipboard-read', 'fullscreen']);
function allowPermission(permission, requestingUrl, server) {
    if (!ALLOWED_PERMISSIONS.has(permission)) return false;
    try { return new URL(String(requestingUrl)).origin === server; } catch { return false; }
}

/** Адрес обновлений на том же сервере: /app/windows (latest.yml и установщик кладёт publish-desktop.sh). */
function feedUrl(server) {
    return server.replace(/\/+$/, '') + '/app/windows';
}

/** Страница, на которой почта сама следит за «Входящими» и показывает уведомления (Inbox.vue). */
function isInboxPage(url, server) {
    try {
        const u = new URL(String(url));
        return u.origin === server && (u.pathname === '/mail' || u.pathname.startsWith('/mail/folder/'));
    } catch { return false; }
}

/** Настройки по умолчанию; в файле хранятся только отличия. */
const DEFAULTS = Object.freeze({
    server: '',
    bounds: null,
    maximized: false,
    autostart: true,
    notifications: true,
    zoom: 0,
    trayHintShown: false,
});

function mergeSettings(saved) {
    const s = { ...DEFAULTS };
    if (saved && typeof saved === 'object') {
        for (const k of Object.keys(DEFAULTS)) {
            if (!(k in saved)) continue;
            const ok = k === 'bounds' ? saved[k] === null || typeof saved[k] === 'object' : typeof saved[k] === typeof DEFAULTS[k];
            if (ok) s[k] = saved[k];
        }
    }
    if (s.server && !originOf(s.server)) s.server = '';
    if (!Number.isFinite(s.zoom) || s.zoom < -3 || s.zoom > 4) s.zoom = 0;
    return s;
}

/** Окно на экране: сохранённое положение годится, только если оно видно хоть на одном мониторе. */
function visibleBounds(bounds, displays) {
    if (!bounds || !Number.isFinite(bounds.width) || bounds.width < 400 || bounds.height < 300) return null;
    const ok = (displays || []).some((d) => {
        const a = d.workArea || d.bounds;
        return bounds.x + 80 < a.x + a.width && bounds.x + bounds.width - 80 > a.x && bounds.y >= a.y - 10 && bounds.y + 40 < a.y + a.height;
    });
    return ok ? bounds : { width: bounds.width, height: bounds.height };
}

/** Письма для уведомления о новой почте: одно — отправитель и тема, несколько — счётчик и имена. */
function newMailNotice(messages) {
    const list = (messages || []).filter(Boolean);
    if (!list.length) return null;
    const name = (m) => (m.from && (m.from.name || m.from.mail)) || 'Без отправителя';
    if (list.length === 1) {
        const m = list[0];
        return { title: name(m), body: m.subject && m.subject !== '(без темы)' ? m.subject : '(без темы)', uid: m.uid };
    }
    const names = [...new Set(list.map(name))];
    return {
        title: `${list.length} ${plural(list.length, 'новое письмо', 'новых письма', 'новых писем')}`,
        body: names.slice(0, 3).join(', ') + (names.length > 3 ? ` и ещё ${names.length - 3}` : ''),
        uid: null,
    };
}

module.exports = {
    originOf, serverCandidates, classifyUrl, mailtoPath, findMailtoArg, unreadFromTitle, plural, trayTooltip,
    badgeFile, allowPermission, feedUrl, isInboxPage, DEFAULTS, mergeSettings, visibleBounds, newMailNotice,
};
