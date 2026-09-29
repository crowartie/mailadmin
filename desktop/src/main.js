'use strict';
// «Почта» для Windows: веб-почта своего сервера в отдельном окне + то, чего нет у вкладки браузера:
// значок в трее со счётчиком, кружок с числом на панели задач, уведомления Windows о новых письмах
// (даже когда окно закрыто или открыт календарь), запуск вместе с Windows, ссылки mailto:, понятный экран
// «нет связи» и обновление само себя с того же сервера. Интерфейс почты — тот же, что в браузере,
// поэтому всё новое в веб-почте появляется в приложении сразу, без выпуска новой версии.
const { app, BrowserWindow, Tray, Menu, nativeImage, shell, ipcMain, session, Notification, dialog, screen, clipboard } = require('electron');
const path = require('node:path');
const { execFile } = require('node:child_process');
const lib = require('./lib');
const store = require('./settings');
const pkg = require('../package.json');

const TEST = process.env.POCHTA_TEST === '1';
// Проверка самообновления установленной версии (test/update-check.js): обновления разрешены и в тестовом режиме.
const UPDATE_TEST = TEST && process.env.POCHTA_UPDATE_TEST === '1';
if (process.env.POCHTA_USER_DATA) app.setPath('userData', process.env.POCHTA_USER_DATA);
const APP_ID = 'ru.mailadmin.pochta';
const PARTITION = 'persist:pochta';
const ASSETS = path.join(__dirname, '..', 'assets');
const RETRY_MS = Number(process.env.POCHTA_RETRY_MS) || 15000;      // «нет связи»: повтор через 15 с
const WATCH_MS = Number(process.env.POCHTA_WATCH_MS) || 30000;      // фоновая проверка «Входящих»
const SETTINGS_FILE = () => path.join(app.getPath('userData'), 'settings.json');

app.setAppUserModelId(APP_ID);   // без него Windows не показывает уведомления от имени «Почты»

let settings = lib.mergeSettings(null);
let win = null;
let tray = null;
let unread = 0;
let tooltip = lib.trayTooltip(0);   // у Tray нет чтения подсказки — держим сами
let quitting = false;
let updateReady = null;          // версия, скачанная и ждущая перезапуска
let retryTimer = null;
let watchTimer = null;
let lastUidnext = null;
const testLog = { external: [], notices: [], loads: [] };

function server() {
    return lib.originOf(process.env.POCHTA_SERVER || '') || settings.server || lib.originOf(pkg.defaultServer || '') || '';
}
function saveSettings() { store.save(SETTINGS_FILE(), settings); }
function icon(name) { return nativeImage.createFromPath(path.join(ASSETS, name)); }

// ── Один экземпляр: второй запуск (ярлык, mailto: из другой программы) передаёт работу первому ─────────
if (!app.requestSingleInstanceLock()) {
    app.quit();
} else {
    app.on('second-instance', (_e, argv) => {
        const m = lib.findMailtoArg(argv);
        if (m) openMailto(m); else showWindow();
    });
    app.whenReady().then(start);
}

function start() {
    settings = store.load(SETTINGS_FILE());
    Menu.setApplicationMenu(null);
    setupSession();
    createTray();
    createWindow();
    applyAutostart();
    registerMailto();
    const m = lib.findMailtoArg(process.argv);
    if (m) openMailto(m);
    startWatch();
    setupUpdates();
    if (TEST) {
        // Для сквозных тестов (test/e2e): состояние оболочки и перехват «открыть в браузере».
        shell.openExternal = async (url) => { testLog.external.push(url); };
        globalThis.__pochta = {
            get unread() { return unread; },
            get tooltip() { return tooltip; },
            get settings() { return { ...settings }; },
            get log() { return testLog; },
            get visible() { return !!win && win.isVisible(); },
            show: () => showWindow(),
            setNotifications: (on) => { settings.notifications = !!on; saveSettings(); },
            watchOnce: () => watchInbox(),
            get updateReady() { return updateReady; },
        };
    }
}

// ── Сессия: разрешения, загрузки, проверка орфографии ──────────────────────────────────────────────
function setupSession() {
    const ses = session.fromPartition(PARTITION);
    ses.setPermissionRequestHandler((_wc, permission, cb, details) => cb(lib.allowPermission(permission, details.requestingUrl || '', server())));
    ses.setPermissionCheckHandler((_wc, permission, origin) => lib.allowPermission(permission, origin || '', server()));
    ses.setSpellCheckerLanguages(['ru', 'en-US']);
    ses.on('will-download', (_e, item) => {
        item.once('done', (_ev, state) => {
            if (state !== 'completed') return;
            const file = item.getSavePath();
            notify('Файл сохранён', path.basename(file), () => shell.showItemInFolder(file));
        });
    });
}

// ── Окно ──────────────────────────────────────────────────────────────────────────────────────────
function createWindow() {
    const saved = lib.visibleBounds(settings.bounds, screen.getAllDisplays());
    win = new BrowserWindow({
        width: 1280, height: 860, ...(saved || {}),
        minWidth: 820, minHeight: 560,
        show: false,
        title: 'Почта',
        icon: path.join(ASSETS, 'icon.png'),
        backgroundColor: '#F5F3EF',
        autoHideMenuBar: true,
        webPreferences: {
            preload: path.join(__dirname, 'preload.js'),
            partition: PARTITION,
            contextIsolation: true,
            sandbox: true,
            nodeIntegration: false,
            spellcheck: true,
            backgroundThrottling: false,   // окно в трее продолжает проверять почту
        },
    });
    if (settings.maximized) win.maximize();
    const hidden = process.argv.includes('--hidden');   // автозапуск с Windows — сразу в трей
    win.once('ready-to-show', () => { if (!hidden) win.show(); });

    const wc = win.webContents;
    wc.setWindowOpenHandler(({ url }) => {
        const kind = lib.classifyUrl(url, server());
        if (kind === 'internal') {
            // Печать письма, вложение во вкладке — отдельное окно той же почты (та же сессия, без моста).
            return { action: 'allow', overrideBrowserWindowOptions: { autoHideMenuBar: true, icon: path.join(ASSETS, 'icon.png'), webPreferences: { partition: PARTITION, contextIsolation: true, sandbox: true, nodeIntegration: false } } };
        }
        if (kind === 'external') shell.openExternal(url);
        if (kind === 'mailto') openMailto(url);
        return { action: 'deny' };
    });
    wc.on('will-navigate', (e, url) => {
        const kind = lib.classifyUrl(url, server());
        if (kind === 'internal') return;
        if (url.startsWith('file:') && wc.getURL().startsWith('file:')) return;   // экраны самого приложения
        e.preventDefault();
        if (kind === 'external') shell.openExternal(url);
        if (kind === 'mailto') openMailto(url);
    });
    wc.on('did-fail-load', (_e, code, desc, url, isMainFrame) => {
        if (TEST) testLog.loads.push(['fail', code, url, isMainFrame]);
        if (!isMainFrame || code === -3) return;   // -3: переход отменён — это не ошибка связи
        showOffline(url, desc);
    });
    // Таймер повтора здесь не гасим: после did-fail-load Chromium «дозагружает» свою страницу ошибки
    // с адресом сервера, и отмена по этому событию оставляла экран «нет связи» навсегда. Таймер и так
    // ничего не делает, если открыт уже не экран «нет связи» (проверка в showOffline).
    wc.on('did-finish-load', () => { wc.setZoomLevel(settings.zoom || 0); });
    wc.on('page-title-updated', (_e, title) => {
        // На странице «Входящих» число непрочитанных есть в заголовке — запасной путь, если мост молчит.
        const n = lib.unreadFromTitle(title);
        if (n !== null) setUnread(n);
    });
    wc.on('before-input-event', (e, input) => onKey(e, input));
    // Ctrl + колесо: тот же шаг, что у Ctrl + / −, и масштаб запоминается между запусками.
    wc.on('zoom-changed', (_e, dir) => {
        settings.zoom = Math.max(-3, Math.min(4, (settings.zoom || 0) + (dir === 'in' ? 0.5 : -0.5)));
        wc.setZoomLevel(settings.zoom); saveSettings();
    });
    wc.on('context-menu', (_e, p) => contextMenu(p));

    win.on('focus', () => win.flashFrame(false));
    win.on('close', (e) => {
        rememberBounds();
        if (quitting) return;
        // Крестик — в трей: письма и напоминания продолжают приходить. Выход — в меню значка.
        e.preventDefault();
        win.hide();
        if (!settings.trayHintShown) {
            settings.trayHintShown = true; saveSettings();
            notify('Почта работает в фоне', 'Уведомления о письмах продолжат приходить. Открыть — значок в трее, выйти — «Выход» в его меню.');
        }
    });
    win.on('resize', debounce(rememberBounds, 800));
    win.on('move', debounce(rememberBounds, 800));

    loadHome();
}

function loadHome(pathname = '/mail') {
    clearTimeout(retryTimer);
    const s = server();
    if (!s) { win.loadFile(path.join(__dirname, 'pages', 'setup.html')); return; }
    win.loadURL(s + pathname);
}

function showOffline(url, reason) {
    const target = url && lib.classifyUrl(url, server()) === 'internal' ? url : server() + '/mail';
    win.loadFile(path.join(__dirname, 'pages', 'offline.html'), { query: { target, reason: String(reason || ''), retry: String(RETRY_MS) } });
    clearTimeout(retryTimer);
    retryTimer = setTimeout(() => {
        if (TEST) testLog.loads.push(['retry', win.webContents.getURL().slice(0, 40), target]);
        if (win && /\/offline\.html(\?|$)/.test(win.webContents.getURL())) win.loadURL(target).catch(() => {});
    }, RETRY_MS);
}

function showWindow() {
    if (!win) return;
    if (win.isMinimized()) win.restore();
    win.show();
    win.focus();
}

function openMailto(mailto) {
    const p = lib.mailtoPath(mailto);
    if (!p || !server()) { showWindow(); return; }
    win.loadURL(server() + p);
    showWindow();
}

function rememberBounds() {
    if (!win || win.isDestroyed() || win.isMinimized()) return;
    settings.maximized = win.isMaximized();
    if (!settings.maximized) settings.bounds = win.getBounds();
    saveSettings();
}

// ── Клавиши: меню у окна нет, поэтому обновить, масштаб и отладку ловим сами ──────────────────────
function onKey(e, input) {
    if (input.type !== 'keyDown') return;
    const wc = win.webContents;
    const ctrl = input.control || input.meta;
    const zoom = (z) => { settings.zoom = Math.max(-3, Math.min(4, z)); wc.setZoomLevel(settings.zoom); saveSettings(); e.preventDefault(); };
    if (input.key === 'F5' || (ctrl && input.key.toLowerCase() === 'r')) { wc.reload(); e.preventDefault(); }
    else if (ctrl && (input.key === '=' || input.key === '+')) zoom(settings.zoom + 0.5);
    else if (ctrl && input.key === '-') zoom(settings.zoom - 0.5);
    else if (ctrl && input.key === '0') zoom(0);
    else if (input.key === 'F11') { win.setFullScreen(!win.isFullScreen()); e.preventDefault(); }
    else if (ctrl && input.shift && input.key.toLowerCase() === 'i') { wc.toggleDevTools(); e.preventDefault(); }
}

// ── Меню правой кнопки: у Electron его нет совсем — без него не вставить текст и не исправить опечатку ─
function contextMenu(p) {
    const wc = win.webContents;
    const items = [];
    if (p.misspelledWord) {
        for (const s of p.dictionarySuggestions.slice(0, 5)) items.push({ label: s, click: () => wc.replaceMisspelling(s) });
        if (!p.dictionarySuggestions.length) items.push({ label: 'Нет вариантов', enabled: false });
        items.push({ label: 'Добавить в словарь', click: () => wc.session.addWordToSpellCheckerDictionary(p.misspelledWord) }, { type: 'separator' });
    }
    if (p.linkURL && !p.linkURL.startsWith('javascript:')) {
        const kind = lib.classifyUrl(p.linkURL, server());
        if (kind === 'external') items.push({ label: 'Открыть ссылку в браузере', click: () => shell.openExternal(p.linkURL) });
        items.push({ label: 'Копировать адрес ссылки', click: () => clipboard.writeText(p.linkURL) }, { type: 'separator' });
    }
    if (p.isEditable) {
        items.push(
            { label: 'Отменить', role: 'undo', enabled: p.editFlags.canUndo },
            { label: 'Повторить', role: 'redo', enabled: p.editFlags.canRedo },
            { type: 'separator' },
            { label: 'Вырезать', role: 'cut', enabled: p.editFlags.canCut },
            { label: 'Копировать', role: 'copy', enabled: p.editFlags.canCopy },
            { label: 'Вставить', role: 'paste', enabled: p.editFlags.canPaste },
            { label: 'Вставить без оформления', role: 'pasteAndMatchStyle', enabled: p.editFlags.canPaste },
            { type: 'separator' },
            { label: 'Выделить всё', role: 'selectAll' },
        );
    } else if (p.selectionText) {
        items.push({ label: 'Копировать', role: 'copy' });
    }
    if (p.mediaType === 'image' && p.srcURL) items.push({ label: 'Копировать изображение', click: () => wc.copyImageAt(p.x, p.y) });
    while (items.length && items[items.length - 1].type === 'separator') items.pop();
    if (items.length) Menu.buildFromTemplate(items).popup({ window: win });
}

// ── Трей и значок на панели задач ─────────────────────────────────────────────────────────────────
function createTray() {
    tray = new Tray(icon('tray.png'));
    tray.setToolTip(tooltip);
    tray.on('click', () => (win && win.isVisible() && win.isFocused() ? win.hide() : showWindow()));
    rebuildTrayMenu();
}

function rebuildTrayMenu() {
    if (!tray) return;
    const items = [
        { label: 'Открыть почту', click: () => { showWindow(); } },
        { label: 'Написать письмо', enabled: !!server(), click: () => { loadHome('/mail?compose=1'); showWindow(); } },
        { type: 'separator' },
        { label: 'Уведомления о новых письмах', type: 'checkbox', checked: settings.notifications, click: (mi) => { settings.notifications = mi.checked; saveSettings(); } },
        { label: 'Запускать вместе с Windows', type: 'checkbox', checked: settings.autostart, click: (mi) => { settings.autostart = mi.checked; saveSettings(); applyAutostart(); } },
        { label: 'Открывать ссылки «mailto:» в Почте…', enabled: app.isPackaged, click: () => chooseMailto() },
        { type: 'separator' },
    ];
    if (updateReady) items.push({ label: `Перезапустить и обновить до ${updateReady}`, click: () => installUpdate() });
    else items.push({ label: 'Проверить обновления', enabled: app.isPackaged, click: () => checkUpdates(true) });
    items.push(
        { label: 'Сменить сервер почты…', click: () => changeServer() },
        { label: `О программе (версия ${app.getVersion()})`, click: () => about() },
        { type: 'separator' },
        { label: 'Выход', click: () => { quitting = true; app.quit(); } },
    );
    tray.setContextMenu(Menu.buildFromTemplate(items));
}

function setUnread(n) {
    n = Math.max(0, Math.floor(Number(n) || 0));
    if (n === unread) return;
    const grew = n > unread;
    unread = n;
    if (tray) {
        tooltip = lib.trayTooltip(n);
        tray.setToolTip(tooltip);
        tray.setImage(icon(n > 0 ? 'tray-unread.png' : 'tray.png'));
    }
    if (win && !win.isDestroyed()) {
        const f = lib.badgeFile(n);
        win.setOverlayIcon(f ? icon(path.join('badges', f)) : null, f ? lib.trayTooltip(n) : '');
        if (grew && !win.isFocused()) win.flashFrame(true);
    }
}

// ── Уведомления о новых письмах ──────────────────────────────────────────────────────────────────
function notify(title, body, onClick) {
    if (TEST) { testLog.notices.push({ title, body }); }
    if (!Notification.isSupported()) return;
    const n = new Notification({ title, body, icon: path.join(ASSETS, 'icon.png'), silent: false });
    n.on('click', () => { showWindow(); if (onClick) onClick(); });
    n.show();
}

/**
 * Фоновая проверка «Входящих» раз в 30 с. Страница «Входящих» сама показывает уведомления (Inbox.vue),
 * поэтому здесь — только когда открыта другая страница (календарь, контакты, облако) или экран «нет связи».
 * Счётчик на панели задач обновляется всегда.
 */
function startWatch() {
    clearInterval(watchTimer);
    watchTimer = setInterval(() => { watchInbox().catch(() => {}); }, WATCH_MS);
    setTimeout(() => { watchInbox().catch(() => {}); }, 5000);
}

async function watchInbox() {
    const s = server();
    if (!s) return;
    const ses = session.fromPartition(PARTITION);
    const get = async (p) => {
        const r = await ses.fetch(s + p, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, redirect: 'manual' });
        if (!r.ok) throw new Error('HTTP ' + r.status);   // не вошли (401/419), сервер недоступен — ждём
        return r.json();
    };
    const st = await get('/mail/api/status?folder=INBOX');
    if (typeof st.inboxUnseen === 'number') setUnread(st.inboxUnseen);
    const next = Number(st.folder && st.folder.uidnext) || 0;
    const prev = lastUidnext;
    lastUidnext = next;
    if (prev === null || next <= prev || !settings.notifications) return;
    const url = win && !win.isDestroyed() ? win.webContents.getURL() : '';
    if (lib.isInboxPage(url, s) && win.isVisible()) return;   // эта страница уведомит сама
    const list = await get('/mail/api/list/INBOX?offset=0&limit=10&filter=unread&folders=0');
    const fresh = (list.messages || []).filter((m) => Number(m.uid) >= prev);
    const n = lib.newMailNotice(fresh);
    if (n) notify(n.title, n.body, () => loadHome(n.uid ? '/mail?uid=' + n.uid : '/mail'));
}

// ── Мост со страницей почты ──────────────────────────────────────────────────────────────────────
function fromServer(e) {
    try { return new URL(e.senderFrame ? e.senderFrame.url : e.sender.getURL()).origin === server(); } catch { return false; }
}
function fromLocal(e) {
    try { return (e.senderFrame ? e.senderFrame.url : e.sender.getURL()).startsWith('file:'); } catch { return false; }
}
ipcMain.on('pochta:config', (e) => {
    e.returnValue = { version: app.getVersion(), hint: pkg.defaultServer ? '' : 'ivan@example.ru' };
});
ipcMain.on('pochta:notifications', (e) => { e.returnValue = fromServer(e) ? settings.notifications : false; });
ipcMain.on('pochta:unread', (e, n) => { if (fromServer(e)) setUnread(n); });
ipcMain.on('pochta:show', (e) => { if (fromServer(e)) showWindow(); });
ipcMain.handle('setup:check', async (e, input) => {
    if (!fromLocal(e)) return { ok: false, error: 'Недоступно' };
    const candidates = lib.serverCandidates(input);
    if (!candidates.length) return { ok: false, error: 'Введите адрес почты (ivan@example.ru) или адрес сервера (mail.example.ru)' };
    for (const c of candidates) {
        if (await looksLikeMailServer(c)) {
            settings.server = c; saveSettings();
            lastUidnext = null;
            rebuildTrayMenu();
            setTimeout(() => loadHome(), 50);
            return { ok: true, server: c };
        }
    }
    return { ok: false, error: `Не нашли почту по адресу ${candidates.map((c) => new URL(c).host).join(' или ')}. Проверьте адрес, интернет или VPN.` };
});

/** Сервер наш, если по /mail/login отвечает страница входа веб-почты (Inertia, data-page). */
async function looksLikeMailServer(origin) {
    try {
        const r = await session.fromPartition(PARTITION).fetch(origin + '/mail/login', { redirect: 'follow' });
        if (!r.ok) return false;
        const t = await r.text();
        return /data-page=|id="app"/.test(t);
    } catch { return false; }
}

async function changeServer() {
    showWindow();
    const r = await dialog.showMessageBox(win, {
        type: 'question', buttons: ['Сменить', 'Отмена'], defaultId: 1, cancelId: 1, title: 'Почта',
        message: 'Сменить сервер почты?', detail: `Сейчас: ${server() || 'не задан'}. Откроется экран ввода адреса; вход на новом сервере — заново.`,
    });
    if (r.response !== 0) return;
    clearTimeout(retryTimer);
    settings.server = ''; saveSettings(); lastUidnext = null; setUnread(0);
    win.loadFile(path.join(__dirname, 'pages', 'setup.html'));
}

function about() {
    showWindow();
    dialog.showMessageBox(win, {
        type: 'info', title: 'О программе', buttons: ['Закрыть'],
        message: `Почта ${app.getVersion()}`,
        detail: `Сервер: ${server() || 'не задан'}\nElectron ${process.versions.electron}, Chromium ${process.versions.chrome}\n\nКлавиши: F5 — обновить, Ctrl+колесо или Ctrl + / − — масштаб, Ctrl+0 — как было, F11 — во весь экран.`,
    });
}

// ── Автозапуск ───────────────────────────────────────────────────────────────────────────────────
function applyAutostart() {
    if (!app.isPackaged || TEST) return;   // из исходников в автозагрузку не прописываемся
    app.setLoginItemSettings({ openAtLogin: settings.autostart, args: ['--hidden'] });
}

// ── Ссылки mailto: ─────────────────────────────────────────────────────────────────────────────────
// Windows 10/11 не даёт программе самой стать почтой по умолчанию — выбирает человек в «Приложениях по
// умолчанию». Чтобы «Почта» там была, регистрируем её как приложение с возможностью mailto
// (RegisteredApplications → Capabilities → ProgId Pochta.mailto). Всё в HKCU, без прав администратора;
// при удалении ключи убирает build/installer.nsh.
const REG_APP = 'Pochta';
function registerMailto() {
    if (!app.isPackaged || TEST || process.platform !== 'win32') return;
    const exe = process.execPath;
    const add = (key, ...rest) => new Promise((r) => execFile('reg.exe', ['add', key, ...rest, '/f'], { windowsHide: true }, () => r()));
    const cls = 'HKCU\\Software\\Classes\\Pochta.mailto';
    const cap = 'HKCU\\Software\\' + REG_APP + '\\Capabilities';
    Promise.all([
        add(cls, '/ve', '/d', 'Ссылка «Написать письмо»'),
        add(cls, '/v', 'URL Protocol', '/d', ''),
        add(cls + '\\DefaultIcon', '/ve', '/d', `"${exe}",0`),
        add(cls + '\\shell\\open\\command', '/ve', '/d', `"${exe}" "%1"`),
        add(cap, '/v', 'ApplicationName', '/d', 'Почта'),
        add(cap, '/v', 'ApplicationDescription', '/d', 'Почта для Windows: письма, календарь, контакты'),
        add(cap + '\\URLAssociations', '/v', 'mailto', '/d', 'Pochta.mailto'),
        add('HKCU\\Software\\RegisteredApplications', '/v', REG_APP, '/d', 'Software\\' + REG_APP + '\\Capabilities'),
    ]).catch(() => {});
}

/** Открыть в параметрах Windows страницу «Почты» среди приложений по умолчанию — там выбирают mailto. */
async function chooseMailto() {
    registerMailto();
    showWindow();
    const r = await dialog.showMessageBox(win, {
        type: 'info', title: 'Ссылки mailto:', buttons: ['Открыть параметры', 'Отмена'], defaultId: 0, cancelId: 1,
        message: 'Чтобы ссылки «написать письмо» на сайтах и в документах открывались в Почте',
        detail: 'В открывшихся параметрах Windows нажмите на строку MAILTO и выберите «Почта».',
    });
    if (r.response !== 0) return;
    shell.openExternal('ms-settings:defaultapps?registeredAppUser=' + REG_APP).catch(() => shell.openExternal('ms-settings:defaultapps'));
}

// ── Обновления: latest.yml и установщик лежат на том же сервере (/app/windows) ───────────────────
let updater = null;
function setupUpdates() {
    if (!app.isPackaged || (TEST && !UPDATE_TEST) || !server()) return;
    try {
        updater = require('electron-updater').autoUpdater;
    } catch { return; }
    updater.setFeedURL({ provider: 'generic', url: lib.feedUrl(server()) });
    updater.autoDownload = true;
    updater.autoInstallOnAppQuit = true;
    updater.on('update-downloaded', (info) => {
        updateReady = info.version;
        rebuildTrayMenu();
        notify(`Обновление Почты ${info.version} готово`, 'Установится при следующем запуске. Сразу — «Перезапустить и обновить» в меню значка в трее.');
    });
    updater.on('error', () => {});   // нет связи, файла ещё нет — тихо, проверим позже
    setTimeout(() => checkUpdates(false), Number(process.env.POCHTA_UPDATE_DELAY_MS) || 60 * 1000);
    setInterval(() => checkUpdates(false), 6 * 3600 * 1000);
}

async function checkUpdates(manual) {
    if (!updater) return;
    try {
        const r = await updater.checkForUpdates();
        if (manual) {
            const v = r && r.updateInfo && r.updateInfo.version;
            const newer = v && v !== app.getVersion();
            dialog.showMessageBox(win, { type: 'info', title: 'Обновления', buttons: ['Хорошо'], message: newer ? `Скачиваю версию ${v}` : `У вас последняя версия (${app.getVersion()})`, detail: newer ? 'Когда скачается, придёт уведомление.' : '' });
        }
    } catch (err) {
        if (manual) dialog.showMessageBox(win, { type: 'warning', title: 'Обновления', buttons: ['Хорошо'], message: 'Не удалось проверить обновления', detail: String(err && err.message || err).slice(0, 300) });
    }
}

function installUpdate() {
    if (!updater) return;
    quitting = true;
    updater.quitAndInstall(true, true);   // тихо и сразу запустить новую версию
}

app.on('before-quit', () => { quitting = true; rememberBounds(); });
app.on('window-all-closed', () => { /* живём в трее */ });

function debounce(fn, ms) {
    let t = null;
    return () => { clearTimeout(t); t = setTimeout(fn, ms); };
}
