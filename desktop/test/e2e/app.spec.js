'use strict';
// Сквозные тесты приложения: настоящее окно Electron против локальной заглушки сервера (stub-server.js).
// Входа и паролей нет — проверяется оболочка: куда ведут ссылки, трей и счётчик, mailto:, «нет связи»,
// первый запуск, фоновая проверка почты и уведомления. Запуск: npm run e2e
const { test, expect, _electron } = require('@playwright/test');
const { spawn } = require('node:child_process');
const path = require('node:path');
const fs = require('node:fs');
const os = require('node:os');
const stub = require('./stub-server');

const ROOT = path.join(__dirname, '..', '..');
const opened = [];

async function launch({ server, args = [], userData } = {}) {
    userData = userData || fs.mkdtempSync(path.join(os.tmpdir(), 'pochta-e2e-'));
    const env = { ...process.env, POCHTA_TEST: '1', POCHTA_USER_DATA: userData, POCHTA_RETRY_MS: '1500', POCHTA_WATCH_MS: '600000' };
    delete env.POCHTA_SERVER;
    if (server) env.POCHTA_SERVER = server;
    const app = await _electron.launch({ args: [ROOT, ...args], env });
    opened.push(app);
    const win = await app.firstWindow();
    return { app, win, userData, env };
}

const state = (app) => app.evaluate(() => {
    const p = globalThis.__pochta;
    return { unread: p.unread, tooltip: p.tooltip, visible: p.visible, external: [...p.log.external], notices: p.log.notices.map((n) => ({ ...n })), settings: p.settings };
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
    const { app, win } = await launch({ server: s.origin });
    await win.waitForSelector('#inbox');
    expect(await win.evaluate(() => window.pochta && window.pochta.desktop)).toBe(true);
    expect(await win.evaluate(() => typeof require + '/' + typeof process)).toBe('undefined/undefined');
    await expect.poll(async () => (await state(app)).unread).toBe(3);
    expect((await state(app)).tooltip).toBe('Почта — 3 непрочитанных');
    await win.evaluate(() => window.pochta.setUnread(21));
    await expect.poll(async () => (await state(app)).tooltip).toBe('Почта — 21 непрочитанное');
    expect(await win.evaluate(() => Notification.permission)).toBe('granted');
    expect(await win.evaluate(() => window.pochta.notificationsOn())).toBe(true);
    await s.close();
});

test('чужие ссылки — в браузер, file: — никуда, печать — отдельным окном почты', async () => {
    const s = await stub.start();
    const { app, win } = await launch({ server: s.origin });
    await win.waitForSelector('#inbox');
    // Отменённый приложением переход Playwright ждал бы до таймаута — жмём ссылку из самой страницы.
    await win.evaluate(() => document.getElementById('ext').click());
    await expect.poll(async () => (await state(app)).external).toContain('https://example.org/doc');
    expect(new URL(win.url()).pathname).toBe('/mail');

    // Переход на file: приложение отменяет; обычный click Playwright ждал бы этот переход до таймаута.
    await win.evaluate(() => document.getElementById('file').click());
    await win.waitForTimeout(500);
    expect(new URL(win.url()).pathname).toBe('/mail');
    expect((await state(app)).external).toHaveLength(1);

    await win.evaluate(() => document.getElementById('popup-ext').click());
    await expect.poll(async () => (await state(app)).external).toContain('https://example.org/popup');
    expect(app.windows()).toHaveLength(1);

    const [child] = await Promise.all([app.waitForEvent('window'), win.evaluate(() => document.getElementById('print').click())]);
    await child.waitForSelector('#print-page');
    expect(new URL(child.url()).pathname).toBe('/mail/print/INBOX/1');
    expect(await child.evaluate(() => typeof window.pochta)).toBe('undefined');   // мост только у главного окна
    await s.close();
});

test('mailto: из страницы и из другой программы (второй запуск) открывает новое письмо', async () => {
    const s = await stub.start();
    const { win, env } = await launch({ server: s.origin });
    await win.waitForSelector('#inbox');
    await win.click('#mail');
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
    const { app, win } = await launch({ server: s.origin });
    await win.waitForSelector('#inbox');
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
    const { win } = await launch({ server: `http://127.0.0.1:${port}` });
    await expect.poll(() => win.url(), { timeout: 15000 }).toContain('offline.html');
    await win.waitForSelector('text=Нет связи с сервером почты');
    const s = await stub.start(port);
    await expect.poll(() => win.url(), { timeout: 15000 }).toMatch(new RegExp(`^http://127\\.0\\.0\\.1:${port}/mail`));
    await win.waitForSelector('#inbox');
    await s.close();
});

test('первый запуск без сервера: экран выбора, ошибка на мусоре, адрес сервера — почта открылась и запомнилась', async () => {
    const s = await stub.start();
    const { app, win, userData } = await launch();
    await expect.poll(() => win.url()).toContain('setup.html');
    await win.fill('#addr', 'просто слово');
    await win.click('#go');
    await expect(win.locator('#err')).toContainText('Введите адрес почты');
    await win.fill('#addr', s.origin);
    await win.click('#go');
    await win.waitForSelector('#inbox', { timeout: 15000 });
    expect((await state(app)).settings.server).toBe(s.origin);
    expect(JSON.parse(fs.readFileSync(path.join(userData, 'settings.json'), 'utf8')).server).toBe(s.origin);
    await s.close();
});

test('фоновая проверка: новое письмо при открытом календаре → уведомление; выключили — тишина; на «Входящих» — молчит', async () => {
    const s = await stub.start();
    const { app, win } = await launch({ server: s.origin });
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

    await app.evaluate(() => globalThis.__pochta.setNotifications(false));
    expect(await win.evaluate(() => window.pochta.notificationsOn())).toBe(false);
    s.state.messages = [{ uid: 102, from: { name: 'Сидоров' }, subject: 'Ещё' }];
    s.state.uidnext = 103;
    const before = (await state(app)).notices.length;
    await app.evaluate(() => globalThis.__pochta.watchOnce());
    expect((await state(app)).notices).toHaveLength(before);

    await app.evaluate(() => globalThis.__pochta.setNotifications(true));
    await win.goto(s.origin + '/mail');
    await win.waitForSelector('#inbox');
    s.state.messages = [{ uid: 103, from: { name: 'Орлов' }, subject: 'Тема' }];
    s.state.uidnext = 104;
    await app.evaluate(() => globalThis.__pochta.watchOnce());
    expect((await state(app)).notices).toHaveLength(before);   // «Входящие» открыты — уведомляет страница сама
    await s.close();
});
