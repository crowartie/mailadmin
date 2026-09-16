#!/bin/bash
# Включить Octane (RoadRunner): приложение остаётся загруженным в памяти вместо старта PHP на каждый запрос.
# nginx отдаёт статику сам, остальное проксирует на 127.0.0.1:8000; PHP-FPM не трогаем — он остаётся для отката
# (deploy/octane-off.sh). Идемпотентно, запускать под sudo: sudo bash /opt/mailadmin/deploy/octane-setup.sh
set -euo pipefail
HERE=$(cd "$(dirname "$0")" && pwd)
APP=$(cd "$HERE/.." && pwd)
[ "$(id -u)" = 0 ] || { echo "запускать от root" >&2; exit 1; }
cd "$APP"

# 1. Бинарник RoadRunner — рядом с приложением (в git не попадает, см. .gitignore)
if [ ! -x "$APP/rr" ]; then
  echo "==> скачиваю RoadRunner"
  HOME=/root vendor/bin/rr get-binary -l "$APP" >/dev/null
fi
chown www-data:www-data "$APP/rr"; chmod 0755 "$APP/rr"
# Базовый конфиг RoadRunner; адрес, число воркеров, exec_ttl и статику Octane передаёт поверх него ключами -o
cat > "$APP/.rr.yaml" <<'EOF'
version: "3"
http:
  # вложения до 256 МБ (как post_max_size у PHP-FPM)
  max_request_size: 260
  pool:
    supervisor:
      # воркер, разросшийся после тяжёлого запроса, заменяется новым
      max_worker_memory: 512
EOF
chown www-data:www-data "$APP/.rr.yaml"

# 2. Octane за nginx на 127.0.0.1: адрес клиента и порт берём из X-Forwarded-* (иначе журнал входов и fail2ban видят 127.0.0.1)
if ! grep -q '^TRUSTED_PROXIES=.*127\.0\.0\.1' .env; then
  if grep -q '^TRUSTED_PROXIES=$' .env; then sed -i 's/^TRUSTED_PROXIES=$/TRUSTED_PROXIES=127.0.0.1/' .env
  elif grep -q '^TRUSTED_PROXIES=' .env; then sed -i 's/^TRUSTED_PROXIES=\(.*\)$/TRUSTED_PROXIES=\1,127.0.0.1/' .env
  else printf '\nTRUSTED_PROXIES=127.0.0.1\n' >> .env; fi
  sudo -u www-data php artisan optimize -q
fi

# 3. Служба
install -m 0644 "$HERE/mailadmin-octane.service" /etc/systemd/system/mailadmin-octane.service
systemctl daemon-reload
systemctl enable -q mailadmin-octane
systemctl restart mailadmin-octane
for i in $(seq 1 30); do
  code=$(curl -s -o /dev/null -w '%{http_code}' -H 'Host: localhost' http://127.0.0.1:8000/mail/login || true)
  [ "$code" = 200 ] && break
  sleep 1
done
if [ "${code:-}" != 200 ]; then
  echo "Octane не отвечает на 127.0.0.1:8000 (код ${code:-нет}); nginx оставлен на PHP-FPM" >&2
  journalctl -u mailadmin-octane -n 20 --no-pager >&2
  exit 1
fi

# 4. nginx: блок PHP-FPM в сайтах заменяем на include общего сниппета, а сниппет переключаем на Octane
mkdir -p /etc/nginx/snippets
cat > /etc/nginx/snippets/mailadmin-backend.conf <<'EOF'
# mailadmin: приложение отдаёт Octane (RoadRunner) на 127.0.0.1:8000, статика — nginx из public/.
# Откат на PHP-FPM: sudo bash /opt/mailadmin/deploy/octane-off.sh
location = /index.php { try_files /not_exists @octane; }
location / { try_files $uri $uri/ @octane; }
location @octane {
    set $suffix "";
    if ($uri = /index.php) { set $suffix ?$query_string; }
    proxy_http_version 1.1;
    proxy_set_header Host $http_host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_set_header X-Forwarded-Host $host;
    proxy_set_header X-Forwarded-Port $server_port;
    proxy_read_timeout 600s;
    proxy_send_timeout 600s;
    proxy_request_buffering off;
    proxy_pass http://127.0.0.1:8000$suffix;
}
location ~ \.php$ { return 404; }
EOF
for f in /etc/nginx/sites-available/mailweb.conf /etc/nginx/sites-available/mailadmin.conf; do
  [ -f "$f" ] || continue
  if grep -q 'fastcgi_pass 127.0.0.1:9999' "$f"; then
    perl -0pi -e 's{    location / \{ try_files \$uri \$uri/ /index\.php\?\$query_string; \}\n    location ~ \\\.php\$ \{\n        fastcgi_pass 127\.0\.0\.1:9999;\n        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;\n        include fastcgi_params;\n    \}\n}{    include /etc/nginx/snippets/mailadmin-backend.conf;\n}' "$f"
    grep -q 'mailadmin-backend.conf' "$f" || { echo "не удалось переписать $f" >&2; exit 1; }
  fi
done
nginx -t >/dev/null 2>&1 && systemctl reload nginx
echo "==> Octane включён: $(systemctl is-active mailadmin-octane), воркеров: $(pgrep -c -f 'rr serve|php-worker|octane' || true)"
