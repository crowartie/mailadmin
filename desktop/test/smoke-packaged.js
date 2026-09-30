'use strict';
// Проверка собранного приложения (dist/win-unpacked или установленного) против настоящих серверов:
// два ящика на разных серверах (POCHTA_SMOKE_SERVERS, по умолчанию innotec и deltaservices) — у каждого
// открывается страница входа почты, мост на месте, файл обновлений на сервере доступен.
// Пароль не вводится. Запуск: node test/smoke-packaged.js <путь к Pochta.exe>
const { _electron } = require('@playwright/test');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');

(async () => {
    const exe = process.argv[2] || path.join(__dirname, '..', 'dist', 'win-unpacked', 'Pochta.exe');
    const userData = fs.mkdtempSync(path.join(os.tmpdir(), 'pochta-smoke-'));
    const servers = (process.env.POCHTA_SMOKE_SERVERS || 'https://mail.innotec.su,https://mail.deltaservices.ru').split(',');
    fs.writeFileSync(path.join(userData, 'settings.json'), JSON.stringify({ accounts: servers.map((s, i) => ({ id: i ? 'a' + (i + 1) : 'main', server: s })), active: 'main' }));
    const env = { ...process.env, POCHTA_TEST: '1', POCHTA_USER_DATA: userData };
    delete env.POCHTA_SERVER;
    delete env.POCHTA_LEGACY_SERVER;
    const app = await _electron.launch({ executablePath: exe, env });
    const out = {};
    try {
        await app.firstWindow();
        out.version = await app.evaluate(({ app: a }) => a.getVersion());
        out.packaged = await app.evaluate(({ app: a }) => a.isPackaged);
        out.accounts = [];
        for (const s of servers) {
            const t0 = Date.now();
            let win = null;
            while (!win && Date.now() - t0 < 30000) {
                win = app.windows().find((w) => w.url().startsWith(s + '/mail'));
                if (!win) await new Promise((r) => setTimeout(r, 300));
            }
            if (!win) throw new Error('не открылась почта ' + s + ': ' + app.windows().map((w) => w.url()).join(' | '));
            await win.waitForLoadState('domcontentloaded');
            out.accounts.push({
                url: win.url(),
                bridge: await win.evaluate(() => !!(window.pochta && window.pochta.desktop) && window.pochta.version),
                node: await win.evaluate(() => typeof require),
                feed: await win.evaluate(async () => {
                    const r = await fetch('/app/windows/latest.yml', { cache: 'no-store' });
                    return r.status + ' ' + (await r.text()).split('\n')[0];
                }),
            });
            if (s === servers[0]) {
                await win.screenshot({ path: path.join(os.tmpdir(), 'pochta-smoke.png') });
                out.shot = path.join(os.tmpdir(), 'pochta-smoke.png');
            }
        }
        // Два ящика — страница выбранного сдвинута вправо на ширину полосы со значками ящиков.
        out.rail = await app.evaluate(({ BrowserWindow }) => BrowserWindow.getAllWindows()[0].contentView.children.find((v) => v.getVisible()).getBounds().x);
    } catch (e) {
        out.error = String(e && e.message || e).slice(0, 400);
    } finally {
        await app.evaluate(({ app: a }) => a.exit(0)).catch(() => {});
        await new Promise((r) => setTimeout(r, 1500));   // процесс отпускает файлы кэша не сразу
        try { fs.rmSync(userData, { recursive: true, force: true }); } catch { /* уберёт Windows вместе с TEMP */ }
    }
    console.log(JSON.stringify(out, null, 1));
    process.exit(out.error ? 1 : 0);
})();
