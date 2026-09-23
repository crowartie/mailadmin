# Жизнь ящика: создание, изменение, блокировка, удаление; псевдонимы, подразделения, книга сотрудников

Ящики живут в базе iRedMail `vmail`. Приложение пишет туда те же строки, что iRedAdmin,
и дополнительно ведёт свой профиль сотрудника и адресную книгу.

```mermaid
flowchart TD
    NEW["Админка: новый сотрудник или импорт CSV"] --> TX["Транзакция в vmail"]
    TX --> MB["mailbox: пароль SSHA512, путь Maildir, квота, службы"]
    MB --> FW["forwardings: доставка себе, пересылки, псевдонимы"]
    FW --> DA["domain_admins, если администратор домена"]
    DA --> COMMIT["Фиксация"]
    COMMIT --> PR["Профиль сотрудника: подразделение, должность, телефоны, 2FA"]
    PR --> UN["Подразделение: адрес отдела = псевдоним на всех его людей"]
    UN --> BOOK["Карточка в книге «Сотрудники» CardDAV"]

    ED["Изменение"] --> TX2["Те же шаги синхронизации"] --> BOOK

    BLK["Закрыть вход"] --> F1["profile.login_blocked, службы IMAP, SMTP, Sieve выключены"]
    F1 --> F2["Почта продолжает приходить"]
    F2 --> F3["Все сеансы и IMAP-соединения завершены"]

    DEL["Удаление"] --> D1["deleted_mailboxes: файлы на диске позже удалит чистильщик iRedMail"]
    D1 --> D2["Строки forwardings, used_quota, domain_admins, mailbox"]
    D2 --> D3["Карточка из книги, календари и контакты DAV, профиль, пароли приложений, сеансы"]

    CRON["dav:sync-employees раз в час"] --> SYNC["Сверка книги «Сотрудники» со списком ящиков: правки из iRedAdmin и SQL тоже попадут"]
```

## Псевдонимы и рассылки

```mermaid
flowchart LR
    AL["Псевдоним sales@"] --> AR["alias: имя и состояние"] --> AT["forwardings с is_alias=1: куда доставлять"]
    TR["«Проверить адрес»"] --> CH["Цепочка: домен-псевдоним → псевдоним → пересылки → рассылка → ящик, с поиском петель"]
    ML["Рассылка"] --> MJ["mlmmj через mailadmin-ctl: подписчики, модерация, архив"]
```

## Код

`MailboxController`, `EmployeeController`, `ImportController`, `Services/Vmail/MailboxService`
(`create`, `update`, `delete`, `setLoginBlocked`), `AliasController`, `Services/Vmail/AliasService`,
`AddressResolver::trace`, `MaillistsController`, `Server/Mlmmj`, `UnitsController`,
`Services/Units/UnitService`, `Services/Dav/EmployeeBook`, задача `DavSyncEmployees`.

Пароли сотрудникам задаёт и меняет только администратор: самостоятельной смены пароля нет сознательно.
