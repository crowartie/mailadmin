#!/usr/bin/env python3
"""Перенос почты из хранилища Kerio Connect (store/mail/<domain>/<user>/<folder>/#msgs/*.eml)
в Maildir пользователей Dovecot/iRedMail. Повторный запуск докачивает только новое
и обновляет флаги (прочитано/отвечено/помечено) по index.fld.

Запуск на сервере с iRedMail (от root):
  kerio2maildir.py --src /mnt/kerio --domain innotec.su [--users a,b] [--jobs 4] [--dry-run]
"""
import argparse, base64, os, re, sqlite3, subprocess, sys, time, shutil, socket
from concurrent.futures import ProcessPoolExecutor

SKIP_TOP = {'Calendar', 'Contacts', 'Tasks', 'Notes', 'Journal', '#assoc', '#msgs', '#sync'}
RENAME = {'INBOX': 'INBOX', 'Sent Items': 'Sent', 'Sent Messages': 'Sent', 'Sent': 'Sent',
          'Deleted Items': 'Trash', 'Deleted Messages': 'Trash', 'Trash': 'Trash',
          'Junk E-mail': 'Junk', 'Spam': 'Junk', 'Junk': 'Junk', 'Drafts': 'Drafts'}
MAIL_CLASSES = ('IPM.Note', 'IPM.Schedule', 'REPORT', 'IPM.Post')
F_SEEN, F_ANSWERED, F_FLAGGED, F_DRAFT = 0x1, 0x2, 0x4, 0x10
HOST = socket.gethostname().split('.')[0] or 'mail'


# ── IMAP modified UTF-7 ───────────────────────────────────────────────
def mutf7_decode(s: str) -> str:
    out, i = [], 0
    while i < len(s):
        c = s[i]
        if c != '&':
            out.append(c)
            i += 1
            continue
        j = s.find('-', i)
        if j < 0:
            j = len(s)
        chunk = s[i + 1:j]
        if chunk == '':
            out.append('&')
        else:
            b = chunk.replace(',', '/')
            b += '=' * (-len(b) % 4)
            try:
                out.append(base64.b64decode(b).decode('utf-16-be'))
            except Exception:
                out.append(chunk)
        i = j + 1
    return ''.join(out)


def mutf7_encode(s: str) -> str:
    out, buf = [], []

    def flush():
        if buf:
            b = base64.b64encode(''.join(buf).encode('utf-16-be')).decode().rstrip('=').replace('/', ',')
            out.append('&' + b + '-')
            buf.clear()

    for ch in s:
        o = ord(ch)
        if 0x20 <= o <= 0x7e:
            flush()
            out.append('&-' if ch == '&' else ch)
        else:
            buf.append(ch)
    flush()
    return ''.join(out)


# ── папки ─────────────────────────────────────────────────────────────
def kerio_folders(userdir):
    """[(kerio_rel_path, [unicode components of target mailbox])]"""
    res = []
    for root, dirs, files in os.walk(userdir):
        rel = os.path.relpath(root, userdir)
        if rel == '.':
            dirs[:] = [d for d in dirs if not d.startswith('__') and not d.startswith('#') and d not in SKIP_TOP
                       and not d.startswith('Contacts.')]
            continue
        parts = rel.split(os.sep)
        dirs[:] = [d for d in dirs if not d.startswith('__') and not d.startswith('#') and d not in SKIP_TOP]
        if '#msgs' in os.listdir(root) and 'index.fld' in files:
            comps = [mutf7_decode(p) for p in parts]
            if comps[0] in RENAME:
                comps[0] = RENAME[comps[0]]
            comps = [c.replace('.', '_').strip() or '_' for c in comps]
            res.append((rel, comps))
    return res


def maildir_subdir(comps):
    if comps == ['INBOX']:
        return ''
    return '.' + '.'.join(mutf7_encode(c) for c in comps)


# ── index.fld ─────────────────────────────────────────────────────────
LINE = re.compile(r'^U([0-9a-f]+) F([0-9a-f]+) S(\d+) D([0-9a-f]+) T([0-9a-f]+) M([0-9a-f]+) .*?(?:C(\S*))?\s*$')


def read_index(path):
    items = []
    try:
        with open(path, 'r', encoding='utf-8', errors='replace') as f:
            for line in f:
                line = line.rstrip('\r\n')
                m = LINE.match(line)
                if not m:
                    continue
                uid, flags, size, d, t, mod, cls = m.groups()
                cls = cls or ''
                if cls and not cls.startswith(MAIL_CLASSES):
                    continue
                items.append((uid, int(flags, 16), int(size), int(t, 16) or int(d, 16), int(mod, 16)))
    except FileNotFoundError:
        pass
    return items


def md_flags(kf, is_drafts):
    s = ''
    if is_drafts and kf & F_DRAFT:
        s += 'D'
    if kf & F_FLAGGED:
        s += 'F'
    if kf & F_ANSWERED:
        s += 'R'
    if kf & F_SEEN:
        s += 'S'
    return s


# ── один пользователь ─────────────────────────────────────────────────
def own(path):
    try:
        os.chown(path, 2000, 2000)
        os.chmod(path, 0o700)
    except OSError:
        pass


def ensure_maildir(base, sub):
    # Каталоги сразу отдаём vmail: иначе Dovecot не создаст индексы, и ящик до конца копирования выглядит пустым.
    home = os.path.dirname(base)
    os.makedirs(base, exist_ok=True)
    own(home)
    own(base)
    d = os.path.join(base, sub) if sub else base
    for x in ('cur', 'new', 'tmp'):
        os.makedirs(os.path.join(d, x), exist_ok=True)
        own(os.path.join(d, x))
    own(d)
    if sub:
        mf = os.path.join(d, 'maildirfolder')
        if not os.path.exists(mf):
            open(mf, 'w').close()
            os.chown(mf, 2000, 2000)
    return d


KEY_RE = re.compile(r'^(\d+)\.k([0-9a-f]+)_([0-9a-f]+)\.[^,]*,S=(\d+):2,([A-Z]*)$')


def process_user(args):
    try:
        return _process_user(args)
    except Exception as e:
        sys.stderr.write(f'{args[0]}: СБОЙ {e!r}\n')
        return dict(user=args[0], new=0, bytes=0, flags=0, missing=0, folders=0, errors=1)


def _process_user(args):
    user, srcdir, home, dbdir, dry, recopy = args
    maildir = os.path.join(home, 'Maildir')
    dbpath = os.path.join(dbdir, user + '.db')
    db = sqlite3.connect(dbpath, timeout=300)
    db.execute('''CREATE TABLE IF NOT EXISTS msgs(user TEXT, folder TEXT, uid TEXT, flags INT, size INT, mdsub TEXT, mdfile TEXT,
                  PRIMARY KEY(user, folder, uid))''')
    known = {(r[0], r[1]): (r[2], r[3], r[4]) for r in db.execute('SELECT folder,uid,flags,mdsub,mdfile FROM msgs WHERE user=?', (user,))}
    stats = dict(user=user, new=0, bytes=0, flags=0, missing=0, folders=0, errors=0)
    subs = set()
    for rel, comps in kerio_folders(srcdir):
        items = read_index(os.path.join(srcdir, rel, 'index.fld'))
        if not items:
            continue
        sub = maildir_subdir(comps)
        is_drafts = comps == ['Drafts']
        stats['folders'] += 1
        if comps != ['INBOX']:
            subs.add('/'.join(comps))
        mdir = None
        if not dry:
            mdir = ensure_maildir(maildir, sub)
        # Уже лежащие в cur/ файлы прошлого прогона (если база не успела их записать) — подхватываем, не копируем заново.
        existing = {}
        curdir = os.path.join(mdir, 'cur') if mdir else None
        if curdir and os.path.isdir(curdir):
            for fn in os.listdir(curdir):
                m = KEY_RE.match(fn)
                if m:
                    # ключ — полный префикс имени: две папки Kerio могут склеиваться в одну (Sent + Sent Items), uid у них пересекаются
                    existing[m.group(1) + '.k' + m.group(2) + '_' + m.group(3)] = fn
        msgs = os.path.join(srcdir, rel, '#msgs')
        n = 0
        for uid, kf, size, t, mod in items:
            fl = md_flags(kf, is_drafts)
            key = (rel, uid)
            prefix = f'{t}.k{uid}_{mod:x}'
            if key in known and recopy and not dry:
                # файла нет на месте (потерян прошлой версией при склейке папок) — забываем запись и копируем заново
                # (запись может указывать на чужой файл с тем же uid из склеенной папки — поэтому смотрим по префиксу, а не по имени из базы)
                if prefix not in existing:
                    db.execute('DELETE FROM msgs WHERE user=? AND folder=? AND uid=?', (user, rel, uid))
                    del known[key]
                    stats['recopied'] = stats.get('recopied', 0) + 1
            if key not in known and prefix in existing:
                fn = existing[prefix]
                db.execute('INSERT OR REPLACE INTO msgs VALUES(?,?,?,?,?,?,?)', (user, rel, uid, kf, int(KEY_RE.match(fn).group(4)), sub, fn))
                known[key] = (kf, sub, fn)
                nf = fn.split(':2,')[0] + ':2,' + fl
                if nf != fn:
                    os.rename(os.path.join(curdir, fn), os.path.join(curdir, nf))
                    db.execute('UPDATE msgs SET mdfile=? WHERE user=? AND folder=? AND uid=?', (nf, user, rel, uid))
                stats['adopted'] = stats.get('adopted', 0) + 1
                continue
            if key in known:
                oflags, osub, ofile = known[key]
                if oflags != kf and not dry:
                    nf = ofile.split(':2,')[0] + ':2,' + fl
                    src_p = os.path.join(maildir, osub, 'cur', ofile) if osub else os.path.join(maildir, 'cur', ofile)
                    if nf != ofile and os.path.exists(src_p):
                        os.rename(src_p, os.path.join(os.path.dirname(src_p), nf))
                        db.execute('UPDATE msgs SET flags=?, mdfile=? WHERE user=? AND folder=? AND uid=?', (kf, nf, user, rel, uid))
                        stats['flags'] += 1
                    else:
                        db.execute('UPDATE msgs SET flags=? WHERE user=? AND folder=? AND uid=?', (kf, user, rel, uid))
                continue
            src = os.path.join(msgs, uid + '.eml')
            try:
                real = os.path.getsize(src)
            except OSError:
                stats['missing'] += 1
                continue
            name = f'{t}.k{uid}_{mod:x}.{HOST},S={real}:2,{fl}'
            stats['new'] += 1
            stats['bytes'] += real
            if dry:
                continue
            tmp = os.path.join(mdir, 'tmp', name)
            dst = os.path.join(mdir, 'cur', name)
            try:
                shutil.copyfile(src, tmp)
                os.utime(tmp, (t, t))
                os.chown(tmp, 2000, 2000)
                os.chmod(tmp, 0o600)
                os.rename(tmp, dst)
            except Exception as e:
                stats['errors'] += 1
                sys.stderr.write(f'{user}: {rel}/{uid}: {e}\n')
                try:
                    os.unlink(tmp)
                except OSError:
                    pass
                continue
            db.execute('INSERT OR REPLACE INTO msgs VALUES(?,?,?,?,?,?,?)', (user, rel, uid, kf, real, sub, name))
            n += 1
            if n % 200 == 0:
                db.commit()
        db.commit()
    db.commit()
    db.close()
    if not dry and os.path.isdir(maildir):
        for root, dirs, files in os.walk(maildir):
            os.chown(root, 2000, 2000)
            os.chmod(root, 0o700)
            for f in files:
                p = os.path.join(root, f)
                try:
                    os.chown(p, 2000, 2000)
                except OSError:
                    pass
        for s in sorted(subs):
            subprocess.run(['doveadm', 'mailbox', 'subscribe', '-u', user, s], stderr=subprocess.DEVNULL)
    return stats


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--src', required=True)
    ap.add_argument('--domain', required=True)
    ap.add_argument('--users', default='')
    ap.add_argument('--jobs', type=int, default=3)
    ap.add_argument('--db', default='/var/lib/mailadmin/kerio-migrate', help='каталог с базами состояния, по одной на ящик')
    ap.add_argument('--dry-run', action='store_true')
    ap.add_argument('--recopy-missing', action='store_true', help='если файл из базы пропал с диска — скопировать заново (осторожно: вернёт и то, что сотрудник удалил)')
    a = ap.parse_args()
    os.makedirs(a.db, exist_ok=True)
    legacy = a.db + '.db'
    if os.path.isfile(legacy):
        # старый формат: одна общая база — раскладываем по ящикам
        old = sqlite3.connect(legacy)
        for (u,) in old.execute('SELECT DISTINCT user FROM msgs').fetchall():
            nd = sqlite3.connect(os.path.join(a.db, u + '.db'))
            nd.execute('CREATE TABLE IF NOT EXISTS msgs(user TEXT, folder TEXT, uid TEXT, flags INT, size INT, mdsub TEXT, mdfile TEXT, PRIMARY KEY(user, folder, uid))')
            nd.executemany('INSERT OR REPLACE INTO msgs VALUES(?,?,?,?,?,?,?)', old.execute('SELECT * FROM msgs WHERE user=?', (u,)).fetchall())
            nd.commit()
            nd.close()
        old.close()
        os.rename(legacy, legacy + '.old')
        for suf in ('-wal', '-shm'):
            if os.path.exists(legacy + suf):
                os.remove(legacy + suf)
    homes = {}
    q = "select username, concat(storagebasedirectory,'/',storagenode,'/',maildir) from vmail.mailbox where domain='%s'" % a.domain
    out = subprocess.run(['mysql', '-N', '-e', q], capture_output=True, text=True).stdout
    for line in out.splitlines():
        u, h = line.split('\t')
        homes[u.split('@')[0]] = h.rstrip('/')
    want = [u for u in a.users.split(',') if u] or sorted(d for d in os.listdir(a.src) if not d.startswith('#') and os.path.isdir(os.path.join(a.src, d)))
    tasks, skipped = [], []
    for u in want:
        if u not in homes:
            skipped.append(u)
            continue
        tasks.append((u + '@' + a.domain, os.path.join(a.src, u), homes[u], a.db, a.dry_run, a.recopy_missing))
    if skipped:
        print('Нет ящика на новом сервере, пропущены:', ' '.join(skipped), flush=True)
    t0 = time.time()
    tot = dict(new=0, bytes=0, flags=0, missing=0, errors=0)
    with ProcessPoolExecutor(max_workers=a.jobs) as ex:
        for st in ex.map(process_user, tasks):
            for k in tot:
                tot[k] += st[k]
            print("%-30s папок %3d  новых %6d  %9.1f МБ  подхвачено %5d  заново %4d  флаги %5d  нет файла %3d  ошибок %d" % (
                st['user'], st['folders'], st['new'], st['bytes'] / 1048576, st.get('adopted', 0), st.get('recopied', 0), st['flags'], st['missing'], st['errors']), flush=True)
    print("Итого: новых %d, %.1f ГБ, флагов обновлено %d, без файла %d, ошибок %d, %d с" % (
        tot['new'], tot['bytes'] / 1073741824, tot['flags'], tot['missing'], tot['errors'], int(time.time() - t0)))


if __name__ == '__main__':
    main()
