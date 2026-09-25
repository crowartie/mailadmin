<?php

namespace Tests\Feature;

use App\Http\Middleware\MobileToken;
use App\Services\Mail\ImapSession;
use App\Services\Mail\MobileDevices;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Вход мобильного приложения по токену (docs/mobile-api.md): выдача, поиск, срок, отзыв, сеанс на запрос. */
class MobileDevicesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::put('app_settings', [], 60);   // настройки по умолчанию, без таблицы app_settings
    }

    /** Таблица устройств в SQLite в памяти; на сервере без pdo_sqlite такие проверки пропускаются. */
    private function withTable(): void
    {
        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('нет pdo_sqlite — проверка работы с базой пропущена');
        }
        (require base_path('database/migrations/2026_09_25_000001_create_mobile_devices.php'))->up();
    }

    public function test_токен_выдаётся_а_в_базе_только_его_хеш(): void
    {
        $this->withTable();
        [$token, $row] = MobileDevices::issue('Ivanov@Example.test', ['name' => 'Pixel 9, Android 15', 'platform' => 'android', 'app_version' => '0.1.0'], '10.0.0.5');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        $this->assertSame('ivanov@example.test', $row->user);
        $this->assertSame(hash('sha256', $token), $row->token_hash);
        $this->assertStringNotContainsString($token, json_encode(DB::table('mobile_devices')->get()));
        $this->assertSame((int) $row->id, (int) MobileDevices::find($token)->id);
    }

    public function test_чужой_или_кривой_токен_не_находится(): void
    {
        $this->withTable();
        MobileDevices::issue('a@example.test', [], '1.1.1.1');

        $this->assertNull(MobileDevices::find(null));
        $this->assertNull(MobileDevices::find('abc'));
        $this->assertNull(MobileDevices::find(str_repeat('0', 64)));
    }

    public function test_после_срока_без_запросов_вход_пропадает(): void
    {
        $this->withTable();
        [$token, $row] = MobileDevices::issue('a@example.test', [], '1.1.1.1');
        DB::table('mobile_devices')->where('id', $row->id)->update(['last_seen_at' => now()->subDays(MobileDevices::days() + 1)]);

        $this->assertNull(MobileDevices::find($token));
        $this->assertSame(0, DB::table('mobile_devices')->count(), 'просроченная запись удаляется');
    }

    public function test_отзыв_одного_и_всех_устройств_ящика(): void
    {
        $this->withTable();
        [$t1, $r1] = MobileDevices::issue('a@example.test', [], '1.1.1.1');
        [$t2] = MobileDevices::issue('a@example.test', [], '1.1.1.2');
        [$t3] = MobileDevices::issue('b@example.test', [], '1.1.1.3');

        $this->assertSame(0, MobileDevices::revoke((int) $r1->id, 'b@example.test'), 'чужое устройство не отзывается');
        $this->assertSame(1, MobileDevices::revoke((int) $r1->id, 'a@example.test'));
        $this->assertNull(MobileDevices::find($t1));

        MobileDevices::revokeUser('A@example.test');
        $this->assertNull(MobileDevices::find($t2));
        $this->assertNotNull(MobileDevices::find($t3), 'другой ящик не задет');
    }

    public function test_без_токена_приложению_отвечают_401_json(): void
    {
        $res = (new MobileToken())->handle(Request::create('/api/v1/me'), fn () => response('не должно дойти'));

        $this->assertSame(401, $res->getStatusCode());
        $this->assertSame('unauthorized', json_decode($res->getContent(), true)['code']);
    }

    public function test_сеанс_приложения_идёт_в_ящик_служебным_входом(): void
    {
        config(['areas.imap.master_user' => 'vmailadmin', 'areas.imap.master_password' => 'секрет']);
        $request = Request::create('/api/v1/folders');
        ImapSession::forApp($request, 'Ivanov@Example.test', 7);
        $imap = new ImapSession($request);

        $this->assertTrue($imap->isApp());
        $this->assertFalse($imap->isMaster(), 'в журнале это действия сотрудника, а не администратора');
        $this->assertSame('ivanov@example.test', $imap->user());
        $this->assertSame('ivanov@example.test*vmailadmin', $imap->loginName());
    }

    public function test_подпись_клиента_приложения(): void
    {
        $this->assertSame('Android · приложение 0.1.0', \App\Models\MailSession::device('MailadminApp/0.1.0 (Android 15; Pixel 9)'));
    }
}
