# mailadmin — корпоративная почта: веб-почта и админка для iRedMail

Замена Kerio Connect на своих серверах: iRedMail (Postfix, Dovecot, Amavis, SpamAssassin, ClamAV, MariaDB, nginx, fail2ban)
плюс это приложение на Laravel 13 + Inertia + Vue 3.

- **Веб-почта** (порт 443, `/mail`): письма, папки, метки, правила, автоответ, отложенные письма, отправка позже,
  напоминания «если не ответят», большие вложения через Nextcloud, общие папки и общие ящики, контакты
  (личные, отдела, компании), календарь с приглашениями и занятостью, задачи, карантин, решения по спаму
  и рассылкам, пароли приложений, 2FA, автонастройка Outlook/телефонов, справка для сотрудников (`/mail/help`).
- **Админка** (порт 8443): сотрудники и подразделения, псевдонимы, рассылки, импорт CSV, перенос с другого
  сервера (imapsync + CardDAV/CalDAV), очередь и журналы Postfix, антиспам и карантин, белые/чёрные списки,
  решения сотрудников, лимиты, сертификат, резервные копии, DNS-проверка, DKIM, DMARC/TLS-RPT отчёты,
  MTA-STS, уведомления (почта/Telegram), fail2ban, администраторы с ролями.

## Установка на чистый сервер

Нужна Ubuntu 24.04, 2+ CPU, 8 ГБ памяти (ClamAV ест ~1 ГБ), диск под почту, белый IP, FQDN-имя
(например `mail.example.ru`) с A-записью на сервер и доступ в интернет.

```bash
apt-get install -y git
git clone <адрес репозитория> /opt/mailadmin
cd /opt/mailadmin/deploy
cp install.conf.example install.conf
nano install.conf          # домен, имя сервера, пароли (пусто — сгенерируются), Let's Encrypt, ClamAV
sudo bash install.sh
```

Скрипт сам ставит iRedMail без вопросов, PHP, Node, Redis, собирает приложение, настраивает nginx
(443 веб-почта, 8443 админка, iRedAdmin уезжает на 8444), Dovecot (master-пользователь, пароли приложений,
полнотекстовый поиск, обучение спама, общий Sieve), Amavis (пороги, карантин в базу, антивирус), fail2ban,
резервные копии, планировщик, imapsync. В конце печатает адреса, пароли и список DNS-записей.
Секреты — в `/root/mailadmin-install.txt`. Скрипт можно запускать повторно: он доделывает пропущенное.

Из России CDN ClamAV отдаёт 403 — укажите `CLAMAV_MIRROR=http://<свой сервер>` (зеркало: `pip install cvdupdate`,
`cvd update` по cron и любой веб-сервер над каталогом баз).

После установки: перезагрузить сервер, войти в админку, включить 2FA, проверить «Настройки → Домены и DNS»,
при необходимости «Настройки → Сертификат» и «Файлы и облако».

## Отчёты сервера

Logwatch, cron-скрипты iRedMail и уведомления приложения не уходят письмами на postmaster, а складываются в `/var/lib/mailadmin/reports` (через `mailadmin-report <вид> <команда>`) и в `storage/app/private/reports`. Смотреть и скачивать — раздел «Отчёты» в админке. Настраивает `deploy/setup-reports.sh` (вызывается из install.sh).

## Переезд с Kerio Connect без паролей сотрудников

Ящики в админке переносятся через imapsync (нужны пароли на старом сервере). Если паролей нет, почту можно забрать
прямо из хранилища Kerio (`/opt/kerio/mailserver/store/mail/<домен>`): смонтировать его по sshfs и сложить в Maildir.

```bash
sshfs -o ro,allow_other root@старый-сервер:/opt/kerio/mailserver/store/mail/example.com /mnt/kerio
python3 deploy/kerio2maildir.py --src /mnt/kerio --domain example.com --jobs 4   # ящики должны быть уже заведены
python3 deploy/kerio-dav-extract.py --src /mnt/kerio --out /var/lib/mailadmin/kerio-dav
sudo -u www-data php artisan dav:import ivanov@example.com /var/lib/mailadmin/kerio-dav/ivanov
```

`kerio2maildir.py` переносит папки (включая вложенные), флаги «прочитано/отвечено/помечено/черновик» и даты,
запоминает сделанное в `/var/lib/mailadmin/kerio-migrate.db` — повторный запуск в день переключения докачивает только новое
и подтягивает изменившиеся флаги. `kerio-dav-extract.py` достаёт vCard/iCalendar из папок Contacts, Calendar, Tasks,
`dav:import` кладёт их в личную книгу и календарь (по UID, без дублей).

## Обновление

```bash
sudo bash /opt/mailadmin/deploy/update.sh
```

Тянет ветку из git, ставит зависимости, собирает фронт, применяет миграции, обновляет служебные скрипты
(`mailadmin-ctl`, `mailadmin-backup`, fail2ban, logrotate, Dovecot).

## Как устроено

- `app/Http/Controllers` — админка; `app/Http/Controllers/Mail` — веб-почта (Inertia-страницы и `/mail/api/*`).
- `app/Services/Mail` — IMAP (webklex/php-imap), отправка, правила Sieve, общие папки (ACL), решения по отправителям.
- `app/Services/Dav`, `app/Dav` — CalDAV/CardDAV на sabre/dav внутри приложения (`/dav/`).
- `app/Services/Server` — сервер: Amavis, карантин, белые списки, Postfix-очередь, журналы, fail2ban, копии, сертификат,
  DNS, отчёты DMARC/TLS-RPT. Всё, что требует root, идёт через `sudo mailadmin-ctl <подкоманда>` — белый список в `deploy/mailadmin-ctl`.
- `app/Console/Commands` — планировщик (`routes/console.php`): уведомления, копии, сводка карантина, напоминания,
  отчёты, возврат отложенных, очередь отправки, перенос ящиков.
- `deploy/` — установщик, обновление, служебные скрипты и шаблоны конфигов.
- `resources/js/Pages` — Vue-страницы (`Mail/*` — веб-почта), `resources/js/Components/Mail` — компоненты веб-почты.

Базы: `mailadmin` (приложение), `vmail` (iRedMail: домены, ящики, псевдонимы), `amavisd` (карантин, списки), `iredapd` (лимиты).
Пароли сотрудников меняет только администратор — самообслуживания намеренно нет.
