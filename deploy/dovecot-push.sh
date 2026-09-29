#!/bin/bash
# Push-уведомления о новых письмах: подключить к Dovecot драйвер lua, который при доставке письма
# во «Входящие» дёргает веб-почту (/mail/api/push/event). Идемпотентно, запускается из update.sh (root).
#  - .env: PUSH_EVENT_TOKEN (секрет для события) и ключи VAPID (PUSH_VAPID_PUBLIC/PRIVATE) — создаются, если их нет;
#  - /etc/dovecot/mailadmin-push.lua — скрипт с подставленными адресом и токеном (читает только dovecot);
#  - dovecot.conf: плагины push_notification в protocol lmtp и блок plugin { push_notification_driver }.
set -e
HERE=$(cd "$(dirname "$0")" && pwd)
APP=$(cd "$HERE/.." && pwd)
ENV="$APP/.env"
CONF=/etc/dovecot/dovecot.conf
LUA=/etc/dovecot/mailadmin-push.lua
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
if ! cmp -s "$tmp" "$LUA" || [ "$(stat -c %G "$LUA" 2>/dev/null)" != vmail ]; then install -m 0640 -o root -g vmail "$tmp" "$LUA"   # доставку (lda/lmtp) Dovecot ведёт от vmail — ему и читать; echo "    push: скрипт Dovecot обновлён"; fi
rm -f "$tmp"

need_reload=0
if ! grep -q 'push_notification_driver' "$CONF"; then
  [ -f "$CONF.bak-push" ] || cp -a "$CONF" "$CONF.bak-push"
  printf '\n# Push-уведомления веб-почты (deploy/dovecot-push.sh): событие о новом письме во «Входящих»\nplugin {\n  push_notification_driver = lua:file=%s\n}\n' "$LUA" >> "$CONF"
  need_reload=1
fi
# Плагины нужны при доставке: iRedMail доставляет через dovecot-lda (protocol lda), LMTP — на всякий случай.
# Строки protocol … { mail_plugins = … } у iRedMail свои — дописываем в них.
for proto in lda lmtp; do
  if ! awk -v p="^protocol $proto \{" '$0 ~ p {f=1} f&&/mail_plugins/{print; exit}' "$CONF" | grep -q push_notification_lua; then
    [ -f "$CONF.bak-push" ] || cp -a "$CONF" "$CONF.bak-push"
    awk -v p="^protocol $proto \{" 'BEGIN{f=0} $0 ~ p {f=1} f&&/mail_plugins =/&&!/push_notification/{sub(/$/, " push_notification push_notification_lua"); f=0} {print}' "$CONF" > "$CONF.tmp" && cat "$CONF.tmp" > "$CONF" && rm -f "$CONF.tmp"
    need_reload=1
  fi
done
if [ "$need_reload" = 1 ]; then
  if doveconf -n >/dev/null 2>&1; then
    systemctl reload dovecot && echo "    push: Dovecot перечитал настройки"
  else
    echo "push: dovecot.conf не прошёл проверку — откат"; cp -a "$CONF.bak-push" "$CONF"; exit 1
  fi
fi
