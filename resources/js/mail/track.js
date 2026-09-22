// Маячок действий, которых сервер сам не видит: открыл окно письма, закрыл не отправив,
// открыл меню. Копим и отправляем пачкой раз в 15 секунд, а при уходе со страницы — сразу
// (keepalive/sendBeacon). Сбой отправки никого не касается: маячок молчит.
const queue = [];
let timer = null;

export function track(action, detail = null) {
    queue.push({ a: action, d: detail == null ? null : String(detail).slice(0, 120), t: Date.now() });
    if (queue.length >= 20) flush();
    else if (!timer) timer = setTimeout(flush, 15000);
}

export function flush(final = false) {
    clearTimeout(timer); timer = null;
    if (!queue.length) return;
    const now = Date.now();
    const events = queue.splice(0).map((e) => ({ a: e.a, d: e.d, ago: Math.max(0, now - e.t) }));
    const body = JSON.stringify({ events });
    try {
        if (final && navigator.sendBeacon && navigator.sendBeacon('/mail/api/activity', new Blob([body], { type: 'application/json' }))) return;
        fetch('/mail/api/activity', {
            method: 'POST', body, credentials: 'same-origin', keepalive: true,
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        }).catch(() => {});
    } catch { /* маячок молчит */ }
}

if (typeof window !== 'undefined') {
    window.addEventListener('pagehide', () => flush(true));
    document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'hidden') flush(true); });
}
