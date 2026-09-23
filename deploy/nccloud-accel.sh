#!/bin/bash
# Личное облако сотрудника: скачивание и просмотр файлов из Nextcloud отдаёт сам nginx.
#
# Приложение отвечает X-Accel-Redirect: /_nccloud/ и двумя служебными заголовками — адрес файла
# в Nextcloud (X-Nc-Url) и вход служебной учётки (X-Nc-Auth). Эта закрытая (internal) location
# берёт их из ответа приложения и сама идёт в Nextcloud: гигабайтное видео не проходит через
# память PHP, работает докачка и перемотка (Range). Служебные заголовки наружу не уходят —
# клиенту отдаётся ответ второго запроса.
#
# Повторный запуск безопасен: вставляет location один раз, при изменении — заменяет.
set -euo pipefail

SNIP=/etc/nginx/snippets/mailadmin-backend.conf
[ -f "$SNIP" ] || { echo "нет $SNIP — Octane не настроен, облако отдавать нечем"; exit 0; }

LOC='location ^~ /_nccloud/ { internal; resolver 127.0.0.1 ipv6=off valid=300s; set $nc_url $upstream_http_x_nc_url; set $nc_auth $upstream_http_x_nc_auth; proxy_pass $nc_url; proxy_set_header Authorization $nc_auth; proxy_set_header Cookie ""; proxy_ssl_server_name on; proxy_buffering off; proxy_read_timeout 600s; proxy_hide_header Set-Cookie; proxy_hide_header Content-Disposition; proxy_hide_header Content-Security-Policy; add_header X-Content-Type-Options nosniff always; add_header Content-Security-Policy "sandbox" always; add_header Cache-Control "private, no-store" always; } # nccloud v1'

if grep -q '# nccloud v1' "$SNIP"; then
  exit 0
fi
cp -a "$SNIP" "$SNIP.bak-nccloud-$(date +%Y%m%d%H%M%S)"
if grep -q '/_nccloud/' "$SNIP"; then
  sed -i '\#^location ^~ /_nccloud/ {.*}#d' "$SNIP"
fi
sed -i '0,/^location \/ {/s||'"$(printf '%s' "$LOC" | sed 's/[&|]/\\&/g')"'\nlocation / {|' "$SNIP"
if nginx -t >/dev/null 2>&1; then
  systemctl reload nginx
  echo "    облако: nginx отдаёт файлы из Nextcloud (/_nccloud/)"
else
  echo "    облако: nginx не принял настройку — откат" >&2
  cp -a "$(ls -t "$SNIP".bak-nccloud-* | head -1)" "$SNIP"
  nginx -t && systemctl reload nginx
  exit 1
fi
