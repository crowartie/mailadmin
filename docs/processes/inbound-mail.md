# Входящая почта: от чужого сервера до папки

Письмо извне проходит Postfix, фильтры Amavis и попадает в Dovecot, где правила Sieve
раскладывают его по папкам. Приложение в этом пути не участвует напрямую: оно готовит
правила (Sieve), списки антиспама и карты Postfix, которые здесь срабатывают.

```mermaid
flowchart TD
    S["Чужой почтовый сервер"] -->|SMTP 25| PS["postscreen: DNSBL, ранние проверки"]
    PS -->|в чёрном списке| R1["Отказ"]
    PS --> HELO{"HELO: имя сервера существует?"}
    HELO -->|нет и не в исключениях helo_access| R2["450 или 554, письмо не принято"]
    HELO --> RCPT{"Получатель есть в vmail?"}
    RCPT -->|нет| R3["550 User unknown"]
    RCPT --> EXT{"Отправитель выдаёт себя за наш домен?"}
    EXT -->|да, не из разрешённых серверов| R4["Отказ: нужен SMTP AUTH"]
    EXT --> POL["iRedAPD: политики, серые списки, лимиты"]
    POL --> Q["Квота ящика: мягкая проверка Dovecot quota-status"]
    Q --> AM["Amavis: ClamAV и SpamAssassin"]
    AM -->|вирус| QV["Карантин в базе amavisd"]
    AM -->|балл выше порога kill| QS["Карантин спама"]
    AM -->|балл выше tag2| TAG["Помечено как спам"]
    AM -->|чисто| LMTP
    TAG --> LMTP["Dovecot LMTP: доставка"]
    LMTP --> G1["Общий Sieve iRedMail"]
    G1 --> G2["Общий Sieve приложения: решения по отправителям, спам, рассылки"]
    G2 --> US["Sieve пользователя: правила и автоответ из веб-почты"]
    US -->|правило| F1["fileinto :create папка"]
    US -->|пересылка| F2["redirect на другой адрес"]
    US -->|автоответ| F3["vacation"]
    US -->|ничего не сработало| INB["Входящие"]
    TAG -.->|если нет своего правила| JUNK["Спам"]
    F1 --> MD[("Maildir")]
    INB --> MD
    JUNK --> MD
    MD --> FTS["fts_xapian: индекс для поиска, в фоне"]
```

## Где это настраивается

| Шаг | Где |
|---|---|
| DNSBL для postscreen | `deploy/antispam-extras.sh` |
| Исключения HELO | `/etc/postfix/helo_access.pcre` (iRedMail) |
| Отправка «от нашего домена» с mail.ru, Яндекса, Gmail | `Server/ExternalSenders`, `mailadmin-ctl external-senders`, задача `external-senders:refresh` |
| Мягкая квота | `deploy/postfix-quota-soft.sh` |
| Пороги спама, карантин | админка «Антиспам», `Server/AmavisConfig`, `Server/Quarantine` |
| Общий Sieve приложения | `Mail/SenderRules`, `mailadmin-ctl sieve-global` |
| Sieve пользователя | [правила](rules.md) |
| Обучение спама: перенос в «Спам» и обратно | imapsieve, `deploy/dovecot-fts-learn.sh`, cron `mailadmin-salearn` |
| Поисковый индекс | `deploy/dovecot-fts-learn.sh`, таймер `mailadmin-index-nightly` |

## Что может пойти не так

- Сервер отправителя представляется именем без DNS — отказ по HELO, письмо теряется у
  отправителя. Лечится строкой-исключением в `helo_access.pcre`.
- Правило пользователя указывает на удалённую папку — `fileinto :create` создаст её заново.
  Поэтому при удалении папки приложение выключает такие правила ([папки](folders-and-shares.md)).
