'use strict';
// Модульные тесты логики оболочки (src/lib.js): node --test test/unit/
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const lib = require('../../src/lib');
const store = require('../../src/settings');

const S = 'https://mail.example.ru';

test('адрес сервера: только https, http — лишь для локальной разработки', () => {
    assert.equal(lib.originOf('https://mail.example.ru/mail/login?x=1'), S);
    assert.equal(lib.originOf('http://mail.example.ru'), null);
    assert.equal(lib.originOf('http://127.0.0.1:8123/mail'), 'http://127.0.0.1:8123');
    assert.equal(lib.originOf('http://localhost:3000'), 'http://localhost:3000');
    assert.equal(lib.originOf('ftp://x.ru'), null);
    assert.equal(lib.originOf('мусор'), null);
});

test('первый запуск: адрес почты → mail.<домен>, затем домен', () => {
    assert.deepEqual(lib.serverCandidates('Ivan@Example.RU'), ['https://mail.example.ru', 'https://example.ru']);
    assert.deepEqual(lib.serverCandidates('  mail.example.ru  '), [S]);
    assert.deepEqual(lib.serverCandidates('https://mail.example.ru/mail'), [S]);
    assert.deepEqual(lib.serverCandidates('http://mail.example.ru'), [S], 'http переводится на https');
    assert.deepEqual(lib.serverCandidates('http://127.0.0.1:9000'), ['http://127.0.0.1:9000']);
    assert.deepEqual(lib.serverCandidates('ivan@'), []);
    assert.deepEqual(lib.serverCandidates('просто слово'), []);
    assert.deepEqual(lib.serverCandidates('intranet'), [], 'узел без точки — не адрес сервера');
    assert.deepEqual(lib.serverCandidates(''), []);
});

test('куда ведёт ссылка', () => {
    assert.equal(lib.classifyUrl(S + '/mail/folder/INBOX', S), 'internal');
    assert.equal(lib.classifyUrl(S + '/mail/print/INBOX/5', S), 'internal');
    assert.equal(lib.classifyUrl('https://files.example.ru/abc/doc.pdf', S), 'external', 'поддомен — другой сайт');
    assert.equal(lib.classifyUrl('https://ya.ru', S), 'external');
    assert.equal(lib.classifyUrl('http://mail.example.ru/mail', S), 'external', 'http того же узла — не наш origin');
    assert.equal(lib.classifyUrl('mailto:ivan@example.ru', S), 'mailto');
    assert.equal(lib.classifyUrl('blob:' + S + '/0b7f', S), 'internal');
    assert.equal(lib.classifyUrl('blob:https://evil.ru/0b7f', S), 'block');
    assert.equal(lib.classifyUrl('file:///C:/Windows/win.ini', S), 'block');
    assert.equal(lib.classifyUrl('javascript:alert(1)', S), 'block');
    assert.equal(lib.classifyUrl('ms-settings:privacy', S), 'block');
    assert.equal(lib.classifyUrl('about:blank', S), 'internal');
    assert.equal(lib.classifyUrl('не ссылка', S), 'block');
});

test('mailto: → окно нового письма', () => {
    assert.equal(lib.mailtoPath('mailto:ivan@example.ru'), '/mail?compose=1&to=' + encodeURIComponent('ivan@example.ru'));
    assert.equal(lib.mailtoPath('mailto:a@x.ru,b@y.ru?subject=Привет'), '/mail?compose=1&to=' + encodeURIComponent('a@x.ru, b@y.ru'));
    assert.equal(lib.mailtoPath('mailto:%D0%B8%D0%B2%D0%B0%D0%BD@%D0%BF%D1%80%D0%B8%D0%BC%D0%B5%D1%80.%D1%80%D1%84'), '/mail?compose=1&to=' + encodeURIComponent('иван@пример.рф'));
    assert.equal(lib.mailtoPath('mailto:?to=c@z.ru'), '/mail?compose=1&to=' + encodeURIComponent('c@z.ru'));
    assert.equal(lib.mailtoPath('mailto:'), null);
    assert.equal(lib.mailtoPath('mailto:не адрес'), null);
    assert.equal(lib.mailtoPath('https://x.ru'), null);
    assert.equal(lib.findMailtoArg(['Pochta.exe', '--hidden', 'MAILTO:a@b.ru']), 'MAILTO:a@b.ru');
    assert.equal(lib.findMailtoArg(['Pochta.exe']), null);
});

test('непрочитанные: заголовок, подсказка, кружок', () => {
    assert.equal(lib.unreadFromTitle('(12) Входящие — Почта'), 12);
    assert.equal(lib.unreadFromTitle('Входящие — Почта'), null);
    assert.equal(lib.unreadFromTitle('Календарь — Почта'), null);
    assert.equal(lib.trayTooltip(0), 'Почта');
    assert.equal(lib.trayTooltip(1), 'Почта — 1 непрочитанное');
    assert.equal(lib.trayTooltip(3), 'Почта — 3 непрочитанных');
    assert.equal(lib.trayTooltip(11), 'Почта — 11 непрочитанных');
    assert.equal(lib.trayTooltip(21), 'Почта — 21 непрочитанное');
    assert.equal(lib.badgeFile(0), null);
    assert.equal(lib.badgeFile(-1), null);
    assert.equal(lib.badgeFile(4), 'badge-4.png');
    assert.equal(lib.badgeFile(10), 'badge-9plus.png');
    for (let i = 1; i <= 10; i++) assert.ok(fs.existsSync(path.join(__dirname, '..', '..', 'assets', 'badges', lib.badgeFile(i))), 'нет файла кружка ' + i);
});

test('разрешения — только странице своего сервера', () => {
    assert.equal(lib.allowPermission('notifications', S + '/mail', S), true);
    assert.equal(lib.allowPermission('clipboard-sanitized-write', S + '/mail', S), true);
    assert.equal(lib.allowPermission('notifications', 'https://evil.ru/', S), false);
    assert.equal(lib.allowPermission('geolocation', S + '/mail', S), false);
    assert.equal(lib.allowPermission('media', S + '/mail', S), false);
    assert.equal(lib.allowPermission('notifications', '', S), false);
});

test('страница «Входящих» и адрес обновлений', () => {
    assert.equal(lib.isInboxPage(S + '/mail', S), true);
    assert.equal(lib.isInboxPage(S + '/mail/folder/Sent', S), true);
    assert.equal(lib.isInboxPage(S + '/calendar', S), false);
    assert.equal(lib.isInboxPage('file:///C:/app/offline.html', S), false);
    assert.equal(lib.feedUrl(S + '/'), S + '/app/windows');
});

test('уведомление о новых письмах', () => {
    assert.equal(lib.newMailNotice([]), null);
    assert.deepEqual(lib.newMailNotice([{ uid: 7, from: { name: 'Иванов Иван', mail: 'i@x.ru' }, subject: 'Счёт' }]), { title: 'Иванов Иван', body: 'Счёт', uid: 7 });
    assert.deepEqual(lib.newMailNotice([{ uid: 8, from: { mail: 'i@x.ru' }, subject: '' }]), { title: 'i@x.ru', body: '(без темы)', uid: 8 });
    const many = lib.newMailNotice([1, 2, 3, 4, 5].map((i) => ({ uid: i, from: { name: 'Отправитель ' + (i % 5) }, subject: 's' })));
    assert.equal(many.title, '5 новых писем');
    assert.equal(many.body, 'Отправитель 1, Отправитель 2, Отправитель 3 и ещё 2');
    assert.equal(many.uid, null);
    assert.equal(lib.newMailNotice([{ uid: 1 }, { uid: 2 }]).title, '2 новых письма');
});

test('настройки: значения по умолчанию, мусор отбрасывается, запись через временный файл', () => {
    const d = lib.mergeSettings(null);
    assert.equal(d.notifications, true);
    assert.equal(d.autostart, true);
    const m = lib.mergeSettings({ server: 'http://evil.ru', zoom: 99, notifications: 'да', autostart: false, bounds: { x: 1, y: 2, width: 900, height: 700 }, лишнее: 1 });
    assert.equal(m.server, '', 'http-сервер не принимается');
    assert.equal(m.zoom, 0);
    assert.equal(m.notifications, true, 'строка вместо флага — берём по умолчанию');
    assert.equal(m.autostart, false);
    assert.deepEqual(m.bounds, { x: 1, y: 2, width: 900, height: 700 });
    assert.equal('лишнее' in m, false);

    const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'pochta-'));
    const file = path.join(dir, 'sub', 'settings.json');
    assert.equal(store.load(file).server, '', 'нет файла — по умолчанию');
    assert.equal(store.save(file, { ...d, server: S, zoom: 1 }), true);
    assert.equal(store.load(file).server, S);
    assert.equal(store.load(file).zoom, 1);
    assert.equal(fs.existsSync(file + '.tmp'), false);
    fs.writeFileSync(file, '{ обрывок');
    assert.equal(store.load(file).server, '', 'битый файл — по умолчанию, без падения');
    fs.rmSync(dir, { recursive: true, force: true });
});

test('положение окна: за пределами экранов — только размер', () => {
    const displays = [{ workArea: { x: 0, y: 0, width: 1920, height: 1040 } }];
    assert.deepEqual(lib.visibleBounds({ x: 100, y: 100, width: 1200, height: 800 }, displays), { x: 100, y: 100, width: 1200, height: 800 });
    assert.deepEqual(lib.visibleBounds({ x: 3000, y: 100, width: 1200, height: 800 }, displays), { width: 1200, height: 800 }, 'второй монитор отключили');
    assert.equal(lib.visibleBounds({ x: 0, y: 0, width: 100, height: 100 }, displays), null);
    assert.equal(lib.visibleBounds(null, displays), null);
});
