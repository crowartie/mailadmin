#!/bin/bash
# Dovecot: полнотекстовый поиск (fts_xapian) и обучение SpamAssassin по переносу писем в «Спам» (imapsieve).
# Идемпотентно: повторный запуск ничего не дублирует. Запускать под sudo.
set -euo pipefail
CONF=/etc/dovecot/dovecot.conf
cp -an "$CONF" "$CONF.bak-fts-$(date +%Y%m%d%H%M%S)"
# Слепок конфигурации до правок: перечитываем Dovecot только если что-то реально изменилось
# (полный перезапуск при каждой выкладке рвал IMAP всем и на 1-2 минуты выключал quota-status → Postfix отвечал 451).
BEFORE=$(doveconf -n 2>/dev/null | md5sum)

# ── 1. FTS: плагин в общий список и в imap ─────────────────────────────
grep -q "fts_xapian" "$CONF" || sed -i -E 's/^(mail_plugins\s*=\s*)(.*)$/\1\2 fts fts_xapian/' "$CONF"
# protocol imap { mail_plugins = ... } — добавить imap_sieve (обучение) один раз
grep -qE "mail_plugins.*imap_sieve" "$CONF" || sed -i -E '/^protocol imap \{/,/^\}/ s/^(\s*mail_plugins\s*=\s*)(.*)$/\1\2 imap_sieve/' "$CONF"

# ── 2. Каталоги и sieve-скрипты обучения ───────────────────────────────
install -d -m 755 /usr/local/lib/dovecot/sieve
install -d -o vmail -g amavis -m 2770 /var/spool/sa-learn /var/spool/sa-learn/spam /var/spool/sa-learn/ham
cat > /usr/local/lib/dovecot/sieve/learn-spam.sh <<'EOF'
#!/bin/sh
# Письмо перенесли в «Спам» → в спул, оттуда cron скормит sa-learn как amavis.
umask 007
exec cat > "/var/spool/sa-learn/spam/$(date +%s%N)-$$.eml"
EOF
cat > /usr/local/lib/dovecot/sieve/learn-ham.sh <<'EOF'
#!/bin/sh
umask 007
exec cat > "/var/spool/sa-learn/ham/$(date +%s%N)-$$.eml"
EOF
chmod 755 /usr/local/lib/dovecot/sieve/learn-*.sh
cat > /var/vmail/sieve/learn-spam.sieve <<'EOF'
require ["vnd.dovecot.pipe", "copy", "imapsieve"];
pipe :copy "learn-spam.sh";
EOF
cat > /var/vmail/sieve/learn-ham.sieve <<'EOF'
require ["vnd.dovecot.pipe", "copy", "imapsieve"];
pipe :copy "learn-ham.sh";
EOF
chown vmail:vmail /var/vmail/sieve/learn-*.sieve

# ── 3. Блок plugin {} для fts и imapsieve ──────────────────────────────
if ! grep -q "mailadmin: fts + learn" "$CONF"; then
cat >> "$CONF" <<'EOF'

# mailadmin: fts + learn
plugin {
  fts = xapian
  # lowmemory: плагин смотрит на MemFree (не MemAvailable); при заполненном страничном кэше MemFree всегда мал,
  # и с порогом по умолчанию (250 МБ) индексатор сбрасывает базу после каждых нескольких писем — в десятки раз медленнее.
  fts_xapian = partial=3 full=20 verbose=0 lowmemory=32
  fts_autoindex = yes
  # body: полнотекстовый индекс обязателен только для поиска по телу. Иначе любой IMAP SEARCH по заголовкам
  # (цепочка ответов при открытии письма) сначала достраивает индекс всей папки — минуты на большом ящике.
  fts_enforced = body
  fts_autoindex_exclude = \Junk
  fts_autoindex_exclude2 = \Trash

  sieve_plugins = sieve_imapsieve sieve_extprograms
  sieve_global_extensions = +vnd.dovecot.pipe
  sieve_pipe_bin_dir = /usr/local/lib/dovecot/sieve
  imapsieve_mailbox1_name = Junk
  imapsieve_mailbox1_causes = COPY APPEND
  imapsieve_mailbox1_before = file:/var/vmail/sieve/learn-spam.sieve
  imapsieve_mailbox2_name = *
  imapsieve_mailbox2_from = Junk
  imapsieve_mailbox2_causes = COPY
  imapsieve_mailbox2_before = file:/var/vmail/sieve/learn-ham.sieve
}
EOF
fi
# Заголовки цепочки ответов — в кэш индекса: поиск In-Reply-To/References идёт по индексу, а не по файлам.
grep -q "mail_always_cache_fields" "$CONF" || printf '\n# mailadmin: заголовки цепочки в кэше индекса\nmail_always_cache_fields = hdr.message-id hdr.in-reply-to hdr.references\n' >> "$CONF"
# indexer-worker по числу ядер: 10 по умолчанию на малой памяти падают с std::bad_alloc и уводят сервер в своп.
# vsz_limit: iRedMail ставит default_vsz_limit = 256M, с ним indexer-worker падает на больших письмах (std::bad_alloc, signal 6).
grep -q "mailadmin: indexer-worker" "$CONF" || printf '\n# mailadmin: indexer-worker — по числу ядер и с памятью под xapian\nservice indexer-worker {\n  process_limit = %s\n  vsz_limit = 2G\n}\n' "$(nproc)" >> "$CONF"
# Старые установки: дописываем lowmemory, если блок fts уже был создан раньше
grep -q 'lowmemory=' "$CONF" || sed -i 's/^\(\s*fts_xapian = partial=3 full=20 verbose=0\)$/\1 lowmemory=32/' "$CONF"
# Старые установки: fts_enforced = no → body (см. выше)
sed -i 's/^\(\s*\)fts_enforced = no$/\1fts_enforced = body/' "$CONF"
# Папка «Рассылки» (Newsletters) у всех сразу, как Junk: общие правила кладут туда хлам-не-спам, и сотрудник должен
# видеть её в списке, а не ждать первого попавшего письма.
if ! grep -q "mailbox Newsletters" "$CONF"; then
  sed -i '/^namespace inbox {/a\    mailbox Newsletters {\n        auto = subscribe\n    }' "$CONF"
fi
# Скрипты компилируются в контексте imapsieve; на лету их скомпилирует сам Dovecot (каталог принадлежит vmail).
sievec -x "+vnd.dovecot.pipe +imapsieve" /var/vmail/sieve/learn-spam.sieve 2>/dev/null || true
sievec -x "+vnd.dovecot.pipe +imapsieve" /var/vmail/sieve/learn-ham.sieve 2>/dev/null || true
chown vmail:vmail /var/vmail/sieve/learn-*.svbin 2>/dev/null || true
if doveconf -n >/dev/null; then
  if [ "$(doveconf -n | md5sum)" != "$BEFORE" ]; then doveadm reload && echo "dovecot: конфигурация перечитана"; fi
else
  echo "dovecot: ошибка в конфигурации, не перечитываю" >&2
fi

# ── 4. Cron: скормить накопленное SpamAssassin от имени amavis ─────────
cat > /usr/local/sbin/mailadmin-salearn <<'EOF'
#!/bin/bash
# Обучение байесовского фильтра SpamAssassin (та же база, что у Amavis).
# cd /: cron запускает скрипт из /root, а perl у пользователя amavis тогда падает на «lib/…: Permission denied»
# (относительный путь в @INC) — с 13.09 по 15.09 из-за этого не обучилось ни одно письмо.
set -u
cd / || exit 1
LOG=/var/log/mailadmin-salearn.log
for kind in spam ham; do
  d=/var/spool/sa-learn/$kind
  files=$(find "$d" -type f -name '*.eml' -mmin +0 2>/dev/null | head -500)
  [ -n "$files" ] || continue
  n=$(echo "$files" | wc -l)
  if echo "$files" | xargs -r sudo -u amavis -H sa-learn --$kind --no-sync >>"$LOG" 2>&1; then
    echo "$files" | xargs -r rm -f
    echo "$(date '+%F %T') $kind: обучено $n" >>"$LOG"
  else
    echo "$(date '+%F %T') $kind: ОШИБКА sa-learn (файлы оставлены)" >>"$LOG"
  fi
done
sudo -u amavis -H sa-learn --sync >>"$LOG" 2>&1 || true
EOF
chmod 755 /usr/local/sbin/mailadmin-salearn
echo "*/5 * * * * root /usr/local/sbin/mailadmin-salearn >/dev/null 2>&1" > /etc/cron.d/mailadmin-salearn

# ── 5. Первичная индексация имеющихся писем в фоне — ОДИН раз ─────────────
# Скрипт вызывается и из update.sh при каждом обновлении; без маркера каждая выкладка запускала бы полную
# переиндексацию всей почты (fts rescan сбрасывает индексы, на HDD это часы нагрузки). Дальше новые письма
# индексируются при доставке (fts_autoindex), вручную ничего гонять не нужно.
MARK=/var/lib/mailadmin/fts-initial-index.done
install -d -m 0755 /var/lib/mailadmin
if [ ! -f "$MARK" ]; then
  # doveadm под root создаёт индексы root-ом — после индексации вернуть владельца vmail, иначе IMAP не откроет индекс
  nohup sh -c 'doveadm fts rescan -A; doveadm index -A -q "*"; find /var/vmail -type d -name xapian-indexes -exec chown -R vmail:vmail {} +' >/var/log/dovecot-fts-index.log 2>&1 &
  date > "$MARK"
  echo "fts: первичная индексация запущена в фоне"
fi

# ── 5. Общий Sieve-скрипт решений сотрудников (спам/рассылки по отправителю), пишет веб-почта через mailadmin-ctl sieve-global
if [ ! -f /var/vmail/sieve/mailadmin-global.sieve ]; then
  printf 'require ["fileinto", "mailbox"];\n# Общие решения сотрудников о отправителях. Файл создаёт веб-почта.\n' > /var/vmail/sieve/mailadmin-global.sieve
  chown vmail:vmail /var/vmail/sieve/mailadmin-global.sieve; chmod 0440 /var/vmail/sieve/mailadmin-global.sieve
  sievec /var/vmail/sieve/mailadmin-global.sieve; chown vmail:vmail /var/vmail/sieve/mailadmin-global.svbin
fi
grep -q "^\s*sieve_before2" "$CONF" || sed -i -E 's|^(\s*)sieve_before = /var/vmail/sieve/dovecot.sieve|&\n\1sieve_before2 = /var/vmail/sieve/mailadmin-global.sieve|' "$CONF"
