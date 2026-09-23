<?php

namespace Tests\Unit;

use App\Exceptions\MailException;
use App\Services\Cloud\ArrayCloudLedger;
use App\Services\Cloud\PersonalCloud;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Личное облако против имитации Nextcloud: какие запросы уходят, куда (только в папку
 * сотрудника), и как разбираются ответы. Сеть не нужна — Http::fake.
 */
class PersonalCloudTest extends TestCase
{
    private const BASE = 'https://nc.example.ru';

    private const HOME = '/remote.php/dav/files/mailcloud/%D0%9E%D0%B1%D0%BB%D0%B0%D0%BA%D0%BE%20%D1%81%D0%BE%D1%82%D1%80%D1%83%D0%B4%D0%BD%D0%B8%D0%BA%D0%BE%D0%B2/ivanov%40example.ru';

    private ArrayCloudLedger $ledger;

    /** @var array<int,array{0:string,1:string}> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledger = new ArrayCloudLedger();
        $this->sent = [];
    }

    private function cloud(string $user = 'ivanov@example.ru', float $quotaGb = 1, bool $filesHost = false): PersonalCloud
    {
        return new PersonalCloud($user, [
            'url' => self::BASE, 'login' => 'mailcloud', 'app_password' => 'secret', 'uid' => 'mailcloud',
            'personal_root' => 'Облако сотрудников', 'personal_quota_gb' => $quotaGb, 'personal_link_days' => 30,
            'files_host' => $filesHost,
        ], $this->ledger);
    }

    /** Ответ PROPFIND: [путь относительно папки сотрудника, папка?, размер] */
    private static function multistatus(array $rows, string $home = self::HOME): string
    {
        $x = '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">';
        foreach ($rows as [$rel, $dir, $size]) {
            $href = $home . ($rel === '' ? '/' : '/' . implode('/', array_map('rawurlencode', explode('/', $rel))) . ($dir ? '/' : ''));
            $x .= '<d:response><d:href>' . $href . '</d:href><d:propstat><d:prop>'
                . '<d:getlastmodified>Tue, 23 Sep 2026 09:12:00 GMT</d:getlastmodified>'
                . ($dir ? '<d:resourcetype><d:collection/></d:resourcetype><oc:size>' . $size . '</oc:size>'
                        : '<d:resourcetype/><d:getcontentlength>' . $size . '</d:getcontentlength><d:getcontenttype>video/mp4</d:getcontenttype>')
                . '<oc:fileid>42</oc:fileid></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>';
        }

        return $x . '</d:multistatus>';
    }

    /**
     * Имитация: правила [метод, конец пути или регулярка, ответ]. Первое совпавшее отвечает.
     * Все запросы запоминаются в $this->sent.
     */
    private function fake(array $rules): void
    {
        Http::fake(function (Request $r) use ($rules) {
            $path = rawurldecode((string) parse_url($r->url(), PHP_URL_PATH));
            $this->sent[] = [$r->method(), $path, $r];
            foreach ($rules as [$method, $match, $resp]) {
                $hit = $method === $r->method() && (str_starts_with($match, '~') ? preg_match($match, $path) : str_ends_with($path, $match));
                if ($hit) {
                    return is_callable($resp) ? $resp($r) : $resp;
                }
            }

            return Http::response('', 404);
        });
    }

    private function home(): string
    {
        return rawurldecode(self::HOME);
    }

    public function test_список_только_своей_папки_и_без_скрытого(): void
    {
        $this->fake([
            ['PROPFIND', '~ivanov@example.ru$~', Http::response(self::multistatus([['', true, 100]]), 207)],
            ['PROPFIND', '/Командировка', Http::response(self::multistatus([
                ['Командировка', true, 3000],
                ['Командировка/Видео', true, 2500],
                ['Командировка/.Корзина', true, 1],
                ['Командировка/Акт.pdf', false, 500],
                ['Командировка/Ведомость.xlsx', false, 200],
            ]) . '', 207)],
        ]);
        $items = $this->cloud()->list('Командировка');

        $this->assertSame(['Видео', 'Акт.pdf', 'Ведомость.xlsx'], array_column($items, 'name'));
        $this->assertTrue($items[0]['dir']);
        $this->assertSame(2500, $items[0]['size']);
        $this->assertSame(500, $items[1]['size']);
        $this->assertSame('Командировка/Акт.pdf', $items[1]['path']);
        foreach ($this->sent as [, $path]) {
            $this->assertStringStartsWith($this->home(), $path, 'запрос ушёл вне папки сотрудника');
        }
        $depth = collect($this->sent)->last()[2]->header('Depth')[0] ?? null;
        $this->assertSame('1', $depth);
    }

    public function test_чужое_в_ответе_отбрасывается(): void
    {
        $foreign = str_replace('ivanov%40example.ru', 'petrov%40example.ru', self::HOME);
        $xml = str_replace('</d:multistatus>', '', self::multistatus([['', true, 1], ['Моё.txt', false, 5]]))
            . str_replace(['<?xml version="1.0"?><d:multistatus xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">', '</d:multistatus>'], '', self::multistatus([['Чужое.txt', false, 9]], $foreign))
            . '</d:multistatus>';
        $this->fake([['PROPFIND', '~~', Http::response($xml, 207)]]);

        $this->assertSame(['Моё.txt'], array_column($this->cloud()->list(''), 'name'));
    }

    public function test_попытка_выйти_наверх_не_доходит_до_облака(): void
    {
        $this->fake([['PROPFIND', '~~', Http::response(self::multistatus([['', true, 1]]), 207)]]);
        foreach (['../petrov@example.ru', '.Корзина', 'a/../../b'] as $bad) {
            try {
                $this->cloud()->list($bad);
                $this->fail('прошёл путь ' . $bad);
            } catch (MailException) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame([], $this->sent);
    }

    public function test_у_каждого_сотрудника_своя_папка(): void
    {
        $a = $this->cloud('ivanov@example.ru')->url('Видео');
        $b = $this->cloud('petrov@example.ru')->url('Видео');
        $this->assertNotSame($a, $b);
        $this->assertStringContainsString('/ivanov%40example.ru/', $a);
        $this->assertStringContainsString('/petrov%40example.ru/', $b);
    }

    public function test_папка_создаётся_и_дубль_объясняется(): void
    {
        $this->fake([
            ['PROPFIND', '~ivanov@example.ru$~', Http::response(self::multistatus([['', true, 1]]), 207)],
            ['MKCOL', '/Отчёты', Http::response('', 201)],
            ['MKCOL', '/Видео', Http::response('', 405)],
        ]);
        $this->assertSame('Отчёты', $this->cloud()->mkdir('', 'Отчёты'));
        $this->expectException(MailException::class);
        $this->expectExceptionMessage('уже есть');
        $this->cloud()->mkdir('', 'Видео');
    }

    public function test_папку_нельзя_перенести_в_саму_себя(): void
    {
        $this->fake([]);
        $this->expectException(MailException::class);
        $this->cloud()->move('Видео', 'Видео/Архив');
    }

    public function test_переименование_тянет_за_собой_ссылки(): void
    {
        $this->ledger->saveLink('ivanov@example.ru', ['path' => 'Видео/Цех.mp4', 'share_id' => '7', 'url' => 'https://nc/s/abc', 'expires_at' => null, 'has_password' => false]);
        $this->fake([['MOVE', '/Видео', function (Request $r) {
            $this->assertStringEndsWith(rawurlencode('Видео с объекта'), $r->header('Destination')[0]);
            $this->assertSame('F', $r->header('Overwrite')[0]);

            return Http::response('', 201);
        }]]);

        $this->assertSame('Видео с объекта', $this->cloud()->rename('Видео', 'Видео с объекта'));
        $this->assertNotNull($this->ledger->link('ivanov@example.ru', 'Видео с объекта/Цех.mp4'));
        $this->assertNull($this->ledger->link('ivanov@example.ru', 'Видео/Цех.mp4'));
    }

    public function test_удаление_в_корзину_с_отзывом_ссылок(): void
    {
        $this->ledger->saveLink('ivanov@example.ru', ['path' => 'Видео/Цех.mp4', 'share_id' => '7', 'url' => 'u', 'expires_at' => null, 'has_password' => false]);
        $this->ledger->saveLink('ivanov@example.ru', ['path' => 'Другое.pdf', 'share_id' => '8', 'url' => 'u', 'expires_at' => null, 'has_password' => false]);
        $this->fake([
            ['PROPFIND', '/Видео', Http::response(self::multistatus([['Видео', true, 2500]]), 207)],
            ['PROPFIND', '~ivanov@example.ru$~', Http::response(self::multistatus([['', true, 1]]), 207)],
            ['DELETE', '/shares/7', Http::response(['ocs' => ['meta' => ['statuscode' => 200]]], 200)],
            ['MKCOL', '/.Корзина', Http::response('', 405)],
            ['MOVE', '/Видео', function (Request $r) {
                $this->assertStringContainsString(rawurlencode('.Корзина') . '/', $r->header('Destination')[0]);

                return Http::response('', 201);
            }],
        ]);

        $this->cloud()->delete('Видео');

        $this->assertNull($this->ledger->link('ivanov@example.ru', 'Видео/Цех.mp4'), 'ссылка на удалённое осталась');
        $this->assertNotNull($this->ledger->link('ivanov@example.ru', 'Другое.pdf'), 'задета чужая ссылка');
        $trash = $this->cloud()->trash();
        $this->assertCount(1, $trash);
        $this->assertSame('Видео', $trash[0]['path']);
        $this->assertTrue($trash[0]['dir']);
    }

    public function test_загрузка_не_начинается_без_места(): void
    {
        $this->fake([['PROPFIND', '~ivanov@example.ru$~', Http::response(self::multistatus([['', true, 900 * 1048576]]), 207)]]);
        $this->expectException(MailException::class);
        $this->expectExceptionMessage('Не хватает места');
        $this->cloud(quotaGb: 1)->startUpload('', 'Цех.mp4', 200 * 1048576);
    }

    public function test_загрузка_частями_от_начала_до_конца(): void
    {
        $size = PersonalCloud::CHUNK * 2 + 5;
        $chunks = [];
        $this->fake([
            ['PROPFIND', '~ivanov@example.ru$~', Http::response(self::multistatus([['', true, 0]]), 207)],
            ['PROPFIND', '/Цех.mp4', Http::response(self::multistatus([['Цех.mp4', false, 1]]), 207)],   // имя занято
            ['PROPFIND', '/Цех (2).mp4', Http::response('', 404)],
            ['MKCOL', '~/dav/uploads/mailcloud/mc[0-9a-f]{30}$~', function (Request $r) {
                $this->assertStringEndsWith(rawurlencode('Цех (2).mp4'), $r->header('Destination')[0]);

                return Http::response('', 201);
            }],
            ['PUT', '~/dav/uploads/mailcloud/mc[0-9a-f]{30}/\d+$~', function (Request $r) use (&$chunks) {
                $chunks[basename(parse_url($r->url(), PHP_URL_PATH))] = strlen($r->body());
                $this->assertSame((string) (PersonalCloud::CHUNK * 2 + 5), $r->header('OC-Total-Length')[0]);

                return Http::response('', 201);
            }],
            ['PROPFIND', '~/dav/uploads/mailcloud/mc[0-9a-f]{30}$~', function () use (&$chunks) {
                $x = '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:"><d:response><d:href>/up/</d:href></d:response>';
                foreach ($chunks as $n => $len) {
                    $x .= '<d:response><d:href>/up/' . $n . '</d:href><d:propstat><d:prop><d:getcontentlength>' . $len . '</d:getcontentlength></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>';
                }

                return Http::response($x . '</d:multistatus>', 207);
            }],
            ['MOVE', '~/\.file$~', Http::response('', 201)],
        ]);
        $c = $this->cloud();
        $up = $c->startUpload('', 'Цех.mp4', $size);
        $this->assertSame('Цех (2).mp4', $up['name']);
        $this->assertSame(3, $up['chunks']);

        // Часть не той длины не уходит в облако: иначе в файл лёг бы обрывок.
        try {
            $c->putChunk($up['id'], 1, str_repeat('x', 100));
            $this->fail('принята неполная часть');
        } catch (MailException) {
            $this->assertSame([], $chunks);
        }

        $c->putChunk($up['id'], 1, str_repeat('x', PersonalCloud::CHUNK));
        $c->putChunk($up['id'], 3, 'xxxxx');
        $this->assertSame([1, 3], $c->uploadStatus($up['id'])['have'], 'после обрыва браузер должен знать, какие части уже есть');

        try {
            $c->finishUpload($up['id']);
            $this->fail('файл собран без второй части');
        } catch (MailException $e) {
            $this->assertStringContainsString('не все части', $e->getMessage());
        }

        $c->putChunk($up['id'], 2, str_repeat('x', PersonalCloud::CHUNK));
        $c->finishUpload($up['id']);
        $this->assertSame('Цех (2).mp4', $c->recent()[0]['path']);
    }

    public function test_чужую_загрузку_продолжить_нельзя(): void
    {
        $this->ledger->saveUpload(['id' => 'mc' . str_repeat('a', 30), 'user' => 'petrov@example.ru', 'path' => 'x.mp4', 'size' => 10, 'chunk_size' => PersonalCloud::CHUNK]);
        $this->fake([]);
        $this->expectException(MailException::class);
        $this->cloud('ivanov@example.ru')->putChunk('mc' . str_repeat('a', 30), 1, str_repeat('x', 10));
    }

    public function test_размеры_частей(): void
    {
        $c = 10;
        $this->assertSame(1, PersonalCloud::chunks(0, $c));
        $this->assertSame(1, PersonalCloud::chunks(10, $c));
        $this->assertSame(2, PersonalCloud::chunks(11, $c));
        $this->assertSame(10, PersonalCloud::chunkLength(25, $c, 1));
        $this->assertSame(5, PersonalCloud::chunkLength(25, $c, 3));
        $this->assertSame(-1, PersonalCloud::chunkLength(25, $c, 4));
        $this->assertSame(-1, PersonalCloud::chunkLength(25, $c, 0));
    }

    public function test_ссылка_создаётся_с_паролем_и_меняется(): void
    {
        $form = null;
        $this->fake([
            ['PROPFIND', '/Цех.mp4', Http::response(self::multistatus([['Цех.mp4', false, 100]]), 207)],
            ['POST', '/api/v1/shares', function (Request $r) use (&$form) {
                $form = $r->data();

                return Http::response(['ocs' => ['meta' => ['statuscode' => 200], 'data' => ['id' => 15, 'url' => 'https://nc.example.ru/s/Tk3n']]], 200);
            }],
            ['PUT', '/api/v1/shares/15', Http::response(['ocs' => ['meta' => ['statuscode' => 200], 'data' => []]], 200)],
        ]);
        $c = $this->cloud();
        $l = $c->link('Цех.mp4', 30, true);

        $this->assertSame('/Облако сотрудников/ivanov@example.ru/Цех.mp4', $form['path']);
        $this->assertEquals(3, $form['shareType']);
        $this->assertEquals(1, $form['permissions'], 'ссылка должна давать только чтение');
        $this->assertSame(date('Y-m-d', strtotime('+30 days')), $form['expireDate']);
        $this->assertSame($l['password'], $form['password']);
        $this->assertMatchesRegularExpression('/^[A-Za-z]{3}\d-[A-Za-z]{2}\d-[A-Za-z]{3}\d$/', $l['password']);
        $this->assertSame('https://nc.example.ru/s/Tk3n', $l['url']);

        $l2 = $c->link('Цех.mp4', 0, false);
        $this->assertNull($l2['expires_at']);
        $this->assertFalse($l2['has_password']);
        $this->assertSame('https://nc.example.ru/s/Tk3n', $l2['url'], 'при изменении адрес ссылки не должен меняться');
    }

    public function test_на_папку_ссылку_не_дать(): void
    {
        $this->fake([['PROPFIND', '/Видео', Http::response(self::multistatus([['Видео', true, 100]]), 207)]]);
        $this->expectException(MailException::class);
        $this->cloud()->link('Видео', 30);
    }

    public function test_приложить_к_письму_берёт_готовую_ссылку(): void
    {
        $this->ledger->saveLink('ivanov@example.ru', ['path' => 'Цех.mp4', 'share_id' => '7', 'url' => 'https://nc/s/abc', 'expires_at' => date('Y-m-d', strtotime('+5 days')), 'has_password' => true]);
        $this->fake([['PROPFIND', '/Цех.mp4', Http::response(self::multistatus([['Цех.mp4', false, 2500]]), 207)]]);

        $out = $this->cloud()->attach(['Цех.mp4']);

        $this->assertSame([['name' => 'Цех.mp4', 'size' => 2500, 'url' => 'https://nc/s/abc', 'expires' => date('Y-m-d', strtotime('+5 days')), 'password' => true]], $out);
        $this->assertCount(1, $this->sent, 'новая ссылка создаваться не должна');
        $html = \App\Services\Mail\MailBuilder::linksBlock($out);
        $this->assertStringContainsString('https://nc/s/abc', $html);
        $this->assertStringContainsString('защищена паролем', $html);
    }

    public function test_скачивание_отдаёт_nginx(): void
    {
        $h = $this->cloud()->accel('Видео/Цех.mp4');
        $this->assertSame('/_nccloud/', $h['X-Accel-Redirect']);
        $this->assertStringStartsWith(self::BASE . self::HOME . '/', $h['X-Nc-Url']);
        $this->assertSame('Basic ' . base64_encode('mailcloud:secret'), $h['X-Nc-Auth']);
    }

    public function test_облако_недоступно_словами(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout'));
        $this->expectException(MailException::class);
        $this->expectExceptionMessage('Облако сейчас недоступно');
        $this->cloud()->list('');
    }

    // ── ссылки через files-хост почты ──

    public function test_ссылка_files_хоста_без_nextcloud_share(): void
    {
        $this->fake([['PROPFIND', '/Цех.mp4', Http::response(self::multistatus([['Цех.mp4', false, 100]]), 207)]]);
        $c = $this->cloud(filesHost: true);

        $l = $c->link('Цех.mp4', 30, false);

        $this->assertMatchesRegularExpression('/^f\d+$/', $l['share_id']);
        $this->assertSame('https://files.test/' . $this->ledger->files[(int) substr($l['share_id'], 1)]['token'] . '/' . rawurlencode('Цех.mp4'), $l['url']);
        $this->assertSame(['PROPFIND'], array_column($this->sent, 0), 'в Nextcloud публичная ссылка создаваться не должна');
        $f = $this->ledger->files[(int) substr($l['share_id'], 1)];
        $this->assertSame(['Цех.mp4', 100, 'video/mp4', date('Y-m-d', strtotime('+30 days')), ''], [$f['name'], $f['size'], $f['mime'], $f['expires_at'], $f['password']]);
        $this->assertSame('Цех.mp4', $c->pathOfFile((int) substr($l['share_id'], 1)));
    }

    public function test_пароль_ссылки_files_хоста_хранится_хэшем_и_снимается(): void
    {
        $this->fake([['PROPFIND', '/Цех.mp4', Http::response(self::multistatus([['Цех.mp4', false, 100]]), 207)]]);
        $c = $this->cloud(filesHost: true);
        $l = $c->link('Цех.mp4', 30, true);
        $id = (int) substr($l['share_id'], 1);

        $this->assertTrue($l['has_password']);
        $this->assertNotSame($l['password'], $this->ledger->files[$id]['password'], 'пароль в открытом виде хранить нельзя');
        $this->assertTrue(password_verify($l['password'], $this->ledger->files[$id]['password']));

        $l2 = $c->link('Цех.mp4', 7, null);
        $this->assertSame($l['url'], $l2['url'], 'при изменении адрес ссылки не должен меняться');
        $this->assertTrue($l2['has_password'], 'null — пароль оставить');
        $this->assertTrue(password_verify($l['password'], $this->ledger->files[$id]['password']));

        $l3 = $c->link('Цех.mp4', 7, false);
        $this->assertFalse($l3['has_password']);
        $this->assertSame('', $this->ledger->files[$id]['password']);
        $this->assertCount(1, $this->ledger->files);
    }

    public function test_старая_ссылка_nextcloud_заменяется_ссылкой_files_хоста(): void
    {
        $this->ledger->saveLink('ivanov@example.ru', ['path' => 'Цех.mp4', 'share_id' => '7', 'url' => 'https://nc/s/abc', 'expires_at' => date('Y-m-d', strtotime('+5 days')), 'has_password' => false]);
        $this->fake([
            ['PROPFIND', '/Цех.mp4', Http::response(self::multistatus([['Цех.mp4', false, 2500]]), 207)],
            ['DELETE', '/shares/7', Http::response(['ocs' => ['meta' => ['statuscode' => 200]]], 200)],
        ]);

        $out = $this->cloud(filesHost: true)->attach(['Цех.mp4']);

        $this->assertStringStartsWith('https://files.test/', $out[0]['url']);
        $this->assertContains(['DELETE', '/ocs/v2.php/apps/files_sharing/api/v1/shares/7'], array_map(fn ($x) => [$x[0], $x[1]], $this->sent), 'старая ссылка в Nextcloud должна быть отозвана');
        $this->assertStringStartsWith('f', $this->ledger->link('ivanov@example.ru', 'Цех.mp4')['share_id']);
    }

    public function test_переименование_меняет_имя_в_ссылке_files_хоста(): void
    {
        $this->fake([
            ['PROPFIND', '/Видео/Цех.mp4', Http::response(self::multistatus([['Видео/Цех.mp4', false, 100]]), 207)],
            ['MOVE', '/Видео/Цех.mp4', Http::response('', 201)],
        ]);
        $c = $this->cloud(filesHost: true);
        $l = $c->link('Видео/Цех.mp4', 30, false);
        $id = (int) substr($l['share_id'], 1);

        $c->rename('Видео/Цех.mp4', 'Цех, смена 2.mp4');

        $this->assertSame('Цех, смена 2.mp4', $this->ledger->files[$id]['name']);
        $moved = $this->ledger->link('ivanov@example.ru', 'Видео/Цех, смена 2.mp4');
        $this->assertStringEndsWith('/' . rawurlencode('Цех, смена 2.mp4'), $moved['url']);
        $this->assertSame('Видео/Цех, смена 2.mp4', $c->pathOfFile($id));
    }

    public function test_удаление_и_отзыв_убирают_запись_files_хоста(): void
    {
        $this->fake([
            ['PROPFIND', '/Цех.mp4', Http::response(self::multistatus([['Цех.mp4', false, 100]]), 207)],
            ['PROPFIND', '/Смета.pdf', Http::response(self::multistatus([['Смета.pdf', false, 100]]), 207)],
            ['PROPFIND', '~ivanov@example.ru$~', Http::response(self::multistatus([['', true, 1]]), 207)],
            ['MKCOL', '/.Корзина', Http::response('', 405)],
            ['MOVE', '/Цех.mp4', Http::response('', 201)],
        ]);
        $c = $this->cloud(filesHost: true);
        $a = (int) substr($c->link('Цех.mp4', 30, false)['share_id'], 1);
        $b = (int) substr($c->link('Смета.pdf', 30, false)['share_id'], 1);

        $c->delete('Цех.mp4');
        $c->unlink('Смета.pdf');

        $this->assertArrayNotHasKey($a, $this->ledger->files, 'по ссылке на удалённый файл скачать нельзя');
        $this->assertArrayNotHasKey($b, $this->ledger->files, 'отозванная ссылка должна перестать работать');
        $this->assertNotContains('DELETE', array_column($this->sent, 0), 'Nextcloud share тут ни при чём');
    }
}
