// Что автоматически приложить к обращению «не работает»: страница, браузер, последние ошибки.
// Сотрудник не обязан это описывать словами — а без этого разбирать обращение почти невозможно.

const errors = [];

function push(kind, text) {
    if (!text) return;
    errors.push({ kind, text: String(text).slice(0, 300), at: new Date().toISOString() });
    if (errors.length > 12) errors.shift();
}

if (typeof window !== 'undefined' && !window.__diagInstalled) {
    window.__diagInstalled = true;
    window.addEventListener('error', (e) => {
        if (e.message) push('js', `${e.message} (${(e.filename || '').split('/').pop()}:${e.lineno || 0})`);
    });
    window.addEventListener('unhandledrejection', (e) => push('js', e.reason?.message || e.reason));
}

/** Неудачный запрос к серверу — вызывается из api.js. */
export function recordApiError(method, url, status, message) {
    push('api', `${method} ${url} → ${status}: ${message || ''}`);
}

/** Браузер и система по-человечески: «Яндекс.Браузер 26 · Windows 10». */
function client() {
    const ua = navigator.userAgent || '';
    const browsers = [
        [/YaBrowser\/(\d+)/, 'Яндекс.Браузер'],
        [/Edg\/(\d+)/, 'Edge'],
        [/OPR\/(\d+)/, 'Opera'],
        [/Firefox\/(\d+)/, 'Firefox'],
        [/CriOS\/(\d+)/, 'Chrome'],
        [/Chrome\/(\d+)/, 'Chrome'],
        [/Version\/(\d+).*Safari/, 'Safari'],
    ];
    let name = 'браузер';
    for (const [re, title] of browsers) {
        const m = ua.match(re);
        if (m) { name = `${title} ${m[1]}`; break; }
    }
    const systems = [
        [/Windows NT 10/, 'Windows 10 или 11'],
        [/Windows NT 6\.1/, 'Windows 7'],
        [/Windows/, 'Windows'],
        [/iPhone OS (\d+)/, 'iPhone iOS $1'],
        [/iPad/, 'iPad'],
        [/Android (\d+)/, 'Android $1'],
        [/Mac OS X/, 'macOS'],
        [/Linux/, 'Linux'],
    ];
    let os = '';
    for (const [re, title] of systems) {
        const m = ua.match(re);
        if (m) { os = title.replace('$1', m[1] || ''); break; }
    }
    return os ? `${name} · ${os}` : name;
}

/** Снимок обстановки для обращения. */
export function context(extra = {}) {
    return {
        url: location.pathname + location.search,
        page: document.title.replace(/\s+—\s+Почта$/, ''),
        client: client(),
        screen: `${window.screen?.width || 0}×${window.screen?.height || 0}`,
        viewport: `${window.innerWidth}×${window.innerHeight}`,
        lang: navigator.language || '',
        tz: Intl.DateTimeFormat().resolvedOptions().timeZone || '',
        errors: errors.slice(-8),
        ...extra,
    };
}
