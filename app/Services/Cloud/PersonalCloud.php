<?php

namespace App\Services\Cloud;

use App\Exceptions\MailException;
use App\Models\AppSetting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Личное облако сотрудника: его папка в Nextcloud.
 *
 * Файлы лежат у одной служебной учётки Nextcloud (та же, что подключена в «Настройки →
 * Файлы и облако»), в папке «<корень>/<адрес ящика>». Сотрудник в Nextcloud не заходит:
 * всё идёт через веб-почту, и каждый путь из браузера проходит PersonalPath — выйти из своей
 * папки нельзя. Общего доступа между сотрудниками нет; наружу — только публичная ссылка
 * на один файл, со сроком и по желанию с паролем. Если настроен files-хост почты, ссылка —
 * его (https://files.<домен>/<токен>/<имя>, как у больших вложений: получатель не видит
 * облака, файл по щелчку сразу скачивается); иначе — публичная ссылка Nextcloud (OCS share).
 *
 * Большие файлы загружаются частями (WebDAV chunking v2 Nextcloud): часть за частью
 * в папку загрузки, потом Nextcloud сам собирает файл. После обрыва связи браузер спрашивает,
 * какие части уже есть, и продолжает с них.
 */
class PersonalCloud
{
    /** Размер части загрузки. Nextcloud требует не меньше 5 МБ (кроме последней). */
    public const CHUNK = 10 * 1048576;

    private const PROPS = '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:prop>'
        . '<d:getlastmodified/><d:getcontentlength/><d:getcontenttype/><d:resourcetype/><d:getetag/><oc:size/><oc:fileid/>'
        . '</d:prop></d:propfind>';

    private string $userDir;

    private bool $rootReady = false;

    /**
     * @param  array{url:string,login:string,app_password:string,uid?:string,personal_root?:string,personal_quota_gb?:int|float,personal_link_days?:int}  $settings
     */
    public function __construct(private readonly string $user, private readonly array $settings, private readonly CloudLedger $ledger)
    {
        $this->userDir = PersonalPath::userDir($user);
    }

    /** Облако для вошедшего сотрудника с настройками из админки. */
    public static function forUser(string $user): self
    {
        if (! self::enabled()) {
            throw MailException::unsupported('Облако не подключено. Обратитесь к администратору.');
        }

        return new self($user, self::settings() + ['files_host' => LocalFiles::enabled()], new DbCloudLedger());
    }

    public static function settings(): array
    {
        $s = Nextcloud::settings();
        $s += ['personal_enabled' => false, 'personal_root' => 'Облако сотрудников', 'personal_quota_gb' => 50, 'personal_link_days' => 30, 'personal_trash_days' => 30];
        if (empty($s['uid']) && filled($s['login'] ?? null) && filled($s['app_password'] ?? null)) {
            // Для входа по WebDAV нужен id пользователя Nextcloud, а Login Flow отдаёт логин —
            // у учёток из LDAP они разные. Узнаём один раз и запоминаем.
            try {
                $r = Http::withBasicAuth($s['login'], $s['app_password'])->withHeaders(['OCS-APIRequest' => 'true', 'Accept' => 'application/json'])
                    ->timeout(15)->get(rtrim($s['url'], '/') . '/ocs/v2.php/cloud/user', ['format' => 'json']);
                if ($r->ok() && filled($r['ocs']['data']['id'] ?? null)) {
                    $s['uid'] = (string) $r['ocs']['data']['id'];
                    AppSetting::put(Nextcloud::GROUP, ['uid' => $s['uid']]);
                }
            } catch (\Throwable) {
            }
        }

        return $s;
    }

    public static function enabled(): bool
    {
        $s = Nextcloud::settings();

        return ! empty($s['personal_enabled']) && filled($s['url'] ?? null) && filled($s['login'] ?? null) && filled($s['app_password'] ?? null);
    }

    // ── адреса ────────────────────────────────────────────────────────────

    private function base(): string
    {
        return rtrim($this->settings['url'], '/');
    }

    private function uid(): string
    {
        return (string) ($this->settings['uid'] ?? '') ?: (string) $this->settings['login'];
    }

    private function root(): string
    {
        return trim((string) ($this->settings['personal_root'] ?? 'Облако сотрудников'), '/') ?: 'Облако сотрудников';
    }

    /** Путь папки сотрудника относительно домашней папки служебной учётки. */
    public function home(): string
    {
        return $this->root() . '/' . $this->userDir;
    }

    /** Полный адрес WebDAV для пути внутри папки сотрудника. Пути извне — только через PersonalPath. */
    public function url(string $rel): string
    {
        return $this->base() . '/remote.php/dav/files/' . rawurlencode($this->uid()) . '/' . PersonalPath::encode(PersonalPath::join($this->home(), $rel));
    }

    private function uploadUrl(string $id, string $part = ''): string
    {
        return $this->base() . '/remote.php/dav/uploads/' . rawurlencode($this->uid()) . '/' . rawurlencode($id) . ($part !== '' ? '/' . rawurlencode($part) : '');
    }

    private function http(): PendingRequest
    {
        return Http::withBasicAuth((string) $this->settings['login'], (string) $this->settings['app_password'])
            ->withHeaders(['OCS-APIRequest' => 'true'])->timeout(60);
    }

    /** Любой запрос к Nextcloud: нет связи — ошибка словами, а не трасса Guzzle. */
    private function send(string $method, string $url, array $headers = [], mixed $body = null, ?string $type = null, int $timeout = 60): Response
    {
        try {
            $req = $this->http()->timeout($timeout)->withHeaders($headers);
            if ($body !== null) {
                $req = $req->withBody($body, $type ?? 'application/octet-stream');
            }

            return $req->send($method, $url);
        } catch (ConnectionException $e) {
            throw MailException::upstream('Облако сейчас недоступно — попробуйте через минуту');
        }
    }

    private function ocs(string $method, string $path, array $form = []): Response
    {
        try {
            $req = $this->http()->withHeaders(['Accept' => 'application/json'])->asForm();
            $url = $this->base() . '/ocs/v2.php/apps/files_sharing/api/v1/' . $path . '?format=json';

            return match ($method) {
                'POST' => $req->post($url, $form),
                'PUT' => $req->put($url, $form),
                'DELETE' => $req->delete($url),
                default => $req->get($url, $form),
            };
        } catch (ConnectionException) {
            throw MailException::upstream('Облако сейчас недоступно — попробуйте через минуту');
        }
    }

    /** Корень и папка сотрудника создаются при первом обращении. */
    private function ensureHome(): void
    {
        if ($this->rootReady) {
            return;
        }
        $r = $this->send('PROPFIND', $this->url(''), ['Depth' => '0']);
        if ($r->status() === 404) {
            $acc = '';
            foreach ([$this->root(), $this->userDir] as $part) {
                $acc = PersonalPath::join($acc, $part);
                $m = $this->send('MKCOL', $this->base() . '/remote.php/dav/files/' . rawurlencode($this->uid()) . '/' . PersonalPath::encode($acc));
                if (! in_array($m->status(), [201, 405], true)) {
                    throw MailException::upstream('Облако не создало папку сотрудника (ответ ' . $m->status() . ')');
                }
            }
        } elseif ($r->status() === 401) {
            throw MailException::upstream('Облако не принимает пароль служебной учётки — сообщите администратору');
        } elseif ($r->status() !== 207) {
            throw MailException::upstream('Облако ответило ' . $r->status());
        }
        $this->rootReady = true;
    }

    // ── чтение ────────────────────────────────────────────────────────────

    /**
     * Содержимое папки: сначала папки, потом файлы, по имени. Скрытые (с точки) — не показываются.
     *
     * @return array<int,array{name:string,path:string,dir:bool,size:int,modified:?string,type:string,fileid:?string}>
     */
    public function list(string $rel): array
    {
        $rel = PersonalPath::clean($rel);
        $this->ensureHome();
        $r = $this->send('PROPFIND', $this->url($rel), ['Depth' => '1'], self::PROPS, 'application/xml');
        if ($r->status() === 404) {
            throw MailException::notFound('Папки нет — возможно, её переименовали или удалили');
        }
        if ($r->status() !== 207) {
            throw MailException::upstream('Облако ответило ' . $r->status());
        }
        $items = [];
        foreach ($this->parse($r->body()) as $it) {
            if ($it['path'] === $rel || $it['path'] === '' || str_starts_with($it['name'], '.')) {
                continue;
            }
            $items[] = $it;
        }
        $coll = class_exists(\Collator::class) ? new \Collator('ru_RU') : null;
        usort($items, function ($a, $b) use ($coll) {
            if ($a['dir'] !== $b['dir']) {
                return $a['dir'] ? -1 : 1;
            }

            return $coll ? $coll->compare($a['name'], $b['name']) : strcasecmp($a['name'], $b['name']);
        });

        return $items;
    }

    /** Один файл или папка; нет — null. */
    public function stat(string $rel): ?array
    {
        $rel = PersonalPath::clean($rel);
        $r = $this->send('PROPFIND', $this->url($rel), ['Depth' => '0'], self::PROPS, 'application/xml');
        if ($r->status() === 404) {
            return null;
        }
        if ($r->status() !== 207) {
            throw MailException::upstream('Облако ответило ' . $r->status());
        }

        return $this->parse($r->body())[0] ?? null;
    }

    /** Сколько занято в папке сотрудника, байт (вместе с корзиной). */
    public function usage(): int
    {
        $this->ensureHome();
        $st = $this->stat('');

        return (int) ($st['size'] ?? 0);
    }

    public function quota(): int
    {
        return (int) round(max(0.1, (float) ($this->settings['personal_quota_gb'] ?? 50)) * 1073741824);
    }

    /**
     * Разбор ответа PROPFIND. Путь элемента считается от папки сотрудника; всё, что вне её,
     * отбрасывается — даже если сервер вдруг вернул чужое.
     *
     * @return array<int,array>
     */
    public function parse(string $xml): array
    {
        $doc = new \DOMDocument();
        if (! @$doc->loadXML($xml)) {
            return [];
        }
        $xp = new \DOMXPath($doc);
        $xp->registerNamespace('d', 'DAV:');
        $xp->registerNamespace('oc', 'http://owncloud.org/ns');
        $prefix = rawurldecode((string) parse_url($this->url(''), PHP_URL_PATH));
        $prefix = rtrim($prefix, '/') . '/';
        $out = [];
        foreach ($xp->query('/d:multistatus/d:response') as $resp) {
            $href = rawurldecode(trim((string) $xp->evaluate('string(d:href)', $resp)));
            $path = rtrim($href, '/') . '/';
            if (! str_starts_with($path, $prefix) && rtrim($path, '/') . '/' !== $prefix) {
                continue;
            }
            $rel = trim(substr($path, strlen($prefix)), '/');
            $ok = null;
            foreach ($xp->query('d:propstat', $resp) as $ps) {
                if (str_contains((string) $xp->evaluate('string(d:status)', $ps), ' 200 ')) {
                    $ok = $ps;
                }
            }
            if ($ok === null) {
                continue;
            }
            $dir = $xp->query('d:prop/d:resourcetype/d:collection', $ok)->length > 0;
            $size = $dir ? (int) $xp->evaluate('string(d:prop/oc:size)', $ok) : (int) $xp->evaluate('string(d:prop/d:getcontentlength)', $ok);
            $mod = trim((string) $xp->evaluate('string(d:prop/d:getlastmodified)', $ok));
            $out[] = [
                'name' => $rel === '' ? '' : PersonalPath::base($rel),
                'path' => $rel,
                'dir' => $dir,
                'size' => $size,
                'modified' => $mod !== '' && ($t = strtotime($mod)) ? date(DATE_ATOM, $t) : null,
                'type' => $dir ? 'folder' : (trim((string) $xp->evaluate('string(d:prop/d:getcontenttype)', $ok)) ?: 'application/octet-stream'),
                'fileid' => trim((string) $xp->evaluate('string(d:prop/oc:fileid)', $ok)) ?: null,
            ];
        }

        return $out;
    }

    // ── папки и файлы ─────────────────────────────────────────────────────

    public function mkdir(string $parent, string $name): string
    {
        $parent = PersonalPath::clean($parent);
        $path = PersonalPath::join($parent, PersonalPath::name($name));
        $this->ensureHome();
        $r = $this->send('MKCOL', $this->url($path));

        return match ($r->status()) {
            201 => $path,
            405 => throw MailException::invalid('Папка «' . PersonalPath::base($path) . '» уже есть'),
            409 => throw MailException::notFound('Родительской папки нет — обновите страницу'),
            default => throw MailException::upstream('Облако не создало папку (ответ ' . $r->status() . ')'),
        };
    }

    public function rename(string $rel, string $newName): string
    {
        $rel = PersonalPath::clean($rel);
        if ($rel === '') {
            throw MailException::invalid('Корневую папку переименовать нельзя');
        }

        return $this->moveTo($rel, PersonalPath::join(PersonalPath::parent($rel), PersonalPath::name($newName)));
    }

    public function move(string $rel, string $toDir): string
    {
        $rel = PersonalPath::clean($rel);
        $toDir = PersonalPath::clean($toDir);
        if ($rel === '') {
            throw MailException::invalid('Корневую папку перенести нельзя');
        }
        if (PersonalPath::within($toDir, $rel)) {
            throw MailException::invalid('Папку нельзя перенести внутрь неё самой');
        }

        return $this->moveTo($rel, PersonalPath::join($toDir, PersonalPath::base($rel)));
    }

    private function moveTo(string $from, string $to): string
    {
        if ($from === $to) {
            return $to;
        }
        $r = $this->send('MOVE', $this->url($from), ['Destination' => $this->url($to), 'Overwrite' => 'F']);
        match (true) {
            in_array($r->status(), [201, 204], true) => null,
            $r->status() === 412 => throw MailException::invalid('Там уже есть «' . PersonalPath::base($to) . '»'),
            $r->status() === 404 => throw MailException::notFound('Файла или папки уже нет'),
            $r->status() === 409 => throw MailException::notFound('Папки назначения нет'),
            default => throw MailException::upstream('Облако не перенесло (ответ ' . $r->status() . ')'),
        };
        // Ссылка в Nextcloud привязана к файлу и переезжает с ним сама; поправить надо свой учёт.
        $this->ledger->movePrefix($this->user, $from, $to);
        // У ссылки files-хоста в адресе и при скачивании — имя файла: переименовали — меняем и там.
        foreach ($this->ledger->linksUnder($this->user, $to) as $l) {
            if (self::fileId($l['share_id']) !== null) {
                $url = $this->ledger->updateFile(self::fileId($l['share_id']), ['name' => PersonalPath::base($l['path'])]);
                if ($url !== null && $url !== $l['url']) {
                    $this->ledger->saveLink($this->user, ['url' => $url] + $l);
                }
            }
        }

        return $to;
    }

    /** Удалить: в корзину сотрудника; публичные ссылки на удалённое отзываются сразу. */
    public function delete(string $rel): void
    {
        $rel = PersonalPath::clean($rel);
        if ($rel === '') {
            throw MailException::invalid('Корневую папку удалить нельзя');
        }
        $st = $this->stat($rel);
        if (! $st) {
            throw MailException::notFound('Файла или папки уже нет');
        }
        foreach ($this->ledger->linksUnder($this->user, $rel) as $l) {
            $this->dropShare($l['share_id']);
            $this->ledger->forgetLink($this->user, $l['path']);
        }
        $this->ensureTrash();
        $trashName = date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . ' ' . $st['name'];
        $r = $this->send('MOVE', $this->url($rel), ['Destination' => $this->url(PersonalPath::TRASH . '/' . $trashName), 'Overwrite' => 'F']);
        if (! in_array($r->status(), [201, 204], true)) {
            throw MailException::upstream('Облако не удалило (ответ ' . $r->status() . ')');
        }
        $this->ledger->addTrash(['user' => $this->user, 'trash_name' => $trashName, 'original_path' => $rel, 'is_dir' => $st['dir'],
            'size' => $st['size'], 'deleted_at' => date('Y-m-d H:i:s')]);
    }

    private function ensureTrash(): void
    {
        $this->ensureHome();
        $r = $this->send('MKCOL', $this->url(PersonalPath::TRASH));
        if (! in_array($r->status(), [201, 405], true)) {
            throw MailException::upstream('Облако не создало корзину (ответ ' . $r->status() . ')');
        }
    }

    /** @return array<int,array{id:int,name:string,path:string,dir:bool,size:int,deleted:string}> */
    public function trash(): array
    {
        return array_map(fn ($t) => ['id' => (int) $t['id'], 'name' => PersonalPath::base($t['original_path']), 'path' => $t['original_path'],
            'dir' => (bool) $t['is_dir'], 'size' => (int) $t['size'], 'deleted' => (string) $t['deleted_at']], $this->ledger->trash($this->user));
    }

    /** Вернуть из корзины на прежнее место; места нет — в корень; имя занято — с номером. */
    public function restore(int $id): string
    {
        $t = $this->ledger->trashItem($this->user, $id);
        if (! $t) {
            throw MailException::notFound('В корзине этого уже нет');
        }
        $dir = PersonalPath::parent($t['original_path']);
        if ($dir !== '' && ! $this->stat($dir)) {
            $dir = '';
        }
        $name = PersonalPath::base($t['original_path']);
        $dest = PersonalPath::join($dir, $name);
        for ($n = 2; $this->stat($dest) && $n < 100; $n++) {
            $dest = PersonalPath::join($dir, PersonalPath::numbered($name, $n, (bool) $t['is_dir']));
        }
        $r = $this->send('MOVE', $this->url(PersonalPath::TRASH . '/' . $t['trash_name']), ['Destination' => $this->url($dest), 'Overwrite' => 'F']);
        if ($r->status() === 404) {
            $this->ledger->forgetTrash($id);
            throw MailException::notFound('Этого уже нет в облаке');
        }
        if (! in_array($r->status(), [201, 204], true)) {
            throw MailException::upstream('Облако не вернуло (ответ ' . $r->status() . ')');
        }
        $this->ledger->forgetTrash($id);

        return $dest;
    }

    /** Удалить из корзины навсегда. */
    public function purge(int $id): void
    {
        $t = $this->ledger->trashItem($this->user, $id);
        if (! $t) {
            return;
        }
        $r = $this->send('DELETE', $this->url(PersonalPath::TRASH . '/' . $t['trash_name']));
        if (! in_array($r->status(), [204, 404], true)) {
            throw MailException::upstream('Облако не удалило (ответ ' . $r->status() . ')');
        }
        $this->ledger->forgetTrash($id);
    }

    public function emptyTrash(): int
    {
        $n = 0;
        foreach ($this->ledger->trash($this->user) as $t) {
            $this->purge((int) $t['id']);
            $n++;
        }

        return $n;
    }

    // ── загрузка частями ──────────────────────────────────────────────────

    /** Начать загрузку: проверить место, выбрать свободное имя, открыть папку загрузки в Nextcloud. */
    public function startUpload(string $dir, string $name, int $size): array
    {
        $dir = PersonalPath::clean($dir);
        $name = PersonalPath::uploadName($name);
        if ($size < 0) {
            throw MailException::invalid('Неверный размер файла');
        }
        $this->ensureHome();
        if ($dir !== '' && ! ($d = $this->stat($dir))) {
            throw MailException::notFound('Папки нет — возможно, её удалили');
        }
        $free = $this->quota() - $this->usage();
        if ($size > $free) {
            throw MailException::tooLarge('Не хватает места в облаке: свободно ' . \App\Support\Format::size(max(0, $free)) . ', нужно ' . \App\Support\Format::size($size) . '. Удалите ненужное или очистите корзину.');
        }
        $dest = PersonalPath::join($dir, $name);
        for ($n = 2; $this->stat($dest) && $n < 100; $n++) {
            $dest = PersonalPath::join($dir, PersonalPath::numbered($name, $n));
        }
        $id = 'mc' . bin2hex(random_bytes(15));
        $r = $this->send('MKCOL', $this->uploadUrl($id), ['Destination' => $this->url($dest)]);
        if ($r->status() !== 201) {
            throw MailException::upstream('Облако не начало загрузку (ответ ' . $r->status() . ')');
        }
        $this->ledger->saveUpload(['id' => $id, 'user' => $this->user, 'path' => $dest, 'size' => $size, 'chunk_size' => self::CHUNK]);

        return ['id' => $id, 'path' => $dest, 'name' => PersonalPath::base($dest), 'chunkSize' => self::CHUNK, 'chunks' => self::chunks($size, self::CHUNK), 'have' => []];
    }

    public static function chunks(int $size, int $chunk): int
    {
        return max(1, (int) ceil($size / $chunk));
    }

    /** Размер части с номером $n (с 1). */
    public static function chunkLength(int $size, int $chunk, int $n): int
    {
        $total = self::chunks($size, $chunk);
        if ($n < 1 || $n > $total) {
            return -1;
        }

        return $n < $total ? $chunk : $size - $chunk * ($total - 1);
    }

    private function openUpload(string $id): array
    {
        $u = $this->ledger->upload($this->user, $id);
        if (! $u || $u['finished_at']) {
            throw MailException::notFound('Загрузка не найдена — начните её заново');
        }

        return $u;
    }

    /** Какие части уже в облаке (целиком): по ним браузер продолжает после обрыва. */
    public function uploadStatus(string $id): array
    {
        $u = $this->openUpload($id);
        $r = $this->send('PROPFIND', $this->uploadUrl($id), ['Depth' => '1'], self::PROPS, 'application/xml');
        if ($r->status() === 404) {
            $this->ledger->forgetUpload($id);
            throw MailException::notFound('Загрузка устарела — начните её заново');
        }
        $have = [];
        $doc = new \DOMDocument();
        if (@$doc->loadXML($r->body())) {
            $xp = new \DOMXPath($doc);
            $xp->registerNamespace('d', 'DAV:');
            foreach ($xp->query('/d:multistatus/d:response') as $resp) {
                $part = basename(rawurldecode(trim((string) $xp->evaluate('string(d:href)', $resp))));
                $len = (int) $xp->evaluate('string(d:propstat/d:prop/d:getcontentlength)', $resp);
                if (ctype_digit($part) && $len === self::chunkLength((int) $u['size'], (int) $u['chunk_size'], (int) $part)) {
                    $have[] = (int) $part;
                }
            }
        }
        sort($have);

        return ['id' => $id, 'path' => $u['path'], 'name' => PersonalPath::base($u['path']), 'chunkSize' => (int) $u['chunk_size'],
            'chunks' => self::chunks((int) $u['size'], (int) $u['chunk_size']), 'have' => $have];
    }

    /** Положить одну часть. Размер обязан совпасть: недокачанный кусок не должен лечь в файл. */
    public function putChunk(string $id, int $n, string $body): void
    {
        $u = $this->openUpload($id);
        $want = self::chunkLength((int) $u['size'], (int) $u['chunk_size'], $n);
        if ($want < 0) {
            throw MailException::invalid('Неверный номер части');
        }
        if (strlen($body) !== $want) {
            throw MailException::invalid('Часть пришла не целиком (' . strlen($body) . ' из ' . $want . ' байт) — она будет отправлена заново');
        }
        $r = $this->send('PUT', $this->uploadUrl($id, (string) $n), ['Destination' => $this->url($u['path']), 'OC-Total-Length' => (string) $u['size']], $body, null, 300);
        if (! in_array($r->status(), [201, 204], true)) {
            throw MailException::upstream('Облако не приняло часть ' . $n . ' (ответ ' . $r->status() . ')');
        }
    }

    /** Все части на месте — Nextcloud собирает файл. */
    public function finishUpload(string $id): array
    {
        $u = $this->openUpload($id);
        $st = $this->uploadStatus($id);
        if (count($st['have']) !== $st['chunks']) {
            throw MailException::invalid('Загружены не все части (' . count($st['have']) . ' из ' . $st['chunks'] . ')');
        }
        $r = $this->send('MOVE', $this->uploadUrl($id, '.file'), ['Destination' => $this->url($u['path']), 'OC-Total-Length' => (string) $u['size'], 'Overwrite' => 'F'], null, null, 600);
        if (! in_array($r->status(), [201, 204], true)) {
            throw MailException::upstream('Облако не собрало файл (ответ ' . $r->status() . ')');
        }
        $this->ledger->finishUpload($id, $u['path']);

        return $this->stat($u['path']) ?? ['name' => PersonalPath::base($u['path']), 'path' => $u['path'], 'dir' => false, 'size' => (int) $u['size'], 'modified' => date(DATE_ATOM), 'type' => 'application/octet-stream', 'fileid' => null];
    }

    public function abortUpload(string $id): void
    {
        $u = $this->ledger->upload($this->user, $id);
        if (! $u) {
            return;
        }
        $this->send('DELETE', $this->uploadUrl($id));
        $this->ledger->forgetUpload($id);
    }

    /** Недавно загруженные — из своего учёта: Nextcloud не умеет быстро искать по дате в папке. */
    public function recent(int $limit = 50): array
    {
        return array_map(fn ($u) => ['name' => PersonalPath::base($u['path']), 'path' => $u['path'], 'dir' => false, 'size' => (int) $u['size'],
            'modified' => $u['finished_at'] ? date(DATE_ATOM, strtotime((string) $u['finished_at'])) : null, 'type' => 'application/octet-stream', 'fileid' => null],
            $this->ledger->recentUploads($this->user, $limit));
    }

    // ── публичные ссылки ──────────────────────────────────────────────────

    /** @return array<string,array> ссылки сотрудника по путям */
    public function links(): array
    {
        return $this->ledger->links($this->user);
    }

    /**
     * Создать или изменить ссылку на файл: срок в днях (0 — бессрочно) и пароль.
     * $password: true — новый пароль, false — без пароля, null — оставить как есть.
     */
    public function link(string $rel, int $days, ?bool $password = null): array
    {
        $rel = PersonalPath::clean($rel);
        $st = $this->stat($rel);
        if (! $st) {
            throw MailException::notFound('Файла уже нет');
        }
        if ($st['dir']) {
            throw MailException::invalid('Ссылку можно дать только на файл, не на папку');
        }
        $expires = $days > 0 ? date('Y-m-d', strtotime('+' . $days . ' days')) : null;
        $old = $this->ledger->link($this->user, $rel);
        if ($old && (self::fileId($old['share_id']) !== null) !== $this->filesHost()) {
            // Ссылка другого вида (files-хост включили или выключили) — выпускаем новую.
            // Пароль был — будет новый: старый хранится хэшем, его не вернуть.
            $this->dropShare($old['share_id']);
            $this->ledger->forgetLink($this->user, $rel);
            $password ??= (bool) $old['has_password'];
            $old = null;
        }
        $pwd = $password === true ? self::password() : null;
        $link = $this->filesHost() ? $this->fileLink($rel, $st, $expires, $password, $pwd, $old) : $this->shareLink($rel, $expires, $password, $pwd, $old);
        if ($link === null) {
            // Ссылку удалили мимо почты (в Nextcloud или из базы) — выпускаем заново.
            $this->ledger->forgetLink($this->user, $rel);

            return $this->link($rel, $days, $password);
        }
        $this->ledger->saveLink($this->user, $link);

        return $link + ($pwd ? ['password' => $pwd] : []);
    }

    /** Ссылка files-хоста почты: запись в webmail_files, файл отдаёт nginx из облака. */
    private function fileLink(string $rel, array $st, ?string $expires, ?bool $password, ?string $pwd, ?array $old): ?array
    {
        $file = ['name' => $st['name'], 'size' => $st['size'], 'mime' => $st['type'] ?: 'application/octet-stream', 'expires_at' => $expires];
        if ($password !== null) {
            $file['password'] = $pwd !== null ? password_hash($pwd, PASSWORD_DEFAULT) : '';
        }
        if ($old) {
            $url = $this->ledger->updateFile((int) self::fileId($old['share_id']), $file);
            if ($url === null) {
                return null;
            }

            return ['path' => $rel, 'share_id' => $old['share_id'], 'url' => $url, 'expires_at' => $expires,
                'has_password' => $password === null ? (bool) $old['has_password'] : $password];
        }
        $f = $this->ledger->issueFile($this->user, $file);

        return ['path' => $rel, 'share_id' => 'f' . $f['id'], 'url' => $f['url'], 'expires_at' => $expires, 'has_password' => (bool) $pwd];
    }

    /** Публичная ссылка Nextcloud — когда files-хоста нет. */
    private function shareLink(string $rel, ?string $expires, ?bool $password, ?string $pwd, ?array $old): ?array
    {
        if ($old) {
            $form = ['expireDate' => $expires ?? ''];
            if ($password !== null) {
                $form['password'] = $pwd ?? '';
            }
            $r = $this->ocs('PUT', 'shares/' . rawurlencode($old['share_id']), $form);
            if ($r->status() === 404 || (int) ($r['ocs']['meta']['statuscode'] ?? 0) === 404) {
                return null;
            }
            $this->ocsOk($r, 'Ссылка не изменена');

            return ['path' => $rel, 'share_id' => $old['share_id'], 'url' => $old['url'], 'expires_at' => $expires,
                'has_password' => $password === null ? $old['has_password'] : $password];
        }
        $form = ['path' => '/' . PersonalPath::join($this->home(), $rel), 'shareType' => 3, 'permissions' => 1];
        if ($expires) {
            $form['expireDate'] = $expires;
        }
        if ($pwd) {
            $form['password'] = $pwd;
        }
        $r = $this->ocs('POST', 'shares', $form);
        $this->ocsOk($r, 'Ссылка не создана');

        return ['path' => $rel, 'share_id' => (string) $r['ocs']['data']['id'], 'url' => (string) $r['ocs']['data']['url'], 'expires_at' => $expires,
            'has_password' => (bool) $pwd];
    }

    private function filesHost(): bool
    {
        return ! empty($this->settings['files_host']);
    }

    /** id записи в webmail_files, если ссылка — files-хоста («f<id>»); иначе это id ссылки Nextcloud. */
    private static function fileId(string $shareId): ?int
    {
        return preg_match('/^f(\d+)$/', $shareId, $m) ? (int) $m[1] : null;
    }

    public function unlink(string $rel): void
    {
        $rel = PersonalPath::clean($rel);
        $old = $this->ledger->link($this->user, $rel);
        if ($old) {
            $this->dropShare($old['share_id']);
            $this->ledger->forgetLink($this->user, $rel);
        }
    }

    private function dropShare(string $shareId): void
    {
        if (($id = self::fileId($shareId)) !== null) {
            $this->ledger->dropFile($id);

            return;
        }
        $r = $this->ocs('DELETE', 'shares/' . rawurlencode($shareId));
        if (! $r->ok() && $r->status() !== 404) {
            throw MailException::upstream('Облако не отозвало ссылку (ответ ' . $r->status() . ')');
        }
    }

    private function ocsOk(Response $r, string $what): void
    {
        $code = (int) ($r['ocs']['meta']['statuscode'] ?? $r->status());
        if (! $r->ok() || ! in_array($code, [100, 200], true)) {
            $msg = (string) ($r['ocs']['meta']['message'] ?? '');
            throw MailException::upstream($what . ($msg !== '' ? ': ' . mb_substr($msg, 0, 160) : ' (ответ ' . $r->status() . ')'));
        }
    }

    /** Пароль для ссылки: читается глазами и проходит политику паролей Nextcloud. */
    public static function password(): string
    {
        $abc = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ';
        $dig = '23456789';
        $pick = fn (string $set, int $n) => implode('', array_map(fn () => $set[random_int(0, strlen($set) - 1)], range(1, $n)));

        return $pick($abc, 3) . $pick($dig, 1) . '-' . $pick($abc, 2) . $pick($dig, 1) . '-' . $pick($abc, 3) . $pick($dig, 1);
    }

    /**
     * Приложить к письму: для каждого файла — действующая ссылка (есть — берём её, нет — новая
     * на срок из настроек). @return array<int,array{name:string,size:int,url:string,expires:?string,password:bool}>
     */
    public function attach(array $paths): array
    {
        $out = [];
        foreach (array_slice(array_values(array_unique($paths)), 0, 20) as $p) {
            $p = PersonalPath::clean((string) $p);
            $st = $this->stat($p);
            if (! $st || $st['dir']) {
                throw MailException::notFound('Файла «' . PersonalPath::base($p) . '» уже нет');
            }
            $l = $this->ledger->link($this->user, $p);
            $stale = $l && (self::fileId($l['share_id']) !== null) !== $this->filesHost();
            if (! $l || $stale || ($l['expires_at'] && $l['expires_at'] < date('Y-m-d'))) {
                $l = $this->link($p, (int) ($this->settings['personal_link_days'] ?? 30), $l ? null : false);
            }
            $out[] = ['name' => $st['name'], 'size' => $st['size'], 'url' => $l['url'], 'expires' => $l['expires_at'], 'password' => (bool) $l['has_password']];
        }

        return $out;
    }

    // ── скачивание ────────────────────────────────────────────────────────

    /**
     * Заголовки для nginx: файл отдаёт он сам, запросом в Nextcloud (X-Accel-Redirect на
     * внутреннюю location /_nccloud/), с поддержкой докачки и перемотки видео. Через PHP
     * гигабайтное видео пришлось бы держать в памяти рабочего процесса.
     */
    public function accel(string $rel): array
    {
        $rel = PersonalPath::clean($rel);

        return [
            'X-Accel-Redirect' => '/_nccloud/',
            'X-Nc-Url' => $this->url($rel),
            'X-Nc-Auth' => 'Basic ' . base64_encode($this->settings['login'] . ':' . $this->settings['app_password']),
            'X-Accel-Buffering' => 'no',
        ];
    }

    /** Где в облаке лежит файл записи webmail_files (ссылка files-хоста); null — ссылку уже отозвали. */
    public function pathOfFile(int $fileId): ?string
    {
        return $this->ledger->filePath($this->user, $fileId);
    }
}
