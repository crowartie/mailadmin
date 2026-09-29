'use strict';
// Настройки приложения в %APPDATA%\Почта\settings.json. Запись через временный файл: при сбое питания
// посреди записи старые настройки остаются целыми, а не превращаются в обрывок JSON.
const fs = require('node:fs');
const path = require('node:path');
const { mergeSettings } = require('./lib');

function load(file) {
    try {
        return mergeSettings(JSON.parse(fs.readFileSync(file, 'utf8')));
    } catch {
        return mergeSettings(null);
    }
}

function save(file, settings) {
    try {
        fs.mkdirSync(path.dirname(file), { recursive: true });
        const tmp = file + '.tmp';
        fs.writeFileSync(tmp, JSON.stringify(settings, null, 1), 'utf8');
        fs.renameSync(tmp, file);
        return true;
    } catch {
        return false;
    }
}

module.exports = { load, save };
