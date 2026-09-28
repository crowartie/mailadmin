<?php

namespace Tests\Unit;

use App\Services\Mail\SharedReads;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * «Кто прочитал» в общих папках (обращение №51): отметки пишутся по Message-ID, первое открытие
 * не перезаписывается, «Непрочитано» снимает, выдача по странице — одним запросом; подписи словами.
 * База — sqlite в памяти, таблица из миграции.
 */
class SharedReadsTest extends TestCase
{
    /** Таблица нужна трём тестам из четырёх; без sqlite (боевой сервер без драйвера) они пропускаются, подписи проверяются всегда. */
    private function needDb(): void
    {
        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('нет pdo_sqlite — тесты с таблицей идут на машине разработчика');
        }
        if (! Schema::hasTable('webmail_shared_reads')) {
            (require base_path('database/migrations/2026_09_29_000001_create_webmail_shared_reads.php'))->up();
        }
        \App\Models\Webmail\SharedRead::query()->delete();
    }

    public function test_путь_и_владелец_общей_папки(): void
    {
        $this->assertSame('info@example.test', SharedReads::ownerOf('Shared/info@example.test/Закупки'));
        $this->assertNull(SharedReads::ownerOf('INBOX/Закупки'));
        $this->assertSame('Закупки', SharedReads::ownerPath('Shared/info@example.test/Закупки'));
        $this->assertSame('INBOX', SharedReads::ownerPath('Shared/info@example.test/INBOX'));
        $this->assertSame('INBOX', SharedReads::ownerPath('Shared/info@example.test'));
        $this->assertSame('abc@x', SharedReads::key(' <abc@x> '));
        $this->assertSame(190, mb_strlen(SharedReads::key(str_repeat('я', 300))));
    }

    public function test_запись_первое_время_и_снятие(): void
    {
        $this->needDb();
        SharedReads::record('info@example.test', '<m1@x>', 'anna@example.test');
        $first = \App\Models\Webmail\SharedRead::query()->first()->read_at;
        SharedReads::record('INFO@example.test', 'm1@x', 'Anna@example.test');   // регистр и скобки — то же
        $this->assertSame(1, \App\Models\Webmail\SharedRead::query()->count());
        $this->assertEquals($first, \App\Models\Webmail\SharedRead::query()->first()->read_at, 'первое открытие не перезаписывается');
        SharedReads::record('info@example.test', 'm1@x', 'boris@example.test');
        SharedReads::record('info@example.test', 'm2@x', 'boris@example.test');
        SharedReads::record('info@example.test', '', 'boris@example.test');         // без Message-ID — нечего писать
        SharedReads::record('info@example.test', 'm3@x', 'info@example.test');      // владелец сам — не читатель

        $map = SharedReads::forMessages('info@example.test', ['m1@x', 'm2@x', 'нет@x', null]);
        $this->assertSame(['anna@example.test', 'boris@example.test'], array_column($map['m1@x'], 'mail'));
        $this->assertSame(['boris@example.test'], array_column($map['m2@x'], 'mail'));
        $this->assertArrayNotHasKey('нет@x', $map);
        $this->assertSame(3, \App\Models\Webmail\SharedRead::query()->count());

        SharedReads::forget('info@example.test', '<m1@x>', 'anna@example.test');
        $this->assertSame(['boris@example.test'], array_column(SharedReads::forMessages('info@example.test', ['m1@x'])['m1@x'], 'mail'));
    }

    public function test_чистка_старше_года(): void
    {
        $this->needDb();
        SharedReads::record('info@example.test', 'old@x', 'anna@example.test');
        \App\Models\Webmail\SharedRead::query()->update(['read_at' => now()->subDays(400)]);
        SharedReads::record('info@example.test', 'new@x', 'anna@example.test');
        $this->assertSame(1, SharedReads::purge());
        $this->assertSame(['new@x'], \App\Models\Webmail\SharedRead::query()->pluck('message_id')->all());
    }

    public function test_подписи_словами(): void
    {
        $this->assertSame('ещё никто', SharedReads::label([]));
        $this->assertSame('Аносов М., Мусин Е.', SharedReads::label([['name' => 'Аносов Михаил Леонидович'], ['name' => 'Мусин Евгений']]));
        $this->assertSame('А Б., В Г., Д Е. и ещё 2', SharedReads::label([['name' => 'А Б'], ['name' => 'В Г'], ['name' => 'Д Е'], ['name' => 'Ж З'], ['name' => 'И К']]));
        $this->assertSame('ivan@example.test', SharedReads::shortName('ivan@example.test'));
        $this->assertSame('Иванов', SharedReads::shortName('Иванов'));
    }
}
