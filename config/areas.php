<?php

// Зоны приложения. Админка — на отдельном порту (требование: «раз это админка,
// то должна быть на отдельном порту»), веб-почта — на обычном.
return [
    'admin_port' => (int) env('ADMIN_PORT', 8080),
    'mail_port' => (int) env('MAIL_PORT', 80),

    // Домен, который подставляется к короткому логину в веб-почте («ivanov» → ivanov@домен).
    'default_domain' => env('MAIL_DEFAULT_DOMAIN', 'example.ru'),

    // Почтовый сервер, к которому ходит веб-почта.
    'imap' => [
        'host' => env('MAIL_IMAP_HOST', '127.0.0.1'),
        'port' => (int) env('MAIL_IMAP_PORT', 993),
        'encryption' => env('MAIL_IMAP_ENCRYPTION', 'ssl'),
        'validate_cert' => (bool) env('MAIL_IMAP_VALIDATE_CERT', false),
        // Master-пользователь Dovecot: планировщик заходит в ящик без пароля пользователя
        // (вернуть отложенное письмо, положить копию отправленного по расписанию).
        'master_user' => env('MAIL_IMAP_MASTER_USER'),
        'master_password' => env('MAIL_IMAP_MASTER_PASSWORD'),
    ],
    'smtp' => [
        'host' => env('MAIL_SMTP_HOST', '127.0.0.1'),
        'port' => (int) env('MAIL_SMTP_PORT', 587),
    ],
    // ManageSieve — правила и автоответ пользователя.
    'sieve' => [
        'host' => env('MAIL_SIEVE_HOST', '127.0.0.1'),
        'port' => (int) env('MAIL_SIEVE_PORT', 4190),
    ],
];
