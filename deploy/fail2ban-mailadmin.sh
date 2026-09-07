#!/bin/bash
# Jail fail2ban для админки (8443) и веб-почты (443): читает storage/logs/auth.log нашего приложения.
# Параметры берутся из аргументов: maxretry findtime(мин) bantime(ч)
set -e
MAXRETRY="${1:-5}"; FINDTIME="${2:-10}"; BANTIME="${3:-24}"
cat > /etc/fail2ban/filter.d/mailadmin.conf <<'EOF'
[Definition]
failregex = FAILED LOGIN (admin|mail) ip=<HOST> user=
ignoreregex =
EOF
cat > /etc/fail2ban/jail.d/mailadmin.local <<EOF
[mailadmin]
enabled  = true
port     = 443,8443
filter   = mailadmin
logpath  = /opt/mailadmin/storage/logs/auth.log
backend  = polling
maxretry = ${MAXRETRY}
findtime = $((FINDTIME * 60))
bantime  = $((BANTIME * 3600))
action   = nftables-multiport[name=mailadmin, port="443,8443", protocol=tcp]
           banned_db[name=mailadmin, port="443,8443", protocol=tcp]
EOF
touch /opt/mailadmin/storage/logs/auth.log
chown www-data:www-data /opt/mailadmin/storage/logs/auth.log
chmod 640 /opt/mailadmin/storage/logs/auth.log
systemctl reload fail2ban 2>/dev/null || systemctl restart fail2ban
fail2ban-client status mailadmin | head -3
