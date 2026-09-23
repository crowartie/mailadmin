# Разработка

Как поднять проект у себя, как устроены тесты, как добавлять функции и как выкладывать.
Перед правками почтового ядра прочитайте [подводные камни](gotchas.md).

## Что нужно

| | Версия | Зачем |
|---|---|---|
| PHP | 8.3+ с xml, mbstring, zip, gd, intl, redis, curl, pdo_mysql, bcmath, pdo_sqlite | приложение, тесты |
| Composer | 2 | зависимости PHP |
| Node | 22 | сборка клиента (Vite, Vue 3, Inertia) |
| MariaDB/MySQL | 10.11+ | база `mailadmin` и доступ к `vmail`, `amavisd`, `iredapd` |
| Redis | любая | кэш |

Расширение `ext-imap` не нужно: webklex/php-imap написан на чистом PHP.

Ключевые пакеты: Laravel 13, Inertia 3, Vue 3, Laravel Octane 2 (RoadRunner), sabre/dav,
HTMLPurifier, webklex/php-imap, symfony/mailer, pragmarx/google2fa.

## Запуск у себя

Веб-почте нужен живой почтовый сервер: Dovecot с master-пользователем и ManageSieve, Postfix
и базы iRedMail. Проще всего — тестовая виртуальная машина, поставленная
[установщиком](operations.md#установка-на-чистый-сервер), и приложение на своей машине,
которое ходит в неё по сети.

```bash
git clone <адрес репозитория> mailadmin && cd mailadmin
composer install
npm ci
cp .env.example .env && php artisan key:generate
# заполнить .env: см. таблицу ниже
php artisan migrate
npm run dev            # Vite
php artisan serve      # или composer dev
```

Зона выбирается по порту. Для разработки задайте в `.env` те порты, на которых реально
открываете приложение (`ADMIN_PORT`, `MAIL_PORT`); если они совпадают, всё считается админкой.
Функции, которым нужен root (очередь, fail2ban, DKIM, сертификат), работают только на
настоящем сервере: они вызывают `sudo mailadmin-ctl`.

### Переменные окружения приложения

Шаблон для боевого сервера — `deploy/env.production.tpl`. Кроме стандартных для Laravel:

| Ключ | Смысл |
|---|---|
| `ADMIN_PORT`, `MAIL_PORT` | порты зон (на сервере 8443 и 443) |
| `MAIL_DEFAULT_DOMAIN` | домен, который дописывается к короткому логину |
| `MAIL_IMAP_HOST`, `_PORT`, `_ENCRYPTION`, `_VALIDATE_CERT` | куда ходить по IMAP |
| `MAIL_IMAP_MASTER_USER`, `MAIL_IMAP_MASTER_PASSWORD` | master-пользователь Dovecot: вход администратора в ящик, фоновые задачи |
| `MAIL_SMTP_HOST`, `MAIL_SMTP_PORT` | отправка из веб-почты |
| `MAIL_SIEVE_HOST`, `MAIL_SIEVE_PORT` | ManageSieve |
| `VMAIL_DB_HOST`, `_PORT`, `_DATABASE`, `_USERNAME`, `_PASSWORD` | база iRedMail (хост и порт используются и для `amavisd`, `iredapd`) |
| `AMAVIS_DB_PASSWORD`, `IREDAPD_DB_PASSWORD` | пароли баз Amavis и iRedAPD |
| `TRUSTED_PROXIES` | адреса обратных прокси, иначе в журнале входов и в fail2ban будет адрес прокси |
| `OCTANE_SERVER`, `OCTANE_HTTPS` | Octane |

Значения по умолчанию в `config/areas.php` рассчитаны на локальный сервер; на рабочей
установке всё задаётся в `.env`.

## Тесты

```bash
php artisan test               # или composer test
vendor/bin/phpunit --filter SieveBuilderTest
```

- `tests/Unit` — быстрые тесты без базы, сети и почтового сервера: разбор структуры письма,
  кодировки, очистка HTML и CSS, Sieve, синтаксис поиска, папки, токены хранилища, ошибки SMTP,
  журнал действий, порядок объявлений в `<script setup>` (`VueSetupOrderTest`).
- `tests/Feature` — поднимает Laravel (нужен `APP_KEY`), проверяет разделение зон.
- Всё, что касается живого IMAP, тестами не покрыто. Такие изменения проверяются
  на тестовой копии сервера (`deploy/test.sh`) и в браузере.

`deploy/test.sh` на сервере держит отдельную копию `/opt/mailadmin-test`, подтягивает туда
код и гоняет тесты до выкладки в рабочую копию.

## Как устроен код и как добавлять

### Новый вызов API веб-почты

1. Маршрут в `routes/mail.php` внутри группы `mail.auth`, префикс `/mail/api`.
2. Контроллер в `app/Http/Controllers/Mail/Api`. Работу с почтой — через `MailStore`
   (соединение даёт `ImapSession`, его внедряет контейнер).
3. Ошибки — `MailException::notFound/denied/invalid/upstream/busy/tooLarge` с русским текстом:
   что случилось и что сделать. Не отдавать наружу текст исключения библиотеки.
4. Метод в `resources/js/mail/api.js`, вызов из композабла или страницы.
5. Если это действие сотрудника — строка в `ActivityMap::describe` и подпись в
   `ActivityMap::LABELS`, иначе журнал его не увидит. Тест — в `tests/Unit/ActivityMapTest.php`.

### Новая настройка пользователя

`Setting::DEFAULTS` (значение по умолчанию) → правило в `Api/SettingsController` → поле в
`Pages/Mail/Settings.vue` (и в списке, по которому страница решает, есть ли несохранённые правки).

### Новая таблица

Миграция в `database/migrations`, модель в `app/Models` (`Webmail/` — данные веб-почты).
Таблицы iRedMail (`vmail`) не менять: их схема принадлежит iRedMail.

### Новая операция с правами root

Подкоманда в `deploy/mailadmin-ctl` с проверкой каждого аргумента, вызов через
`App\Services\Server\Ctl::run()` / `Ctl::out()`. `update.sh` переустанавливает обёртку при выкладке.

### Новая задача по расписанию

Команда в `app/Console/Commands`, строка в `routes/console.php`, по возможности
`->withoutOverlapping()`. Если по задаче должна судить страница «Состояние», пишите
heartbeat в кэш и добавьте проверку в `HealthChecks`.

### Клиент

- Страницы — `resources/js/Pages`, компоненты — `resources/js/Components`, стили — `resources/scss`.
- В `<script setup>` значение из композабла, созданного ниже, передавать в композабл выше только
  обёрткой-функцией (см. [подводные камни](gotchas.md#клиент-веб-почты-vue)).
- После изменения клиента — сборка `npm run build` и проверка в браузере: белый экран тесты не ловят.

## Соглашения

- Комментарии, тексты интерфейса, сообщения коммитов — по-русски.
- Комментарий объясняет, **почему** код такой, и какой случай он закрывает.
- Пользователь видит ошибку словами: что случилось и что делать.
- Отступы 4 пробела, LF (`.editorconfig`); PHP — стиль Laravel Pint.
- Скрипты `deploy/` — bash с `set -euo pipefail`, повторный запуск безопасен.
- В репозиторий не попадают пароли, ключи, адреса внутренних сетей, имена сотрудников
  и их переписка. Для примеров — `example.ru`, `ivanov@example.ru`.

## Выкладка

```bash
git status --short | grep '^ D'     # пусто — ничего не удаляется по ошибке
git commit … && git push
ssh <сервер> sudo /opt/mailadmin/deploy/update.sh
```

`update.sh` тянет ветку, ставит зависимости, собирает клиент, применяет миграции, обновляет
служебные скрипты и перезапускает Octane. Подробно — [эксплуатация](operations.md#обновление).
