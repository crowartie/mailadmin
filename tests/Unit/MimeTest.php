<?php

namespace Tests\Unit;

use App\Services\Mail\Mime;
use Tests\TestCase;

/**
 * Разбор служебных частей письма. Здесь закреплены случаи, на которых мы уже спотыкались:
 * Kerio пишет идентификаторы цепочки слитно, письма приходят с непонятной датой,
 * а имена папок хранятся в своей кодировке IMAP.
 */
class MimeTest extends TestCase
{
    /** Kerio пишет «<a><b>» слитно — без разбора получался склеенный «a@xb@y», и письмо не уходило. */
    public function test_идентификаторы_цепочки(): void
    {
        $this->assertSame(['a@x', 'b@y'], Mime::messageIds('<a@x><b@y>'));
        $this->assertSame(['a@x', 'b@y'], Mime::messageIds('<a@x> <b@y>'));
        $this->assertSame(['a@x'], Mime::messageIds('a@x'));
        $this->assertSame(['a@x'], Mime::messageIds(['<a@x>', '<a@x>']), 'повторы убираются');
        $this->assertSame([], Mime::messageIds(null));
        $this->assertSame([], Mime::messageIds('без собаки'), 'не идентификатор — не берём');
    }

    /**
     * Письмо с непонятной датой должно уходить в конец списка, а не всплывать в начало
     * переписки: strtotime на мусоре возвращает false, то есть ноль.
     */
    public function test_время_для_сортировки(): void
    {
        $this->assertSame(PHP_INT_MAX, Mime::sortTime('не дата'));
        $this->assertSame(PHP_INT_MAX, Mime::sortTime(null));
        $this->assertSame(strtotime('2026-09-17T10:00:00+08:00'), Mime::sortTime('2026-09-17T10:00:00+08:00'));
    }

    /** Имя папки хранится в modified UTF-7 — в интерфейсе нужно человеческое. */
    public function test_имя_папки(): void
    {
        $this->assertSame('АБЗ', Mime::utf8Name('&BBAEEQQX-'));
        $this->assertSame('Sent', Mime::utf8Name('Sent'), 'латиница не меняется');
    }

    /** Адрес без имени: показываем сам адрес, а не пустую строку. */
    public function test_адрес(): void
    {
        $this->assertSame(['name' => 'Иван', 'mail' => 'i@x.ru'], Mime::address('Иван', 'i@x.ru'));
        $this->assertSame(['name' => 'i@x.ru', 'mail' => 'i@x.ru'], Mime::address(null, 'i@x.ru'));
        $this->assertSame(['name' => 'i@x.ru', 'mail' => 'i@x.ru'], Mime::address('', 'I@X.RU'), 'адрес приводится к нижнему регистру');
    }

    /** Заголовки со строками-продолжениями склеиваются в одно значение. */
    public function test_заголовки(): void
    {
        $raw = "Subject: Счёт\r\nReferences: <a@x>\r\n <b@y>\r\nFrom: i@x.ru\r\n";
        $fields = Mime::parseHeaderFields($raw);

        $this->assertSame('Счёт', $fields['subject']);
        $this->assertSame('<a@x> <b@y>', $fields['references']);
        $this->assertSame('<a@x> <b@y>', Mime::headerValue($raw, 'References'));
        $this->assertNull(Mime::headerValue($raw, 'Reply-To'), 'чего нет — того нет');
    }

    /** OR в IMAP бинарный: N условий превращаются в N-1 вложенных OR. */
    public function test_критерии_поиска(): void
    {
        $one = Mime::orCriteria([['Message-ID', 'a@x']]);
        $this->assertSame(['HEADER', 'Message-ID', '"a@x"'], $one);

        $two = Mime::orCriteria([['Message-ID', 'a@x'], ['References', 'a@x']]);
        $this->assertSame('OR', $two[0], 'два условия — один OR впереди');
        $this->assertCount(7, $two);
    }

    /** Отказ почтового сервера объясняем словами, а не английским хвостом протокола. */
    public function test_причина_отказа(): void
    {
        $this->assertSame('папка открыта только для просмотра', Mime::imapReason('Permission denied'));
        $this->assertSame('закончилось место в ящике', Mime::imapReason('[OVERQUOTA] Quota exceeded'));
        $this->assertSame('папки или письма больше нет', Mime::imapReason('Mailbox not found'));
    }
}
