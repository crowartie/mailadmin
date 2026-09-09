# @@TITLE@@ — создано deploy/install.sh; правки перезапишутся при повторной установке.
server {
    listen @@PORT@@ ssl http2;
    listen [::]:@@PORT@@ ssl http2;
    server_name @@SERVER_NAMES@@;
    root /opt/mailadmin/public;
    index index.php;
    charset utf-8;
    # Свой набор заголовков: add_header на уровне server отменяет общий набор iRedMail (conf-enabled/headers.conf),
    # где Referrer-Policy strict-origin — с ним браузер шлёт Referer «/», и back() после ошибки уводит на главную.
    add_header X-Frame-Options sameorigin;
    add_header X-Content-Type-Options nosniff;
    add_header Referrer-Policy same-origin;
    add_header Content-Security-Policy "default-src https: data: 'unsafe-inline' 'unsafe-eval'";
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
