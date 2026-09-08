#!/usr/bin/env bash
# Подготовка MAIL01 ко второй волне админки: sudo-обёртка для www-data, доступ к журналам,
# доступ приложения к базам iredapd/amavisd, токен mlmmjadmin, каталог копий, пароли приложений в Dovecot.
# Запуск: sudo bash mail01-wave2.sh   (файлы mailadmin-ctl и mailadmin-backup лежат рядом)
set -euo pipefail
HERE=$(cd "$(dirname "$0")" && pwd)
APP=/opt/mailadmin

install -m 0755 -o root -g root "$HERE/mailadmin-ctl" /usr/local/sbin/mailadmin-ctl
install -m 0755 -o root -g root "$HERE/mailadmin-backup" /usr/local/sbin/mailadmin-backup
cat > /etc/sudoers.d/mailadmin <<'EOF'
# Веб-приложение управляет сервером только через mailadmin-ctl (белый список подкоманд внутри).
www-data ALL=(root) NOPASSWD: /usr/local/sbin/mailadmin-ctl
EOF
chmod 0440 /etc/sudoers.d/mailadmin
visudo -c >/dev/null

usermod -aG adm www-data   # чтение /var/log/mail.log без sudo
mkdir -p /var/backups/mail && chown root:www-data /var/backups/mail && chmod 0750 /var/backups/mail

# Пароли служебных баз → .env приложения (белые списки iRedAPD/Amavis, карантин Amavis).
P_IREDAPD=$(awk '$1=="iredapd"{print $2}' /root/mail01-sql-passwords.txt)
P_AMAVIS=$(awk '$1=="amavisd"{print $2}' /root/mail01-sql-passwords.txt)
grep -q '^IREDAPD_DB_PASSWORD=' $APP/.env || printf '\nIREDAPD_DB_PASSWORD=%s\nAMAVIS_DB_PASSWORD=%s\n' "$P_IREDAPD" "$P_AMAVIS" >> $APP/.env

# mlmmjadmin: у unattended-установки пустой токен — выставляем свой.
if grep -q "^api_auth_tokens = \[''\]" /opt/mlmmjadmin/settings.py; then
  TOKEN=$(openssl rand -hex 24)
  sed -i "s/^api_auth_tokens = \[''\]/api_auth_tokens = ['$TOKEN']/" /opt/mlmmjadmin/settings.py
  systemctl restart mlmmjadmin
fi
if ! grep -q '^MLMMJADMIN_TOKEN=' $APP/.env; then
  TOKEN=$(sed -n "s/^api_auth_tokens = \['\([^']*\)'\].*/\1/p" /opt/mlmmjadmin/settings.py)
  printf 'MLMMJADMIN_TOKEN=%s\nMLMMJADMIN_URL=http://127.0.0.1:7790\n' "$TOKEN" >> $APP/.env
fi

# Пароли приложений: второй passdb Dovecot из базы приложения (таблица app_passwords).
P_APP=$(sed -n 's/^DB_PASSWORD=//p' $APP/.env)
cat > /etc/dovecot/dovecot-app-passwords.conf <<EOF
driver = mysql
connect = host=127.0.0.1 port=3306 dbname=mailadmin user=mailadmin password=$P_APP
default_pass_scheme = SSHA512
password_query = SELECT password, username AS user FROM app_passwords WHERE username = '%u' AND active = 1
EOF
chmod 0640 /etc/dovecot/dovecot-app-passwords.conf; chown root:dovecot /etc/dovecot/dovecot-app-passwords.conf
if ! grep -q 'dovecot-app-passwords.conf' /etc/dovecot/dovecot.conf; then
  cp -a /etc/dovecot/dovecot.conf /etc/dovecot/dovecot.conf.bak-wave2
  python3 /dev/stdin <<'PYEOF'
p = '/etc/dovecot/dovecot.conf'
s = open(p).read()
old = "passdb {\n    args = /etc/dovecot/dovecot-mysql.conf\n    driver = sql\n}"
new = ("passdb {\n    args = /etc/dovecot/dovecot-mysql.conf\n    driver = sql\n    result_failure = continue\n}\n\n"
       "# Пароли приложений (админка): отдельная таблица в базе приложения.\n"
       "passdb {\n    driver = sql\n    args = /etc/dovecot/dovecot-app-passwords.conf\n}")
assert old in s, 'passdb block not found'
open(p, 'w').write(s.replace(old, new, 1))
PYEOF
  doveconf -n >/dev/null && systemctl reload dovecot
fi
echo "==> готово"
