#!/bin/bash
# Push-уведомления о новых письмах: подключить к Dovecot драйвер lua, который при доставке письма
# во «Входящие» дёргает веб-почту (/mail/api/push/event). Идемпотентно, запускается из update.sh (root).
#  - .env: PUSH_EVENT_TOKEN (секрет для события) и ключи VAPID (PUSH_VAPID_PUBLIC/PRIVATE) — создаются, если их нет;
#  - /etc/dovecot/mailadmin-push.lua — скрипт с подставленными адресом и токеном (читает vmail — от него идёт доставка);
#  - dovecot.conf: плагины mail_lua push_notification push_notification_lua в protocol lda и lmtp
#    (iRedMail доставляет через dovecot-lda) и блок plugin { push_notification_driver }.
# Порядок плагинов важен: без mail_lua перед push_notification_lua доставка падает с
# «undefined symbol: dlua_dovecot_register» (29.09.2026 так на 4 минуты встала вся доставка).
# Поэтому перед правкой конфигурации плагины пробуются на живом ящике через doveadm.
set -e
HERE=$(cd "$(dirname "$0")" && pwd)
APP=$(cd "$HERE/.." && pwd)
ENV="$APP/.env"
CONF=/etc/dovecot/dovecot.conf
LUA=/etc/dovecot/mailadmin-push.lua
PLUGINS="mail_lua push_notification push_notification_lua"
[ -f "$ENV" ] || { echo "push: нет $ENV"; exit 0; }
[ -f /usr/lib/dovecot/modules/lib22_push_notification_lua_plugin.so ] || { echo "push: в Dovecot нет push_notification_lua — уведомления не подключены"; exit 0; }

getenv() { grep -E "^$1=" "$ENV" | tail -1 | cut -d= -f2- | tr -d '"' | tr -d "'"; }
changed=0
if [ -z "$(getenv PUSH_EVENT_TOKEN)" ]; then
  printf '\n# Push-уведомления: токен события от Dovecot (deploy/dovecot-push.sh)\nPUSH_EVENT_TOKEN=%s\n' "$(head -c 32 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 40)" >> "$ENV"
  changed=1
fi
if [ -z "$(getenv PUSH_VAPID_PUBLIC)" ] || [ -z "$(getenv PUSH_VAPID_PRIVATE)" ]; then
  keys=$(cd "$APP" && sudo -u www-data php artisan push:keys --dotenv 2>/dev/null || true)
  if echo "$keys" | grep -q '^PUSH_VAPID_PUBLIC='; then
    sed -i '/^PUSH_VAPID_PUBLIC=/d;/^PUSH_VAPID_PRIVATE=/d' "$ENV"
    printf '# Ключи VAPID для push-уведомлений (push:keys); менять нельзя — подписки устройств перестанут работать\n%s\n' "$keys" >> "$ENV"
    changed=1
  else
    echo "push: ключи VAPID не создались (composer install ещё не прошёл?) — повторится при следующей выкладке"
  fi
fi
[ "$changed" = 1 ] && (cd "$APP" && sudo -u www-data php artisan optimize -q || true)

TOKEN=$(getenv PUSH_EVENT_TOKEN)
PORT=$(getenv OCTANE_PORT); PORT=${PORT:-8000}
URL="http://127.0.0.1:$PORT/mail/api/push/event"
tmp=$(mktemp)
sed -e "s#__URL__#$URL#" -e "s#__TOKEN__#$TOKEN#" "$HERE/dovecot-push.lua" > "$tmp"
if ! cmp -s "$tmp" "$LUA" || [ "$(stat -c %G "$LUA" 2>/dev/null)" != vmail ]; then
  install -m 0640 -o root -g vmail "$tmp" "$LUA"
  echo "    push: скрипт Dovecot обновлён"
fi
rm -f "$tmp"

# Уже подключено — больше ничего не трогаем.
if grep -q 'push_notification_driver' "$CONF" && grep -A3 '^protocol lda {' "$CONF" | grep -q 'push_notification_lua'; then
  exit 0
fi

# Проверка на живом ящике: doveadm грузит те же плагины, что и доставка; если что-то не так — конфигурацию не трогаем.
user=$(doveadm user '*' 2>/dev/null | head -1)
if [ -z "$user" ]; then echo "push: не нашёл ни одного ящика для проверки плагинов — Dovecot не трогаю"; exit 0; fi
err=$(mktemp)
if ! doveadm -o "mail_plugins=$(doveconf -h mail_plugins) $PLUGINS" -o "plugin/push_notification_driver=lua:file=$LUA" mailbox status -u "$user" messages INBOX >/dev/null 2>"$err"; then
  echo "push: плагины Dovecot не загрузились, доставку не трогаю: $(head -c 300 "$err")"; rm -f "$err"; exit 0
fi
rm -f "$err"

[ -f "$CONF.bak-push" ] || cp -a "$CONF" "$CONF.bak-push"
if ! grep -q 'push_notification_driver' "$CONF"; then
  printf '\n# Push-уведомления веб-почты (deploy/dovecot-push.sh): событие о новом письме во «Входящих»\nplugin {\n  push_notification_driver = lua:file=%s\n}\n' "$LUA" >> "$CONF"
fi
# Доставка у iRedMail — через dovecot-lda (protocol lda); LMTP — на случай, если её переключат.
for proto in lda lmtp; do
  if ! grep -A3 "^protocol $proto {" "$CONF" | grep -q 'push_notification_lua'; then
    awk -v proto="$proto" -v add="$PLUGINS" '
      $0 ~ ("^protocol " proto " \\{") { f = 1 }
      f && /mail_plugins =/ && !/push_notification_lua/ { $0 = $0 " " add; f = 0 }
      { print }' "$CONF" > "$CONF.tmp" && cat "$CONF.tmp" > "$CONF" && rm -f "$CONF.tmp"
  fi
done
if doveconf -n >/dev/null 2>&1; then
  systemctl reload dovecot && echo "    push: Dovecot перечитал настройки (плагины $PLUGINS в lda и lmtp)"
else
  echo "push: dovecot.conf не прошёл проверку — откат"; cp -a "$CONF.bak-push" "$CONF"; exit 1
fi
