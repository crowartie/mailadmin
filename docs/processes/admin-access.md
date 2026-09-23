# Вход в админку, зоны, роли, журнал действий администраторов

Админка и веб-почта — одно приложение, разделённое по портам. Запрос на маршрут чужой
зоны получает 404 раньше, чем начнётся сеанс.

```mermaid
flowchart TD
    RQ["Запрос"] --> AR["EnsureArea: порт → зона; за доверенным прокси — X-Forwarded-Port"]
    AR -->|маршрут другой зоны| N404["404"]
    AR --> Z{"Зона"}
    Z -->|почта| MAIL["Вход в веб-почту: webmail-login.md"]
    Z -->|админка| L["Форма входа /login"]
    L --> BF{"8 неудач с адреса за 15 минут?"}
    BF -->|да| BL["Блок, запись в admin_logins"]
    BF --> PW{"Пароль"}
    PW -->|локальная учётка| BC["bcrypt"]
    PW -->|учётка через почту| IM["IMAP-вход в Dovecot"]
    BC --> TF
    IM --> TF{"2FA настроена?"}
    TF -->|да| CODE["/login/code"] --> OK
    TF -->|нет| POL{"Политика требует 2FA для всех?"}
    POL -->|да| SET["Только страница настройки 2FA"]
    POL -->|нет| OK["Админка"]
    OK --> ROLE{"EnforceRole на изменяющих запросах"}
    ROLE -->|owner, admin| ALL["Всё"]
    ROLE -->|operator| OPR["Только ящики, псевдонимы, контакты компании"]
    ROLE -->|viewer| VW["Только смотреть"]
    ALL --> AUD["AdminAction::log: кто, откуда, что, над чем"]
    OPR --> AUD
    AUD --> LOGS["Видно в «Журналах» и на «Обзоре»"]
```

## Код

`Support/Area`, `Middleware/EnsureArea`, `Auth/LoginController`, `Auth/TwoFactorController`,
`Middleware/EnsureTwoFactorVerified`, `Middleware/EnforceRole`, `Models/User` (роли), `Models/AdminAction`,
`Settings/AdminsController`. Первый администратор создаётся установщиком: `php artisan admin:create`.
