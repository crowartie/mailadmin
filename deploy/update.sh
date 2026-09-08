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

echo "==> git pull"
git -c safe.directory="$APP" fetch -q origin
git -c safe.directory="$APP" checkout -q "${BRANCH:-$(git -c safe.directory="$APP" rev-parse --abbrev-ref HEAD)}"
git -c safe.directory="$APP" pull -q --ff-only

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
install -m 0755 -o root -g root "$HERE/fail2ban-mailadmin.sh" /usr/local/sbin/mailadmin-f2b
install -m 0644 "$HERE/logrotate-mailadmin" /etc/logrotate.d/mailadmin
bash "$HERE/dovecot-fts-learn.sh" >/dev/null 2>&1 || true
systemctl reload php8.3-fpm
echo "==> готово: $(git -c safe.directory="$APP" log --oneline -1)"
