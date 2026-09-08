// Обёртка над fetch для /mail/api/*: CSRF из cookie, JSON, единый разбор ошибок.
function xsrf() {
    const m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
}

export class ApiError extends Error {
    constructor(message, status, payload) {
        super(message);
        this.status = status;
        this.payload = payload;
    }
}

async function request(method, url, body, opts = {}) {
    const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf() };
    let payload = body;
    if (body && !(body instanceof FormData)) {
        headers['Content-Type'] = 'application/json';
        payload = JSON.stringify(body);
    }
    const r = await fetch(url, { method, headers, body: payload, credentials: 'same-origin', keepalive: opts.keepalive || false });
    if (r.status === 401 || (r.redirected && r.url.includes('/mail/login'))) {
        window.location.href = '/mail/login';
        throw new ApiError('Сессия закончилась', 401);
    }
    const text = await r.text();
    let data = null;
    try { data = text ? JSON.parse(text) : null; } catch { data = { message: text }; }
    if (!r.ok) {
        const msg = data?.message || (data?.errors && Object.values(data.errors).flat()[0]) || `Ошибка ${r.status}`;
        throw new ApiError(msg, r.status, data);
    }
    return data;
}

const enc = (s) => encodeURIComponent(s);

export const api = {
    folders: () => request('GET', '/mail/api/folders'),
    createFolder: (name, parent) => request('POST', '/mail/api/folders', { name, parent }),
    renameFolder: (path, name) => request('PATCH', `/mail/api/folders/${enc(path)}`, { name }),
    deleteFolder: (path) => request('DELETE', `/mail/api/folders/${enc(path)}`),
    folderShares: (path) => request('GET', `/mail/api/folders/${enc(path)}/shares`),
    shareFolder: (path, withMail, level) => request('POST', `/mail/api/folders/${enc(path)}/shares`, { with: withMail, level }),
    unshareFolder: (path, withMail) => request('DELETE', `/mail/api/folders/${enc(path)}/shares`, { with: withMail }),
    emptyFolder: (path) => request('POST', `/mail/api/folders/${enc(path)}/empty`),

    list: (folder, { page = 1, filter = 'all', q = '' } = {}) => {
        const p = new URLSearchParams({ page, filter });
        if (q) p.set('q', q);
        return request('GET', `/mail/api/list/${enc(folder)}?${p}`);
    },
    message: (folder, uid, peek = false) => request('GET', `/mail/api/message/${enc(folder)}/${uid}${peek ? '?peek=1' : ''}`),
    attachmentUrl: (folder, uid, index, inline = false) => `/mail/api/message/${enc(folder)}/${uid}/attachment/${index}${inline ? '?inline=1' : ''}`,
    rawUrl: (folder, uid) => `/mail/api/message/${enc(folder)}/${uid}/raw`,

    action: (folder, uids, op, extra = {}) => request('POST', '/mail/api/action', { folder, uids, op, ...extra }),

    send: (form, opts) => request('POST', '/mail/api/send', form, opts),
    draft: (form) => request('POST', '/mail/api/draft', form),
    openDraft: (uid) => request('GET', `/mail/api/draft/${uid}`),
    outbox: () => request('GET', '/mail/api/outbox'),
    cancelOutbox: (id) => request('DELETE', `/mail/api/outbox/${id}`),

    suggest: (q) => request('GET', `/mail/api/suggest?q=${enc(q)}`),

    settings: () => request('GET', '/mail/api/settings'),
    saveSettings: (patch) => request('PUT', '/mail/api/settings', patch),
    labels: () => request('GET', '/mail/api/labels'),
    createLabel: (name, color) => request('POST', '/mail/api/labels', { name, color }),
    updateLabel: (id, name, color) => request('PATCH', `/mail/api/labels/${id}`, { name, color }),
    deleteLabel: (id) => request('DELETE', `/mail/api/labels/${id}`),

    rules: () => request('GET', '/mail/api/rules'),
    saveRules: (rules, autoreply) => request('PUT', '/mail/api/rules', { rules, autoreply }),

    // Безопасность.
    security: () => request('GET', '/mail/api/security'),
    twofaSetup: () => request('POST', '/mail/api/security/2fa/setup'),
    twofaEnable: (code) => request('POST', '/mail/api/security/2fa/enable', { code }),
    twofaDisable: (password) => request('POST', '/mail/api/security/2fa/disable', { password }),
    createAppPassword: (name, password) => request('POST', '/mail/api/security/app-passwords', { name, password }),
    deleteAppPassword: (id) => request('DELETE', `/mail/api/security/app-passwords/${id}`),
    kickSession: (id) => request('POST', '/mail/api/security/sessions/kick', { id }),
    kickOthers: () => request('POST', '/mail/api/security/sessions/kick-others'),

    // Контакты.
    books: () => request('GET', '/mail/api/contacts/books'),
    contacts: ({ book = '', q = '' } = {}) => {
        const p = new URLSearchParams();
        if (book) p.set('book', book);
        if (q) p.set('q', q);
        return request('GET', `/mail/api/contacts${p.toString() ? '?' + p : ''}`);
    },
    contact: (book, uri) => request('GET', `/mail/api/contacts/${enc(book)}/${enc(uri)}`),
    createContact: (data) => request('POST', '/mail/api/contacts', data),
    updateContact: (book, uri, data) => request('PUT', `/mail/api/contacts/${enc(book)}/${enc(uri)}`, data),
    deleteContact: (book, uri) => request('DELETE', `/mail/api/contacts/${enc(book)}/${enc(uri)}`),
    copyContact: (book, uri, to = 'personal') => request('POST', `/mail/api/contacts/${enc(book)}/${enc(uri)}/copy`, { to }),
    suggestContact: (book, uri, note = '') => request('POST', `/mail/api/contacts/${enc(book)}/${enc(uri)}/suggest`, { note }),
    contactGroups: () => request('GET', '/mail/api/contacts/groups'),
    contactHistory: () => request('GET', '/mail/api/contacts/history'),
    forgetHistory: (email) => request('DELETE', `/mail/api/contacts/history/${enc(email)}`),
    importContacts: (file, book = 'personal') => { const fd = new FormData(); fd.append('file', file, file.name); fd.append('book', book); return request('POST', '/mail/api/contacts/import', fd); },
    exportUrl: (book = '') => `/mail/api/contacts/export${book ? '?book=' + enc(book) : ''}`,

    // Календарь.
    calendars: () => request('GET', '/mail/api/calendars'),
    createCalendar: (name, color) => request('POST', '/mail/api/calendars', { name, color }),
    updateCalendar: (uri, patch) => request('PATCH', `/mail/api/calendars/${enc(uri)}`, patch),
    deleteCalendar: (uri) => request('DELETE', `/mail/api/calendars/${enc(uri)}`),
    shares: (uri) => request('GET', `/mail/api/calendars/${enc(uri)}/shares`),
    share: (uri, withMail, level) => request('POST', `/mail/api/calendars/${enc(uri)}/shares`, { with: withMail, level }),
    unshare: (uri, withMail) => request('DELETE', `/mail/api/calendars/${enc(uri)}/shares`, { with: withMail }),
    events: (from, to, calendars = []) => {
        const p = new URLSearchParams({ from, to });
        if (calendars.length) p.set('calendars', calendars.join(','));
        return request('GET', `/mail/api/events?${p}`);
    },
    event: (cal, uri) => request('GET', `/mail/api/events/${enc(cal)}/${enc(uri)}`),
    createEvent: (data) => request('POST', '/mail/api/events', data),
    updateEvent: (cal, uri, data) => request('PUT', `/mail/api/events/${enc(cal)}/${enc(uri)}`, data),
    deleteEvent: (cal, uri, occurrence = null) => request('DELETE', `/mail/api/events/${enc(cal)}/${enc(uri)}`, occurrence ? { occurrence } : undefined),
    respond: (cal, uri, status) => request('POST', `/mail/api/events/${enc(cal)}/${enc(uri)}/respond`, { status }),
    freebusy: (users, from, to) => request('GET', `/mail/api/freebusy?${new URLSearchParams({ users: users.join(','), from, to })}`),
};

/** Форма «Написать» → FormData (файлы прикладываются как files[]). */
export function composeForm(c, files = []) {
    const fd = new FormData();
    const fields = ['from', 'to', 'cc', 'bcc', 'subject', 'html', 'inReplyTo', 'references', 'answeredFolder', 'answeredUid',
        'sourceFolder', 'sourceUid', 'draftUid', 'sendAt', 'remindDays'];
    for (const f of fields) {
        if (c[f] !== undefined && c[f] !== null && c[f] !== '') fd.append(f, c[f]);
    }
    for (const f of ['keepAttachments', 'priority', 'receipt']) {
        if (c[f]) fd.append(f, '1');
    }
    files.forEach((file) => fd.append('files[]', file, file.name));
    (c.cloud || []).forEach((i) => fd.append('cloud[]', String(i)));
    return fd;
}
