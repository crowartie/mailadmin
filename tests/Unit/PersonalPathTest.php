<?php

namespace Tests\Unit;

use App\Exceptions\MailException;
use App\Services\Cloud\PersonalPath;
use Tests\TestCase;

/**
 * Изоляция личного облака держится на разборе путей: из браузера приходит путь относительно
 * папки сотрудника, и выйти из неё не должно получиться ни одним способом.
 */
class PersonalPathTest extends TestCase
{
    public function test_обычный_путь_чистится(): void
    {
        $this->assertSame('', PersonalPath::clean(''));
        $this->assertSame('', PersonalPath::clean('/'));
        $this->assertSame('Командировка/Видео', PersonalPath::clean('/Командировка//Видео/'));
        $this->assertSame('Командировка/Видео', PersonalPath::clean('Командировка\\Видео'));
        $this->assertSame('Отчёт за сентябрь', PersonalPath::clean('  Отчёт   за сентябрь '));
    }

    /** @return array<string,array{0:string}> */
    public static function escapes(): array
    {
        return [
            'наверх' => ['../petrov@example.ru'],
            'наверх в середине' => ['Видео/../../petrov@example.ru'],
            'точка' => ['./Видео'],
            'корзина' => ['.Корзина/что-то'],
            'скрытое имя' => ['Видео/.hidden'],
            'обратная косая наверх' => ['..\\..\\etc'],
            'знаки' => ['Видео/a:b'],
            'нулевой байт' => ["Видео/a\0b"],
            'звёздочка' => ['*'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('escapes')]
    public function test_выйти_из_своей_папки_нельзя(string $path): void
    {
        $this->expectException(MailException::class);
        PersonalPath::clean($path);
    }

    public function test_слишком_длинное_имя_и_глубина(): void
    {
        try {
            PersonalPath::name(str_repeat('я', 201));
            $this->fail('длинное имя прошло');
        } catch (MailException) {
            $this->assertTrue(true);
        }
        $this->expectException(MailException::class);
        PersonalPath::clean(implode('/', array_fill(0, 31, 'п')));
    }

    public function test_имя_из_загрузки_исправляется_а_не_отвергается(): void
    {
        $this->assertSame('Отчёт_ итог.pdf', PersonalPath::uploadName('Отчёт: итог.pdf'));
        $this->assertSame('photo.jpg', PersonalPath::uploadName('C:\\Users\\me\\photo.jpg'));
        $this->assertSame('photo.jpg', PersonalPath::uploadName('../../photo.jpg'));
        $this->assertSame('hidden', PersonalPath::uploadName('.hidden'));
        $this->assertSame('файл', PersonalPath::uploadName('...'));
        $long = PersonalPath::uploadName(str_repeat('а', 300) . '.mp4');
        $this->assertSame(200, mb_strlen($long));
        $this->assertStringEndsWith('.mp4', $long);
    }

    public function test_папка_сотрудника_только_из_адреса(): void
    {
        $this->assertSame('ivanov@example.ru', PersonalPath::userDir(' Ivanov@Example.RU '));
        foreach (['../x@example.ru', 'x@example.ru/..', 'x', 'x@', 'a/b@example.ru'] as $bad) {
            try {
                PersonalPath::userDir($bad);
                $this->fail('прошёл адрес ' . $bad);
            } catch (MailException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_вспомогательные(): void
    {
        $this->assertSame('a/b/c', PersonalPath::join('a', '', '/b/', 'c'));
        $this->assertSame('a/b', PersonalPath::parent('a/b/c'));
        $this->assertSame('', PersonalPath::parent('c'));
        $this->assertSame('c', PersonalPath::base('a/b/c'));
        $this->assertTrue(PersonalPath::within('a/b', 'a'));
        $this->assertTrue(PersonalPath::within('a', 'a'));
        $this->assertFalse(PersonalPath::within('ab', 'a'));
        $this->assertTrue(PersonalPath::within('что угодно', ''));
        $this->assertSame('Отчёт (2).pdf', PersonalPath::numbered('Отчёт.pdf', 2));
        $this->assertSame('Видео (3)', PersonalPath::numbered('Видео', 3));
        $this->assertSame('Архив.2026 (2)', PersonalPath::numbered('Архив.2026', 2, true));
        $this->assertSame('%D0%92%D0%B8%D0%B4%D0%B5%D0%BE/a%20b.mp4', PersonalPath::encode('Видео/a b.mp4'));
    }
}
