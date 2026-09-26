<?php

namespace Tests\Unit;

use App\Http\Middleware\RecordActivity;
use App\Services\Mail\MailAddressList;
use App\Services\Mail\MailBuilder;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Черновик с опечаткой в адресе сохраняется (раньше 422 каждые 30 с, и текст жил только во вкладке),
 * а при открытии возвращается строка адресатов как её набрали.
 */
class DraftLenientRecipientsTest extends TestCase
{
    public function test_для_черновика_неверный_адрес_пропускается(): void
    {
        $list = MailAddressList::parseAddresses('Иван <ivan@example.org>, petrov@, sidorov@example.org', true);
        $this->assertSame(['ivan@example.org', 'sidorov@example.org'], array_map(fn ($a) => $a->getAddress(), $list));
    }

    public function test_при_отправке_неверный_адрес_по_прежнему_ошибка(): void
    {
        $this->expectException(HttpException::class);
        MailAddressList::parseAddresses('petrov@', false);
    }

    public function test_строки_адресатов_из_заголовка(): void
    {
        $raw = ['to' => 'ivan@example.org, petrov@', 'cc' => '', 'bcc' => ''];
        $head = "Subject: x\r\n" . MailBuilder::RAW_RCPT_HEADER . ': ' . base64_encode(json_encode($raw)) . "\r\nX-Other: y\r\n";
        $this->assertSame($raw, MailBuilder::draftRawRecipients($head));
        $this->assertSame([], MailBuilder::draftRawRecipients("Subject: x\r\n"));
        // Перенесённое на несколько строк значение
        $long = ['to' => str_repeat('someone@example.org, ', 40) . 'oops@', 'cc' => '', 'bcc' => ''];
        $folded = implode("\r\n ", str_split(base64_encode(json_encode($long)), 70));
        $this->assertSame($long, MailBuilder::draftRawRecipients(MailBuilder::RAW_RCPT_HEADER . ': ' . $folded . "\r\nX-Other: y\r\n"));
    }

    public function test_причина_отказа_без_адресов_и_имён(): void
    {
        $this->assertSame('Неверный адрес: …', RecordActivity::reason(new HttpException(422, 'Неверный адрес: petrov@mail')));
        $this->assertSame('«…» весит 600 МБ', RecordActivity::reason(new HttpException(422, '«Отчёт.pdf» весит 600 МБ')));
        $v = ValidationException::withMessages(['files.0' => 'x', 'from' => 'y']);
        $this->assertSame('Проверка полей: files.0, from', RecordActivity::reason($v));
    }
}
