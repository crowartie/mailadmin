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
# Предел на письмо. По умолчанию было 15 МБ «по проводу» — это всего ~11 МБ вложений,
# и почта с коммерческими предложениями и чертежами отбивалась с 552 5.3.4, а отправитель
# видел «Ваше сообщение не доставлено». mail.ru, Яндекс и Gmail шлют до 25 МБ вложений,
# что с кодировкой даёт ~34 МБ, поэтому берём 40 МБ с запасом.
# Ставим, только если предел ещё ниже: если администратор поднял его в админке — не трогаем.
cur_size=$(postconf -h message_size_limit 2>/dev/null || echo 0)
if [ "${cur_size:-0}" -lt 41943040 ]; then set_if message_size_limit 41943040; fi
# Эти два предела относятся к локальным почтовым файлам и должны быть не меньше письма;
# место ограничивают квоты Dovecot, а не они.
set_if virtual_mailbox_limit 0
set_if mailbox_size_limit 0
if [ "$changed" = 1 ]; then
  postfix reload >/dev/null 2>&1 || systemctl reload postfix
  echo "postfix: delay_warning_time=1h, queue lifetime 2d, message_size_limit=$(postconf -h message_size_limit)"
fi
