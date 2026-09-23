# Календари, контакты и задачи (CalDAV, CardDAV)

Свой сервер DAV на sabre/dav внутри приложения. Телефоны, Outlook и Thunderbird подключаются
к нему напрямую, веб-почта — через тот же сервер внутренним вызовом.

```mermaid
flowchart TD
    CL["Телефон, Outlook, Thunderbird"] -->|/.well-known/caldav, carddav| WK["Перенаправление на /dav/"]
    WK --> DV["DavController: запрос Laravel → запрос Sabre"]
    CL -->|/dav/…| DV
    DV --> AUTH["AuthBackend: HTTP Basic, короткий логин + домен"]
    AUTH --> VER{"Пароль верен? IMAP-вход, удачная пара кэшируется на 10 минут"}
    VER -->|нет| U401["401"]
    VER --> PROV["DavStore::ensureUser: личная книга и календарь при первом входе"]
    PROV --> SRV["Sabre: ACL, CalDAV, CardDAV, приглашения, общий доступ, синхронизация"]
    SRV --> DB[("Таблицы dav_* в mailadmin")]
    SRV --> SYS["Системные: «Сотрудники», «Контакты компании», календари отделов — только чтение"]
    SRV -->|приглашение на встречу| IMIP["iMIP: письмо участнику от noreply"]

    WEB["Веб-почта: календарь, контакты, задачи"] --> TA["Server::call с TrustedAuth"] --> SRV
    CR1["calendar:reminders каждую минуту"] --> REM["Напоминания о событиях письмом"]
    CR2["dav:sync-employees раз в час"] --> SYS
```

## Код

`app/Dav/Server.php` (сборка сервера), `app/Dav/AuthBackend`, `CardBackend`, `CalBackend`, `IMip`,
`TrustedAuth`; `Mail/DavController`; `Services/Dav/DavStore` и помощники (`Calendars`,
`ContactBooks`, `DavTasks`, `DavSharing`, `DavProvisioning`, `EmployeeBook`); задачи
`CalendarReminders`, `DavSyncEmployees`, `DavImportFiles`.

Предложения сотрудников в «Контакты компании» одобряет администратор (`CompanyContactsController`).
