<?php

namespace Tests\Unit;

use App\Models\Webmail\PushSubscription;
use App\Services\Mail\PushNotifier;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Push-уведомления о новых письмах: текст уведомления по письмам, подписки устройств (одна на адрес,
 * переход к другому сотруднику), событие от Dovecot — только по токену и только если есть устройства,
 * отправка подменяется. База — sqlite в памяти.
 */
class PushNotifierTest extends TestCase
{
    private function needDb(): void
    {
        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('нет pdo_sqlite — тесты с таблицей идут на машине разработчика');
        }
        if (! Schema::hasTable('webmail_push_subscriptions')) {
            (require base_path('database/migrations/2026_09_30_000001_create_webmail_push_subscriptions.php'))->up();
        }
        PushSubscription::query()->delete();
    }

    public function test_текст_уведомления(): void
    {
        $one = PushNotifier::payload([['uid' => 42, 'from' => 'Иванов Иван <ivan@example.test>', 'subject' => 'Счёт 12']], 3);
        $this->assertSame(['title' => 'Иванов Иван', 'body' => 'Счёт 12', 'tag' => 'mail-42', 'url' => '/mail?uid=42', 'unseen' => 3], $one);

        // Заголовки в MIME-кодировке, как их отдаёт Dovecot: на телефоне показывалось «=?UTF-8?B?…».
        $enc = PushNotifier::payload([['uid' => 8, 'from' => '=?UTF-8?B?0JrQvtCy0Y/Qt9C40L0g0KHRgtC10L/QsNC9?= <k@example.test>', 'subject' => '=?utf-8?Q?=D0=A1=D1=87=D1=91=D1=82_=E2=84=96_5?=']]);
        $this->assertSame('Ковязин Степан', $enc['title']);
        $this->assertSame('Счёт № 5', $enc['body']);
        $this->assertSame('Ковязин Степан, a@x', PushNotifier::payload([['uid' => 1, 'from' => '=?UTF-8?B?0JrQvtCy0Y/Qt9C40L0g0KHRgtC10L/QsNC9?= <k@x>'], ['uid' => 2, 'from' => 'a@x']])['body']);

        $bare = PushNotifier::payload([['uid' => 7, 'from' => 'ivan@example.test', 'subject' => '']]);
        $this->assertSame('ivan@example.test', $bare['title']);
        $this->assertSame('(без темы)', $bare['body']);
        $this->assertArrayNotHasKey('unseen', $bare);

        $many = PushNotifier::payload([
            ['uid' => 1, 'from' => '"Петров" <p@x>', 'subject' => 'а'], ['uid' => 2, 'from' => 'Сидоров <s@x>', 'subject' => 'б'],
            ['uid' => 3, 'from' => 'Петров <p@x>', 'subject' => 'в'], ['uid' => 4, 'from' => 'Кузнецов <k@x>', 'subject' => 'г'], ['uid' => 5, 'from' => 'Орлов <o@x>', 'subject' => 'д'],
        ]);
        $this->assertSame('5 новых писем', $many['title']);
        $this->assertSame('Петров, Сидоров, Кузнецов и ещё 1', $many['body']);
        $this->assertSame('/mail', $many['url']);
        $this->assertSame('2 новых письма', PushNotifier::payload([['uid' => 1], ['uid' => 2]])['title']);
        $this->assertSame('21 новое письмо', PushNotifier::payload(array_fill(0, 21, ['uid' => 1]))['title']);
        $this->assertSame('Новое письмо', PushNotifier::payload([['uid' => 9]])['title']);
    }

    public function test_подписки_устройств(): void
    {
        $this->needDb();
        $a = PushNotifier::subscribe('Anna@example.test', 'https://push.example/abc', 'p256', 'auth1', 'iPhone · Safari · приложение');
        $this->assertSame('anna@example.test', $a->user);
        PushNotifier::subscribe('anna@example.test', 'https://push.example/abc', 'p256-new', 'auth2');   // тот же адрес — обновление
        $this->assertSame(1, PushSubscription::query()->count());
        $this->assertSame('p256-new', PushSubscription::query()->first()->p256dh);
        PushNotifier::subscribe('anna@example.test', 'https://push.example/second', 'k', 'a');
        $this->assertCount(2, PushNotifier::devices('anna@example.test'));

        // В том же браузере вошёл другой сотрудник — подписка переходит к нему.
        PushNotifier::subscribe('boris@example.test', 'https://push.example/abc', 'k', 'a');
        $this->assertCount(1, PushNotifier::devices('anna@example.test'));
        $this->assertCount(1, PushNotifier::devices('boris@example.test'));

        $this->assertSame(1, PushNotifier::unsubscribe('anna@example.test', 'https://push.example/second'));
        $this->assertSame(0, PushNotifier::unsubscribe('anna@example.test', 'https://push.example/abc'), 'чужую подписку не снять');

        try {
            PushNotifier::subscribe('anna@example.test', 'http://plain', 'k', 'a');
            $this->fail('адрес без https не годится');
        } catch (\App\Exceptions\MailException $e) {
            $this->assertStringContainsString('неполная', $e->getMessage());
        }
    }

    public function test_без_ключей_служба_выключена_и_ничего_не_шлёт(): void
    {
        $this->needDb();
        config(['areas.push.public' => '', 'areas.push.private' => '']);
        $this->assertFalse(PushNotifier::enabled());
        PushNotifier::subscribe('anna@example.test', 'https://push.example/abc', 'k', 'a');
        $n = (new PushNotifier)->notifyNewMail('anna@example.test', [['uid' => 1, 'from' => 'x@y', 'subject' => 's']]);
        $this->assertSame(0, $n);
    }

    public function test_событие_от_dovecot_по_токену(): void
    {
        $this->needDb();
        config(['areas.admin_port' => 8080, 'areas.mail_port' => 80, 'areas.push.event_token' => 'секрет-токен']);

        $this->postJson('http://localhost/mail/api/push/event', ['user' => 'anna@example.test', 'messages' => [['uid' => 1]]])->assertStatus(404);
        $this->postJson('http://localhost/mail/api/push/event', ['user' => 'anna@example.test', 'messages' => [['uid' => 1]]], ['X-Push-Token' => 'не тот'])->assertStatus(404);
        $this->postJson('http://localhost/mail/api/push/event', ['user' => '', 'messages' => []], ['X-Push-Token' => 'секрет-токен'])->assertStatus(422);
        // Устройств нет — отвечаем сразу, в ящик не ходим и отправку не зовём.
        $this->postJson('http://localhost/mail/api/push/event', ['user' => 'anna@example.test', 'messages' => [['uid' => 1]]], ['X-Push-Token' => 'секрет-токен'])
            ->assertOk()->assertJson(['ok' => true, 'sent' => 0]);

        PushNotifier::subscribe('anna@example.test', 'https://push.example/abc', 'k', 'a');
        $this->mock(PushNotifier::class, function ($m) {
            $m->shouldReceive('notifyNewMail')->once()
                ->withArgs(fn ($user, $messages, $unseen) => $user === 'anna@example.test' && $messages[0]['subject'] === 'Счёт' && ($unseen === null || is_int($unseen)))
                ->andReturn(1);
        });
        $this->postJson('http://localhost/mail/api/push/event', ['user' => 'Anna@example.test', 'messages' => [['uid' => 5, 'from' => 'x@y', 'subject' => 'Счёт']]], ['X-Push-Token' => 'секрет-токен'])
            ->assertOk()->assertJson(['ok' => true, 'sent' => 1]);
    }

    public function test_имя_отправителя(): void
    {
        $this->assertSame('Иванов Иван', PushNotifier::fromName('"Иванов Иван" <i@x>'));
        $this->assertSame('Иванов', PushNotifier::fromName('Иванов <i@x>'));
        $this->assertSame('i@x', PushNotifier::fromName('<i@x>'));
        $this->assertSame('i@x', PushNotifier::fromName('i@x'));
    }
}
