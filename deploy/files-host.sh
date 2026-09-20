#!/usr/bin/env bash
# Хост хранилища больших вложений (files.<домен>): nginx, каталог, лимиты, сертификат.
#   sudo bash /opt/mailadmin/deploy/files-host.sh [files.домен]
# Повторный запуск безопасен. Сертификат добавляется, только когда DNS-имя уже смотрит на этот сервер.
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
APP=/opt/mailadmin
DOMAIN="$(grep -E '^MAIL_DEFAULT_DOMAIN=' "$APP/.env" 2>/dev/null | head -1 | cut -d= -f2 | tr -d '"' || true)"
[ -n "$DOMAIN" ] || DOMAIN="$(hostname -d)"
HOST="${1:-files.$DOMAIN}"
MAIL_HOST="$(hostname -f)"
[ "$(id -u)" = 0 ] || { echo "нужен root: sudo bash $0"; exit 1; }

echo "== хранилище: $APP/storage/app/files"
install -d -o www-data -g www-data -m 0750 "$APP/storage/app/files" "$APP/storage/app/private/preview"

echo "== nginx: $HOST"
sed -e "s|@@HOST@@|$HOST|g" -e "s|@@MAIL_HOST@@|$MAIL_HOST|g" "$HERE/nginx-files.conf.tpl" > /etc/nginx/sites-available/files.conf
ln -sf /etc/nginx/sites-available/files.conf /etc/nginx/sites-enabled/files.conf

# Скачивание с самого почтового хоста (предпросмотр в почте, /f/ на mail.*) — тот же закрытый location.
SNIP=/etc/nginx/snippets/mailadmin-backend.conf
FILES_LOC='location ^~ /_files/ { internal; alias '"$APP"'/storage/app/files/; add_header X-Content-Type-Options nosniff; add_header Content-Security-Policy "sandbox"; add_header X-Robots-Tag "noindex, nofollow"; }'
if [ -f "$SNIP" ]; then
  if ! grep -q '/_files/' "$SNIP"; then
    sed -i '0,/^location \/ {/s||'"$FILES_LOC"'\nlocation / {|' "$SNIP"
  elif ! grep -q '/_files/.*X-Robots-Tag' "$SNIP"; then
    # Строка уже есть, но без заголовков (первая версия скрипта) — заменяем целиком.
    sed -i 's|^location ^~ /_files/ {.*}$|'"$FILES_LOC"'|' "$SNIP"
  fi
fi

# Файл до 500 МБ должен пройти через nginx и RoadRunner.
for f in /etc/nginx/sites-enabled/mailweb.conf /etc/nginx/sites-enabled/mailadmin.conf; do
  [ -f "$f" ] && sed -i 's/client_max_body_size 260m;/client_max_body_size 520m;/' "$f"
done
if [ -f "$APP/.rr.yaml" ] && grep -q 'max_request_size: 260' "$APP/.rr.yaml"; then
  sed -i 's/max_request_size: 260/max_request_size: 520/' "$APP/.rr.yaml"
  systemctl restart mailadmin-octane 2>/dev/null || true
fi

# Почта остаётся хостом по умолчанию на 443: иначе запросы по IP уходили бы в files.conf (он первый по алфавиту).
MW=/etc/nginx/sites-enabled/mailweb.conf
if [ -f "$MW" ] && ! grep -q 'listen 443 ssl http2 default_server' "$MW"; then
  sed -i -e 's/^\(\s*listen 443 ssl http2\);/\1 default_server;/' -e 's/^\(\s*listen \[::\]:443 ssl http2\);/\1 default_server;/' "$MW"
fi

nginx -t && systemctl reload nginx

echo "== сертификат"
IP="$(dig +short @8.8.8.8 "$HOST" 2>/dev/null | tail -1 || true)"
MY="$(dig +short @8.8.8.8 "$MAIL_HOST" 2>/dev/null | tail -1 || true)"
if [ -z "$IP" ]; then
  echo "   DNS $HOST ещё не отвечает — сертификат добавьте позже: sudo bash $0 $HOST"
elif [ -n "$MY" ] && [ "$IP" != "$MY" ]; then
  echo "   $HOST → $IP, а $MAIL_HOST → $MY: разные адреса, сертификат не трогаю"
elif certbot certificates 2>/dev/null | grep -q "$HOST"; then
  echo "   $HOST уже в сертификате"
else
  CERT="$(certbot certificates 2>/dev/null | awk '/Certificate Name:/{print $3; exit}')"
  NAMES="$(certbot certificates 2>/dev/null | awk '/Domains:/{ $1=""; print; exit }' | xargs | tr ' ' ',')"
  if [ -n "$CERT" ] && [ -n "$NAMES" ]; then
    certbot certonly --webroot -w /opt/www/well_known --cert-name "$CERT" -d "$NAMES,$HOST" --expand -n \
      && systemctl reload nginx && (systemctl reload postfix dovecot 2>/dev/null || true) \
      && echo "   добавлен $HOST" || echo "   certbot не смог — см. /var/log/letsencrypt/letsencrypt.log"
  else
    echo "   certbot-сертификат не найден, добавьте $HOST вручную"
  fi
fi
echo "== готово: https://$HOST"
