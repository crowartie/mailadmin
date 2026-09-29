// Push-уведомления и «приложение на экране Домой» (PWA): регистрация сервис-воркера,
// подписка браузера у своего push-сервиса и передача её на наш сервер (PushNotifier),
// счётчик непрочитанных на иконке. Всё необязательное: без поддержки в браузере почта
// работает как раньше, ошибки здесь никогда не мешают письмам.
import { api } from './api';

const SW_URL = '/sw.js';

export function supported() {
    return typeof window !== 'undefined' && 'serviceWorker' in navigator && 'PushManager' in window && typeof Notification !== 'undefined';
}

/** Почта открыта как установленное приложение (с экрана «Домой»), а не во вкладке браузера. */
export function standalone() {
    try {
        return window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
    } catch { return false; }
}

/** iPhone/iPad: push и значок на иконке работают только у приложения, добавленного на экран «Домой». */
export function isIos() {
    const ua = navigator.userAgent || '';
    return /iPhone|iPad|iPod/.test(ua) || (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1);
}

/** Зарегистрировать сервис-воркер; повторные вызовы возвращают ту же регистрацию. */
export async function register() {
    if (!('serviceWorker' in navigator)) return null;
    try {
        return await navigator.serviceWorker.register(SW_URL, { scope: '/' });
    } catch { return null; }
}

function keyBytes(b64url) {
    const pad = '='.repeat((4 - (b64url.length % 4)) % 4);
    const raw = atob((b64url + pad).replace(/-/g, '+').replace(/_/g, '/'));
    return Uint8Array.from(raw, (c) => c.charCodeAt(0));
}

function agentName() {
    const ua = navigator.userAgent || '';
    const os = /iPhone/.test(ua) ? 'iPhone' : /iPad/.test(ua) || (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1) ? 'iPad' : /Android/.test(ua) ? 'Android' : /Windows/.test(ua) ? 'Windows' : /Macintosh/.test(ua) ? 'Mac' : /Linux/.test(ua) ? 'Linux' : '';
    const br = /Edg\//.test(ua) ? 'Edge' : /YaBrowser/.test(ua) ? 'Яндекс Браузер' : /OPR\//.test(ua) ? 'Opera' : /Firefox/.test(ua) ? 'Firefox' : /Chrome\//.test(ua) ? 'Chrome' : /Safari/.test(ua) ? 'Safari' : 'браузер';
    return [os, br, standalone() ? 'приложение' : ''].filter(Boolean).join(' · ');
}

/**
 * Оформить подписку и отправить на сервер. Зовётся после разрешения уведомлений (по нажатию человека)
 * и при каждом открытии почты с включённой настройкой — подписка могла смениться или пропасть.
 * @returns {Promise<'ok'|'unsupported'|'denied'|'off'|'ios-not-installed'|'failed'>}
 */
export async function sync(enabled) {
    if (!supported()) return isIos() && !standalone() ? 'ios-not-installed' : 'unsupported';
    const reg = await register();
    if (!reg) return 'unsupported';
    try {
        const cur = await reg.pushManager.getSubscription();
        if (!enabled) {
            if (cur) { try { await api.pushUnsubscribe(cur.endpoint); } catch {} await cur.unsubscribe().catch(() => {}); }
            return 'off';
        }
        if (Notification.permission !== 'granted') return Notification.permission === 'denied' ? 'denied' : 'failed';
        const info = await api.pushKey();
        if (!info.enabled || !info.key) return 'unsupported';
        let sub = cur;
        if (!sub) {
            sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes(info.key) });
        }
        const j = sub.toJSON();
        await api.pushSubscribe({ endpoint: sub.endpoint, keys: j.keys, agent: agentName() });
        return 'ok';
    } catch (e) {
        return 'failed';
    }
}

/** Счётчик непрочитанных на иконке приложения (Chrome, Edge, Safari на экране «Домой»). */
export function setBadge(n) {
    try {
        // Приложение для Windows: кружок на панели задач и подсказка у значка в трее.
        window.pochta?.setUnread?.(n);
        if (!('setAppBadge' in navigator)) return;
        (n > 0 ? navigator.setAppBadge(n) : navigator.clearAppBadge()).catch(() => {});
    } catch { /* нет поддержки */ }
}
