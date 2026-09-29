<?php

namespace Tests\Unit;

use App\Exceptions\MailException;
use App\Models\Webmail\SharedReply;
use App\Services\Mail\SharedReplies;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * «Кто ответил» в общих папках (обращение №57): ответ из папки Shared/… запоминается с копией .eml,
 * из своей папки — нет; выдача по странице списка одним запросом; ответ открывает владелец ящика
 * и сам ответивший, посторонний получает «не найдено»; уборка старше года удаляет и файл.
 * База — sqlite в памяти, файлы — во временном каталоге.
 */
class SharedRepliesTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/shared-replies-test-' . bin2hex(random_bytes(4));
        config(['mailadmin.shared_replies_dir' => $this->dir]);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($this->dir);
        }
        parent::tearDown();
    }

    private function needDb(): void
    {
        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('нет pdo_sqlite — тесты с таблицей идут на машине разработчика');
        }
        if (! Schema::hasTable('webmail_shared_replies')) {
            (require base_path('database/migrations/2026_09_29_000003_create_webmail_shared_replies.php'))->up();
        }
        SharedReply::query()->delete();
    }

    private function email(string $subject = 'Re: Счёт'): Email
    {
        return (new Email)->from('anna@example.test')->to('client@example.org')->subject($subject)->text('Отвечаю.');
    }

    public function test_подписи_без_базы(): void
    {
        $this->assertSame('никто', SharedReplies::label([]));
        $this->assertSame('Аносов М., Мусин Е.', SharedReplies::label([['name' => 'Аносов Михаил Леонидович'], ['name' => 'Мусин Евгений']]));
        $this->assertStringContainsString('shared-replies', SharedReplies::dir());
    }

    public function test_запись_только_из_общей_папки_и_выдача(): void
    {
        $this->needDb();
        $email = $this->email();
        $raw = $email->toString();

        $own = SharedReplies::record('anna@example.test', ['answeredFolder' => 'INBOX', 'inReplyTo' => '<m1@x>'], $email, $raw);
        $this->assertNull($own, 'ответ из своей папки не запоминается');
        $none = SharedReplies::record('anna@example.test', ['answeredFolder' => 'Shared/info@example.test/Закупки', 'inReplyTo' => ''], $email, $raw);
        $this->assertNull($none, 'без Message-ID исходного письма привязать не к чему');

        $r = SharedReplies::record('Anna@example.test', ['answeredFolder' => 'Shared/info@example.test/Закупки', 'inReplyTo' => '<m1@x>'], $email, $raw);
        $this->assertNotNull($r);
        $this->assertSame('info@example.test', $r->owner);
        $this->assertSame('Закупки', $r->folder);
        $this->assertSame('m1@x', $r->message_id);
        $this->assertSame('anna@example.test', $r->user);
        $this->assertSame('Re: Счёт', $r->subject);
        $this->assertFileExists($this->dir . '/' . $r->file);
        $this->assertSame(strlen($raw), $r->size);

        SharedReplies::record('boris@example.test', ['answeredFolder' => 'Shared/info@example.test', 'inReplyTo' => 'm1@x'], $this->email('Re: Счёт ещё раз'), $raw);
        $map = SharedReplies::forMessages('info@example.test', ['<m1@x>', 'm2@x', null]);
        $this->assertSame(['anna@example.test', 'boris@example.test'], array_column($map['m1@x'], 'mail'));
        $this->assertArrayNotHasKey('m2@x', $map);
        $this->assertSame('Re: Счёт ещё раз', $map['m1@x'][1]['subject']);

        $list = SharedReplies::attach(['messages' => [['uid' => 1, 'messageId' => '<m1@x>'], ['uid' => 2, 'messageId' => 'm2@x']]], 'Shared/info@example.test/Закупки');
        $this->assertCount(2, $list['messages'][0]['replies']);
        $this->assertSame([], $list['messages'][1]['replies']);
        $this->assertArrayNotHasKey('subject', $list['messages'][0]['replies'][0], 'в списке — только кто, когда и id');
        $same = SharedReplies::attach(['messages' => [['uid' => 1, 'messageId' => 'm1@x']]], 'INBOX');
        $this->assertArrayNotHasKey('replies', $same['messages'][0], 'в своей папке ничего не приклеивается');
    }

    public function test_доступ_показ_и_уборка(): void
    {
        $this->needDb();
        $email = $this->email();
        $r = SharedReplies::record('anna@example.test', ['answeredFolder' => 'Shared/info@example.test/Закупки', 'inReplyTo' => '<m1@x>'], $email, $email->toString());

        $this->assertSame($r->id, SharedReplies::find($r->id, 'INFO@example.test')->id, 'владелец ящика видит');
        $this->assertSame($r->id, SharedReplies::find($r->id, 'anna@example.test')->id, 'сам ответивший видит');
        // Права папки в тестах не прочитать (нет IMAP) — посторонний получает «не найдено», а не «нет прав».
        try {
            SharedReplies::find($r->id, 'stranger@example.test');
            $this->fail('посторонний не должен видеть чужой ответ');
        } catch (MailException $e) {
            $this->assertStringContainsString('не найден', $e->getMessage());
        }

        $shown = SharedReplies::show($r);
        $this->assertSame('Re: Счёт', $shown['subject']);
        $this->assertSame($r->id, $shown['id']);
        $this->assertStringContainsString('Отвечаю', (string) ($shown['text'] ?? ''));

        $r->forceFill(['replied_at' => now()->subDays(SharedReplies::KEEP_DAYS + 1)])->save();
        $this->assertSame(1, SharedReplies::purge());
        $this->assertFileDoesNotExist($this->dir . '/' . $r->file);
        $this->assertSame(0, SharedReply::query()->count());
    }
}
