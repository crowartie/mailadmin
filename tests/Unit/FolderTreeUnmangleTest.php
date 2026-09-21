<?php

namespace Tests\Unit;

use App\Services\Mail\FolderTree;
use PHPUnit\Framework\TestCase;

class FolderTreeUnmangleTest extends TestCase
{
    public function test_imap_encoded_name_is_decoded(): void
    {
        $this->assertSame('Реклама', FolderTree::unmangle('&BCAENQQ6BDsEMAQ8BDA-'));
        $this->assertSame('INBOX/Уведомления', FolderTree::unmangle('INBOX/&BCMEMgQ1BDQEPgQ8BDsENQQ9BDgETw-'));
        $this->assertSame('03 ПОЛЮС-КРАСНОЯРСК', FolderTree::unmangle('03 &BB8EHgQbBC4EIQ--&BBoEIAQQBCEEHQQeBC8EIAQhBBo-'));
    }

    public function test_ordinary_names_are_untouched(): void
    {
        foreach (['Счета & Акты', 'R&D team', 'Проекты', 'A&B-C', 'Отклоненные счета', '&-'] as $n) {
            $this->assertSame($n, FolderTree::unmangle($n));
        }
    }
}
