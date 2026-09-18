#!/usr/bin/env bash
# Обновление приложения из git на уже установленном сервере: код, зависимости, сборка, миграции, служебные скрипты.
# Использование: sudo bash /opt/mailadmin/deploy/update.sh [ветка]
set -euo pipefail
HERE=$(cd "$(dirname "$0")" && pwd)
APP=$(cd "$HERE/.." && pwd)
BRANCH=${1:-}
[ "$(id -u)" = 0 ] || { echo "запускать от root: sudo bash update.sh" >&2; exit 1; }
cd "$APP"
export COMPOSER_ALLOW_SUPERUSER=1 HOME=/root

SELF_BEFORE=$(sha1sum "$0" | cut -d' ' -f1)

echo "==> git pull"
git -c safe.directory="$APP" fetch -q origin
git -c safe.directory="$APP" checkout -q "${BRANCH:-$(git -c safe.directory="$APP" rev-parse --abbrev-ref HEAD)}"
git -c safe.directory="$APP" pull -q --ff-only

# Если обновился сам этот файл, дальше надо идти по новой его версии: bash читает
# скрипт по мере выполнения, и правки в уже прочитанной части просто не применятся.
if [ "${UPDATE_REEXEC:-}" != 1 ] && [ "$(sha1sum "$0" | cut -d" " -f1)" != "$SELF_BEFORE" ]; then
  echo "==> update.sh обновился — перезапускаю по новой версии"
  UPDATE_REEXEC=1 exec bash "$0" "$@"
fi

echo "==> зависимости и сборка"
composer install --no-dev --optimize-autoloader --no-interaction --quiet
npm ci --no-audit --no-fund --loglevel=error
chown -R root:www-data public/build 2>/dev/null || true
npm run build >/dev/null

echo "==> миграции и кэш"
chown -R www-data:www-data storage bootstrap/cache public/build
chgrp -R www-data "$APP"; chmod -R g+rX "$APP"; chmod 0640 .env
sudo -u www-data php artisan migrate --force -q
sudo -u www-data php artisan optimize -q

echo "==> служебные скрипты"
install -m 0755 -o root -g root "$HERE/mailadmin-ctl" /usr/local/sbin/mailadmin-ctl
install -m 0755 -o root -g root "$HERE/mailadmin-backup" /usr/local/sbin/mailadmin-backup
install -m 0755 -o root -g root "$HERE/mailadmin-spamstats" /usr/local/sbin/mailadmin-spamstats
install -m 0755 -o root -g root "$HERE/mailadmin-spamnet" /usr/local/sbin/mailadmin-spamnet
install -m 0755 -o root -g root "$HERE/mailadmin-sysinfo" /usr/local/sbin/mailadmin-sysinfo
install -m 0755 -o root -g root "$HERE/fail2ban-mailadmin.sh" /usr/local/sbin/mailadmin-f2b
install -m 0644 "$HERE/logrotate-mailadmin" /etc/logrotate.d/mailadmin
# mail.log теперь наш (хранится 10 недель, как журналы Dovecot). Из пачки rsyslog его
# нужно убрать: двух записей об одном файле logrotate не допускает.
if grep -q '^/var/log/mail\.log$' /etc/logrotate.d/rsyslog 2>/dev/null; then
  sed -i '\#^/var/log/mail\.log$#d' /etc/logrotate.d/rsyslog
  echo "    mail.log переведён на хранение 10 недель"
fi
bash "$HERE/dovecot-fts-learn.sh" >/dev/null 2>&1 || true
bash "$HERE/postfix-quota-soft.sh" >/dev/null 2>&1 || true
bash "$HERE/postfix-delivery.sh" >/dev/null 2>&1 || true
bash "$HERE/antispam-extras.sh" >/dev/null 2>&1 || true
systemctl reload php8.3-fpm
# Octane держит код в памяти — после выкладки воркеры надо перезапустить (мягко, без обрыва запросов)
if systemctl is-active -q mailadmin-octane; then systemctl reload mailadmin-octane || systemctl restart mailadmin-octane; fi
echo "==> готово: $(git -c safe.directory="$APP" log --oneline -1)"
