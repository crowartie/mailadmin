'use strict';
// Сквозные тесты приложения: настоящее окно Electron против локальных заглушек сервера (stub-server.js).
// Входа и паролей нет — проверяется оболочка: куда ведут ссылки, трей и счётчик, mailto:, «нет связи»,
// первый запуск, несколько ящиков на разных серверах, фоновая проверка почты и уведомления. Запуск: npm run e2e
const { test, expect, _electron } = require('@playwright/test');
const { spawn } = require('node:child_process');
const path = require('node:path');
const fs = require('node:fs');
const os = require('node:os');
const stub = require('./stub-server');

const ROOT = path.join(__dirname, '..', '..');
const opened = [];

async function launch({ server, args = [], userData, extraEnv = {} } = {}) {
    userData = userData || fs.mkdtempSync(path.join(os.tmpdir(), 'pochta-e2e-'));
    const env = { ...process.env, POCHTA_TEST: '1', POCHTA_USER_DATA: userData, POCHTA_RETRY_MS: '1500', POCHTA_WATCH_MS: '600000', ...extraEnv };
    delete env.POCHTA_SERVER;
    if (server) env.POCHTA_SERVER = server;
    const app = await _electron.launch({ args: [ROOT, ...args], env });
    opened.push(app);
    await app.firstWindow();
    return { app, userData, env };
}

/** Страница, чей адрес подходит под условие: окно — это полоса ящиков, почта каждого ящика — отдельная страница. */
async function page(app, pred, timeout = 15000) {
    const t0 = Date.now();
    for (;;) {
        const p = app.windows().find((w) => { try { return pred(w.url()); } catch { return false; } });
        if (p) return p;
        if (Date.now() - t0 > timeout) throw new Error('нет страницы: ' + app.windows().map((w) => w.url()).join(' | '));
        await new Promise((r) => setTimeout(r, 150));
    }
}
const mailPage = (app, origin) => page(app, (u) => u.startsWith(origin));
const shellPage = (app) => page(app, (u) => u.includes('shell.html'));
const setupPage = (app) => page(app, (u) => u.includes('setup.html'));
const inPage = (p, id) => p.evaluate((x) => document.getElementById(x).click(), id);   // отменённые переходы Playwright ждал бы до таймаута

const state = (app) => app.evaluate(() => {
    const p = globalThis.__pochta;
    return { unread: p.unread, tooltip: p.tooltip, visible: p.visible, adding: p.adding, active: p.active, external: [...p.log.external], notices: p.log.notices.map((n) => ({ ...n })), settings: p.settings };
});

test.afterEach(async () => {
    while (opened.length) {
        const a = opened.pop();
        await a.evaluate(({ app }) => { app.exit(0); }).catch(() => {});
        await a.close().catch(() => {});
    }
});

test('открывает почту своего сервера; мост на месте, Node странице недоступен; счётчик из заголовка и из моста', async () => {
    const s = await stub.start();
    const { app } = await launch({ server: s.origin });
    const win = await mailPage(app, s.origin);
    await win.waitForSelector('#inbox');
    expect(await win.evaluate(() => window.pochta && window.pochta.desktop)).toBe(true);
    expect(await win.evaluate(() => typeof require + '/' + typeof process)).toBe('undefined/undefined');
    await expect.poll(async () => (await state(app)).unread).toBe(3);
    expect((await state(app)).tooltip).toBe('Почта — 3 непрочитанных');
    await win.evaluate(() => window.pochta.setUnread(21));
    await expect.poll(async () => (await state(app)).tooltip).toBe('Почта — 21 непрочитанное');
    expect(await win.evaluate(() => Notification.permission)).toBe('granted');
    expect(await win.evaluate(() => window.pochta.notificationsOn())).toBe(true);
    // Один ящик — полосы слева нет, почта во всю ширину окна.
    expect(await app.evaluate(({ BrowserWindow }) => BrowserWindow.getAllWindows()[0].contentView.children.find((v) => v.getVisible()).getBounds().x)).toBe(0);
    expect(await app.evaluate(({ BrowserWindow }) => BrowserWindow.getAllWindows()[0].getTitle())).toBe('(3) Входящие — Почта');
    await s.close();
});

test('чужие ссылки — в браузер, file: — никуда, печать — отдельным окном почты', async () => {
    const s = await stub.start();
    const { app } = await launch({ server: s.origin });
    const win = await mailPage(app, s.origin);
    await win.waitForSelector('#inbox');
    await inPage(win, 'ext');
    await expect.poll(async () => (await state(app)).external).toContain('https://example.org/doc');
    expect(new URL(win.url()).pathname).toBe('/mail');

    await inPage(win, 'file');
    await win.waitForTimeout(500);
    expect(new URL(win.url()).pathname).toBe('/mail');
    expect((await state(app)).external).toHaveLength(1);

    const before = app.windows().length;
    await inPage(win, 'popup-ext');
    await expect.poll(async () => (await state(app)).external).toContain('https://example.org/popup');
    expect(app.windows()).toHaveLength(before);

    const [child] = await Promise.all([app.waitForEvent('window'), inPage(win, 'print')]);
    await child.waitForSelector('#print-page');
    expect(new URL(child.url()).pathname).toBe('/mail/print/INBOX/1');
    expect(await child.evaluate(() => typeof window.pochta)).toBe('undefined');   // мост только у страницы ящика
    await s.close();
});

test('mailto: из страницы и из другой программы (второй запуск) открывает новое письмо', async () => {
    const s = await stub.start();
    const { app, env } = await launch({ server: s.origin });
    const win = await mailPage(app, s.origin);
    await win.waitForSelector('#inbox');
    await inPage(win, 'mail');
    await win.waitForSelector('#compose');
    expect(await win.textContent('#compose')).toContain('ivan@example.org');

    // Второй экземпляр с тем же профилем отдаёт ссылку первому и выходит.
    const electronExe = require('electron');
    const second = spawn(electronExe, [ROOT, 'mailto:petr@example.org?subject=Привет'], { env, stdio: 'ignore' });
    const code = await new Promise((r) => second.on('exit', r));
    expect(code).toBe(0);
    await expect.poll(() => win.url(), { timeout: 10000 }).toContain('to=petr%40example.org');
    await win.waitForSelector('#compose');
    await s.close();
});

test('крестик прячет окно в трей, почта продолжает работать; подсказка — один раз', async () => {
    const s = await stub.start();
    const { app } = await launch({ server: s.origin });
    await (await mailPage(app, s.origin)).waitForSelector('#inbox');
    await expect.poll(async () => (await state(app)).visible).toBe(true);
    await app.evaluate(({ BrowserWindow }) => BrowserWindow.getAllWindows()[0].close());
    await expect.poll(async () => (await state(app)).visible).toBe(false);
    let st = await state(app);
    expect(st.notices.map((n) => n.title)).toContain('Почта работает в фоне');
    expect(st.settings.trayHintShown).toBe(true);
    await app.evaluate(() => globalThis.__pochta.show());
    await expect.poll(async () => (await state(app)).visible).toBe(true);
    await app.evaluate(({ BrowserWindow }) => BrowserWindow.getAllWindows()[0].close());
    await expect.poll(async () => (await state(app)).visible).toBe(false);
    st = await state(app);
    expect(st.notices.filter((n) => n.title === 'Почта работает в фоне')).toHaveLength(1);
    await s.close();
});

test('нет связи: экран с объяснением, сервер поднялся — почта открывается сама', async () => {
    const probe = await stub.start();
    const port = probe.port;
    await probe.close();
    const origin = `http://127.0.0.1:${port}`;
    const { app } = await launch({ server: origin });
    const win = await page(app, (u) => u.includes('offline.html'), 20000);
    await win.waitForSelector('text=Нет связи с сервером почты');
    const s = await stub.start(port);
    await expect.poll(() => win.url(), { timeout: 15000 }).toMatch(new RegExp(`^http://127\\.0\\.0\\.1:${port}/mail`));
    await win.waitForSelector('#inbox');
    await s.close();
});

test('первый запуск без сервера: экран выбора, ошибка на мусоре, адрес сервера — почта открылась и запомнилась', async () => {
    const s = await stub.start();
    const { app, userData } = await launch();
    const setup = await setupPage(app);
    expect(await setup.isVisible('#cancel')).toBe(false);   // первый ящик — отменять нечего
    await setup.fill('#addr', 'просто слово');
    await setup.click('#go');
    await expect(setup.locator('#err')).toContainText('Введите адрес почты');
    await setup.fill('#addr', s.origin);
    await setup.click('#go');
    await (await mailPage(app, s.origin)).waitForSelector('#inbox', { timeout: 15000 });
    const st = await state(app);
    expect(st.settings.accounts).toEqual([{ id: 'main', server: s.origin }]);
    expect(st.adding).toBe(false);
    expect(JSON.parse(fs.readFileSync(path.join(userData, 'settings.json'), 'utf8')).accounts[0].server).toBe(s.origin);
    await s.close();
});

test('фоновая проверка: новое письмо при открытом календаре → уведомление; выключили — тишина; на «Входящих» — молчит', async () => {
    const s = await stub.start();
    const { app } = await launch({ server: s.origin });
    const win = await mailPage(app, s.origin);
    await win.waitForSelector('#inbox');
    await app.evaluate(() => globalThis.__pochta.watchOnce());   // запомнить, с какого письма считать новые
    await win.goto(s.origin + '/calendar');
    await win.waitForSelector('#cal');

    s.state.messages = [
        { uid: 100, from: { name: 'Иванов Иван', mail: 'i@x.ru' }, subject: 'Счёт' },
        { uid: 101, from: { name: 'Петров Пётр', mail: 'p@x.ru' }, subject: 'Акт' },
    ];
    s.state.uidnext = 102; s.state.unseen = 5;
    await app.evaluate(() => globalThis.__pochta.watchOnce());
    let st = await state(app);
    expect(st.unread).toBe(5);
    expect(st.notices.at(-1)).toEqual({ title: '2 новых письма', body: 'Иванов Иван, Петров Пётр' });
    expect(await win.evaluate(() => window.pochta.notificationsOn())).toBe(false);   // календарь — страница не уведомляет

    await app.evaluate(() => globalThis.__pochta.setNotifications(false));
    s.state.messages = [{ uid: 102, from: { name: 'Сидоров' }, subject: 'Ещё' }];
    s.state.uidnext = 103;
    const before = (await state(app)).notices.length;
    await app.evaluate(() => globalThis.__pochta.watchOnce());
    expect((await state(app)).notices).toHaveLength(before);

    await app.evaluate(() => globalThis.__pochta.setNotifications(true));
    await win.goto(s.origin + '/mail');
    await win.waitForSelector('#inbox');
    expect(await win.evaluate(() => window.pochta.notificationsOn())).toBe(true);
    s.state.messages = [{ uid: 103, from: { name: 'Орлов' }, subject: 'Тема' }];
    s.state.uidnext = 104;
    await app.evaluate(() => globalThis.__pochta.watchOnce());
    expect((await state(app)).notices).toHaveLength(before);   // «Входящие» на экране — уведомляет страница сама
    await s.close();
});

test('два ящика на разных серверах: добавление, полоса слева, переключение, общий счётчик, уведомления с подписью, удаление', async () => {
    const a = await stub.start();
    const b = await stub.start();
    b.state.unseen = 2;
    const { app, userData } = await launch({ server: a.origin });
    const pa = await mailPage(app, a.origin);
    await pa.waitForSelector('#inbox');

    // Добавляем второй ящик: экран адреса с кнопкой «Отмена», полоса слева уже видна.
    await app.evaluate(() => globalThis.__pochta.addAccount());
    let setup = await setupPage(app);
    await setup.waitForSelector('#cancel:not([hidden])');
    await setup.click('#cancel');
    await expect.poll(async () => (await state(app)).adding).toBe(false);
    expect((await state(app)).settings.accounts).toHaveLength(1);

    await app.evaluate(() => globalThis.__pochta.addAccount());
    setup = await setupPage(app);
    await setup.fill('#addr', b.origin);
    await setup.click('#go');
    const pb = await mailPage(app, b.origin);
    await pb.waitForSelector('#inbox', { state: 'attached' });
    let st = await state(app);
    expect(st.settings.accounts.map((x) => x.server)).toEqual([a.origin, b.origin]);
    expect(st.active).toBe('a2');
    await expect.poll(async () => (await state(app)).unread).toBe(5);   // 3 + 2
    const la = new URL(a.origin).host; const lb = new URL(b.origin).host;
    expect((await state(app)).tooltip).toBe(`Почта — ${la}: 3, ${lb}: 2`);

    // Полоса: два ящика со счётчиками, выбран второй; страница ящика сдвинута вправо на ширину полосы.
    const sh = await shellPage(app);
    await expect(sh.locator('button.acc')).toHaveCount(2);
    await expect(sh.locator('button.acc.on')).toHaveAttribute('data-id', 'a2');
    await expect(sh.locator('button.acc[data-id="main"] .badge')).toHaveText('3');
    expect(await app.evaluate(({ BrowserWindow }) => BrowserWindow.getAllWindows()[0].contentView.children.find((v) => v.getVisible()).getBounds().x)).toBe(60);
    expect(await app.evaluate(({ BrowserWindow }) => BrowserWindow.getAllWindows()[0].getTitle())).toBe(`(2) Входящие — Почта · ${lb}`);

    // Переключение значком: страница первого ящика та же, без повторной загрузки (вход сохранён).
    const loadedAt = await pa.evaluate(() => window.__loaded);
    await sh.click('button.acc[data-id="main"]');
    await expect.poll(async () => (await state(app)).active).toBe('main');
    expect(await pa.evaluate(() => window.__loaded)).toBe(loadedAt);
    expect(await pa.evaluate(() => window.pochta.notificationsOn())).toBe(true);
    expect(await pb.evaluate(() => window.pochta.notificationsOn())).toBe(false);   // скрытый ящик — уведомляет проверка в фоне

    // Письмо во второй ящик, пока выбран первый: уведомление с подписью ящика; нажатие откроет второй.
    await app.evaluate(() => globalThis.__pochta.watchOnce());
    b.state.messages = [{ uid: 100, from: { name: 'Клиент Дельты', mail: 'c@d.ru' }, subject: 'Заказ' }];
    b.state.uidnext = 101; b.state.unseen = 3;
    await app.evaluate(() => globalThis.__pochta.watchOnce());
    st = await state(app);
    expect(st.notices.at(-1)).toEqual({ title: 'Клиент Дельты', body: `Заказ · ${lb}` });
    expect(st.unread).toBe(6);

    // Перезапуск: оба ящика и выбор сохранились.
    await app.evaluate(({ app: a2 }) => a2.exit(0)).catch(() => {});
    opened.pop();
    const again = await launch({ userData });
    await mailPage(again.app, a.origin);
    await mailPage(again.app, b.origin);
    expect((await state(again.app)).settings.accounts).toHaveLength(2);
    expect((await state(again.app)).active).toBe('main');

    // Удаление второго ящика: страница закрыта, полоса исчезла, счётчик только первого.
    await again.app.evaluate(() => globalThis.__pochta.removeAccount('a2'));
    await expect.poll(async () => (await state(again.app)).settings.accounts.map((x) => x.id)).toEqual(['main']);
    await expect.poll(() => again.app.windows().some((w) => w.url().startsWith(b.origin))).toBe(false);
    await expect.poll(async () => (await state(again.app)).tooltip).toBe('Почта — 3 непрочитанных');
    expect(await again.app.evaluate(({ BrowserWindow }) => BrowserWindow.getAllWindows()[0].contentView.children.find((v) => v.getVisible()).getBounds().x)).toBe(0);
    await a.close(); await b.close();
});

test('Ctrl+1 / Ctrl+2 переключают ящики', async () => {
    const a = await stub.start();
    const b = await stub.start();
    const ud = fs.mkdtempSync(path.join(os.tmpdir(), 'pochta-e2e-'));
    fs.writeFileSync(path.join(ud, 'settings.json'), JSON.stringify({ accounts: [{ id: 'main', server: a.origin }, { id: 'a2', server: b.origin }], active: 'main' }));
    const { app } = await launch({ userData: ud });
    const pa = await mailPage(app, a.origin);
    const pb = await mailPage(app, b.origin);
    await pa.waitForSelector('#inbox');
    await pb.waitForSelector('#inbox', { state: 'attached' });   // второй ящик скрыт — страница есть, но не на экране
    // Нажатие — через само приложение: так оно не зависит от того, в каком окне сейчас фокус у человека.
    const press = (origin, key) => app.evaluate(({ webContents }, [o, k]) => {
        const wc = webContents.getAllWebContents().find((w) => w.getURL().startsWith(o));
        wc.sendInputEvent({ type: 'keyDown', keyCode: k, modifiers: ['control'] });
        wc.sendInputEvent({ type: 'keyUp', keyCode: k, modifiers: ['control'] });
    }, [origin, key]);
    await press(a.origin, '2');
    await expect.poll(async () => (await state(app)).active).toBe('a2');
    await press(b.origin, '1');
    await expect.poll(async () => (await state(app)).active).toBe('main');
    await a.close(); await b.close();
});

test('обновление с 1.0.x: вошедший в прежний сервер получает его первым ящиком, не вошедший — выбор адреса', async () => {
    const s = await stub.start();
    s.state.requireLogin = true;
    // «1.0.2»: открывали зашитый сервер, вошли (кука входа в прежнем разделе хранения), в настройках сервера нет.
    const ud = fs.mkdtempSync(path.join(os.tmpdir(), 'pochta-e2e-'));
    const first = await launch({ server: s.origin, userData: ud });
    await (await mailPage(first.app, s.origin)).waitForSelector('#inbox');
    await first.app.evaluate(({ session }) => session.fromPartition('persist:pochta').cookies.flushStore());
    await first.app.evaluate(({ app }) => app.exit(0)).catch(() => {});
    opened.pop();
    fs.writeFileSync(path.join(ud, 'settings.json'), JSON.stringify({ trayHintShown: true, zoom: 0 }));

    const upgraded = await launch({ userData: ud, extraEnv: { POCHTA_LEGACY_SERVER: s.origin } });
    await (await mailPage(upgraded.app, s.origin)).waitForSelector('#inbox');
    expect((await state(upgraded.app)).settings.accounts).toEqual([{ id: 'main', server: s.origin }]);

    // Сотрудник другой компании поставил 1.0.x, но в зашитый сервер не входил — ему выбор адреса.
    const fresh = await launch({ extraEnv: { POCHTA_LEGACY_SERVER: s.origin } });
    await setupPage(fresh.app);
    expect((await state(fresh.app)).settings.accounts).toEqual([]);
    expect((await state(fresh.app)).adding).toBe(true);
    await s.close();
});
