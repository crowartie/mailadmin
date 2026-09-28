<?php

namespace Tests\Feature;

use App\Http\Controllers\MailboxController;
use App\Services\Dav\EmployeeBook;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** Кнопка «Обновить книгу сотрудников» в админке: синхронизация сейчас, сброс кэша подсказок, итог словами. */
class SyncEmployeesButtonTest extends TestCase
{
    // Подмена статического AdminAction::log (alias-мок) возможна, только пока класс не загружен, — отдельный процесс.
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function test_обновляет_книгу_и_сбрасывает_кэш_подсказок(): void
    {
        $book = \Mockery::mock(EmployeeBook::class);
        $book->shouldReceive('sync')->once()->andReturn(['added' => 2, 'updated' => 1, 'removed' => 0]);
        Cache::put('mail.directory', ['старое'], 600);

        // Журнал администратора пишет в базу, которой в тестах нет, — подменяем запись.
        \Mockery::mock('alias:' . \App\Models\AdminAction::class)->shouldReceive('log')->once()->withArgs(fn ($a) => $a === 'mailboxes.sync-employees');
        $this->withoutMiddleware();
        // Контроллер — напрямую: через контейнер его зависимости тянут DavStore и базу, которой в тестах нет.
        $r = (new MailboxController(\Mockery::mock(\App\Services\Vmail\MailboxService::class)))->syncEmployees($book);

        $this->assertNull(Cache::get('mail.directory'), 'справочник подсказок должен перечитаться');
        $this->assertStringContainsString('добавлено 2, обновлено 1, удалено 0', (string) $r->getSession()?->get('success') ?? '');
    }
}
