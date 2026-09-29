'use strict';
// Сквозные тесты окна приложения (Electron) — по одному: у каждого свой значок в трее и свой профиль.
module.exports = {
    testDir: __dirname,
    testMatch: /.*\.spec\.js$/,
    workers: 1,
    timeout: 60000,
    retries: 0,
    reporter: [['list']],
};
