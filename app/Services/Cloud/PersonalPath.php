<?php

namespace App\Services\Cloud;

use App\Exceptions\MailException;

/**
 * Пути в личном облаке сотрудника.
 *
 * Всё, что приходит из браузера, — путь ОТНОСИТЕЛЬНО папки сотрудника. Здесь он чистится
 * и проверяется до того, как из него соберётся адрес в Nextcloud: никаких «..», никаких
 * абсолютных путей, обратных косых, служебных знаков и скрытых (с точки) имён — через них
 * можно было бы выйти в соседнюю папку или в корзину. Сам адрес папки сотрудника строит
 * только сервер, из адреса его ящика (userDir), и браузер на него повлиять не может.
 *
 * Чистые функции без сети и базы — их проверяет PersonalPathTest.
 */
final class PersonalPath
{
    /** Корзина сотрудника: служебная папка внутри его папки, в списках не показывается. */
    public const TRASH = '.Корзина';

    public const MAX_NAME = 200;

    public const MAX_DEPTH = 30;

    /** Знаки, запрещённые в именах: разделители путей и то, что не любит Windows при скачивании. */
    private const BAD = '/[\\\\\/:*?"<>|\x00-\x1F\x7F]/u';

    /**
     * Относительный путь из браузера → «a/b/c» (пустая строка — корень папки сотрудника).
     */
    public static function clean(?string $rel): string
    {
        $rel = str_replace('\\', '/', (string) $rel);
        $out = [];
        foreach (explode('/', $rel) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $out[] = self::name($part);
        }
        if (count($out) > self::MAX_DEPTH) {
            throw MailException::invalid('Слишком глубокая вложенность папок');
        }

        return implode('/', $out);
    }

    /** Одно имя файла или папки: проверка и нормализация. */
    public static function name(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        if ($name === '' || $name === '.' || $name === '..') {
            throw MailException::invalid('Недопустимое имя');
        }
        if (str_starts_with($name, '.')) {
            throw MailException::invalid('Имя не может начинаться с точки');
        }
        if (preg_match(self::BAD, $name)) {
            throw MailException::invalid('В имени нельзя использовать знаки \\ / : * ? " < > |');
        }
        if (mb_strlen($name) > self::MAX_NAME) {
            throw MailException::invalid('Имя длиннее ' . self::MAX_NAME . ' знаков');
        }

        return $name;
    }

    /** Имя из загрузки: плохие знаки заменяются, а не отвергаются — файл с телефона не должен падать из-за «:» в имени. */
    public static function uploadName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = trim(preg_replace(self::BAD, '_', $name) ?? '');
        $name = ltrim($name, '.');
        if ($name === '') {
            $name = 'файл';
        }
        if (mb_strlen($name) > self::MAX_NAME) {
            $ext = pathinfo($name, PATHINFO_EXTENSION);
            $keep = self::MAX_NAME - ($ext !== '' ? mb_strlen($ext) + 1 : 0);
            $name = mb_substr($name, 0, max(1, $keep)) . ($ext !== '' ? '.' . $ext : '');
        }

        return $name;
    }

    /** Папка сотрудника: только из адреса ящика, в нижнем регистре. */
    public static function userDir(string $user): string
    {
        $user = mb_strtolower(trim($user));
        if (! preg_match('/^[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}$/', $user)) {
            throw MailException::invalid('Некорректный адрес ящика');
        }

        return $user;
    }

    /** Соединить части относительного пути. */
    public static function join(string ...$parts): string
    {
        return implode('/', array_values(array_filter(array_map(fn ($p) => trim($p, '/'), $parts), fn ($p) => $p !== '')));
    }

    public static function parent(string $rel): string
    {
        $pos = strrpos($rel, '/');

        return $pos === false ? '' : substr($rel, 0, $pos);
    }

    public static function base(string $rel): string
    {
        $pos = strrpos($rel, '/');

        return $pos === false ? $rel : substr($rel, $pos + 1);
    }

    /** Лежит ли $rel внутри $dir (или совпадает). */
    public static function within(string $rel, string $dir): bool
    {
        return $dir === '' || $rel === $dir || str_starts_with($rel, $dir . '/');
    }

    /** «Отчёт.pdf», 2 → «Отчёт (2).pdf»; у папок и файлов без расширения — просто « (2)». */
    public static function numbered(string $name, int $n, bool $dir = false): string
    {
        $ext = $dir ? '' : pathinfo($name, PATHINFO_EXTENSION);
        if ($ext === '' || $ext === $name) {
            return $name . ' (' . $n . ')';
        }

        return mb_substr($name, 0, -mb_strlen($ext) - 1) . ' (' . $n . ').' . $ext;
    }

    /** Путь к Nextcloud: каждая часть кодируется отдельно, разделители остаются. */
    public static function encode(string $path): string
    {
        return implode('/', array_map('rawurlencode', array_filter(explode('/', $path), fn ($p) => $p !== '')));
    }
}
