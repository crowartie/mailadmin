#!/usr/bin/env python3
"""Достаёт контакты, события и задачи из хранилища Kerio Connect
(store/mail/<domain>/<user>/{Contacts,Calendar,Tasks}/#msgs/*.eml — внутри vCard/iCalendar)
и складывает файлами: <out>/<user>/contacts/*.vcf, <out>/<user>/calendar/*.ics, <out>/<user>/tasks/*.ics.
Общие контакты домена (#public/Contacts) — в <out>/_public/contacts.
Дальше: php artisan dav:import <user>@<domain> <out>/<user>

  kerio-dav-extract.py --src /mnt/kerio --out /var/lib/mailadmin/kerio-dav [--users a,b]
"""
import argparse, email, os, re, sys
from email import policy

KINDS = {'Contacts': ('contacts', 'text/vcard', '.vcf'), 'Calendar': ('calendar', 'text/calendar', '.ics'), 'Tasks': ('tasks', 'text/calendar', '.ics')}


def extract(path, ctype):
    with open(path, 'rb') as f:
        msg = email.message_from_binary_file(f, policy=policy.default)
    parts = [msg] if not msg.is_multipart() else list(msg.walk())
    for p in parts:
        if p.get_content_type() == ctype or (ctype == 'text/vcard' and p.get_content_type() in ('text/x-vcard', 'text/directory')):
            try:
                return p.get_content()
            except Exception:
                payload = p.get_payload(decode=True) or b''
                return payload.decode('utf-8', 'replace')
    return None


def do_folder(src, dst, ctype, ext):
    msgs = os.path.join(src, '#msgs')
    if not os.path.isdir(msgs):
        return 0
    n = 0
    for name in sorted(os.listdir(msgs)):
        if not name.endswith('.eml'):
            continue
        body = extract(os.path.join(msgs, name), ctype)
        if not body or ('BEGIN:VCARD' not in body and 'BEGIN:VCALENDAR' not in body):
            continue
        os.makedirs(dst, exist_ok=True)
        with open(os.path.join(dst, name[:-4] + ext), 'w', encoding='utf-8', newline='') as f:
            f.write(body)
        n += 1
    return n


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--src', required=True)
    ap.add_argument('--out', required=True)
    ap.add_argument('--users', default='')
    a = ap.parse_args()
    users = [u for u in a.users.split(',') if u] or sorted(d for d in os.listdir(a.src) if not d.startswith('#') and os.path.isdir(os.path.join(a.src, d)))
    for u in users:
        counts = {}
        for kdir, (kind, ctype, ext) in KINDS.items():
            n = do_folder(os.path.join(a.src, u, kdir), os.path.join(a.out, u, kind), ctype, ext)
            if n:
                counts[kind] = n
        if counts:
            print(u, ' '.join(f'{k}={v}' for k, v in counts.items()), flush=True)
    pub = os.path.join(a.src, '#public', 'Contacts')
    if os.path.isdir(pub):
        n = do_folder(pub, os.path.join(a.out, '_public', 'contacts'), 'text/vcard', '.vcf')
        print('_public contacts=%d' % n)


if __name__ == '__main__':
    main()
