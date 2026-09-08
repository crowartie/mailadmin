# @@TITLE@@ — создано deploy/install.sh; правки перезапишутся при повторной установке.
server {
    listen @@PORT@@ ssl http2;
    listen [::]:@@PORT@@ ssl http2;
    server_name @@SERVER_NAMES@@;
    root /opt/mailadmin/public;
    index index.php;
    charset utf-8;
    ssl_certificate /etc/ssl/certs/iRedMail.crt;
    ssl_certificate_key /etc/ssl/private/iRedMail.key;
    access_log /var/log/nginx/@@LOG@@.access.log;
    error_log  /var/log/nginx/@@LOG@@.error.log;
@@ROOT_REDIRECT@@
    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ \.php$ {
        fastcgi_pass 127.0.0.1:9999;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }
    location ~ /\.(?!well-known).* { deny all; }
    client_max_body_size 260m;
}
