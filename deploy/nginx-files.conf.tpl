# Хранилище больших вложений — https://@@HOST@@/<токен>/<имя>. Создано deploy/files-host.sh.
#
# Отдельный хост нарочно: cookie почты сюда не ходят, а сюда положенный файл никогда не
# исполнится как страница почты (другой origin). Приложение только проверяет ссылку и
# отвечает X-Accel-Redirect, сам файл отдаёт nginx из закрытого location /_files/.
server {
    listen 80;
    listen [::]:80;
    server_name @@HOST@@;
    location ^~ /.well-known/acme-challenge/ { root /opt/www/well_known; }
    location / { return 301 https://$host$request_uri; }
}
server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name @@HOST@@;
    charset utf-8;
    add_header X-Content-Type-Options nosniff;
    add_header X-Frame-Options deny;
    add_header Referrer-Policy no-referrer;
    add_header X-Robots-Tag "noindex, nofollow";
    ssl_certificate /etc/ssl/certs/iRedMail.crt;
    ssl_certificate_key /etc/ssl/private/iRedMail.key;
    access_log /var/log/nginx/files.access.log;
    error_log  /var/log/nginx/files.error.log;
    client_max_body_size 1m;

    location ^~ /.well-known/acme-challenge/ { root /opt/www/well_known; }
    # Сам файл — только по внутреннему редиректу из приложения.
    location ^~ /_files/ {
        internal;
        alias /opt/mailadmin/storage/app/files/;
        # Заголовки приложения при X-Accel-Redirect nginx не пропускает — ставим здесь.
        add_header X-Content-Type-Options nosniff;
        add_header Content-Security-Policy "sandbox";
        add_header X-Robots-Tag "noindex, nofollow";
    }
    location = / { return 302 https://@@MAIL_HOST@@/mail; }
    location / {
        proxy_http_version 1.1;
        proxy_set_header Host $http_host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Forwarded-Host $host;
        proxy_set_header X-Forwarded-Port $server_port;
        proxy_read_timeout 60s;
        # Ссылка «/<токен>/<имя>» превращается в маршрут приложения /f/<токен>/<имя>.
        proxy_pass http://127.0.0.1:8000/f$request_uri;
    }
}
