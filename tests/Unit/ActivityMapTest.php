<?php

namespace Tests\Unit;

use App\Services\Mail\ActivityMap;
use Tests\TestCase;

/**
 * Журнал действий: что считается действием, а что — фон; и что в подробности
 * попадают только числа и роли папок, а не содержимое.
 */
class ActivityMapTest extends TestCase
{
    public function test_фон_не_записывается(): void
    {
        $this->assertNull(ActivityMap::describe('GET', 'mail/api/status', ['folder' => 'INBOX']));
        $this->assertNull(ActivityMap::describe('GET', 'mail/api/suggest', ['q' => 'нос']));
        $this->assertNull(ActivityMap::describe('GET', 'mail/api/folders'));
        $this->assertNull(ActivityMap::describe('GET', 'mail/api/feedback/unread'));
        $this->assertNull(ActivityMap::describe('POST', 'mail/api/activity'));
        $this->assertNull(ActivityMap::describe('GET', 'login'));
    }

    public function test_поиск_запоминает_поле_но_не_текст(): void
    {
        [$a, $f, $d] = ActivityMap::describe('GET', 'mail/api/list/INBOX', ['q' => 'тема:ШМР участка сорбции', 'page' => 1]);
        $this->assertSame('search', $a);
        $this->assertSame('inbox', $f);
        $this->assertSame('тема', $d);
        $this->assertStringNotContainsString('ШМР', $d);

        [$a, , $d] = ActivityMap::describe('GET', 'mail/api/list/Sent', ['q' => 'договор', 'everywhere' => 1]);
        $this->assertSame('search', $a);
        $this->assertSame('текст, везде', $d);

        [$a, $f, $d] = ActivityMap::describe('GET', 'mail/api/list/INBOX%2F12%20%D0%90%D0%9E', ['page' => 3]);
        $this->assertSame('list', $a);
        $this->assertSame('inbox-sub', $f);
        $this->assertSame('стр. 3', $d);
    }

    public function test_письмо_и_вложения(): void
    {
        $this->assertSame(['open', 'inbox', null], ActivityMap::describe('GET', 'mail/api/message/INBOX/1141'));
        $this->assertSame(['attachment', 'sent', null], ActivityMap::describe('GET', 'mail/api/message/Sent/12/attachment/0'));
        // Встроенная картинка письма грузится браузером сама — отдельное действие, не скачивание.
        $this->assertSame(['image', 'inbox', null], ActivityMap::describe('GET', 'mail/api/message/INBOX/12/attachment/3', ['inline' => '1']));
        $this->assertSame(['attachment.preview', 'own', null], ActivityMap::describe('GET', 'mail/api/message/Проекты%2F2026/7/attachment/2/preview.pdf'));
        $this->assertSame(['attachment.mail', 'inbox', null], ActivityMap::describe('GET', 'mail/api/message/INBOX/7/attachment/1/message/0'));
        $this->assertSame(['thread', 'inbox', null], ActivityMap::describe('GET', 'mail/api/message/INBOX/7/thread'));
    }

    public function test_действия_над_письмами_только_числа(): void
    {
        [$a, $f, $d] = ActivityMap::describe('POST', 'mail/api/action', [], ['folder' => 'INBOX', 'uids' => [1, 2, 3], 'op' => 'move', 'target' => 'INBOX/Поставщики']);
        $this->assertSame('msg.move', $a);
        $this->assertSame('inbox', $f);
        $this->assertSame('писем 3 → inbox-sub', $d);
        $this->assertStringNotContainsString('Поставщики', $d);

        $this->assertSame(['msg.delete', 'trash', 'писем 1'], ActivityMap::describe('POST', 'mail/api/action', [], ['folder' => 'Trash', 'uids' => [9], 'op' => 'delete']));
    }

    public function test_отправка_считает_адресатов_и_файлы(): void
    {
        [$a, , $d] = ActivityMap::describe('POST', 'mail/api/send', [], ['to' => 'a@x.ru, Б <b@x.ru>', 'cc' => 'c@x.ru', 'subject' => 'секрет', 'html' => '<p>тайна</p>', 'cloud' => ['t1']], 2);
        $this->assertSame('send', $a);
        $this->assertSame('адресатов 3, файлов 2, в облаке 1', $d);
        $this->assertStringNotContainsString('x.ru', $d);
        $this->assertStringNotContainsString('секрет', $d);
    }

    public function test_папки_настройки_страницы(): void
    {
        $this->assertSame(['folder.create', 'inbox', 'вложенная'], ActivityMap::describe('POST', 'mail/api/folders', [], ['name' => 'Новая', 'parent' => 'INBOX']));
        $this->assertSame(['folder.rename', 'own', null], ActivityMap::describe('PATCH', 'mail/api/folders/Старая', [], ['name' => 'Новая']));
        $this->assertSame(['folder.delete', 'sent-sub', null], ActivityMap::describe('DELETE', 'mail/api/folders/Sent%2FАрсентьев'));
        $this->assertSame(['settings.save', null, 'signature, signature_reply'], ActivityMap::describe('PUT', 'mail/api/settings', [], ['signature' => '<img src="data:…">', 'signature_reply' => true]));
        $this->assertSame(['rules.save', null, 'правил 2'], ActivityMap::describe('PUT', 'mail/api/rules', [], ['rules' => [[], []]]));
        $this->assertSame(['page.folder', 'junk', null], ActivityMap::describe('GET', 'mail/folder/Junk', ['q' => 'x']));
        $this->assertSame(['page.settings', null, 'rules'], ActivityMap::describe('GET', 'mail/settings/rules'));
        $this->assertSame(['page.mail', null, null], ActivityMap::describe('GET', 'mail'));
        $this->assertSame(['file.renew', null, null], ActivityMap::describe('POST', 'mail/api/files/abc/renew'));
        // Выдача и правка ссылки облака — одно действие с разной подробностью; приложение ходит под /api/v1.
        $this->assertSame(['cloud.link', null, null], ActivityMap::describe('POST', 'mail/api/cloud/link', [], ['path' => 'Цех.mp4', 'days' => 30]));
        $this->assertSame(['cloud.link', null, 'изменил с паролем'], ActivityMap::describe('PUT', 'mail/api/cloud/link', [], ['path' => 'Цех.mp4', 'days' => 365, 'password' => true]));
        $this->assertSame(['cloud.link', null, 'изменил'], ActivityMap::describe('PUT', 'mail/api/cloud/link', [], ['path' => 'Цех.mp4', 'days' => 0]));
    }

    public function test_роли_папок(): void
    {
        $this->assertSame('inbox', ActivityMap::role('INBOX'));
        $this->assertSame('inbox-sub', ActivityMap::role('INBOX/12 АО/Оплата'));
        $this->assertSame('sent', ActivityMap::role('Sent Items'));
        $this->assertSame('trash', ActivityMap::role('Deleted Items'));
        $this->assertSame('own', ActivityMap::role('Проекты/2026'));
        $this->assertSame('shared', ActivityMap::role('Общие/info'));
        $this->assertNull(ActivityMap::role(''));
    }

    public function test_у_каждого_действия_есть_подпись(): void
    {
        foreach (['open', 'send', 'msg.move', 'folder.create', 'compose.open', 'menu.open', 'search', 'page.folder', 'error'] as $a) {
            $this->assertArrayHasKey($a, ActivityMap::LABELS, $a);
        }
    }
}
