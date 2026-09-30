'use strict';
// «Почта» для Windows: веб-почта своих серверов в отдельном окне + то, чего нет у вкладки браузера:
// значок в трее со счётчиком, кружок с числом на панели задач, уведомления Windows о новых письмах
// (даже когда окно закрыто или открыт календарь), запуск вместе с Windows, ссылки mailto:, понятный экран
// «нет связи» и обновление само себя с сервера. Интерфейс почты — тот же, что в браузере, поэтому всё новое
// в веб-почте появляется в приложении сразу, без выпуска новой версии.
//
// Почтовых ящиков может быть несколько, в том числе на разных серверах (innotec.su, deltaservices.ru):
// у каждого своя встроенная страница (WebContentsView) и свой раздел хранения — свой вход, все остаются
// открытыми. Окно само — полоса ящиков слева (pages/shell.html), видна, когда ящиков больше одного.
const { app, BrowserWindow, WebContentsView, Tray, Menu, nativeImage, shell, ipcMain, session, Notification, dialog, screen, clipboard } = require('electron');
const path = require('node:path');
const { execFile } = require('node:child_process');
const lib = require('./lib');
const store = require('./settings');
const pkg = require('../package.json');

const TEST = process.env.POCHTA_TEST === '1';
// Проверка самообновления установленной версии (test/update-check.js): обновления разрешены и в тестовом режиме.
const UPDATE_TEST = TEST && process.env.POCHTA_UPDATE_TEST === '1';
if (process.env.POCHTA_USER_DATA) app.setPath('userData', process.env.POCHTA_USER_DATA);
// Тесты (POCHTA_TEST) — под своим идентификатором и без настоящих уведомлений: иначе Electron из node_modules
// создаёт в «Пуске» ярлык Electron.lnk с идентификатором установленной «Почты», и Windows рисует ей значок
// Electron (атом) на панели задач и в уведомлениях (так было 30.09.2026).
const APP_ID = TEST ? 'ru.mailadmin.pochta.test' : 'ru.mailadmin.pochta';
const ASSETS = path.join(__dirname, '..', 'assets');
const RETRY_MS = Number(process.env.POCHTA_RETRY_MS) || 15000;      // «нет связи»: повтор через 15 с
const WATCH_MS = Number(process.env.POCHTA_WATCH_MS) || 30000;      // фоновая проверка «Входящих»
const SETTINGS_FILE = () => path.join(app.getPath('userData'), 'settings.json');
const BG = '#F5F3EF';
// Тесты не должны мешать человеку за компьютером: окна — за пределами экрана, без значка на панели задач
// и без перехвата фокуса (30.09.2026 окна тестов мелькали на экране и забирали ввод).
const OFFSCREEN = TEST ? { x: -20000, y: -20000, skipTaskbar: true } : {};

app.setAppUserModelId(APP_ID);   // без него Windows не показывает уведомления от имени «Почты»

let settings = lib.mergeSettings(null);
let win = null;
let tray = null;
let setupView = null;            // экран «адрес почты»: первый запуск или добавление ящика
let adding = false;
let totalUnread = 0;
let tooltip = lib.trayTooltip(0);   // у Tray нет чтения подсказки — держим сами
let quitting = false;
let updateReady = null;          // версия, скачанная и ждущая перезапуска
let watchTimer = null;
const views = new Map();         // id ящика → { acc, view, unread, lastUidnext, retryTimer }
const sessionsReady = new Set();
const testLog = { external: [], notices: [], loads: [] };

const accounts = () => settings.accounts;
const multi = () => accounts().length > 1;
const activeAcc = () => accounts().find((a) => a.id === settings.active) || accounts()[0] || null;
const activeRec = () => { const a = activeAcc(); return a ? views.get(a.id) : null; };
const label = (acc) => lib.accountLabel(acc.server);
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

async function start() {
    settings = store.load(SETTINGS_FILE());
    if (!accounts().length) {
        const seeded = lib.originOf(process.env.POCHTA_SERVER || '') || await legacyServer();
        if (seeded) { settings.accounts = [{ id: 'main', server: seeded }]; settings.active = 'main'; saveSettings(); }
    }
    Menu.setApplicationMenu(null);
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
            get unread() { return totalUnread; },
            get tooltip() { return tooltip; },
            get settings() { return JSON.parse(JSON.stringify(settings)); },
            get log() { return testLog; },
            get visible() { return !!win && win.isVisible(); },
            get adding() { return adding; },
            get active() { return settings.active; },
            show: () => showWindow(),
            setNotifications: (on) => { settings.notifications = !!on; saveSettings(); },
            watchOnce: () => watchAll(),
            addAccount: () => startAdding(),
            switchTo: (id) => switchTo(id),
            removeAccount: (id) => removeAccount(id, false),
            get updateReady() { return updateReady; },
        };
    }
}

/**
 * Версии 1.0.x открывали сервер, зашитый при сборке (mail.innotec.su), и не записывали его в настройки.
 * Первым ящиком он становится, только если в нём действительно выполнен вход: сотрудники второй компании,
 * поставившие 1.0.x, видели лишь страницу входа innotec — им чужой сервер не нужен, покажем выбор адреса.
 * Нет связи при запуске — решаем по наличию кук сервера (иначе человек потерял бы свой ящик).
 */
async function legacyServer() {
    const s = lib.originOf(process.env.POCHTA_LEGACY_SERVER || pkg.legacyServer || '');
    if (!s) return '';
    const ses = session.fromPartition(lib.partitionFor('main'));
    try {
        if (!(await ses.cookies.get({ url: s })).length) return '';
    } catch { return ''; }
    try {
        const r = await ses.fetch(s + '/mail/api/status?folder=INBOX', { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, redirect: 'manual' });
        return r.ok ? s : '';
    } catch { return s; }
}

// ── Разделы хранения: разрешения, загрузки, проверка орфографии — у каждого ящика свои ────────────────
function setupSession(acc) {
    const part = lib.partitionFor(acc.id);
    if (sessionsReady.has(part)) return;
    sessionsReady.add(part);
    const ses = session.fromPartition(part);
    const srv = () => (accounts().find((a) => lib.partitionFor(a.id) === part) || {}).server || '';
    ses.setPermissionRequestHandler((_wc, permission, cb, details) => cb(lib.allowPermission(permission, details.requestingUrl || '', srv())));
    ses.setPermissionCheckHandler((_wc, permission, origin) => lib.allowPermission(permission, origin || '', srv()));
    ses.setSpellCheckerLanguages(['ru', 'en-US']);
    ses.on('will-download', (_e, item) => {
        item.once('done', (_ev, state) => {
            if (state !== 'completed') return;
            const file = item.getSavePath();
            notify('Файл сохранён', path.basename(file), () => shell.showItemInFolder(file));
        });
    });
}

// ── Окно: полоса ящиков + страницы ящиков поверх неё ─────────────────────────────────────────────────
function createWindow() {
    const saved = lib.visibleBounds(settings.bounds, screen.getAllDisplays());
    win = new BrowserWindow({
        width: 1280, height: 860, ...(saved || {}), ...OFFSCREEN,
        minWidth: 820, minHeight: 560,
        show: false,
        title: 'Почта',
        icon: path.join(ASSETS, 'icon.png'),
        backgroundColor: BG,
        autoHideMenuBar: true,
        webPreferences: {
            preload: path.join(__dirname, 'shell-preload.js'),
            contextIsolation: true,
            sandbox: true,
            nodeIntegration: false,
        },
    });
    if (settings.maximized) win.maximize();
    const hidden = process.argv.includes('--hidden');   // автозапуск с Windows — сразу в трей
    win.once('ready-to-show', () => { if (hidden) return; if (TEST) win.showInactive(); else win.show(); });
    win.on('page-title-updated', (e) => e.preventDefault());   // заголовок окна — от страницы ящика
    win.webContents.on('before-input-event', (e, input) => onKey(e, input, null));
    win.webContents.on('will-navigate', (e) => e.preventDefault());
    win.webContents.setWindowOpenHandler(() => ({ action: 'deny' }));
    win.loadFile(path.join(__dirname, 'pages', 'shell.html'));

    win.on('focus', () => { win.flashFrame(false); const r = activeRec(); if (r && !adding) r.view.webContents.focus(); });
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
    win.on('resize', () => { layout(); debounced(); });
    win.on('move', debounced);

    for (const acc of accounts()) createAccountView(acc);
    if (!accounts().length) startAdding();
    layout();
}
const debounced = debounce(rememberBounds, 800);

function createAccountView(acc) {
    setupSession(acc);
    const view = new WebContentsView({
        webPreferences: {
            preload: path.join(__dirname, 'preload.js'),
            partition: lib.partitionFor(acc.id),
            contextIsolation: true,
            sandbox: true,
            nodeIntegration: false,
            spellcheck: true,
            backgroundThrottling: false,   // окно в трее продолжает проверять почту
        },
    });
    view.setBackgroundColor(BG);
    const rec = { acc, view, unread: 0, lastUidnext: null, retryTimer: null };
    views.set(acc.id, rec);
    wireView(rec);
    win.contentView.addChildView(view);
    view.webContents.loadURL(acc.server + '/mail');
    return rec;
}

function wireView(rec) {
    const wc = rec.view.webContents;
    const srv = () => rec.acc.server;
    wc.setWindowOpenHandler(({ url }) => {
        const kind = lib.classifyUrl(url, srv());
        if (kind === 'internal') {
            // Печать письма, вложение во вкладке — отдельное окно той же почты (тот же вход, без моста).
            return { action: 'allow', overrideBrowserWindowOptions: { ...OFFSCREEN, autoHideMenuBar: true, icon: path.join(ASSETS, 'icon.png'), webPreferences: { partition: lib.partitionFor(rec.acc.id), contextIsolation: true, sandbox: true, nodeIntegration: false } } };
        }
        if (kind === 'external') shell.openExternal(url);
        if (kind === 'mailto') openMailto(url);
        return { action: 'deny' };
    });
    wc.on('will-navigate', (e, url) => {
        const kind = lib.classifyUrl(url, srv());
        if (kind === 'internal') return;
        if (url.startsWith('file:') && wc.getURL().startsWith('file:')) return;   // экраны самого приложения
        e.preventDefault();
        if (kind === 'external') shell.openExternal(url);
        if (kind === 'mailto') openMailto(url);
    });
    wc.on('did-fail-load', (_e, code, desc, url, isMainFrame) => {
        if (TEST) testLog.loads.push(['fail', rec.acc.id, code, url, isMainFrame]);
        if (!isMainFrame || code === -3) return;   // -3: переход отменён — это не ошибка связи
        showOffline(rec, url, desc);
    });
    // Таймер повтора здесь не гасим: после did-fail-load Chromium «дозагружает» свою страницу ошибки
    // с адресом сервера, и отмена по этому событию оставляла экран «нет связи» навсегда.
    wc.on('did-finish-load', () => { wc.setZoomLevel(settings.zoom || 0); });
    wc.on('page-title-updated', (_e, title) => {
        // На странице «Входящих» число непрочитанных есть в заголовке — запасной путь, если мост молчит.
        const n = lib.unreadFromTitle(title);
        if (n !== null) setUnread(rec, n);
        if (rec === activeRec()) updateTitle();
    });
    wc.on('before-input-event', (e, input) => onKey(e, input, wc));
    // Ctrl + колесо: тот же шаг, что у Ctrl + / −, и масштаб запоминается между запусками.
    wc.on('zoom-changed', (_e, dir) => setZoom((settings.zoom || 0) + (dir === 'in' ? 0.5 : -0.5)));
    wc.on('context-menu', (_e, p) => contextMenu(p, wc, srv()));
}

function layout() {
    if (!win || win.isDestroyed()) return;
    const [w, h] = win.getContentSize();
    const rail = lib.railWidth(accounts().length, adding);
    const act = activeAcc();
    for (const [id, rec] of views) {
        const on = !adding && act && id === act.id;
        rec.view.setVisible(on);
        if (on) rec.view.setBounds({ x: rail, y: 0, width: Math.max(0, w - rail), height: h });
    }
    if (setupView) {
        setupView.setVisible(adding);
        if (adding) setupView.setBounds({ x: rail, y: 0, width: Math.max(0, w - rail), height: h });
    }
    pushShell();
    updateTitle();
}

function pushShell() {
    if (!win || win.isDestroyed()) return;
    const act = activeAcc();
    win.webContents.send('shell:state', {
        adding,
        accounts: accounts().map((a) => ({ id: a.id, label: label(a), unread: (views.get(a.id) || {}).unread || 0, active: !!act && a.id === act.id })),
    });
}

function updateTitle() {
    if (!win || win.isDestroyed()) return;
    const r = activeRec();
    if (adding || !r) { win.setTitle(accounts().length ? 'Почта — новый ящик' : 'Почта'); return; }
    const t = r.view.webContents.getTitle() || 'Почта';
    win.setTitle(multi() ? `${t} · ${label(r.acc)}` : t);
}

function switchTo(id) {
    if (!accounts().some((a) => a.id === id)) return;
    closeSetup();
    settings.active = id; saveSettings();
    layout();
    rebuildTrayMenu();
    const r = activeRec();
    if (r && !TEST) r.view.webContents.focus();
}

// ── Добавление ящика ───────────────────────────────────────────────────────────────────────────────
function startAdding() {
    closeSetup();
    adding = true;
    setupView = new WebContentsView({ webPreferences: { preload: path.join(__dirname, 'preload.js'), contextIsolation: true, sandbox: true, nodeIntegration: false } });
    setupView.setBackgroundColor(BG);
    setupView.webContents.on('will-navigate', (e) => e.preventDefault());
    setupView.webContents.setWindowOpenHandler(() => ({ action: 'deny' }));
    setupView.webContents.on('before-input-event', (e, input) => onKey(e, input, null));
    win.contentView.addChildView(setupView);
    setupView.webContents.loadFile(path.join(__dirname, 'pages', 'setup.html'));
    layout();
    setupView.webContents.once('did-finish-load', () => { if (setupView && !TEST) setupView.webContents.focus(); });
}

function closeSetup() {
    adding = false;
    if (!setupView) return;
    const v = setupView;
    setupView = null;
    try { win.contentView.removeChildView(v); } catch { /* уже убран */ }
    v.webContents.close();
}

function addAccount(server) {
    const id = lib.newAccountId(accounts());
    const acc = { id, server };
    settings.accounts = [...accounts(), acc];
    settings.active = id;
    saveSettings();
    closeSetup();
    createAccountView(acc);
    layout();
    rebuildTrayMenu();
    if (accounts().length === 1) setupUpdates();
    return acc;
}

async function removeAccount(id, ask = true) {
    const acc = accounts().find((a) => a.id === id);
    if (!acc) return;
    if (ask) {
        showWindow();
        const r = await dialog.showMessageBox(win, {
            type: 'question', buttons: ['Убрать', 'Отмена'], defaultId: 1, cancelId: 1, title: 'Почта',
            message: `Убрать ящик ${label(acc)} из приложения?`,
            detail: 'Письма на сервере останутся. Вход в этот ящик в приложении забудется — чтобы вернуть, добавьте его снова.',
        });
        if (r.response !== 0) return;
    }
    const rec = views.get(id);
    views.delete(id);
    if (rec) {
        clearTimeout(rec.retryTimer);
        try { win.contentView.removeChildView(rec.view); } catch { /* уже убран */ }
        rec.view.webContents.close();
    }
    // Выйти из ящика: без этого повторное добавление открыло бы его уже вошедшим.
    session.fromPartition(lib.partitionFor(id)).clearStorageData().catch(() => {});
    settings.accounts = accounts().filter((a) => a.id !== id);
    if (settings.active === id) settings.active = accounts().length ? accounts()[0].id : '';
    saveSettings();
    recountUnread();
    if (!accounts().length) startAdding(); else layout();
    rebuildTrayMenu();
}

function accountMenu(id) {
    const acc = accounts().find((a) => a.id === id);
    if (!acc) return;
    Menu.buildFromTemplate([
        { label: label(acc), enabled: false },
        { type: 'separator' },
        { label: 'Открыть', click: () => switchTo(id) },
        { label: 'Обновить страницу', click: () => { const r = views.get(id); if (r) r.view.webContents.reload(); } },
        { type: 'separator' },
        { label: 'Убрать ящик из приложения…', click: () => removeAccount(id) },
    ]).popup({ window: win });
}

// ── Экран «нет связи», окно, mailto: ──────────────────────────────────────────────────────────────────
function showOffline(rec, url, reason) {
    const wc = rec.view.webContents;
    const target = url && lib.classifyUrl(url, rec.acc.server) === 'internal' ? url : rec.acc.server + '/mail';
    wc.loadFile(path.join(__dirname, 'pages', 'offline.html'), { query: { target, reason: String(reason || ''), retry: String(RETRY_MS) } });
    clearTimeout(rec.retryTimer);
    rec.retryTimer = setTimeout(() => {
        if (TEST) testLog.loads.push(['retry', rec.acc.id, wc.getURL().slice(0, 40), target]);
        if (views.get(rec.acc.id) === rec && /\/offline\.html(\?|$)/.test(wc.getURL())) wc.loadURL(target).catch(() => {});
    }, RETRY_MS);
}

function openPath(pathname, id) {
    const rec = id ? views.get(id) : activeRec();
    if (!rec) { showWindow(); return; }
    clearTimeout(rec.retryTimer);
    if (id && id !== settings.active) {
        switchTo(id);
    } else {
        closeSetup();
        layout();
    }
    rec.view.webContents.loadURL(rec.acc.server + pathname);
    showWindow();
}

function showWindow() {
    if (!win) return;
    if (TEST) { win.showInactive(); return; }
    if (win.isMinimized()) win.restore();
    win.show();
    win.focus();
}

/** mailto: — новое письмо в выбранном сейчас ящике (от чьего имени писать, человек поменяет в окне письма). */
function openMailto(mailto) {
    const p = lib.mailtoPath(mailto);
    if (!p || !activeRec()) { showWindow(); return; }
    openPath(p);
}

function rememberBounds() {
    if (!win || win.isDestroyed() || win.isMinimized()) return;
    settings.maximized = win.isMaximized();
    if (!settings.maximized) settings.bounds = win.getBounds();
    saveSettings();
}

function setZoom(z) {
    settings.zoom = Math.max(-3, Math.min(4, z));
    for (const rec of views.values()) rec.view.webContents.setZoomLevel(settings.zoom);
    saveSettings();
}

// ── Клавиши: меню у окна нет, поэтому обновить, масштаб, ящики и отладку ловим сами ─────────────────
function onKey(e, input, wc) {
    if (input.type !== 'keyDown') return;
    const ctrl = input.control || input.meta;
    const k = input.key;
    if (ctrl && !input.shift && !input.alt && /^[1-9]$/.test(k)) {
        const acc = accounts()[Number(k) - 1];
        if (acc) { switchTo(acc.id); e.preventDefault(); }
        return;
    }
    if (!wc) return;
    if (k === 'F5' || (ctrl && k.toLowerCase() === 'r')) { wc.reload(); e.preventDefault(); }
    else if (ctrl && (k === '=' || k === '+')) { setZoom(settings.zoom + 0.5); e.preventDefault(); }
    else if (ctrl && k === '-') { setZoom(settings.zoom - 0.5); e.preventDefault(); }
    else if (ctrl && k === '0') { setZoom(0); e.preventDefault(); }
    else if (k === 'F11') { win.setFullScreen(!win.isFullScreen()); e.preventDefault(); }
    else if (ctrl && input.shift && k.toLowerCase() === 'i') { wc.toggleDevTools(); e.preventDefault(); }
}

// ── Меню правой кнопки: у Electron его нет совсем — без него не вставить текст и не исправить опечатку ─
function contextMenu(p, wc, server) {
    const items = [];
    if (p.misspelledWord) {
        for (const s of p.dictionarySuggestions.slice(0, 5)) items.push({ label: s, click: () => wc.replaceMisspelling(s) });
        if (!p.dictionarySuggestions.length) items.push({ label: 'Нет вариантов', enabled: false });
        items.push({ label: 'Добавить в словарь', click: () => wc.session.addWordToSpellCheckerDictionary(p.misspelledWord) }, { type: 'separator' });
    }
    if (p.linkURL && !p.linkURL.startsWith('javascript:')) {
        const kind = lib.classifyUrl(p.linkURL, server);
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
    const act = activeAcc();
    const items = [
        { label: 'Открыть почту', click: () => showWindow() },
        { label: 'Написать письмо', enabled: !!act, click: () => openPath('/mail?compose=1') },
        { type: 'separator' },
    ];
    for (const [i, a] of accounts().entries()) {
        const n = (views.get(a.id) || {}).unread || 0;
        items.push({ label: `${label(a)}${n ? ` — ${n}` : ''}`, type: 'radio', checked: !!act && a.id === act.id, accelerator: i < 9 ? `Ctrl+${i + 1}` : undefined, registerAccelerator: false, click: () => { switchTo(a.id); showWindow(); } });
    }
    items.push({ label: 'Добавить почтовый ящик…', click: () => { startAdding(); showWindow(); } });
    if (accounts().length) {
        items.push({ label: 'Убрать почтовый ящик', submenu: accounts().map((a) => ({ label: label(a) + '…', click: () => removeAccount(a.id) })) });
    }
    items.push(
        { type: 'separator' },
        { label: 'Уведомления о новых письмах', type: 'checkbox', checked: settings.notifications, click: (mi) => { settings.notifications = mi.checked; saveSettings(); } },
        { label: 'Запускать вместе с Windows', type: 'checkbox', checked: settings.autostart, click: (mi) => { settings.autostart = mi.checked; saveSettings(); applyAutostart(); } },
        { label: 'Открывать ссылки «mailto:» в Почте…', enabled: app.isPackaged, click: () => chooseMailto() },
        { type: 'separator' },
    );
    if (updateReady) items.push({ label: `Перезапустить и обновить до ${updateReady}`, click: () => installUpdate() });
    else items.push({ label: 'Проверить обновления', enabled: app.isPackaged, click: () => checkUpdates(true) });
    items.push(
        { label: `О программе (версия ${app.getVersion()})`, click: () => about() },
        { type: 'separator' },
        { label: 'Выход', click: () => { quitting = true; app.quit(); } },
    );
    tray.setContextMenu(Menu.buildFromTemplate(items));
}

function setUnread(rec, n) {
    n = Math.max(0, Math.floor(Number(n) || 0));
    if (!rec || rec.unread === n) return;
    rec.unread = n;
    recountUnread();
    rebuildTrayMenu();
}

function recountUnread() {
    const list = accounts().map((a) => ({ label: label(a), unread: (views.get(a.id) || {}).unread || 0 }));
    const n = list.reduce((s, a) => s + a.unread, 0);
    const grew = n > totalUnread;
    totalUnread = n;
    tooltip = lib.trayTooltip(n, list);
    if (tray) {
        tray.setToolTip(tooltip);
        tray.setImage(icon(n > 0 ? 'tray-unread.png' : 'tray.png'));
    }
    if (win && !win.isDestroyed()) {
        const f = lib.badgeFile(n);
        win.setOverlayIcon(f ? icon(path.join('badges', f)) : null, f ? tooltip : '');
        if (grew && !win.isFocused()) win.flashFrame(true);
    }
    pushShell();
}

// ── Уведомления о новых письмах ──────────────────────────────────────────────────────────────────
function notify(title, body, onClick) {
    if (TEST) { testLog.notices.push({ title, body }); return; }
    if (!Notification.isSupported()) return;
    const n = new Notification({ title, body, icon: path.join(ASSETS, 'icon.png'), silent: false });
    n.on('click', () => { showWindow(); if (onClick) onClick(); });
    n.show();
}

/**
 * Страница уведомляет сама, только если это «Входящие» выбранного ящика и окно на экране — тогда нажатие
 * открывает письмо без перезагрузки. Во всех остальных случаях (окно в трее, другой ящик, календарь) —
 * фоновая проверка ниже. Одно письмо — одно уведомление.
 */
function pageNotifies(rec) {
    return !!rec && !adding && rec === activeRec() && !!win && win.isVisible() && !win.isMinimized()
        && lib.isInboxPage(rec.view.webContents.getURL(), rec.acc.server);
}

function startWatch() {
    clearInterval(watchTimer);
    watchTimer = setInterval(() => { watchAll().catch(() => {}); }, WATCH_MS);
    setTimeout(() => { watchAll().catch(() => {}); }, 5000);
}

async function watchAll() {
    await Promise.all([...views.values()].map((rec) => watchInbox(rec).catch(() => {})));
}

async function watchInbox(rec) {
    const s = rec.acc.server;
    const ses = session.fromPartition(lib.partitionFor(rec.acc.id));
    const get = async (p) => {
        const r = await ses.fetch(s + p, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, redirect: 'manual' });
        if (!r.ok) throw new Error('HTTP ' + r.status);   // не вошли (401/419), сервер недоступен — ждём
        return r.json();
    };
    const st = await get('/mail/api/status?folder=INBOX');
    if (views.get(rec.acc.id) !== rec) return;   // ящик убрали, пока ждали ответа
    if (typeof st.inboxUnseen === 'number') setUnread(rec, st.inboxUnseen);
    const next = Number(st.folder && st.folder.uidnext) || 0;
    const prev = rec.lastUidnext;
    rec.lastUidnext = next;
    if (prev === null || next <= prev || !settings.notifications || pageNotifies(rec)) return;
    const list = await get('/mail/api/list/INBOX?offset=0&limit=10&filter=unread&folders=0');
    const fresh = (list.messages || []).filter((m) => Number(m.uid) >= prev);
    const n = lib.labelNotice(lib.newMailNotice(fresh), label(rec.acc), multi());
    if (n) notify(n.title, n.body, () => openPath(n.uid ? '/mail?uid=' + n.uid : '/mail', rec.acc.id));
}

// ── Мосты со страницами ─────────────────────────────────────────────────────────────────────────────
/** Ящик, с чьей страницы пришло сообщение; только если страница с его сервера. */
function senderAccount(e) {
    for (const rec of views.values()) {
        if (rec.view.webContents !== e.sender) continue;
        try { return new URL(e.senderFrame ? e.senderFrame.url : e.sender.getURL()).origin === rec.acc.server ? rec : null; } catch { return null; }
    }
    return null;
}
function fromLocal(e) {
    try { return (e.senderFrame ? e.senderFrame.url : e.sender.getURL()).startsWith('file:'); } catch { return false; }
}
ipcMain.on('pochta:config', (e) => {
    e.returnValue = { version: app.getVersion(), hint: 'ivan@example.ru', accounts: accounts().length };
});
ipcMain.on('pochta:notifications', (e) => {
    const rec = senderAccount(e);
    e.returnValue = !!rec && settings.notifications && pageNotifies(rec);
});
ipcMain.on('pochta:unread', (e, n) => { const rec = senderAccount(e); if (rec) setUnread(rec, n); });
ipcMain.on('pochta:show', (e) => { const rec = senderAccount(e); if (rec) { switchTo(rec.acc.id); showWindow(); } });
ipcMain.on('shell:ready', (e) => { if (win && e.sender === win.webContents) pushShell(); });
ipcMain.on('shell:switch', (e, id) => { if (win && e.sender === win.webContents) switchTo(String(id)); });
ipcMain.on('shell:add', (e) => { if (win && e.sender === win.webContents) startAdding(); });
ipcMain.on('shell:menu', (e, id) => { if (win && e.sender === win.webContents) accountMenu(String(id)); });
ipcMain.on('setup:cancel', (e) => {
    if (!setupView || e.sender !== setupView.webContents || !accounts().length) return;
    closeSetup(); layout();
});
ipcMain.handle('setup:check', async (e, input) => {
    if (!setupView || e.sender !== setupView.webContents || !fromLocal(e)) return { ok: false, error: 'Недоступно' };
    const candidates = lib.serverCandidates(input);
    if (!candidates.length) return { ok: false, error: 'Введите адрес почты (ivan@example.ru) или адрес сервера (mail.example.ru)' };
    if (accounts().length >= lib.MAX_ACCOUNTS) return { ok: false, error: `Больше ${lib.MAX_ACCOUNTS} ящиков добавить нельзя` };
    for (const c of candidates) {
        if (await looksLikeMailServer(c)) {
            setTimeout(() => addAccount(c), 50);
            return { ok: true, server: c };
        }
    }
    return { ok: false, error: `Не нашли почту по адресу ${candidates.map((c) => new URL(c).host).join(' или ')}. Проверьте адрес, интернет или VPN.` };
});

/** Сервер наш, если по /mail/login отвечает страница входа веб-почты (Inertia, data-page). */
async function looksLikeMailServer(origin) {
    try {
        const r = await session.defaultSession.fetch(origin + '/mail/login', { redirect: 'follow' });
        if (!r.ok) return false;
        const t = await r.text();
        return /data-page=|id="app"/.test(t);
    } catch { return false; }
}

function about() {
    showWindow();
    dialog.showMessageBox(win, {
        type: 'info', title: 'О программе', buttons: ['Закрыть'],
        message: `Почта ${app.getVersion()}`,
        detail: `Ящики: ${accounts().map((a) => label(a)).join(', ') || 'не добавлены'}\nElectron ${process.versions.electron}, Chromium ${process.versions.chrome}\n\n`
            + 'Клавиши: Ctrl+1…9 — ящики, F5 — обновить, Ctrl+колесо или Ctrl + / − — масштаб, Ctrl+0 — как было, F11 — во весь экран.',
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

// ── Обновления: latest.yml и установщик лежат на сервере почты (/app/windows) ──────────────────────
// Версии на всех серверах одинаковые (publish-desktop.sh выкладывает на все), берём сервер первого ящика.
let updater = null;
function setupUpdates() {
    if (updater || !app.isPackaged || (TEST && !UPDATE_TEST) || !accounts().length) return;
    try {
        updater = require('electron-updater').autoUpdater;
    } catch { return; }
    updater.setFeedURL({ provider: 'generic', url: lib.feedUrl(accounts()[0].server) });
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
