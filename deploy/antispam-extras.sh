#!/bin/bash
# Внешние базы спама, которых нет в iRedMail из коробки. Идемпотентно, запускать под sudo.
#  - Razor: база отпечатков спам-писем (плагин SpamAssassin загружен, но без регистрации не работает).
#  - postscreen: ещё два списка IP с малым весом (SpamCop, PSBL); порог остаётся 2 — один список письмо не отобьёт.
set -u
cd /

# ── Razor: домашняя папка пользователя amavis (под ним работает SpamAssassin внутри Amavis) ──
RZ=/var/lib/amavis/.razor
install -d -o amavis -g amavis -m 0750 "$RZ"
[ -s "$RZ/razor-agent.conf" ] || sudo -u amavis -H razor-admin -home="$RZ" -create >/dev/null 2>&1 || echo "razor: -create не удался" >&2
if [ -e "$RZ/identity" ]; then
  echo "razor: уже зарегистрирован"
elif sudo -u amavis -H razor-admin -home="$RZ" -register >/dev/null 2>&1; then
  echo "razor: зарегистрирован"
else
  echo "razor: регистрация не удалась (нет доступа к razor.cloudmark.com?)" >&2
fi
LC=/etc/spamassassin/local.cf
grep -q "^razor_config" "$LC" 2>/dev/null || printf '\n# mailadmin: Razor у пользователя amavis\nrazor_config %s/razor-agent.conf\n' "$RZ" >> "$LC"
chown -R amavis:amavis "$RZ"

# ── postscreen: SpamCop и PSBL с весом 1 (только если списки отвечают) ──
sites=$(postconf -h postscreen_dnsbl_sites | tr -s ' \n' ' ')
changed=0
for s in "bl.spamcop.net*1" "psbl.surriel.com*1"; do
  host=${s%%\**}
  case " $sites " in *" $host"*|*"$host="*|*"$host*"*) continue ;; esac
  if dig +short +time=3 "2.0.0.127.$host" 2>/dev/null | grep -q "^127\."; then
    sites="$sites $s"; changed=1
  else
    echo "postscreen: $host не отвечает на тестовый запрос — не добавлен" >&2
  fi
done
if [ "$changed" = 1 ]; then
  postconf -e "postscreen_dnsbl_sites = $(echo "$sites" | sed 's/^ *//;s/ *$//')"
  postfix reload >/dev/null 2>&1 || systemctl reload postfix
  echo "postscreen: добавлены SpamCop/PSBL → $(postconf -h postscreen_dnsbl_sites | tr -s ' \n' ' ')"
else
  echo "postscreen: списки уже на месте"
fi
systemctl reload amavis 2>/dev/null || systemctl restart amavis
