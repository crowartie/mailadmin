<?php

// Зоны приложения. Админка — на отдельном порту (требование: «раз это админка,
// то должна быть на отдельном порту»), веб-почта — на обычном.
return [
    'admin_port' => (int) env('ADMIN_PORT', 8080),
    'mail_port' => (int) env('MAIL_PORT', 80),

    // Домен для короткого логина в веб-почте.
    'default_domain' => env('MAIL_DEFAULT_DOMAIN', 'innotec.su'),

    // Почтовый сервер, к которому ходит веб-почта.
    'imap' => [
        'host' => env('MAIL_IMAP_HOST', '192.168.30.102'),
        'port' => (int) env('MAIL_IMAP_PORT', 993),
        'encryption' => env('MAIL_IMAP_ENCRYPTION', 'ssl'),
        'validate_cert' => (bool) env('MAIL_IMAP_VALIDATE_CERT', false),
    ],
    'smtp' => [
        'host' => env('MAIL_SMTP_HOST', '192.168.30.102'),
        'port' => (int) env('MAIL_SMTP_PORT', 587),
    ],
];
