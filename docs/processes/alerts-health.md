# Состояние сервера и оповещения

Страница «Состояние» собирает проверки в одном месте, а задача `alerts:check` каждые
5 минут превращает красные проверки в оповещения на почту и в Telegram.

```mermaid
flowchart TD
    HC["HealthChecks::checks"] --> G1["Службы: systemd, fail2ban"]
    HC --> G2["Почта: очередь, ошибки Postfix и Dovecot, индексатор, ночной таймер поиска"]
    HC --> G3["Антиспам: Bayes, очередь обучения, DNSBL, Razor, Pyzor, свежесть правил"]
    HC --> G4["Фоновые задачи: пульс планировщика, cron, импорт обращений, общие папки, переписки"]
    HC --> G5["Обслуживание: копия, сертификат, время, обновления, перезагрузка, ошибки приложения"]
    HC --> G6["Ящики: у предела квоты, закрытые"]
    G1 --> PAGE["Страница «Состояние»: плитки и список проверок"]
    G2 --> PAGE
    G3 --> PAGE
    G4 --> PAGE
    G5 --> PAGE
    G6 --> PAGE

    CR["alerts:check каждые 5 минут"] --> AL["Alerts::check: очередь, диск, службы, базы ClamAV, сертификат меньше 14 дней, копия старше 36 часов, все красные проверки"]
    AL --> DD{"Уже сообщали за 6 часов?"}
    DD -->|да| QUIET["Молчим"]
    DD -->|нет| SEND["Alerts::send"]
    SEND --> CP["Копия в «Отчёты»"]
    SEND --> EM["Письма адресатам из настроек"]
    SEND --> TGM["Telegram-бот"]
    DIG["alerts:check --digest в заданное время"] --> SUM["Ежедневная сводка"] --> SEND
```

## Код

`HealthController`, `Server/HealthChecks`, `Services/ServerHealth`, `Server/Alerts`,
`Settings/AlertsController`, `Server/SystemReports`, `SystemReportsController`, задача `AlertsCheck`.
Отчёты Logwatch и cron-скриптов iRedMail складываются в `/var/lib/mailadmin/reports`
через `deploy/mailadmin-report` вместо писем на postmaster.
