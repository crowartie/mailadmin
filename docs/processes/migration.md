# Перенос почты с другого сервера

Два пути: по IMAP, если известны пароли на старом сервере, и прямо из хранилища Kerio,
если паролей нет. Оба повторяемы: второй запуск докачивает только новое.

```mermaid
flowchart TD
    Q{"Есть пароли на старом сервере?"}
    Q -->|да| IM["Админка → Перенос: источник, строки «логин пароль ящик»"]
    IM --> MR["MailMigration в очереди → migrate:run в фоне"]
    MR --> SY["imapsync → 127.0.0.1:993 как ящик*master: Sent, Trash, Junk сопоставлены"]
    SY --> DVI["DavImport: контакты и календари по CardDAV и CalDAV, без дублей по UID"]
    DVI --> ST["Статус и журнал в админке"]

    Q -->|нет, есть доступ к хранилищу Kerio| MNT["sshfs только для чтения: store/mail/домен"]
    MNT --> K2["kerio2maildir.py: письма .eml → Maildir ящиков"]
    K2 --> FL["Папки, вложенные папки, флаги прочитано, отвечено, флажок, черновик, даты"]
    FL --> DB[("Состояние переноса в SQLite: повторный запуск — только новое и изменившиеся флаги")]
    MNT --> KD["kerio-dav-extract.py: vCard, iCalendar из Contacts, Calendar, Tasks"]
    KD --> DI["artisan dav:import ящик каталог"]
```

## Код и что учесть

`MigrationController`, `Services/Migration/Imapsync`, `Services/Migration/DavImport`,
задача `MigrateRun`; `deploy/kerio2maildir.py`, `deploy/kerio-dav-extract.py`, команда `DavImportFiles`.

- Ящики на новом сервере должны быть заведены заранее.
- `kerio2maildir.py` переносит флаг «черновик» только для папки «Черновики». Черновики Outlook,
  лежавшие в других папках, после переноса выглядят как письма без отправителя и даты.
- После переноса подписки на папки сверить: `php artisan mail:fix-subscriptions`.
