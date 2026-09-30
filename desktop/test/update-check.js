'use strict';
// Проверка самообновления на живом сервере: установленная версия запускается в тестовом режиме с включёнными
// обновлениями, скачивает свежую версию с /app/windows, при выходе ставит её; проверяем версию в установленной папке.
// Запуск: node test/update-check.js <путь к установленному Pochta.exe> <ожидаемая новая версия>
const { _electron } = require('@playwright/test');
const asar = require('@electron/asar');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');

const installedVersion = (exe) => JSON.parse(asar.extractFile(path.join(path.dirname(exe), 'resources', 'app.asar'), 'package.json').toString()).version;

(async () => {
    const [exe, want] = process.argv.slice(2).filter((a) => !a.startsWith('--'));
    const before = installedVersion(exe);
    const userData = fs.mkdtempSync(path.join(os.tmpdir(), 'pochta-upd-'));
    // С 1.1.0 сервер не зашит: обновления берутся с сервера первого ящика.
    fs.writeFileSync(path.join(userData, 'settings.json'), JSON.stringify({ accounts: [{ id: 'main', server: process.env.POCHTA_UPDATE_SERVER || 'https://mail.innotec.su' }] }));
    // --real: обычный режим (так работает у людей). Нужен для версий до 1.0.1, где в тестовом режиме обновления
    // выключены; скачанное обновление видно по папке electron-updater (%LOCALAPPDATA%\pochta-desktop-updater\pending).
    const real = process.argv.includes('--real');
    const env = real
        ? { ...process.env, POCHTA_USER_DATA: userData }
        : { ...process.env, POCHTA_TEST: '1', POCHTA_UPDATE_TEST: '1', POCHTA_UPDATE_DELAY_MS: '1500', POCHTA_USER_DATA: userData };
    delete env.POCHTA_SERVER;
    delete env.POCHTA_TEST;
    if (!real) env.POCHTA_TEST = '1';
    const pending = path.join(process.env.LOCALAPPDATA || '', 'pochta-desktop-updater', 'pending');
    const app = await _electron.launch({ executablePath: exe, env });
    const out = { before };
    const t0 = Date.now();
    try {
        await app.firstWindow();
        for (;;) {
            const ready = real
                ? (fs.existsSync(path.join(pending, `Pochta-Setup-${want}.exe`)) && fs.existsSync(path.join(pending, 'update-info.json')) ? want : null)
                : await app.evaluate(() => globalThis.__pochta.updateReady);
            if (ready) { out.downloaded = ready; out.downloadSec = Math.round((Date.now() - t0) / 1000); break; }
            if (Date.now() - t0 > 6 * 60 * 1000) throw new Error('за 6 минут обновление не скачалось');
            await new Promise((r) => setTimeout(r, 2000));
        }
        await app.evaluate(({ app }) => app.quit());   // autoInstallOnAppQuit: установщик запускается при выходе
    } catch (e) {
        out.error = String(e && e.message || e).slice(0, 300);
        await app.evaluate(({ app }) => app.exit(0)).catch(() => {});
    }
    if (!out.error) {
        const t1 = Date.now();
        for (;;) {
            let v = null;
            try { v = installedVersion(exe); } catch { /* установщик как раз меняет файлы */ }
            if (v === want) { out.after = v; out.installSec = Math.round((Date.now() - t1) / 1000); break; }
            if (Date.now() - t1 > 3 * 60 * 1000) { out.error = 'после выхода версия осталась ' + v; break; }
            await new Promise((r) => setTimeout(r, 2000));
        }
    }
    try { fs.rmSync(userData, { recursive: true, force: true }); } catch { /* файлы ещё заняты — останутся в TEMP */ }
    console.log(JSON.stringify(out, null, 1));
    process.exit(out.error ? 1 : 0);
})();
