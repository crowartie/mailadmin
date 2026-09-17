<?php

namespace Tests\Unit;

use App\Services\Mail\Structure;
use Tests\TestCase;

/**
 * Разбор ответа BODYSTRUCTURE. Здесь закреплены случаи из настоящей почты:
 * простое письмо, письмо «текст + HTML», письмо с вложением и русским именем файла,
 * а также вложенная структура «текст + HTML + картинки в тексте», которую шлёт Outlook.
 */
class StructureTest extends TestCase
{
    public function test_простое_письмо_одна_часть(): void
    {
        $line = '* 1 FETCH (UID 5 BODYSTRUCTURE ("text" "plain" ("charset" "utf-8") NIL NIL "7bit" 120 4 NIL NIL NIL NIL))';
        $parts = Structure::parse($line);

        $this->assertCount(1, $parts);
        $this->assertSame('1', $parts[0]['no'], 'у письма из одной части номер части — единица');
        $this->assertSame('text/plain', $parts[0]['mime']);
        $this->assertSame(120, $parts[0]['size']);
        $this->assertSame([], Structure::attachments($parts), 'вложений нет');
        $this->assertCount(1, Structure::bodyParts($parts));
    }

    public function test_текст_и_html(): void
    {
        $line = '* 1 FETCH (UID 5 BODYSTRUCTURE (("text" "plain" ("charset" "utf-8") NIL NIL "base64" 400 5 NIL NIL NIL NIL)'
            . '("text" "html" ("charset" "utf-8") NIL NIL "base64" 800 9 NIL NIL NIL NIL) "alternative"))';
        $parts = Structure::parse($line);

        $this->assertSame(['1', '2'], array_column($parts, 'no'));
        $this->assertSame(['text/plain', 'text/html'], array_column($parts, 'mime'));
        // base64 раздувает данные на треть, да ещё ставит перевод строки каждые 76 знаков:
        // человеку показываем размер самого текста, а не части письма.
        $this->assertSame(292, $parts[0]['size']);
        $this->assertSame(400, $parts[0]['rawSize']);
        $this->assertCount(2, Structure::bodyParts($parts));
        $this->assertSame([], Structure::attachments($parts));
    }

    public function test_вложение_с_русским_именем(): void
    {
        $line = '* 1 FETCH (UID 9 BODYSTRUCTURE (("text" "html" ("charset" "utf-8") NIL NIL "base64" 1200 20 NIL NIL NIL NIL)'
            . '("application" "pdf" ("name" "=?UTF-8?B?0KHRh9GR0YIucGRm?=") NIL NIL "base64" 40000 NIL '
            . '("attachment" ("filename" "=?UTF-8?B?0KHRh9GR0YIucGRm?=")) NIL NIL) "mixed"))';
        $parts = Structure::parse($line);
        $att = Structure::attachments($parts);

        $this->assertCount(1, $att);
        $this->assertSame('2', $att[0]['no']);
        $this->assertSame('Счёт.pdf', $att[0]['name'], 'имя приходит закодированным — показываем человеческое');
        $this->assertSame('application/pdf', $att[0]['mime']);
        $this->assertSame(29232, $att[0]['size'], 'из размера части вычтены переводы строк base64');
        $this->assertSame('attachment', $att[0]['disposition']);
    }

    public function test_вложенная_структура_с_картинками_в_тексте(): void
    {
        // Так письмо выглядит из Outlook: снаружи «mixed» с вложением, внутри «related»
        // с HTML и картинками, на которые ссылается текст.
        $line = '* 1 FETCH (UID 11 BODYSTRUCTURE (((("text" "plain" ("charset" "utf-8") NIL NIL "base64" 100 2 NIL NIL NIL NIL)'
            . '("text" "html" ("charset" "utf-8") NIL NIL "base64" 900 10 NIL NIL NIL NIL) "alternative")'
            . '("image" "png" ("name" "logo.png") "<logo@01D>" NIL "base64" 2000 NIL ("inline" ("filename" "logo.png")) NIL NIL) "related")'
            . '("application" "vnd.openxmlformats-officedocument.spreadsheetml.sheet" ("name" "smeta.xlsx") NIL NIL "base64" 80000 NIL '
            . '("attachment" ("filename" "smeta.xlsx")) NIL NIL) "mixed"))';
        $parts = Structure::parse($line);

        $this->assertSame(['1.1.1', '1.1.2', '1.2', '2'], array_column($parts, 'no'), 'номера частей — как их считает сервер');

        $bodies = Structure::bodyParts($parts);
        $this->assertSame(['1.1.1', '1.1.2'], array_column($bodies, 'no'), 'тело письма — текст и HTML внутри «alternative»');

        $att = Structure::attachments($parts);
        $this->assertSame(['1.2', '2'], array_column($att, 'no'));
        $this->assertSame('logo@01D', $att[0]['id'], 'картинка из текста узнаётся по Content-ID');
        $this->assertSame('inline', $att[0]['disposition']);
        $this->assertSame('smeta.xlsx', $att[1]['name']);
    }

    public function test_непонятный_ответ_не_ломает_ничего(): void
    {
        $this->assertNull(Structure::parse('* 1 FETCH (UID 5 FLAGS (\\Seen))'), 'без BODYSTRUCTURE разбирать нечего');
        $this->assertNull(Structure::parse(''));
    }

    public function test_раскодирование_части(): void
    {
        $this->assertSame('Привет', Structure::decode(base64_encode('Привет'), 'base64'));
        $this->assertSame('Привет', Structure::decode("=D0=9F=D1=80=D0=B8=D0=B2=D0=B5=D1=82", 'quoted-printable'));
        $this->assertSame('как есть', Structure::decode('как есть', '7bit'));
    }
}
