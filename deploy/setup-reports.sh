#!/bin/bash
# Системные отчёты — в админку (раздел «Отчёты»), а не письмами root → postmaster.
# Запускается install.sh, можно запускать повторно.
set -e
HERE="$(cd "$(dirname "$0")" && pwd)"
install -m 755 "$HERE/mailadmin-report" /usr/local/sbin/mailadmin-report
install -d -o root -g www-data -m 2750 /var/lib/mailadmin/reports

# Logwatch: не письмом, а файлом.
if [ -x /usr/share/logwatch/scripts/logwatch.pl ]; then
    cat > /etc/cron.daily/00logwatch <<'EOF'
#!/bin/bash
# Сводка Logwatch уходит в админку почты (Отчёты), не по почте.
test -x /usr/share/logwatch/scripts/logwatch.pl || exit 0
/usr/local/sbin/mailadmin-report logwatch /usr/sbin/logwatch --output stdout
EOF
    chmod 755 /etc/cron.daily/00logwatch
fi

# Crontab root: вывод скриптов iRedMail (резервная копия баз, чистка ящиков) — в отчёты.
crontab -l 2>/dev/null | sed -E \
    -e 's#^([0-9*/,-]+[[:space:]]+[0-9*/,-]+[[:space:]]+[0-9*/,-]+[[:space:]]+[0-9*/,-]+[[:space:]]+[0-9*/,-]+[[:space:]]+)(/bin/bash /var/vmail/backup/backup_mysql\.sh)[[:space:]]*$#\1/usr/local/sbin/mailadmin-report backup-mysql \2#' \
    -e 's#^([0-9*/,-]+[[:space:]]+[0-9*/,-]+[[:space:]]+[0-9*/,-]+[[:space:]]+[0-9*/,-]+[[:space:]]+[0-9*/,-]+[[:space:]]+)(python3 /opt/www/iredadmin/tools/delete_mailboxes\.py)[[:space:]]*$#\1/usr/local/sbin/mailadmin-report iredadmin-cleanup \2#' \
    | crontab -

# Amavis: письма администратору о вирусах/спаме/запрещённых вложениях не нужны — всё видно в карантине админки.
if grep -qE '_admin_maps\s*=>\s*\["root\\@\$mydomain"\]' /etc/amavis/conf.d/50-user; then
    sed -i -E 's/^(\s*(virus|spam|bad_header|banned)_admin_maps\s*=>\s*)\["root\\@\$mydomain"\],/\1undef,/' /etc/amavis/conf.d/50-user
    systemctl reset-failed amavis 2>/dev/null || true
    systemctl restart amavis
fi
echo "reports: готово (crontab root, logwatch, amavis)"
