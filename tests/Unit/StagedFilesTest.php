<?php

namespace Tests\Unit;

use App\Services\Mail\ActivityMap;
use App\Services\Mail\MailBuilder;
use PHPUnit\Framework\TestCase;

/** Большие файлы, заранее положенные в хранилище: какие токены берутся из формы и как это видит журнал. */
class StagedFilesTest extends TestCase
{
    private const T1 = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';

    private const T2 = 'BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB';

    public function test_токены_из_формы_без_мусора_и_повторов(): void
    {
        $form = ['staged' => [['token' => self::T1, 'name' => 'a.zip'], ['token' => self::T1], ['token' => '../../etc'], ['name' => 'без токена'], 'строка', ['token' => self::T2]]];
        $this->assertSame([self::T1, self::T2], MailBuilder::stagedTokens($form));
        $this->assertSame([], MailBuilder::stagedTokens([]));
    }

    public function test_журнал_загрузка_записывается_отзыв_нет(): void
    {
        $this->assertSame(['compose.stage', null, null], ActivityMap::describe('POST', 'mail/api/compose/stage'));
        $this->assertNull(ActivityMap::describe('DELETE', 'mail/api/compose/stage/' . self::T1));
        $this->assertArrayHasKey('compose.stage', ActivityMap::LABELS);
    }

    public function test_отправка_считает_их_в_облаке(): void
    {
        [, , $detail] = ActivityMap::describe('POST', 'mail/api/send', [], ['to' => 'a@b.ru', 'staged' => [['token' => self::T1], ['token' => self::T2]]]);
        $this->assertStringContainsString('в облаке 2', $detail);
    }
}
