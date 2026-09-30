'use strict';
// Мост между страницей почты и приложением. Страница видит только window.pochta с несколькими
// безопасными действиями; доступа к Node и к файлам у неё нет (contextIsolation + sandbox).
// Главный процесс проверяет, что сообщение пришло со страницы своего сервера (main.js, fromServer).
const { contextBridge, ipcRenderer } = require('electron');

const cfg = ipcRenderer.sendSync('pochta:config') || {};

contextBridge.exposeInMainWorld('pochta', {
    desktop: true,
    version: String(cfg.version || ''),
    /** Включены ли уведомления о новых письмах (меню значка в трее). */
    notificationsOn: () => ipcRenderer.sendSync('pochta:notifications') === true,
    /** Непрочитанные во «Входящих» — для кружка на панели задач и подсказки у значка в трее. */
    setUnread: (n) => ipcRenderer.send('pochta:unread', Number(n) || 0),
    /** Показать окно (нажали на уведомление). */
    show: () => ipcRenderer.send('pochta:show'),
});

// Первый запуск: страница выбора сервера (setup.html) — только у локальной страницы приложения.
if (location.protocol === 'file:') {
    contextBridge.exposeInMainWorld('pochtaSetup', {
        hint: String(cfg.hint || ''),
        adding: cfg.accounts > 0,   // ящики уже есть — это добавление ещё одного, можно отменить
        check: (input) => ipcRenderer.invoke('setup:check', String(input || '')),
        cancel: () => ipcRenderer.send('setup:cancel'),
    });
}
