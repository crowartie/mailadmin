#!/bin/bash
# Память под почту: буфер MariaDB, своп, прогрев кэша индексов после перезагрузки. Идемпотентно.
set -e
cat > /etc/mysql/mariadb.conf.d/99-mailadmin.cnf <<'CNF'
# mailadmin: базы vmail/amavisd/mailadmin в памяти целиком (по умолчанию 128 МБ — под крошечный сервер)
[mysqld]
innodb_buffer_pool_size = 1G
innodb_log_file_size = 256M
CNF
cat > /etc/sysctl.d/99-mailadmin.conf <<'SYS'
# mailadmin: не отправлять процессы в своп ради кэша, кэш и так огромный
vm.swappiness = 10
vm.vfs_cache_pressure = 50
SYS
sysctl -q -p /etc/sysctl.d/99-mailadmin.conf
cat > /usr/local/sbin/mailadmin-warmcache <<'WARM'
#!/bin/bash
# После перезагрузки страничный кэш пуст: читаем индексы Dovecot и поиска в память, чтобы первые
# открытия папок и поиск не упирались в HDD. Только чтение, ничего не пишет.
ionice -c3 nice -n 19 bash -c '
  find /var/vmail -type f \( -name "dovecot.index*" -o -name "dovecot.list.index*" -o -path "*/xapian-indexes/*" \) -print0 2>/dev/null | xargs -0 -r cat > /dev/null
' &
WARM
chmod 755 /usr/local/sbin/mailadmin-warmcache
cat > /etc/systemd/system/mailadmin-warmcache.service <<'UNIT'
[Unit]
Description=mailadmin: прогрев кэша индексов почты после старта
After=dovecot.service
[Service]
Type=oneshot
ExecStart=/usr/local/sbin/mailadmin-warmcache
[Install]
WantedBy=multi-user.target
UNIT
systemctl daemon-reload; systemctl enable -q mailadmin-warmcache.service
systemctl restart mariadb && sleep 2 && mysql -N -e "select @@innodb_buffer_pool_size/1024/1024 as MB" | sed 's/^/buffer pool, МБ: /'
sysctl -n vm.swappiness | sed 's/^/swappiness: /'
systemctl is-active mariadb postfix dovecot | tr '\n' ' '; echo
WARM_TEST=$(timeout 3 systemctl is-enabled mailadmin-warmcache); echo "warmcache: $WARM_TEST"
