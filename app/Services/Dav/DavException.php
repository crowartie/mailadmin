<?php

namespace App\Services\Dav;

/** Ошибка хранилища книг и календарей с HTTP-статусом для ответа API. */
class DavException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message, $status);
    }
}
