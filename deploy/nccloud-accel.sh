#!/bin/bash
# Личное облако сотрудника: скачивание и просмотр файлов из Nextcloud отдаёт сам nginx.
#
# Приложение отвечает X-Accel-Redirect: /_nccloud/ и двумя служебными заголовками — адрес файла
# в Nextcloud (X-Nc-Url) и вход служебной учётки (X-Nc-Auth). Эта закрытая (internal) location
# берёт их из ответа приложения и сама идёт в Nextcloud: гигабайтное видео не проходит через
# память PHP, работает докачка и перемотка (Range). Служебные заголовки наружу не уходят —
# клиенту отдаётся ответ второго запроса.
#
# Нужна в двух местах: на хосте почты (раздел «Облако», предпросмотр) и на files-хосте —
# ссылки на файлы облака в письмах выглядят как у больших вложений (files.<домен>/<токен>/<имя>).
# Тело исходного запроса в Nextcloud не передаём: POST с паролем ссылки превращается
# nginx-ом в GET, и пароль уходить дальше не должен.
#
# Повторный запуск безопасен: вставляет location один раз, старую версию заменяет.
set -euo pipefail

VER='# nccloud v2'
LOC='location ^~ /_nccloud/ { internal; resolver 127.0.0.1 ipv6=off valid=300s; set $nc_url $upstream_http_x_nc_url; set $nc_auth $upstream_http_x_nc_auth; proxy_pass $nc_url; proxy_pass_request_body off; proxy_set_header Content-Length ""; proxy_set_header Content-Type ""; proxy_set_header Authorization $nc_auth; proxy_set_header Cookie ""; proxy_ssl_server_name on; proxy_buffering off; proxy_read_timeout 600s; proxy_hide_header Set-Cookie; proxy_hide_header Content-Disposition; proxy_hide_header Content-Security-Policy; add_header X-Content-Type-Options nosniff always; add_header Content-Security-Policy "sandbox" always; add_header Cache-Control "private, no-store" always; add_header X-Robots-Tag "noindex, nofollow" always; } '"$VER"

# $1 — файл, $2 — строка (регулярка sed), перед которой вставить; отступ берётся у неё.
put() {
  local f="$1" anchor="$2"
  [ -f "$f" ] || return 0
  grep -q "$VER" "$f" && return 0
  grep -qE "$anchor" "$f" || { echo "    облако: в $f нет «$anchor» — пропуск" >&2; return 0; }
  cp -a "$f" "$f.bak-nccloud-$(date +%Y%m%d%H%M%S)"
  sed -i '\#location ^~ /_nccloud/ {.*}#d' "$f"
  local esc
  esc="$(printf '%s' "$LOC" | sed 's/[&|\\]/\\&/g')"
  sed -i -E '0,\#^([[:space:]]*)('"$anchor"')#s||\1'"$esc"'\n\1\2|' "$f"
  CHANGED+=("$f")
}

CHANGED=()
put /etc/nginx/snippets/mailadmin-backend.conf 'location / \{'
put /etc/nginx/sites-available/files.conf 'location = / \{'
[ ${#CHANGED[@]} -gt 0 ] || exit 0

if nginx -t >/dev/null 2>&1; then
  systemctl reload nginx
  echo "    облако: nginx отдаёт файлы из Nextcloud (/_nccloud/): ${CHANGED[*]}"
else
  echo "    облако: nginx не принял настройку — откат" >&2
  for f in "${CHANGED[@]}"; do cp -a "$(ls -t "$f".bak-nccloud-* | head -1)" "$f"; done
  nginx -t && systemctl reload nginx
  exit 1
fi
