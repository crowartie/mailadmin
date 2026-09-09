#!/usr/bin/env bash
# ============================================================================
#  Почтовый сервер «под ключ» на чистой Ubuntu 24.04:
#  iRedMail 1.8.7 (Postfix, Dovecot, Amavis, SpamAssassin, MariaDB, nginx, fail2ban)
#  + веб-почта и админка mailadmin (Laravel, /opt/mailadmin).
#
#  Как пользоваться (от root или через sudo, на свежей машине с FQDN-именем):
#    git clone <репозиторий> /opt/mailadmin
#    cd /opt/mailadmin/deploy && cp install.conf.example install.conf && nano install.conf
#    sudo bash install.sh
#
#  Скрипт идемпотентный: повторный запуск ничего не ломает и доделывает пропущенное.
#  Секреты (пароли БД, master-пароль Dovecot, пароль админа) складываются в /root/mailadmin-install.txt.
# ============================================================================
set -euo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)
APP=$(cd "$HERE/.." && pwd)
CONF="$HERE/install.conf"
IREDMAIL_VER=1.8.7
IREDMAIL_URL="https://github.com/iredmail/iRedMail/releases/download/${IREDMAIL_VER}/iRedMail-${IREDMAIL_VER}.tar.gz"
NODE_MAJOR=22
SECRETS=/root/mailadmin-install.txt

log()  { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
warn() { printf '\033[1;33m!!  %s\033[0m\n' "$*"; }
die()  { printf '\033[1;31mОШИБКА: %s\033[0m\n' "$*" >&2; exit 1; }
# Без head в конце конвейера: под pipefail SIGPIPE от head роняет весь скрипт.
rand() { openssl rand -base64 96 | tr -dc 'A-Za-z0-9' | cut -c1-"${1:-24}"; }
secret() { # сохранить пару ключ=значение в /root/mailadmin-install.txt (один раз)
  touch "$SECRETS"; chmod 0600 "$SECRETS"
  grep -q "^$1=" "$SECRETS" || printf '%s=%s\n' "$1" "$2" >> "$SECRETS"
}
# Первое совпадение по sed-выражению; отсутствие файла — не ошибка (иначе set -e молча роняет скрипт на пустом значении).
readval() { { sed -n "$2" "$1" 2>/dev/null || true; } | awk 'NR==1'; }
getsecret() { readval "$SECRETS" "s/^$1=//p"; }

# ── 0. Проверки и параметры ────────────────────────────────────────────────
[ "$(id -u)" = 0 ] || die "запускать от root: sudo bash install.sh"
[ -f "$CONF" ] || die "нет $CONF — скопируйте install.conf.example в install.conf и заполните"
# shellcheck disable=SC1090
. "$CONF"
: "${DOMAIN:?в install.conf нужен DOMAIN}"; : "${HOSTNAME:?в install.conf нужен HOSTNAME}"
TIMEZONE=${TIMEZONE:-UTC}; ADMIN_EMAIL=${ADMIN_EMAIL:-admin@$DOMAIN}
LETSENCRYPT=${LETSENCRYPT:-no}; ENABLE_CLAMAV=${ENABLE_CLAMAV:-yes}; OUTBOUND_PER_HOUR=${OUTBOUND_PER_HOUR:-200}
[ "$HOSTNAME" != "$DOMAIN" ] || die "HOSTNAME должен отличаться от DOMAIN (например mail.$DOMAIN)"
grep -q 'Ubuntu 24.04' /etc/os-release || warn "скрипт проверялся на Ubuntu 24.04; у вас $(. /etc/os-release; echo "$PRETTY_NAME")"
export DEBIAN_FRONTEND=noninteractive

MYSQL_ROOT_PASSWORD=${MYSQL_ROOT_PASSWORD:-$(getsecret MYSQL_ROOT_PASSWORD)}; MYSQL_ROOT_PASSWORD=${MYSQL_ROOT_PASSWORD:-$(rand 20)}
POSTMASTER_PASSWORD=${POSTMASTER_PASSWORD:-$(getsecret POSTMASTER_PASSWORD)}; POSTMASTER_PASSWORD=${POSTMASTER_PASSWORD:-$(rand 18)}
ADMIN_PASSWORD=${ADMIN_PASSWORD:-$(getsecret ADMIN_PASSWORD)}; ADMIN_PASSWORD=${ADMIN_PASSWORD:-$(rand 16)}
secret ADMIN_PASSWORD "$ADMIN_PASSWORD"
POSTMASTER_NOTE="postmaster@$DOMAIN / $POSTMASTER_PASSWORD"
[ -f /etc/iredmail-release ] && POSTMASTER_NOTE="postmaster@$DOMAIN (iRedMail стоял раньше — пароль прежний)"

log "Имя хоста и часовой пояс"
hostnamectl set-hostname "$HOSTNAME"
if ! grep -qE "^127\.0\.1\.1\s+$HOSTNAME" /etc/hosts; then sed -i "/^127\.0\.1\.1/d" /etc/hosts; printf '127.0.1.1 %s %s\n' "$HOSTNAME" "${HOSTNAME%%.*}" >> /etc/hosts; fi
timedatectl set-timezone "$TIMEZONE" || warn "не удалось выставить часовой пояс $TIMEZONE"

# ── 1. iRedMail ────────────────────────────────────────────────────────────
if [ -f /etc/iredmail-release ]; then
  log "iRedMail уже установлен ($(head -1 /etc/iredmail-release)) — пропускаю"
else
  log "Установка iRedMail $IREDMAIL_VER (без вопросов, 10–20 минут)"
  apt-get update -q && apt-get install -y -q curl ca-certificates gnupg
  cd /root
  [ -f "iRedMail-${IREDMAIL_VER}.tar.gz" ] || curl -fsSL -o "iRedMail-${IREDMAIL_VER}.tar.gz" "$IREDMAIL_URL"
  [ -d "iRedMail-${IREDMAIL_VER}" ] || tar xzf "iRedMail-${IREDMAIL_VER}.tar.gz"
  cat > "iRedMail-${IREDMAIL_VER}/config" <<EOF
export STORAGE_BASE_DIR=/var/vmail
export WEB_SERVER=NGINX
export BACKEND_ORIG=MARIADB
export BACKEND=MYSQL
export MYSQL_ROOT_PASSWD=${MYSQL_ROOT_PASSWORD}
export FIRST_DOMAIN=${DOMAIN}
export DOMAIN_ADMIN_PASSWD_PLAIN=${POSTMASTER_PASSWORD}
export USE_IREDADMIN=YES
export USE_SOGO=NO
export USE_NETDATA=NO
export USE_FAIL2BAN=YES
#EOF
EOF
  chmod 0600 "iRedMail-${IREDMAIL_VER}/config"
  secret MYSQL_ROOT_PASSWORD "$MYSQL_ROOT_PASSWORD"; secret POSTMASTER_PASSWORD "$POSTMASTER_PASSWORD"
  ( cd "iRedMail-${IREDMAIL_VER}" && AUTO_USE_EXISTING_CONFIG_FILE=y AUTO_INSTALL_WITHOUT_CONFIRM=y \
      AUTO_CLEANUP_REMOVE_SENDMAIL=y AUTO_CLEANUP_REPLACE_FIREWALL_RULES=y AUTO_CLEANUP_RESTART_FIREWALL=y \
      AUTO_CLEANUP_REPLACE_MYSQL_CONFIG=y bash iRedMail.sh ) || die "iRedMail не установился, смотрите вывод выше и /root/iRedMail-${IREDMAIL_VER}/runtime/install.log"
  cd "$APP"
fi
TIPS=/root/iRedMail-${IREDMAIL_VER}/iRedMail.tips
[ -f "$TIPS" ] || die "нет $TIPS — установка iRedMail не завершилась"

# Пароли служебных баз iRedMail — из его же файлов.
# iRedMail.tips пароли не печатает — берём из настроек iRedAdmin (vmailadmin) и iRedAPD (iredapd, amavisd).
pyval() { readval "$1" "s/^$2 *= *['\"]\([^'\"]*\)['\"].*/\1/p"; }
VMAILADMIN_PASSWORD=$(pyval /opt/www/iredadmin/settings.py vmail_db_password)
IREDAPD_DB_PASSWORD=$(pyval /opt/iredapd/settings.py iredapd_db_password)
AMAVIS_DB_PASSWORD=$(pyval /opt/iredapd/settings.py amavisd_db_password)
[ -n "$VMAILADMIN_PASSWORD" ] || die "не нашёл пароль vmailadmin в /opt/www/iredadmin/settings.py"
[ -n "$IREDAPD_DB_PASSWORD" ] && [ -n "$AMAVIS_DB_PASSWORD" ] || die "не нашёл пароли iredapd/amavisd в /opt/iredapd/settings.py"
mysql() { command mysql -u root -p"$MYSQL_ROOT_PASSWORD" "$@"; }
mysql -e 'select 1' >/dev/null 2>&1 || mysql() { command mysql "$@"; }   # на свежем iRedMail root входит через сокет
mysql -e 'select 1' >/dev/null || die "нет доступа к MariaDB от root"

# ── 2. Пакеты приложения ───────────────────────────────────────────────────
log "Пакеты: PHP-расширения, Redis, Node $NODE_MAJOR, Composer, fts_xapian, imapsync"
apt-get update -q
apt-get install -y -q git unzip redis-server certbot dovecot-fts-xapian \
  php8.3-cli php8.3-fpm php8.3-xml php8.3-mbstring php8.3-zip php8.3-gd php8.3-intl php8.3-redis php8.3-curl php8.3-mysql php8.3-bcmath
systemctl enable --now redis-server >/dev/null
if ! command -v node >/dev/null || [ "$(node -v | sed 's/v\([0-9]*\).*/\1/')" -lt "$NODE_MAJOR" ]; then
  curl -fsSL "https://deb.nodesource.com/setup_${NODE_MAJOR}.x" | bash - && apt-get install -y -q nodejs
fi
if ! command -v composer >/dev/null; then
  curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php && php /tmp/composer-setup.php --quiet --install-dir=/usr/local/bin --filename=composer && rm -f /tmp/composer-setup.php
fi
# imapsync — перенос ящиков со старого сервера; зависимости из apt, сам скрипт — с GitHub.
apt-get install -y -q libauthen-ntlm-perl libcgi-pm-perl libcrypt-openssl-rsa-perl libdata-uniqid-perl libencode-imaputf7-perl \
  libfile-copy-recursive-perl libfile-tail-perl libio-socket-inet6-perl libio-socket-ssl-perl libio-tee-perl libhtml-parser-perl \
  libjson-webtoken-perl libmail-imapclient-perl libparse-recdescent-perl libproc-processtable-perl libmodule-scandeps-perl \
  libreadonly-perl libregexp-common-perl libsys-meminfo-perl libterm-readkey-perl libtest-mockobject-perl libtest-pod-perl \
  libunicode-string-perl liburi-perl libwww-perl libtest-nowarnings-perl libtest-deep-perl libtest-warn-perl >/dev/null 2>&1 || warn "часть perl-зависимостей imapsync не поставилась"
if [ ! -x /usr/local/bin/imapsync ]; then
  curl -fsSL -o /usr/local/bin/imapsync https://raw.githubusercontent.com/imapsync/imapsync/master/imapsync && chmod 755 /usr/local/bin/imapsync || warn "imapsync не скачался — перенос почты будет недоступен, положите файл в /usr/local/bin/imapsync вручную"
fi

# ── 3. База приложения и .env ──────────────────────────────────────────────
log "База mailadmin и .env"
# На уже установленном сервере пароли берём из .env — иначе сломаем работающую установку.
DB_PASSWORD=$(readval "$APP/.env" 's/^DB_PASSWORD=//p'); DB_PASSWORD=${DB_PASSWORD:-$(getsecret DB_PASSWORD)}; DB_PASSWORD=${DB_PASSWORD:-$(rand 24)}; secret DB_PASSWORD "$DB_PASSWORD"
mysql <<EOF
CREATE DATABASE IF NOT EXISTS mailadmin CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'mailadmin'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';
ALTER USER 'mailadmin'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';
GRANT ALL PRIVILEGES ON mailadmin.* TO 'mailadmin'@'localhost';
FLUSH PRIVILEGES;
EOF
MASTER_PASSWORD=$(readval "$APP/.env" 's/^MAIL_IMAP_MASTER_PASSWORD=//p'); MASTER_PASSWORD=${MASTER_PASSWORD:-$(getsecret MASTER_PASSWORD)}; MASTER_PASSWORD=${MASTER_PASSWORD:-$(rand 28)}; secret MASTER_PASSWORD "$MASTER_PASSWORD"
# mlmmjadmin: у unattended-установки пустой токен — выставляем свой.
if grep -q "^api_auth_tokens = \[''\]" /opt/mlmmjadmin/settings.py 2>/dev/null; then
  sed -i "s/^api_auth_tokens = \[''\]/api_auth_tokens = ['$(openssl rand -hex 24)']/" /opt/mlmmjadmin/settings.py
  systemctl restart mlmmjadmin || true
fi
MLMMJADMIN_TOKEN=$(readval /opt/mlmmjadmin/settings.py "s/^api_auth_tokens = \['\([^']*\)'\].*/\1/p")
if [ ! -f "$APP/.env" ]; then
  sed -e "s|@@DOMAIN@@|$DOMAIN|g" -e "s|@@HOSTNAME@@|$HOSTNAME|g" -e "s|@@TIMEZONE@@|$TIMEZONE|g" \
      -e "s|@@DB_PASSWORD@@|$DB_PASSWORD|" -e "s|@@VMAIL_DB_PASSWORD@@|$VMAILADMIN_PASSWORD|" -e "s|@@MASTER_PASSWORD@@|$MASTER_PASSWORD|" \
      -e "s|@@IREDAPD_DB_PASSWORD@@|$IREDAPD_DB_PASSWORD|" -e "s|@@AMAVIS_DB_PASSWORD@@|$AMAVIS_DB_PASSWORD|" -e "s|@@MLMMJADMIN_TOKEN@@|$MLMMJADMIN_TOKEN|" \
      "$HERE/env.production.tpl" > "$APP/.env"
  chmod 0640 "$APP/.env"
fi
# Master-пользователь Dovecot (iRedMail уже держит passdb master на этот файл).
if ! grep -q '^mailadmin:' /etc/dovecot/dovecot-master-users 2>/dev/null; then
  printf 'mailadmin:%s\n' "$(doveadm pw -s SSHA512 -p "$MASTER_PASSWORD")" >> /etc/dovecot/dovecot-master-users
  chown root:dovecot /etc/dovecot/dovecot-master-users; chmod 0640 /etc/dovecot/dovecot-master-users
fi

# ── 4. Приложение: зависимости, сборка, миграции ───────────────────────────
log "Composer, npm, миграции"
cd "$APP"
export COMPOSER_ALLOW_SUPERUSER=1 HOME=/root
composer install --no-dev --optimize-autoloader --no-interaction --quiet
npm ci --no-audit --no-fund --loglevel=error
npm run build >/dev/null
grep -q '^APP_KEY=.\+' .env || php artisan key:generate --force -q
install -d -m 2775 -o www-data -g www-data storage/app/private/outbox storage/app/private/migrations storage/app/private/sieve storage/logs
touch storage/logs/auth.log
chown -R www-data:www-data storage bootstrap/cache public/build
chmod -R ug+rwX storage bootstrap/cache
chgrp -R www-data "$APP"; chmod -R g+rX "$APP"; chmod 0640 .env; chgrp www-data .env
sudo -u www-data php artisan migrate --force -q
sudo -u www-data php artisan optimize -q
# Первого администратора создаём только в пустой таблице: повторный запуск не должен сбрасывать пароли.
if [ "$(mysql -N mailadmin -e 'select count(*) from users')" = 0 ]; then
  sudo -u www-data php artisan admin:create "$ADMIN_EMAIL" --password="$ADMIN_PASSWORD" --role=owner >/dev/null
  ADMIN_NOTE="$ADMIN_EMAIL / $ADMIN_PASSWORD"
else
  ADMIN_NOTE="(администраторы уже есть — пароли не менялись)"
fi

# ── 5. nginx: 443 — веб-почта, 8443 — админка, iRedAdmin уезжает на 8444 ───
log "nginx"
for f in /etc/nginx/sites-enabled/00-default-ssl.conf /etc/nginx/sites-available/00-default-ssl.conf; do
  [ -f "$f" ] && sed -i -E 's/^(\s*listen\s+(\[::\]:)?)443(\s+ssl)/\18444\3/' "$f"
done
NAMES="$HOSTNAME webmail.$DOMAIN imap.$DOMAIN smtp.$DOMAIN autoconfig.$DOMAIN autodiscover.$DOMAIN"
render_site() { # порт лог заголовок редирект-корня
  sed -e "s|@@PORT@@|$1|g" -e "s|@@LOG@@|$2|g" -e "s|@@TITLE@@|$3|g" -e "s|@@SERVER_NAMES@@|$NAMES|g" -e "s|@@ROOT_REDIRECT@@|$4|" "$HERE/nginx-site.conf.tpl"
}
render_site 443  mailweb  "Веб-почта"  '    location = / { return 302 /mail; }' > /etc/nginx/sites-available/mailweb.conf
render_site 8443 mailadmin "Админка"   '' > /etc/nginx/sites-available/mailadmin.conf
ln -sf /etc/nginx/sites-available/mailweb.conf /etc/nginx/sites-enabled/mailweb.conf
ln -sf /etc/nginx/sites-available/mailadmin.conf /etc/nginx/sites-enabled/mailadmin.conf
nginx -t && systemctl reload nginx
# Файрвол iRedMail (nftables): открыть админку и iRedAdmin.
if [ -f /etc/nftables.conf ] && ! grep -q 'dport 8443' /etc/nftables.conf; then
  sed -i -E 's/^(\s*)(tcp dport 443 accept)$/\1\2\n\18443 accept placeholder/' /etc/nftables.conf
  sed -i -E 's/^(\s*)8443 accept placeholder$/\1tcp dport 8443 accept\n\1tcp dport 8444 accept/' /etc/nftables.conf
  nft -f /etc/nftables.conf 2>/dev/null || warn "nftables: правила не перечитались, проверьте /etc/nftables.conf"
fi

# PHP-FPM: пул iRedMail слушает 127.0.0.1:9999; поднимаем лимиты под вложения до 256 МБ.
POOL=/etc/php/8.3/fpm/pool.d/www.conf
if ! grep -q 'upload_max_filesize' "$POOL"; then
  cat >> "$POOL" <<'EOF'

; mailadmin: вложения до 256 МБ (в письме или через облако) и долгие операции (перенос, пересортировка)
request_terminate_timeout = 600s
php_admin_value[upload_max_filesize] = 256M
php_admin_value[post_max_size] = 260M
php_admin_value[max_execution_time] = 600
php_admin_value[max_input_time] = 600
php_admin_value[memory_limit] = 512M
EOF
fi
systemctl restart php8.3-fpm

# ── 6. Служебная обёртка, sudo, копии, журналы ─────────────────────────────
log "mailadmin-ctl, sudoers, резервные копии, fail2ban, logrotate, планировщик"
install -m 0755 -o root -g root "$HERE/mailadmin-ctl" /usr/local/sbin/mailadmin-ctl
install -m 0755 -o root -g root "$HERE/mailadmin-backup" /usr/local/sbin/mailadmin-backup
install -m 0755 -o root -g root "$HERE/fail2ban-mailadmin.sh" /usr/local/sbin/mailadmin-f2b
printf '# Веб-приложение управляет сервером только через mailadmin-ctl (белый список подкоманд внутри).\nwww-data ALL=(root) NOPASSWD: /usr/local/sbin/mailadmin-ctl\n' > /etc/sudoers.d/mailadmin
chmod 0440 /etc/sudoers.d/mailadmin; visudo -c >/dev/null
usermod -aG adm www-data
install -d -m 0750 -o root -g www-data /var/backups/mail
/usr/local/sbin/mailadmin-f2b 5 10 24 >/dev/null || warn "fail2ban jail не поднялся"
install -m 0644 "$HERE/logrotate-mailadmin" /etc/logrotate.d/mailadmin
( { crontab -l 2>/dev/null || true; } | { grep -v 'artisan schedule:run' || true; }; echo "* * * * * cd $APP && php artisan schedule:run >> /dev/null 2>&1" ) | crontab -

# ── 7. Dovecot: пароли приложений, полнотекстовый поиск, обучение спама, общий Sieve ──
log "Dovecot: пароли приложений, fts_xapian, imapsieve, sieve_before2"
cat > /etc/dovecot/dovecot-app-passwords.conf <<EOF
driver = mysql
connect = host=127.0.0.1 port=3306 dbname=mailadmin user=mailadmin password=$DB_PASSWORD
default_pass_scheme = SSHA512
password_query = SELECT password, username AS user FROM app_passwords WHERE username = '%u' AND active = 1
EOF
chmod 0640 /etc/dovecot/dovecot-app-passwords.conf; chown root:dovecot /etc/dovecot/dovecot-app-passwords.conf
if ! grep -q 'dovecot-app-passwords.conf' /etc/dovecot/dovecot.conf; then
  cp -a /etc/dovecot/dovecot.conf "/etc/dovecot/dovecot.conf.bak-install-$(date +%Y%m%d%H%M%S)"
  python3 - <<'PYEOF'
p = '/etc/dovecot/dovecot.conf'
s = open(p).read()
old = "passdb {\n    args = /etc/dovecot/dovecot-mysql.conf\n    driver = sql\n}"
new = ("passdb {\n    args = /etc/dovecot/dovecot-mysql.conf\n    driver = sql\n    result_failure = continue\n}\n\n"
       "# Пароли приложений (mailadmin): отдельная таблица в базе приложения.\n"
       "passdb {\n    driver = sql\n    args = /etc/dovecot/dovecot-app-passwords.conf\n}")
assert old in s, 'passdb block not found in dovecot.conf'
open(p, 'w').write(s.replace(old, new, 1))
PYEOF
fi
bash "$HERE/dovecot-fts-learn.sh"
bash "$HERE/setup-reports.sh"
doveconf -n >/dev/null && systemctl restart dovecot

# ── 8. Amavis: пороги, карантин в базу, антивирус ──────────────────────────
log "Amavis и ClamAV"
AMV=/etc/amavis/conf.d/50-user
sed -i -E 's/^(\$sa_tag2_level_deflt\s*=\s*)[0-9.]+;/\16.2;/; s/^(\$sa_kill_level_deflt\s*=\s*)[0-9.]+;/\112.0;/; s/^(\$sa_dsn_cutoff_level\s*=\s*)[0-9.]+;/\110.0;/' "$AMV"
# Карантин включается политикой в базе amavisd: без spam_quarantine_to письма выше kill просто выбрасываются.
mysql amavisd -e "UPDATE policy SET spam_lover='N', spam_quarantine_to=IFNULL(NULLIF(spam_quarantine_to,''),'spam-quarantine'), virus_quarantine_to=IFNULL(NULLIF(virus_quarantine_to,''),'virus-quarantine'), banned_quarantine_to=IFNULL(NULLIF(banned_quarantine_to,''),'banned-quarantine'), bad_header_quarantine_to=IFNULL(NULLIF(bad_header_quarantine_to,''),'bad-header-quarantine') WHERE policy_name='@.'"
# amavisd-release ищет сокет тут:
[ -e /var/lib/amavis/amavisd.sock ] || ln -s /var/run/amavis/amavisd.socket /var/lib/amavis/amavisd.sock
if [ "$ENABLE_CLAMAV" = yes ]; then
  if [ -n "${CLAMAV_MIRROR:-}" ] && ! grep -q '^PrivateMirror' /etc/clamav/freshclam.conf; then
    sed -i -E 's/^(DatabaseMirror .*)$/#\1/' /etc/clamav/freshclam.conf
    printf '\n# Своё зеркало баз (CDN ClamAV недоступен из этой сети)\nPrivateMirror %s\n' "$CLAMAV_MIRROR" >> /etc/clamav/freshclam.conf
  fi
  rm -f /var/lib/clamav/freshclam.dat
  systemctl enable --now clamav-freshclam >/dev/null 2>&1 || true
  for _ in $(seq 1 60); do ls /var/lib/clamav/main.c?d >/dev/null 2>&1 && break; sleep 5; done
  if ls /var/lib/clamav/main.c?d >/dev/null 2>&1; then
    systemctl enable --now clamav-daemon >/dev/null 2>&1 || true
    /usr/local/sbin/mailadmin-ctl amavis-virus on >/dev/null
  else
    warn "базы ClamAV не скачались (CDN недоступен?) — антивирус выключен, включите позже в Настройки → Антиспам"
    /usr/local/sbin/mailadmin-ctl amavis-virus off >/dev/null
  fi
else
  /usr/local/sbin/mailadmin-ctl amavis-virus off >/dev/null
fi
systemctl reset-failed amavis 2>/dev/null || true; systemctl restart amavis

# ── 9. Данные: noreply@, лимит исходящих ───────────────────────────────────
log "Стартовые данные"
sudo -u www-data php artisan setup:bootstrap --throttle="$OUTBOUND_PER_HOUR"

# ── 10. Сертификат Let's Encrypt (по желанию) ──────────────────────────────
if [ "$LETSENCRYPT" = yes ] && [ ! -d "/etc/letsencrypt/live/$HOSTNAME" ]; then
  log "Let's Encrypt для $NAMES"
  DARGS=""; for n in $NAMES; do DARGS="$DARGS -d $n"; done
  # shellcheck disable=SC2086
  if certbot certonly --webroot -w /opt/www/well_known $DARGS --email "${LETSENCRYPT_EMAIL:-$ADMIN_EMAIL}" --agree-tos --no-eff-email -n; then
    ln -sf "/etc/letsencrypt/live/$HOSTNAME/fullchain.pem" /etc/ssl/certs/iRedMail.crt
    ln -sf "/etc/letsencrypt/live/$HOSTNAME/privkey.pem" /etc/ssl/private/iRedMail.key
    systemctl reload nginx postfix dovecot
  else
    warn "сертификат не выпущен (DNS ещё не указывает сюда или закрыт порт 80) — остался самоподписанный; повторите позже: sudo bash $0"
  fi
fi

# ── Итог ───────────────────────────────────────────────────────────────────
DKIM=$(/usr/local/sbin/mailadmin-ctl dkim-txt "$DOMAIN" 2>/dev/null || echo '(ключ появится после перезапуска amavis)')
IP=$(curl -fsS -4 https://ifconfig.me 2>/dev/null || hostname -I | awk '{print $1}')
cat <<EOF

============================================================================
 Готово. Секреты сохранены в $SECRETS (только root).

 Админка:    https://$HOSTNAME:8443   — $ADMIN_NOTE
 Веб-почта:  https://$HOSTNAME/mail  — $POSTMASTER_NOTE
 iRedAdmin:  https://$HOSTNAME:8444/iredadmin (на всякий случай)

 DNS-записи для $DOMAIN (проверка — Админка → Настройки → Домены и DNS):
   $HOSTNAME                A      $IP
   $DOMAIN                  MX 10  $HOSTNAME
   imap, smtp, webmail, autoconfig, autodiscover.$DOMAIN   CNAME  $HOSTNAME
   $DOMAIN                  TXT    "v=spf1 mx -all"
   dkim._domainkey.$DOMAIN  TXT    $DKIM
   _dmarc.$DOMAIN           TXT    "v=DMARC1; p=quarantine; rua=mailto:postmaster@$DOMAIN"
   PTR для $IP → $HOSTNAME (у провайдера)

 Дальше: перезагрузите сервер один раз (iRedMail просит после установки),
 войдите в админку, включите 2FA, проверьте вкладку «Домены и DNS».
 Обновление приложения: sudo bash $APP/deploy/update.sh
============================================================================
EOF
