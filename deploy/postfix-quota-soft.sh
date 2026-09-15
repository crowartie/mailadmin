#!/bin/bash
# Проверка квоты получателя (Dovecot quota-status, 127.0.0.1:12340) — мягкая: если Dovecot в этот момент
# перезапускается, письмо принимаем (переполненный ящик всё равно отобьёт LMTP), а не отвечаем отправителю
# «451 4.3.5 Server configuration problem». Идемпотентно, запускать под sudo.
set -euo pipefail
r=$(postconf -h smtpd_recipient_restrictions | tr -s ' \n' ' ')
case "$r" in
  *"default_action=DUNNO"*) exit 0 ;;
  *"check_policy_service inet:127.0.0.1:12340"*)
    new=${r/check_policy_service inet:127.0.0.1:12340/check_policy_service { inet:127.0.0.1:12340, default_action=DUNNO \}}
    postconf -e "smtpd_recipient_restrictions = $new"
    postfix reload >/dev/null 2>&1 || systemctl reload postfix
    echo "postfix: quota-status теперь с default_action=DUNNO" ;;
esac
