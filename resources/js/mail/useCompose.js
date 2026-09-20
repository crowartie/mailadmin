import { api, composeForm } from './api';
import { addrString, escapeHtml, when } from './format';

/**
 * Написание письма: новое, ответ, ответ всем, пересылка, «как новое», черновик,
 * быстрый ответ из окна чтения, отправка с окном отмены и очередь отложенных.
 *
 * Отправка, как и действия над письмами, уходит на сервер не сразу: N секунд (Настройки →
 * Общие) письмо ждёт, и «Отменить» возвращает форму вместе с приложенными файлами.
 *
 * @param {object} ctx props, settings, folders, folder, folderInfo, compose, open, cursor,
 *                     mobileRead, menu, toast, list, dialog, outboxCount
 *                     + load, refresh, fail, showToast, flushPendingAct, rolePath, router
 */
export function useCompose(ctx) {
    /** Отложенная отправка: { payload, seconds, timer }. */
    let pending = null;
    // Своя метка у каждого окна письма: раньше два «Написать» подряд давали один и тот же
    // ключ, Vue переиспользовал компонент, и во второй форме оставался текст первой.
    let seq = 0;

    const identities = () => ctx.props.identities || [];

    /** Подпись зависит от поля «От»: у общего ящика (info и т. п.) — его собственная. */
    function signatureText(fromMail) {
        const id = identities().find((i) => i.shared && i.mail.toLowerCase() === (fromMail || '').toLowerCase());

        return id ? (id.signature || '') : (ctx.settings.value.signature || '');
    }

    function signature(forReply, fromMail) {
        const s = signatureText(fromMail);
        if (!s || (forReply && !ctx.settings.value.signature_reply)) return '';

        return `<p><br></p><div class="sig">${s}</div>`;
    }

    /** Письмо из общей папки — от имени её владельца, если нам разрешено писать за него. */
    function sharedFrom(m) {
        const path = m ? m.folder : ctx.folder.value;
        const owner = (ctx.folders.value.find((f) => f.path === path) || {}).owner;

        return owner && identities().some((i) => i.shared && i.mail === owner) ? owner : '';
    }

    function quote(m) {
        const inner = m.html || `<pre style="white-space:pre-wrap;font:inherit">${escapeHtml(m.text || '')}</pre>`;

        return `<p><br></p><div class="quote"><div style="color:#6B7787">${escapeHtml(when(m.date, true))}, ${escapeHtml(m.from.name)} &lt;${escapeHtml(m.from.mail)}&gt; писал(а):</div><blockquote>${inner}</blockquote></div>`;
    }

    /** Это мы сами — свой адрес или один из общих ящиков, за которые можем писать. */
    function me(a) {
        const mail = (a.mail || '').toLowerCase();

        return mail === ctx.props.user.toLowerCase() || identities().some((i) => i.mail.toLowerCase() === mail);
    }

    function replyTargets(m) {
        if (ctx.folderInfo.value.role === 'sent') return m.to;

        return m.replyTo?.length ? m.replyTo : [m.from];
    }

    /** «Re:» и «Fwd:» не удваиваем, а у письма без темы не оставляем висящий пробел. */
    function answerSubject(prefix, subject) {
        const re = prefix === 'Re' ? /^re:/i : /^fwd?:/i;
        if (re.test(subject)) return subject;

        return (prefix + ': ' + (subject === '(без темы)' ? '' : subject)).trim();
    }

    function parseList(s) {
        return (s || '').split(/,(?![^<]*>)/).map((p) => p.trim()).filter(Boolean).map((p) => {
            const m = p.match(/^"?([^"<]*)"?\s*<([^>]+)>$/);

            return m ? { name: m[1].trim(), mail: m[2].trim() } : { name: '', mail: p };
        });
    }

    function startCompose(mode = 'new', m = null, text = '') {
        ctx.menu.value = null;
        if (mode === 'draft') { openDraft(m.uid); return; }
        const c = { token: ++seq, mode, to: [], cc: [], bcc: [], subject: '', html: '', from: sharedFrom(m) || '' };
        if (mode === 'new') {
            // «Написать письмо» из карточки адресата: адресат уже подставлен.
            if (Array.isArray(m?.to)) c.to = [...m.to];
            c.html = `<p>${escapeHtml(text)}</p>${signature(false, c.from)}`;
        } else if (mode === 'reply' || mode === 'replyAll') {
            c.to = replyTargets(m).filter((a) => !me(a) || replyTargets(m).length === 1);
            if (mode === 'replyAll') {
                const seen = new Set(c.to.map((a) => a.mail));
                [...m.to, ...(m.cc || [])].forEach((a) => {
                    if (!me(a) && !seen.has(a.mail)) { seen.add(a.mail); c.cc.push(a); }
                });
            }
            c.subject = answerSubject('Re', m.subject);
            c.html = `<p>${escapeHtml(text)}</p>${signature(true, c.from)}${quote(m)}`;
            c.inReplyTo = m.messageId;
            c.references = [m.references, m.messageId].filter(Boolean).join(' ');
            c.answeredFolder = m.folder;
            c.answeredUid = m.uid;
            c.attachments = m.attachments || [];
            c.sourceFolder = m.folder;
            c.sourceUid = m.uid;
            c.keepAttachments = false;
        } else if (mode === 'forward') {
            c.subject = answerSubject('Fwd', m.subject);
            const hdr = `<div class="fwd" style="color:#6B7787">---------- Пересланное письмо ----------<br>От: ${escapeHtml(m.from.name)} &lt;${escapeHtml(m.from.mail)}&gt;<br>Дата: ${escapeHtml(when(m.date, true))}<br>Тема: ${escapeHtml(m.subject)}<br>Кому: ${escapeHtml(addrString(m.to))}</div><br>`;
            c.html = `<p><br></p>${signature(true, c.from)}<p><br></p>${hdr}${m.html || `<pre style="white-space:pre-wrap;font:inherit">${escapeHtml(m.text || '')}</pre>`}`;
            c.references = [m.references, m.messageId].filter(Boolean).join(' ');
            c.attachments = m.attachments || [];
            c.sourceFolder = m.folder;
            c.sourceUid = m.uid;
            c.keepAttachments = true;
        } else if (mode === 'again') {
            // «Изменить как новое» (в Kerio и Outlook — «Отправить повторно»): те же получатели,
            // тема, текст и вложения, без цитаты и шапки пересылки. Подпись не добавляем —
            // в отправленном письме она уже есть.
            c.to = [...(m.to || [])];
            c.cc = [...(m.cc || [])];
            c.subject = m.subject === '(без темы)' ? '' : (m.subject || '');
            c.html = m.html || `<pre style="white-space:pre-wrap;font:inherit">${escapeHtml(m.text || '')}</pre>`;
            c.attachments = m.attachments || [];
            c.sourceFolder = m.folder;
            c.sourceUid = m.uid;
            c.keepAttachments = true;
        }
        ctx.compose.value = c;
        ctx.mobileRead.value = true;
    }

    /** Из меню: открыть письмо (если ещё не открыто) и начать ответ или пересылку. */
    async function openThen(mode) {
        const uid = ctx.menu.value?.uids?.[0];
        ctx.menu.value = null;
        if (!uid) return;
        let m = ctx.open.value && ctx.open.value.uid === uid ? ctx.open.value : null;
        if (!m) {
            try {
                m = await api.message(ctx.folder.value, uid);
                ctx.open.value = m;
                ctx.cursor.value = uid;
            } catch (e) {
                ctx.fail(e);

                return;
            }
        }
        startCompose(mode, m);
    }

    async function openDraft(uid) {
        try {
            const d = await api.openDraft(uid);
            ctx.compose.value = {
                token: ++seq, mode: 'draft', ...d,
                to: parseList(d.to), cc: parseList(d.cc), bcc: parseList(d.bcc),
                keepAttachments: d.attachments?.length > 0,
                sourceFolder: ctx.rolePath('drafts'), sourceUid: uid,
            };
            ctx.mobileRead.value = true;
        } catch (e) {
            ctx.fail(e);
        }
    }

    /** Черновик сохранён: обновляем счётчик папки и список, если открыты «Черновики». */
    function onDraftSaved() {
        api.folders().then((r) => { if (Array.isArray(r)) ctx.folders.value = r; }).catch(() => {});
        if (ctx.folderInfo.value.role === 'drafts') ctx.load(ctx.list.value.page, true);
    }

    function onComposeClose(opts) {
        if (opts?.discard && opts.draftUid) {
            api.action(ctx.rolePath('drafts'), [opts.draftUid], 'delete').then(ctx.refresh).catch(() => {});
        }
        ctx.compose.value = null;
        if (!ctx.open.value) ctx.mobileRead.value = false;
    }

    async function doSend(payload) {
        const r = await api.send(composeForm(payload.form, payload.files));
        if (r.folders) ctx.folders.value = r.folders;
        if (ctx.folderInfo.value.role === 'drafts' || ctx.folderInfo.value.role === 'sent') ctx.load(1, true);

        return r;
    }

    function formToCompose(f) {
        return { token: ++seq, mode: 'new', ...f, to: parseList(f.to), cc: parseList(f.cc), bcc: parseList(f.bcc), attachments: [] };
    }

    function send(payload) {
        ctx.compose.value = null;
        if (!ctx.open.value) ctx.mobileRead.value = false;
        if (payload.sendAt) {
            doSend(payload)
                .then(() => { ctx.outboxCount.value++; ctx.showToast({ text: `Отправится ${when(payload.sendAt, true)}` }); })
                .catch(ctx.fail);

            return;
        }
        const secs = Number(ctx.settings.value.undo_seconds ?? 5);
        if (!secs) {
            doSend(payload).then(() => ctx.showToast({ text: 'Письмо отправлено' })).catch(ctx.fail);

            return;
        }
        // Пока идёт обратный отсчёт, письмо есть только в этой вкладке. Закрытый ноутбук,
        // упавший браузер или вложение тяжелее 64 КБ (столько тянет fetch с keepalive) —
        // и письма нет нигде. Поэтому сначала откладываем его в черновики: даже в худшем
        // случае человек найдёт свой текст, а не пустоту.
        keepDraft(payload);
        pending = { payload, seconds: secs };
        // И не обещаем того, чего ещё не случилось: письмо пока не отправлено.
        ctx.showToast({ text: 'Отправляем…', actionLabel: 'Отменить', seconds: secs }, 0);
        const tick = () => {
            if (!pending) return;
            pending.seconds--;
            if (pending.seconds <= 0) {
                const p = pending;
                pending = null;
                ctx.toast.value = null;
                doSend(p.payload)
                    .then(() => ctx.showToast({ text: 'Письмо отправлено' }, 2000))
                    .catch((e) => { ctx.fail(e); ctx.compose.value = { ...formToCompose(p.payload.form), files: p.payload.files }; });

                return;
            }
            ctx.toast.value = { ...ctx.toast.value, seconds: pending.seconds };
            pending.timer = setTimeout(tick, 1000);
        };
        pending.timer = setTimeout(tick, 1000);
    }

    /**
     * Сохранить черновик до отправки, чтобы письмо пережило закрытую вкладку.
     * Если черновик уже есть (автосохранение), обходимся без повторной заливки вложений.
     */
    function keepDraft(payload) {
        const form = payload.form;
        if (!form) return;
        const keep = !!form.draftUid;
        // Файлы-ссылки в страховочный черновик не кладём (см. draftFiles в окне письма).
        const viaCloud = new Set(form.cloud || []);
        api.draft(composeForm({ ...form, draftKeepFiles: keep }, keep ? [] : (payload.files || []).filter((f, i) => !viaCloud.has(i))))
            // Обычно ответ успевает прийти до конца отсчёта, и тогда отправка сама уберёт
            // этот черновик. Если не успел — письмо уже ушло, а черновик останется висеть;
            // это видно и поправимо, в отличие от потерянного письма.
            .then((r) => { if (r?.draftUid && pending) form.draftUid = r.draftUid; })
            .catch(() => {});   // не вышло — отправку из-за этого не задерживаем
    }

    function undoSend() {
        if (!pending) { ctx.toast.value = null; return; }
        clearTimeout(pending.timer);
        const p = pending;
        pending = null;
        ctx.toast.value = null;
        // Вместе с формой возвращаем и приложенные файлы: раньше «Отменить» открывало письмо без них.
        ctx.compose.value = { ...formToCompose(p.payload.form), files: p.payload.files || [] };
        ctx.mobileRead.value = true;
        ctx.showToast({ text: 'Отправка отменена' }, 2000);
    }

    /** Уходим со страницы: незавершённое доводим до сервера, пока вкладка ещё жива. */
    function flushPending() {
        ctx.flushPendingAct(true);
        if (!pending) return;
        clearTimeout(pending.timer);
        const p = pending;
        pending = null;
        api.send(composeForm(p.payload.form, p.payload.files), { keepalive: true }).catch(() => {});
    }

    async function quickReply({ text, message: m, done }) {
        const form = {
            to: addrString(replyTargets(m)),
            // Та же тема, что и у полного ответа: раньше быстрый ответ уходил с «Re: (без темы)».
            subject: answerSubject('Re', m.subject),
            ...(sharedFrom(m) ? { from: sharedFrom(m) } : {}),
            html: `<p>${escapeHtml(text).replace(/\n/g, '<br>')}</p>${signature(true, sharedFrom(m))}${quote(m)}`,
            inReplyTo: m.messageId,
            references: [m.references, m.messageId].filter(Boolean).join(' '),
            answeredFolder: m.folder,
            answeredUid: m.uid,
        };
        try {
            const r = await api.send(composeForm(form, []));
            if (r.folders) ctx.folders.value = r.folders;
            const row = ctx.list.value.messages.find((x) => x.uid === m.uid);
            if (row) row.answered = true;
            ctx.showToast({ text: 'Ответ отправлен' });
            // Поле очищает MessageView — но только после того, как письмо действительно ушло.
            if (done) done(true);
        } catch (e) {
            ctx.fail(e);
            if (done) done(false);
        }
    }

    /** «Встреча» из письма: событие с темой письма и всеми участниками переписки. */
    function meetingFrom(m) {
        const people = [m.from, ...(m.to || []), ...(m.cc || [])].map((a) => a.mail).filter((x) => x && !me({ mail: x }));
        const p = new URLSearchParams({
            new: '1',
            title: m.subject === '(без темы)' ? '' : m.subject,
            attendees: [...new Set(people)].join(','),
            description: (m.text || '').slice(0, 800),
        });
        ctx.router.visit('/calendar?' + p);
    }

    function unsubscribe(m) {
        const h = m.listUnsubscribe || '';
        const mailto = h.match(/<mailto:([^>]+)>/i);
        const http = h.match(/<(https?:[^>]+)>/i);
        if (http) {
            // Ссылка ведёт на чужой сайт из письма, которое человек уже счёл лишним: показываем
            // адрес и спрашиваем. Раньше один щелчок открывал произвольную страницу без вопроса.
            let host = http[1];
            try {
                host = new URL(http[1]).host;
            } catch {
                /* оставим как есть */
            }
            if (!window.confirm(`Открыть страницу отписки на сайте ${host}?`)) return;
            window.open(http[1], '_blank', 'noopener');

            return;
        }
        if (mailto) {
            const [addr, qs] = mailto[1].split('?');
            const subj = new URLSearchParams(qs || '').get('subject') || 'Unsubscribe';
            ctx.compose.value = { token: ++seq, mode: 'new', to: [{ name: '', mail: addr }], cc: [], bcc: [], subject: subj, html: '<p>Unsubscribe</p>' };
            ctx.mobileRead.value = true;   // на телефоне окно письма иначе остаётся за кадром
        }
    }

    async function showOutbox() {
        try {
            ctx.dialog.value = { kind: 'outbox', rows: await api.outbox() };
        } catch (e) {
            ctx.fail(e);
        }
    }

    async function cancelOutbox(id) {
        try {
            await api.cancelOutbox(id);
            ctx.dialog.value.rows = ctx.dialog.value.rows.filter((r) => r.id !== id);
            ctx.outboxCount.value = Math.max(0, ctx.outboxCount.value - 1);
            ctx.showToast({ text: 'Письмо вернулось в черновики' });
        } catch (e) {
            ctx.fail(e);
        }
    }

    return {
        startCompose, openThen, openDraft, onDraftSaved, onComposeClose,
        send, undoSend, flushPending, quickReply, meetingFrom, unsubscribe,
        showOutbox, cancelOutbox, parseList,
    };
}
