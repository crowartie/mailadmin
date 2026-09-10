# .env приложения — создаётся deploy/install.sh. Секреты живут только здесь (файл вне git).
APP_NAME="Почта @@DOMAIN@@"
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://@@HOSTNAME@@:8443
APP_LOCALE=ru
APP_TIMEZONE=@@TIMEZONE@@

LOG_CHANNEL=daily
LOG_LEVEL=warning

# База приложения (настройки, правила, календари, журналы админки).
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=mailadmin
DB_USERNAME=mailadmin
DB_PASSWORD=@@DB_PASSWORD@@

# База iRedMail с доменами и ящиками (пользователь vmailadmin из iRedMail.tips).
VMAIL_DB_HOST=127.0.0.1
VMAIL_DB_PORT=3306
VMAIL_DB_DATABASE=vmail
VMAIL_DB_USERNAME=vmailadmin
VMAIL_DB_PASSWORD=@@VMAIL_DB_PASSWORD@@

SESSION_DRIVER=database
CACHE_STORE=redis
QUEUE_CONNECTION=redis
REDIS_HOST=127.0.0.1

# Порты: админка и веб-почта на одном хосте.
ADMIN_PORT=8443
MAIL_PORT=443

# Локальный Dovecot/Postfix для веб-почты.
MAIL_IMAP_HOST=127.0.0.1
# Dovecot на этом же сервере: соединение с 127.0.0.1 он считает защищённым и пускает без TLS
# даже при ssl=required — экономим TLS-рукопожатие на каждый запрос веб-почты (~150 мс).
MAIL_IMAP_PORT=143
MAIL_IMAP_ENCRYPTION=false
MAIL_IMAP_VALIDATE_CERT=false
MAIL_SMTP_HOST=127.0.0.1
MAIL_SMTP_PORT=587
MAIL_SIEVE_HOST=127.0.0.1
MAIL_SIEVE_PORT=4190
MAIL_DEFAULT_DOMAIN=@@DOMAIN@@

# Master-пользователь Dovecot: приложение входит в любой ящик без пароля сотрудника (общие папки, перенос, сводки).
MAIL_IMAP_MASTER_USER=mailadmin
MAIL_IMAP_MASTER_PASSWORD=@@MASTER_PASSWORD@@

# Служебные базы iRedMail: белые списки и карантин Amavis, лимиты iRedAPD, рассылки mlmmj.
IREDAPD_DB_PASSWORD=@@IREDAPD_DB_PASSWORD@@
AMAVIS_DB_PASSWORD=@@AMAVIS_DB_PASSWORD@@
MLMMJADMIN_TOKEN=@@MLMMJADMIN_TOKEN@@
MLMMJADMIN_URL=http://127.0.0.1:7790

# Общая книга SOGo не используется (свой CardDAV внутри приложения).
SOGO_CARDDAV_URL=
SOGO_CARDDAV_USER=
SOGO_CARDDAV_PASSWORD=
