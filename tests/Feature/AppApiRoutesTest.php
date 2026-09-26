<?php

namespace Tests\Feature;

use App\Http\Middleware\MobileToken;
use App\Services\Cloud\ArrayCloudLedger;
use App\Services\Cloud\CloudLedger;
use App\Services\Dav\DavException;
use App\Services\Dav\DavStore;
use App\Services\Mail\ImapSession;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Маршруты, добавленные или уточнённые для приложения (docs/mobile-api.md, «Справочник»):
 * отписка от чужого календаря, правка ссылки облака, загрузка контактов в выбранную книгу.
 *
 * Вход по токену проверяет MobileDevicesTest; здесь сеанс приложения подставляется в контейнер
 * напрямую, а хранилища подменяются: живого DAV и Nextcloud у тестов нет.
 */
class AppApiRoutesTest extends TestCase
{
    private const USER = 'ivanov@example.test';

    protected function setUp(): void
    {
        parent::setUp();
        // Не зависим от .env машины, где идут тесты: порт 80 — веб-почта, служебный вход задан.
        config(['areas.admin_port' => 8080, 'areas.mail_port' => 80, 'areas.imap.master_user' => 'vmailadmin', 'areas.imap.master_password' => 'секрет']);
        Cache::put('app_settings', [], 60);
        $this->withoutMiddleware(MobileToken::class);
        $request = Request::create('/api/v1');
        ImapSession::forApp($request, self::USER, 7);
        $this->app->instance(ImapSession::class, new ImapSession($request));
    }

    // ── календарь ──

    public function test_отписка_от_чужого_календаря(): void
    {
        $this->mock(DavStore::class, fn ($m) => $m->shouldReceive('unsubscribe')->once()->with(self::USER, 'petrov-work'));

        $this->deleteJson('http://localhost/api/v1/calendars/petrov-work/subscription')->assertOk()->assertJson(['ok' => true]);
    }

    public function test_от_своего_календаря_не_отписаться(): void
    {
        $this->mock(DavStore::class, fn ($m) => $m->shouldReceive('unsubscribe')->once()->andThrow(new DavException('Это не чужой календарь', 403)));

        $this->deleteJson('http://localhost/api/v1/calendars/personal/subscription')->assertStatus(403)->assertJson(['message' => 'Это не чужой календарь']);
    }

    // ── контакты ──

    public function test_загрузка_контактов_в_выбранную_книгу_отвечает_числом(): void
    {
        $vcf = "BEGIN:VCARD\r\nVERSION:3.0\r\nFN:Пётр\r\nEMAIL:p@example.test\r\nEND:VCARD\r\n";
        $this->mock(DavStore::class, fn ($m) => $m->shouldReceive('importCards')->once()->with(self::USER, 'work', $vcf)->andReturn(['imported' => 1, 'skipped' => 0]));

        $this->post('http://localhost/api/v1/contacts/import', ['book' => 'work', 'file' => UploadedFile::fake()->createWithContent('contacts.vcf', $vcf)], ['Accept' => 'application/json'])
            ->assertOk()->assertJson(['imported' => 1, 'skipped' => 0]);
    }

    public function test_без_книги_контакты_идут_в_личную(): void
    {
        $this->mock(DavStore::class, fn ($m) => $m->shouldReceive('importCards')->once()->with(self::USER, DavStore::PERSONAL, \Mockery::any())->andReturn(['imported' => 0, 'skipped' => 2]));

        $this->post('http://localhost/api/v1/contacts/import', ['file' => UploadedFile::fake()->createWithContent('contacts.vcf', 'BEGIN:VCARD')], ['Accept' => 'application/json'])
            ->assertOk()->assertJson(['imported' => 0, 'skipped' => 2]);
    }

    // ── облако: правка ссылки ──

    /** Облако «подключено» настройками из кэша, учёт — в памяти, Nextcloud — Http::fake. */
    private function cloud(): ArrayCloudLedger
    {
        Cache::put('app_settings', ['cloud' => [
            'personal_enabled' => true, 'enabled' => true, 'url' => 'https://nc.example.test', 'login' => 'mailcloud', 'uid' => 'mailcloud',
            'app_password' => Crypt::encryptString('secret'), 'personal_root' => 'Облако сотрудников',
        ]], 60);
        $ledger = new ArrayCloudLedger();
        $this->app->instance(CloudLedger::class, $ledger);

        return $ledger;
    }

    private static function propfind(string $rel): string
    {
        $href = '/remote.php/dav/files/mailcloud/' . rawurlencode('Облако сотрудников') . '/' . rawurlencode(self::USER) . '/' . rawurlencode($rel);

        return '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:response><d:href>' . $href . '</d:href>'
            . '<d:propstat><d:prop><d:getlastmodified>Tue, 23 Sep 2026 09:12:00 GMT</d:getlastmodified><d:resourcetype/><d:getcontentlength>100</d:getcontentlength>'
            . '<d:getcontenttype>video/mp4</d:getcontenttype><oc:fileid>42</oc:fileid></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response></d:multistatus>';
    }

    public function test_правка_ссылки_облака_меняет_срок_и_не_меняет_адрес(): void
    {
        $ledger = $this->cloud();
        $ledger->saveLink(self::USER, ['path' => 'Цех.mp4', 'share_id' => '15', 'url' => 'https://nc.example.test/s/Tk3n', 'expires_at' => date('Y-m-d', strtotime('+5 days')), 'has_password' => false]);
        $form = null;
        Http::fake(function (ClientRequest $r) use (&$form) {
            $path = rawurldecode((string) parse_url($r->url(), PHP_URL_PATH));
            if ($r->method() === 'PROPFIND' && str_ends_with($path, '/Цех.mp4')) {
                return Http::response(self::propfind('Цех.mp4'), 207);
            }
            if ($r->method() === 'PUT' && str_ends_with($path, '/shares/15')) {
                $form = $r->data();

                return Http::response(['ocs' => ['meta' => ['statuscode' => 200], 'data' => []]], 200);
            }

            return Http::response('', 404);
        });

        $r = $this->putJson('http://localhost/api/v1/cloud/link', ['path' => 'Цех.mp4', 'days' => 365])->assertOk();

        $this->assertSame(date('Y-m-d', strtotime('+365 days')), $form['expireDate'] ?? null, 'в Nextcloud ушёл новый срок');
        $this->assertArrayNotHasKey('password', $form, 'пароль не передан — не трогаем');
        $r->assertJsonPath('link.url', 'https://nc.example.test/s/Tk3n')
            ->assertJsonPath('link.expires_at', date('Y-m-d', strtotime('+365 days')))
            ->assertJsonPath('link.has_password', false);
    }

    public function test_правка_ссылки_которой_нет_отвечает_404_и_не_создаёт_новую(): void
    {
        $this->cloud();
        Http::fake();

        $this->putJson('http://localhost/api/v1/cloud/link', ['path' => 'Нет.pdf', 'days' => 30])
            ->assertNotFound()->assertJsonPath('message', 'Ссылки на этот файл нет — сначала создайте её');
        Http::assertNothingSent();
    }

    public function test_у_правки_ссылки_срок_обязателен(): void
    {
        $this->cloud();

        $this->putJson('http://localhost/api/v1/cloud/link', ['path' => 'Цех.mp4'])->assertStatus(422);
    }
}
