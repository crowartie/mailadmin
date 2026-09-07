<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Какой зоне принадлежит запрос: админка или веб-почта.
 *
 * Порт берём из SERVER_PORT (его выставляет nginx по тому, на каком listen
 * пришло соединение), а не из заголовка Host — Host клиент может не прислать
 * или подделать, и Symfony тогда молча считает порт 80.
 */
final class Area
{
    /** Базовый адрес веб-почты для ссылок из админки: https://host[:port]. */
    public static function mailUrl(Request $request): string
    {
        $port = (int) config('areas.mail_port');
        $scheme = $request->getScheme();
        $host = $request->getHost();
        $default = $scheme === 'https' ? 443 : 80;

        return $scheme . '://' . $host . ($port === $default ? '' : ':' . $port);
    }

    public const ADMIN = 'admin';
    public const MAIL = 'mail';

    public static function port(Request $request): int
    {
        $port = (int) $request->server('SERVER_PORT');

        return $port > 0 ? $port : (int) $request->getPort();
    }

    public static function current(Request $request): string
    {
        $admin = (int) config('areas.admin_port');
        $mail = (int) config('areas.mail_port');

        // Порты совпадают только при локальной разработке через artisan serve — тогда всё считается админкой.
        if ($admin === $mail) {
            return self::ADMIN;
        }

        return self::port($request) === $admin ? self::ADMIN : self::MAIL;
    }

    public static function isAdmin(Request $request): bool
    {
        return self::current($request) === self::ADMIN;
    }
}
