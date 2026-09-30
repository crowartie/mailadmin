'use strict';
// Заглушка сервера почты для сквозных тестов: те же адреса, что у веб-почты, но без входа и без IMAP.
// Страница /mail ведёт себя как «Входящие»: заголовок «(3) Входящие — Почта», ссылки наружу, mailto:,
// window.open печати; /mail/api/status и /mail/api/list отдают то, что задаст тест (state).
const http = require('node:http');

function start(port = 0) {
    // requireLogin: /mail/api/status отвечает 401 без куки входа, а куку ставит открытие /mail — как вход в почту.
    const state = { unseen: 3, uidnext: 100, messages: [], requests: [], requireLogin: false };
    const page = (title, body) => `<!doctype html><html lang="ru"><head><meta charset="utf-8"><title>${title}</title></head><body>${body}</body></html>`;
    const server = http.createServer((req, res) => {
        const u = new URL(req.url, 'http://x');
        state.requests.push(u.pathname + u.search);
        const html = (code, s) => { res.writeHead(code, { 'Content-Type': 'text/html; charset=utf-8' }); res.end(s); };
        const json = (o) => { res.writeHead(200, { 'Content-Type': 'application/json' }); res.end(JSON.stringify(o)); };
        if (u.pathname === '/mail/login') return html(200, page('Вход — Почта', '<div id="app" data-page="{}">Вход</div>'));
        if (u.pathname === '/mail' || u.pathname.startsWith('/mail/folder/')) {
            res.setHeader('Set-Cookie', 'sess=1; Path=/; Max-Age=86400; HttpOnly');
            const compose = u.searchParams.get('compose') ? `<p id="compose">Новое письмо кому: ${u.searchParams.get('to') || ''}</p>` : '';
            return html(200, page(`(${state.unseen}) Входящие — Почта`, `
                <h1 id="inbox">Входящие</h1>${compose}
                <a id="ext" href="https://example.org/doc">внешняя ссылка</a>
                <a id="mail" href="mailto:ivan@example.org">написать Ивану</a>
                <a id="file" href="file:///C:/Windows/win.ini">файл</a>
                <button id="print" onclick="window.open('/mail/print/INBOX/1')">Печать</button>
                <button id="popup-ext" onclick="window.open('https://example.org/popup')">Наружу</button>
                <script>window.__loaded = Date.now();</script>`));
        }
        if (u.pathname.startsWith('/mail/print/')) return html(200, page('Печать — Почта', '<p id="print-page">Печать письма</p>'));
        if (u.pathname === '/calendar') return html(200, page('Календарь — Почта', '<h1 id="cal">Календарь</h1>'));
        if (u.pathname === '/mail/api/status' && state.requireLogin && !/(^|;\s*)sess=1/.test(req.headers.cookie || '')) {
            res.writeHead(401, { 'Content-Type': 'application/json' }); return res.end('{"message":"Unauthenticated."}');
        }
        if (u.pathname === '/mail/api/status') return json({ folder: { unseen: state.unseen, messages: 50, uidnext: state.uidnext }, inboxUnseen: state.unseen, reminders: [] });
        if (u.pathname === '/mail/api/list/INBOX') return json({ messages: state.messages, total: state.messages.length });
        html(404, page('Нет', 'нет такой страницы'));
    });
    return new Promise((resolve) => server.listen(port, '127.0.0.1', () => {
        resolve({ server, state, origin: `http://127.0.0.1:${server.address().port}`, port: server.address().port, close: () => new Promise((r) => server.close(r)) });
    }));
}

module.exports = { start };
