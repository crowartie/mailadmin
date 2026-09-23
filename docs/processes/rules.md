# Правила разбора и автоответ

Правила хранятся в базе как JSON и собираются в скрипт Sieve, который Dovecot выполняет
при доставке. Сначала сервер должен принять скрипт, только потом правила сохраняются.

```mermaid
flowchart TD
    UI["Настройки → Правила: условия, действия, «при всех» или «при любом»"] --> V["Проверка: до 50 правил, известные поля и действия"]
    V --> B["SieveBuilder: require, автоответ первым, правило за правилом"]
    B --> ACT{"Действие"}
    ACT -->|в папку, копия| FI["fileinto :create или :copy"]
    ACT -->|в папку по адресу или домену отправителя| VAR["address :matches → переменная, части через «-», fileinto папка/имя"]
    ACT -->|метка, флажок, прочитано| AF["addflag"]
    ACT -->|переслать| RD["redirect или redirect :copy"]
    ACT -->|ответить текстом| VC["vacation"]
    ACT -->|уничтожить| DS["discard"]
    FI --> MS
    VAR --> MS
    AF --> MS
    RD --> MS
    VC --> MS
    DS --> MS["ManageSieve 4190: STARTTLS, вход пользователя, PUTSCRIPT, SETACTIVE"]
    MS -->|сервер не принял| E422["422 с текстом сервера, правила не сохранены"]
    MS --> SAVE["webmail_rules: правила, автоответ, текст скрипта"]
```

```mermaid
flowchart LR
    AP["«Разложить Входящие»"] --> RR["RuleRunner: правила в IMAP SEARCH"]
    RR --> OK["от, кому, тема, заголовок → перенос, копия, метка, флажок"]
    RR --> SKIP["размер, текст, шаблоны, пересылка, ответ → пропущено, показано человеку"]
```

## Код

`Mail/Api/RulesController` (`show`, `update`, `apply`), `SieveBuilder`, `ManageSieveClient`,
`Models/Webmail/RuleSet`, `RuleRunner`, `RuleFolders`; клиент — `mail/useMailRules.js`,
`Pages/Mail/Settings.vue`.

Правила работают только для входящих: Sieve не видит отправленные письма.
Порядок при доставке: общий Sieve iRedMail → общий Sieve приложения → скрипт пользователя
([входящая почта](inbound-mail.md)).
