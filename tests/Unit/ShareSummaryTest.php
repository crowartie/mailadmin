<?php

namespace Tests\Unit;

use App\Services\Mail\ShareSummary;
use PHPUnit\Framework\TestCase;

/**
 * Сводка страницы «Общий доступ»: строка на сотрудника с итогом по папкам и уровню.
 * Ошибка здесь показала бы админу «все папки» там, где человек видит только «Входящие».
 */
class ShareSummaryTest extends TestCase
{
    private const FOLDERS = [
        ['path' => 'INBOX', 'name' => 'Входящие', 'role' => 'inbox'],
        ['path' => 'Sent', 'name' => 'Отправленные', 'role' => 'sent'],
        ['path' => 'Drafts', 'name' => 'Черновики', 'role' => 'drafts'],
        ['path' => 'Junk', 'name' => 'Спам', 'role' => 'spam'],
        ['path' => 'Trash', 'name' => 'Корзина', 'role' => 'trash'],
        ['path' => 'Закупки', 'name' => 'Закупки', 'role' => 'custom'],
    ];

    private function row(string $with, string $folder, string $level, string $owner = 'info@example.test'): array
    {
        $f = array_values(array_filter(self::FOLDERS, fn ($x) => $x['path'] === $folder))[0] ?? ['name' => $folder, 'role' => 'custom'];

        return ['owner' => $owner, 'ownerName' => 'Инфо', 'folder' => $folder, 'folderName' => $f['name'], 'role' => $f['role'], 'with' => $with, 'withName' => ucfirst(strtok($with, '@')), 'level' => $level];
    }

    private function allFor(string $with, string $level): array
    {
        return array_map(fn ($f) => $this->row($with, $f['path'], $level), self::FOLDERS);
    }

    public function test_все_папки_одним_уровнем_это_стандартная_строка(): void
    {
        $out = ShareSummary::build($this->allFor('anna@example.test', 'owner'), ['info@example.test' => self::FOLDERS]);
        $this->assertCount(1, $out);
        $p = $out[0]['people'][0];
        $this->assertSame(['all', 'owner', true, true], [$p['scope'], $p['level'], $p['standard'], $p['hasInbox']]);
        $this->assertSame('все папки · владелец', ShareSummary::label($p));
        $this->assertSame(['Входящие', 'Черновики', 'Отправленные', 'Спам', 'Корзина', 'Закупки'], array_column($out[0]['folders'], 'name'), 'системные папки впереди');
    }

    public function test_все_кроме_пары_папок_и_перечень(): void
    {
        $rows = array_filter($this->allFor('boris@example.test', 'reader'), fn ($r) => ! in_array($r['folder'], ['Trash', 'Junk'], true));
        $rows = array_merge(array_values($rows), [$this->row('vera@example.test', 'INBOX', 'reader'), $this->row('vera@example.test', 'Sent', 'reader')]);
        $out = ShareSummary::build($rows, ['info@example.test' => self::FOLDERS]);
        [$boris, $vera] = $out[0]['people'];
        $this->assertSame('except', $boris['scope']);
        $this->assertSame('все, кроме Спам, Корзина · читатель', ShareSummary::label($boris));
        $this->assertSame('some', $vera['scope']);
        $this->assertSame('Входящие, Отправленные · читатель', ShareSummary::label($vera));
        $this->assertFalse($vera['standard']);
    }

    public function test_разные_права_по_папкам_и_порядок_исключения_впереди(): void
    {
        $mixed = $this->allFor('gleb@example.test', 'owner');
        $mixed[3]['level'] = 'reader';   // Спам — только читать
        $rows = array_merge($this->allFor('anna@example.test', 'owner'), $mixed);
        $out = ShareSummary::build($rows, ['info@example.test' => self::FOLDERS]);
        $this->assertSame(['Gleb', 'Anna'], array_column($out[0]['people'], 'withName'), 'сначала тот, у кого права отличаются');
        $g = $out[0]['people'][0];
        $this->assertSame('mixed', $g['level']);
        $this->assertSame('все папки · разные', ShareSummary::label($g));
        $this->assertSame('reader', array_values(array_filter($g['perFolder'], fn ($x) => $x['folder'] === 'Junk'))[0]['level']);
    }

    public function test_ошибка_ящика_и_половина_папок_это_перечень_а_не_кроме(): void
    {
        $err = ['owner' => 'nauka@example.test', 'ownerName' => 'Наука', 'folder' => '', 'folderName' => '', 'role' => 'error', 'with' => '', 'withName' => '', 'level' => 'IMAP не отвечает'];
        $half = array_slice($this->allFor('dima@example.test', 'editor'), 0, 3);
        $out = ShareSummary::build(array_merge([$err], $half), ['info@example.test' => self::FOLDERS]);
        $this->assertSame('Инфо', $out[0]['ownerName']);
        $this->assertSame('some', $out[0]['people'][0]['scope'], 'три папки из шести — это перечень');
        $this->assertSame('IMAP не отвечает', $out[1]['error']);
        $this->assertSame([], $out[1]['people']);
    }

    public function test_без_списка_папок_папки_берутся_из_прав(): void
    {
        $out = ShareSummary::build([$this->row('vera@example.test', 'INBOX', 'reader')], []);
        $this->assertSame('all', $out[0]['people'][0]['scope'], 'известна одна папка, и она открыта');
        $this->assertSame(['Входящие'], array_column($out[0]['folders'], 'name'));
    }
}
