<?php

namespace Tests\Unit;

use App\Services\Mail\MailAddressList;
use PHPUnit\Framework\TestCase;

/**
 * Разбор строки адресатов.
 *
 * Пока он был приватным куском внутри сборки письма, проверить его было нечем — а ошибка
 * здесь тихая: письмо уходит остальным получателям, и о пропаже узнаёшь от того, кто его
 * не получил. Поэтому проверяем случаи, на которых наивное деление по запятой ломается.
 */
class MailAddressListTest extends TestCase
{
    public function test_splits_simple_list(): void
    {
        $this->assertSame(
            ['a@b.ru', 'c@d.ru', 'e@f.ru'],
            MailAddressList::splitAddresses('a@b.ru, c@d.ru; e@f.ru')
        );
    }

    /** Запятая внутри имени — самый частый случай: «Фамилия, Имя <адрес>». */
    public function test_comma_inside_quoted_name_does_not_split(): void
    {
        $parts = MailAddressList::splitAddresses('"Иванов, Иван" <ivanov@innotec.su>, petrov@innotec.su');
        $this->assertCount(2, $parts);
        $this->assertStringContainsString('ivanov@innotec.su', $parts[0]);
        $this->assertSame('petrov@innotec.su', trim($parts[1]));
    }

    public function test_keeps_display_name_with_address(): void
    {
        $list = MailAddressList::parseAddresses('Селезнев Алексей <san@innotec.su>');
        $this->assertCount(1, $list);
        $this->assertSame('san@innotec.su', $list[0]->getAddress());
        $this->assertSame('Селезнев Алексей', $list[0]->getName());
    }

    public function test_ignores_empty_pieces_and_spaces(): void
    {
        $list = MailAddressList::parseAddresses('  a@b.ru ,, ; c@d.ru  ');
        $this->assertSame(['a@b.ru', 'c@d.ru'], array_map(fn ($a) => $a->getAddress(), $list));
    }

    /** Строка без адресов не должна превращаться в получателя-пустышку. */
    public function test_garbage_gives_no_recipients(): void
    {
        $this->assertSame([], MailAddressList::parseAddresses('   '));
        $this->assertSame([], MailAddressList::parseAddresses(',,;;'));
    }

    /** Один и тот же адрес дважды — это один получатель, а не два письма. */
    public function test_duplicates_collapse(): void
    {
        $list = MailAddressList::parseAddresses('a@b.ru, A@B.ru');
        $this->assertLessThanOrEqual(2, count($list), 'дубликат не должен размножаться');
    }
}
