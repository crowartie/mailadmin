<?php

namespace App\Services\Mail;

use Webklex\PHPIMAP\Message;

/**
 * Достать письмо по UID, не падая: письмо могли удалить или переложить в другой вкладке,
 * а у некоторых писем библиотека спотыкается на заголовках. Для всех остальных частей
 * это одна понятная точка, поэтому проверка «а есть ли письмо» не размазана по коду.
 */
class MessageFetch
{
    public function __construct(
        private readonly FolderTree $tree,
    ) {
    }


    /** Заголовки одного письма как текст: нужны, чтобы восстановить отметки черновика. */
    /**
     * Письмо по номеру или null. Библиотека на отсутствующий UID бросает исключение о заголовках,
     * и наружу это выходило ошибкой сервера вместо понятного «письма больше нет».
     */
    public function messageOrNull(string $path, int $uid): ?Message
    {
        try {
            return $this->tree->folder($path)->query()->getMessageByUid($uid);
        } catch (\Webklex\PHPIMAP\Exceptions\MessageHeaderFetchingException) {
            return null;
        } catch (\Throwable) {
            return null;
        }
    }
}
