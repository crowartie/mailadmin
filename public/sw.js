/* Сервис-воркер веб-почты: нужен, чтобы почту можно было поставить на экран «Домой» телефона
   как приложение и чтобы приходили push-уведомления о новых письмах, когда почта закрыта.
   Страницы не кэширует: почта живая, устаревший список хуже, чем «нет связи». */
const VERSION = 'mail-sw-1';

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (e) => e.waitUntil(self.clients.claim()));

// Уведомление о новом письме от сервера (PushNotifier): заголовок — отправитель, текст — тема.
self.addEventListener('push', (e) => {
    let d = {};
    try { d = e.data ? e.data.json() : {}; } catch { d = { title: 'Почта', body: e.data ? e.data.text() : '' }; }
    const jobs = [self.registration.showNotification(d.title || 'Новое письмо', {
        body: d.body || '',
        tag: d.tag || 'mail',
        renotify: true,
        icon: '/icon-192.png?v=3',
        badge: '/icon-192.png?v=3',
        data: { url: d.url || '/mail' },
    })];
    // Счётчик на иконке: сервер присылает число непрочитанных во «Входящих».
    if (typeof d.unseen === 'number' && 'setAppBadge' in navigator) {
        jobs.push((d.unseen > 0 ? navigator.setAppBadge(d.unseen) : navigator.clearAppBadge()).catch(() => {}));
    }
    e.waitUntil(Promise.all(jobs));
});

// Нажали на уведомление: открытая почта переходит к письму, закрытая — открывается на нём.
self.addEventListener('notificationclick', (e) => {
    e.notification.close();
    const url = (e.notification.data && e.notification.data.url) || '/mail';
    e.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((list) => {
        for (const c of list) {
            if ('focus' in c) {
                if ('navigate' in c) c.navigate(url).catch(() => {});
                return c.focus();
            }
        }
        return self.clients.openWindow(url);
    }));
});

// Push-сервис сменил адрес подписки: страница при следующем открытии переподпишется сама (push.js → sync).
self.addEventListener('pushsubscriptionchange', () => {});
