<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Ошибка работы с почтой, понятная человеку.
 *
 * Раньше хранилище само вызывало abort() и тем самым отвечало HTTP-кодом из глубины: из-за этого
 * его нельзя было позвать ни из планировщика, ни из консольной команды, ни из теста — вместе с ним
 * всегда тянулся HTTP-слой. Теперь оно бросает это исключение, а превращает его в ответ
 * один обработчик (bootstrap/app.php).
 *
 * Сообщение пишем сразу для сотрудника: его показывают в интерфейсе как есть.
 */
class MailException extends RuntimeException
{
    /** @param int $status код ответа, если ошибка дойдёт до HTTP */
    public function __construct(string $message, private readonly int $status = 422)
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }

    /** Письма, папки или вложения больше нет. */
    public static function notFound(string $message = 'Письмо не найдено'): self
    {
        return new self($message, 404);
    }

    /** Прав не хватает: чужая папка «только для просмотра», нет доступа к общему ящику. */
    public static function denied(string $message): self
    {
        return new self($message, 409);
    }

    /** Запрос составлен неверно: пустое имя папки, непонятная дата, чужой адрес отправителя. */
    public static function invalid(string $message): self
    {
        return new self($message, 422);
    }

    /** Почтовый сервер не смог выполнить команду (не наша вина и не вина пользователя). */
    public static function upstream(string $message): self
    {
        return new self($message, 502);
    }

    /** Занято или ещё не готово: идёт индексация, конвертер занят. */
    public static function busy(string $message): self
    {
        return new self($message, 503);
    }

    /** Слишком большой объект для этой операции. */
    public static function tooLarge(string $message): self
    {
        return new self($message, 413);
    }

    /** Тип файла не поддерживается. */
    public static function unsupported(string $message): self
    {
        return new self($message, 415);
    }
}
