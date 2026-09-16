#!/bin/bash
# Откат с Octane на PHP-FPM: nginx снова отдаёт приложение через fastcgi (127.0.0.1:9999), служба Octane выключается.
# Запускать под sudo: sudo bash /opt/mailadmin/deploy/octane-off.sh
set -euo pipefail
[ "$(id -u)" = 0 ] || { echo "запускать от root" >&2; exit 1; }
mkdir -p /etc/nginx/snippets
cat > /etc/nginx/snippets/mailadmin-backend.conf <<'EOF'
# mailadmin: приложение через PHP-FPM (Octane выключен; включить обратно — deploy/octane-setup.sh)
location / { try_files $uri $uri/ /index.php?$query_string; }
location ~ \.php$ {
    fastcgi_pass 127.0.0.1:9999;
    fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
    include fastcgi_params;
}
EOF
systemctl is-active -q php8.3-fpm || systemctl start php8.3-fpm
nginx -t >/dev/null 2>&1 && systemctl reload nginx
systemctl disable -q --now mailadmin-octane 2>/dev/null || true
echo "==> приложение снова на PHP-FPM, Octane остановлен"
