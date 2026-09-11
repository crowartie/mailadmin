// Небольшая обёртка над fetch для админки: CSRF из cookie, JSON, понятная ошибка.
// Нужна там, где страница обновляет себя сама и перерисовывать её целиком через Inertia незачем.
function xsrf() {
    const m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
}

export async function http(method, url, body) {
    const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf() };
    let payload = body;
    if (body && !(body instanceof FormData)) {
        headers['Content-Type'] = 'application/json';
        payload = JSON.stringify(body);
    }
    const r = await fetch(url, { method, headers, body: payload, credentials: 'same-origin' });
    const text = await r.text();
    let data = null;
    try { data = text ? JSON.parse(text) : null; } catch { data = { message: text }; }
    if (r.status === 401 || r.status === 419) {
        window.location.reload();
        throw new Error('Сессия закончилась');
    }
    if (!r.ok) {
        throw new Error(data?.message || (data?.errors && Object.values(data.errors).flat()[0]) || `Ошибка ${r.status}`);
    }
    return data;
}
