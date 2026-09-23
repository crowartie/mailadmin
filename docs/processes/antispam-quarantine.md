# Антиспам и карантин

Письмо оценивает Amavis со SpamAssassin и ClamAV. Сомнительное — помечается, явный спам
и вирусы — в карантин в базе. Сотрудники решают по отправителям, администратор — по порогам
и спискам; обучение идёт само из переносов в «Спам».

```mermaid
flowchart TD
    IN["Входящее письмо"] --> WB{"Белый или чёрный список amavisd?"}
    WB -->|белый| PASS["Мимо фильтра спама"]
    WB -->|чёрный| QS
    WB --> SA["SpamAssassin: правила, Bayes, Razor, Pyzor, DNSBL"]
    SA --> SC{"Балл"}
    SC -->|ниже tag2| PASS
    SC -->|tag2 и выше| TAG["Заголовок спама → общий Sieve кладёт в «Спам»"]
    SC -->|kill и выше| QS["Карантин: msgs, msgrcpt, quarantine"]
    IN --> AV["ClamAV"] -->|вирус| QV["Карантин вирусов"]
    QS --> DG["quarantine:digest: сводка сотруднику в заданное время"]
    DG --> REL["Сотрудник или админ: «Выпустить»"]
    REL --> AR["mailadmin-ctl amavis-release → доставка"]
    QS -->|срок хранения| PG["backup:run --purge-quarantine"]
```

```mermaid
flowchart LR
    MV["Сотрудник переносит в «Спам» или из него"] --> IS["imapsieve Dovecot"] --> SP["/var/spool/sa-learn/spam или ham"]
    SP --> CR["cron каждые 5 минут: sa-learn"]
    SM["«Это рассылка», «Это спам» у отправителя"] --> SR["SenderRules → общий Sieve приложения"]
    SR -->|часто у многих| PROM["Админка: решение для всех"]
```

## Код

`AntispamController`, `Settings/SpamController`, `Server/Antispam`, `Server/AmavisConfig`,
`Server/WbList`, `Server/Quarantine`, `Mail/SenderRules`, `Mail/QuarantineController`,
задача `QuarantineDigest`; настройка обучения — `deploy/dovecot-fts-learn.sh`,
DNSBL — `deploy/antispam-extras.sh`.
