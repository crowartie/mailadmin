# Автонастройка почтовых программ

Человек вводит только адрес и пароль, программа сама узнаёт серверы и порты.

```mermaid
flowchart TD
    OL["Outlook"] -->|POST autodiscover.домен/autodiscover/autodiscover.xml| AD["AutoconfigController::autodiscover"]
    TB["Thunderbird и другие"] -->|GET autoconfig.домен/mail/config-v1.1.xml или .well-known/autoconfig| AC["AutoconfigController::autoconfig"]
    AP["iPhone, Mac"] -->|/mail/apple.mobileconfig со страницы «Телефон и программы»| MC["AutoconfigController::mobileconfig"]
    AD --> ANS["IMAP imap.домен:993 SSL, SMTP smtp.домен:465 SSL"]
    AC --> ANS
    MC --> SIGN["Профиль подписан сертификатом сервера: mailadmin-ctl profile-sign"] --> ANS
    ANS --> CL["Программа подключается к Dovecot и Postfix напрямую"]
```

Нужны DNS-записи `autoconfig` и `autodiscover` (CNAME на имя сервера); установщик печатает их
в конце. SRV-записи `_autodiscover._tcp`, `_imaps._tcp`, `_submission._tcp` не обязательны,
но помогают части программ.

Код: `Mail/AutoconfigController`, маршруты в начале `routes/mail.php`.
