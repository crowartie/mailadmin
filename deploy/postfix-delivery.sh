#!/bin/bash
# Недоставленные письма: раньше отправитель узнавал о проблеме только через 5 дней и без предупреждений
# (домен с опечаткой, который «существует», но не принимает почту, — временная ошибка для Postfix).
# Теперь через час приходит «письмо задерживается», а через 2 дня — окончательный отказ. Идемпотентно, под sudo.
set -euo pipefail
changed=0
set_if() {
  local key=$1 want=$2 cur
  cur=$(postconf -h "$key" 2>/dev/null || true)
  if [ "$cur" != "$want" ]; then postconf -e "$key = $want"; changed=1; fi
}
set_if delay_warning_time 1h
set_if maximal_queue_lifetime 2d
set_if bounce_queue_lifetime 2d
if [ "$changed" = 1 ]; then
  postfix reload >/dev/null 2>&1 || systemctl reload postfix
  echo "postfix: delay_warning_time=1h, queue lifetime 2d"
fi
