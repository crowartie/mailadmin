#!/bin/bash
# Личные флаги «прочитано» в общих папках (Dovecot INDEXPVT, обращение №51).
#
# После включения INDEXPVT у каждого читателя чужого ящика свой индекс флагов \Seen — и он пуст:
# все старые письма стали бы непрочитанными у всех. Этот скрипт один раз переносит текущее
# состояние: что помечено прочитанным в ящике владельца, помечается прочитанным у каждого,
# кому ящик открыт (пары из vmail.share_folder).
#
#   shared-pvt-seed.sh --check   только показать, сколько где будет помечено
#   shared-pvt-seed.sh --apply   пометить
#
# Запускать под root после правки namespace shared в dovecot.conf и «doveadm reload».
set -u
MODE="${1:---check}"
[ "$(id -u)" = 0 ] || { echo "нужен root"; exit 1; }
command -v doveadm >/dev/null || { echo "нет doveadm"; exit 1; }

# Проверяем, что INDEXPVT уже включён: без него флаги легли бы в общий индекс — на всех.
if [ "$MODE" = "--apply" ] && ! doveconf -h namespace/shared/location 2>/dev/null | grep -q INDEXPVT; then
    echo "в namespace shared нет INDEXPVT — сначала включите его"; exit 1
fi

pairs=$(mysql -N -e 'select from_user, to_user from vmail.share_folder' 2>/dev/null) || { echo "не прочитать vmail.share_folder"; exit 1; }
total=0
while read -r owner reader; do
    [ -n "$owner" ] && [ -n "$reader" ] || continue
    # Все папки владельца (кроме его собственных общих).
    doveadm mailbox list -u "$owner" 2>/dev/null | grep -v '^Shared/' | while read -r box; do
        # Прочитанные у владельца: doveadm search печатает «guid uid».
        uids=$(doveadm search -u "$owner" mailbox "$box" seen 2>/dev/null | awk '{print $2}' | paste -sd, -)
        [ -n "$uids" ] || continue
        n=$(echo "$uids" | tr ',' '\n' | wc -l)
        target="Shared/$owner/$box"
        if [ "$MODE" = "--apply" ]; then
            # Личный индекс читателя: флаг ложится только ему. Списки длиннее 500 — частями.
            echo "$uids" | tr ',' '\n' | split -l 500 - /tmp/pvt-seed.
            for part in /tmp/pvt-seed.*; do
                doveadm flags add -u "$reader" '\Seen' mailbox "$target" uid "$(paste -sd, - < "$part")" 2>&1 | grep -v '^$' | sed "s/^/  ! /"
                rm -f "$part"
            done
            echo "  $reader ← $target: помечено $n"
        else
            echo "  $reader ← $target: будет помечено $n"
        fi
    done
done <<< "$pairs"
echo "готово ($MODE)"
