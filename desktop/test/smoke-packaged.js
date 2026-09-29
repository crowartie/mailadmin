'use strict';
// Проверка собранного приложения (dist/win-unpacked или установленного) против настоящего сервера:
// окно открывает страницу входа почты, мост на месте, файл обновлений на сервере доступен.
// Пароль не вводится. Запуск: node test/smoke-packaged.js <путь к Pochta.exe>
const { _electron } = require('@playwright/test');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');

(async () => {
    const exe = process.argv[2] || path.join(__dirname, '..', 'dist', 'win-unpacked', 'Pochta.exe');
    const userData = fs.mkdtempSync(path.join(os.tmpdir(), 'pochta-smoke-'));
    const env = { ...process.env, POCHTA_TEST: '1', POCHTA_USER_DATA: userData };
    delete env.POCHTA_SERVER;
    const app = await _electron.launch({ executablePath: exe, env });
    const out = {};
    try {
        const win = await app.firstWindow();
        await win.waitForLoadState('domcontentloaded');
        await win.waitForFunction(() => location.pathname.startsWith('/mail'), null, { timeout: 30000 });
        out.url = win.url();
        out.title = await win.title();
        out.bridge = await win.evaluate(() => !!(window.pochta && window.pochta.desktop) && window.pochta.version);
        out.node = await win.evaluate(() => typeof require);
        out.version = await app.evaluate(({ app }) => app.getVersion());
        out.packaged = await app.evaluate(({ app }) => app.isPackaged);
        out.feed = await win.evaluate(async () => {
            const r = await fetch('/app/windows/latest.yml', { cache: 'no-store' });
            return r.status + ' ' + (await r.text()).split('\n')[0];
        });
        await win.screenshot({ path: path.join(os.tmpdir(), 'pochta-smoke.png') });
        out.shot = path.join(os.tmpdir(), 'pochta-smoke.png');
    } catch (e) {
        out.error = String(e && e.message || e).slice(0, 300);
    } finally {
        await app.evaluate(({ app }) => app.exit(0)).catch(() => {});
        await new Promise((r) => setTimeout(r, 1500));   // процесс отпускает файлы кэша не сразу
        try { fs.rmSync(userData, { recursive: true, force: true }); } catch { /* уберёт Windows вместе с TEMP */ }
    }
    console.log(JSON.stringify(out, null, 1));
    process.exit(out.error ? 1 : 0);
})();
