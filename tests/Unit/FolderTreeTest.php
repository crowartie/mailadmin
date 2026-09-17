<?php

namespace Tests\Unit;

use App\Services\Mail\FolderTree;
use App\Services\Mail\MailStore;
use Tests\TestCase;

/**
 * Разбор путей папок — то немногое в дереве, что не требует сервера.
 * Ошибка здесь стоит дорого: по владельцу общей папки решается, куда переносить
 * письмо и чью корзину считать своей.
 */
class FolderTreeTest extends TestCase
{
    public function test_роль_папки_по_имени(): void
    {
        $this->assertSame('inbox', FolderTree::roleOfPath('INBOX'));
        $this->assertSame('trash', FolderTree::roleOfPath('Deleted Items'), 'наследие Outlook и Kerio');
        $this->assertSame('spam', FolderTree::roleOfPath('junk'), 'регистр не важен');
        $this->assertSame('custom', FolderTree::roleOfPath('Счета'));
    }

    public function test_владелец_общей_папки(): void
    {
        $this->assertSame('bn@innotec.su', FolderTree::sharedOwner('Shared/bn@innotec.su/INBOX'));
        $this->assertSame('bn@innotec.su', FolderTree::sharedOwner('Shared/BN@innotec.su'));
        $this->assertNull(FolderTree::sharedOwner('INBOX'), 'своя папка — владельца нет');
        $this->assertNull(FolderTree::sharedOwner('Shared/'), 'один только корень — ещё не ящик');
    }

    /** Контроллеры зовут те же функции через MailStore — разделение не должно это ломать. */
    public function test_обёртки_в_MailStore_отвечают_так_же(): void
    {
        $this->assertSame('sent', MailStore::roleOfPath('Sent Items'));
        $this->assertSame('bn@innotec.su', MailStore::sharedOwner('Shared/bn@innotec.su/Счета'));
        $this->assertSame(FolderTree::SHARED_PREFIX, MailStore::SHARED_PREFIX);
    }
}
