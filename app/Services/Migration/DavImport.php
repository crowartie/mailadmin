<?php

namespace App\Services\Migration;

use App\Dav\Server;
use App\Services\Dav\Cards;
use App\Services\Dav\DavStore;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Перенос контактов и календарей со старого сервера по CardDAV/CalDAV (Kerio Connect, Nextcloud, Google и др.):
 * находим principal → домашние коллекции → все книги и календари → каждую карточку/событие кладём в личную книгу
 * и личный календарь сотрудника у нас. Повторный запуск не плодит дубли (объекты ложатся по своему UID).
 */
class DavImport
{
    private PendingRequest $http;

    private string $base;

    public function __construct(string $baseUrl, string $login, string $password)
    {
        $this->base = rtrim($baseUrl, '/');
        if (! preg_match('~^https?://~i', $this->base)) {
            $this->base = 'https://' . $this->base;
        }
        $this->http = Http::withBasicAuth($login, $password)->withoutVerifying()->timeout(60)->withOptions(['allow_redirects' => ['strict' => true, 'max' => 5]])->withHeaders(['User-Agent' => 'mailadmin-migration']);
    }

    /** @return array{contacts:int,events:int,books:int,calendars:int,notes:string[]} */
    public function run(string $targetUser, DavStore $store): array
    {
        $notes = [];
        $result = ['contacts' => 0, 'events' => 0, 'books' => 0, 'calendars' => 0, 'notes' => &$notes];
        $store->ensureUser($targetUser);

        foreach (['carddav' => 'addressbook-home-set', 'caldav' => 'calendar-home-set'] as $kind => $homeProp) {
            try {
                $principal = $this->principal($kind);
                if (! $principal) {
                    $notes[] = ($kind === 'carddav' ? 'CardDAV' : 'CalDAV') . ': сервер не назвал principal — пропущено';
                    continue;
                }
                $home = $this->home($principal, $kind, $homeProp);
                if (! $home) {
                    $notes[] = ($kind === 'carddav' ? 'Книги' : 'Календари') . ': домашняя коллекция не найдена';
                    continue;
                }
                foreach ($this->collections($home, $kind) as $col) {
                    $objects = $this->objects($col['href'], $kind);
                    if (! $objects) {
                        continue;
                    }
                    if ($kind === 'carddav') {
                        $result['books']++;
                        $n = 0;
                        foreach ($objects as $vcf) {
                            foreach (Cards::split($vcf) as $one) {
                                // Карточка ложится под своим UID: повторный перенос перезапишет её, а не удвоит.
                                $uid = preg_match('/^UID:(.+)$/m', $one, $mm) ? trim($mm[1]) : Cards::uid();
                                $uri = preg_replace('/[^A-Za-z0-9@._-]/', '_', $uid) . '.vcf';
                                $normalized = Cards::build(Cards::parse($one), $one);
                                [$code] = Server::call($targetUser, 'PUT', "addressbooks/{$targetUser}/" . DavStore::PERSONAL . "/{$uri}", $normalized, ['Content-Type' => 'text/vcard; charset=utf-8']);
                                if ($code < 300) {
                                    $n++;
                                }
                            }
                        }
                        $result['contacts'] += $n;
                        $notes[] = 'Книга «' . ($col['name'] ?: $col['href']) . '»: ' . $n . ' контактов';
                    } else {
                        $result['calendars']++;
                        $n = 0;
                        foreach ($objects as $ics) {
                            if (! preg_match('/^UID:(.+)$/m', $ics, $mm)) {
                                continue;
                            }
                            $uri = preg_replace('/[^A-Za-z0-9@._-]/', '_', trim($mm[1])) . '.ics';
                            [$code] = Server::call($targetUser, 'PUT', "calendars/{$targetUser}/personal/{$uri}", $ics, ['Content-Type' => 'text/calendar; charset=utf-8']);
                            if ($code < 300) {
                                $n++;
                            }
                        }
                        $result['events'] += $n;
                        $notes[] = 'Календарь «' . ($col['name'] ?: $col['href']) . '»: ' . $n . ' событий';
                    }
                }
            } catch (\Throwable $e) {
                $notes[] = ($kind === 'carddav' ? 'CardDAV' : 'CalDAV') . ': ' . mb_substr($e->getMessage(), 0, 200);
            }
        }

        return $result;
    }

    private function principal(string $kind): ?string
    {
        foreach (['/.well-known/' . $kind, '/' . $kind . '/', '/'] as $path) {
            $r = $this->propfind($this->base . $path, 0, '<d:current-user-principal/>');
            if ($r && preg_match('~current-user-principal>\s*<d:href>([^<]+)</d:href>~i', $r, $m)) {
                return $this->abs(html_entity_decode($m[1]));
            }
            if ($r && preg_match('~current-user-principal>\s*<[a-z0-9]*:?href>([^<]+)<~i', $r, $m)) {
                return $this->abs(html_entity_decode($m[1]));
            }
        }

        return null;
    }

    private function home(string $principal, string $kind, string $prop): ?string
    {
        $ns = $kind === 'carddav' ? 'urn:ietf:params:xml:ns:carddav' : 'urn:ietf:params:xml:ns:caldav';
        $r = $this->propfind($principal, 0, '<x:' . $prop . ' xmlns:x="' . $ns . '"/>');
        if ($r && preg_match('~' . $prop . '>\s*<[a-z0-9]*:?href>([^<]+)<~i', $r, $m)) {
            return $this->abs(html_entity_decode($m[1]));
        }

        return null;
    }

    /** @return array<int,array{href:string,name:string}> */
    private function collections(string $home, string $kind): array
    {
        $r = $this->propfind($home, 1, '<d:resourcetype/><d:displayname/>');
        $out = [];
        if (! $r) {
            return $out;
        }
        $want = $kind === 'carddav' ? 'addressbook' : 'calendar';
        foreach (preg_split('~</[a-z0-9]*:?response>~i', $r) as $chunk) {
            if (! preg_match('~<[a-z0-9]*:?href>([^<]+)<~i', $chunk, $h)) {
                continue;
            }
            if (! preg_match('~<[a-z0-9]*:?' . $want . '\b~i', $chunk)) {
                continue;
            }
            $name = preg_match('~displayname>([^<]*)<~i', $chunk, $n) ? html_entity_decode($n[1]) : '';
            $out[] = ['href' => $this->abs(html_entity_decode($h[1])), 'name' => $name];
        }

        return $out;
    }

    /** Все объекты коллекции (REPORT multiget слишком разный у серверов — берём список и GET по одному). @return string[] */
    private function objects(string $col, string $kind): array
    {
        $r = $this->propfind($col, 1, '<d:getcontenttype/>');
        $out = [];
        if (! $r) {
            return $out;
        }
        foreach (preg_split('~</[a-z0-9]*:?response>~i', $r) as $chunk) {
            if (! preg_match('~<[a-z0-9]*:?href>([^<]+)<~i', $chunk, $h)) {
                continue;
            }
            $href = html_entity_decode($h[1]);
            $ext = $kind === 'carddav' ? '.vcf' : '.ics';
            if (! str_ends_with(strtolower($href), $ext) && ! preg_match('~text/(vcard|calendar)~i', $chunk)) {
                continue;
            }
            $g = $this->http->get($this->abs($href));
            if ($g->ok() && ($body = trim($g->body())) !== '') {
                $out[] = $body;
            }
        }

        return $out;
    }

    private function propfind(string $url, int $depth, string $props): ?string
    {
        try {
            $r = $this->http->withHeaders(['Depth' => (string) $depth, 'Content-Type' => 'application/xml'])
                ->withBody('<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop>' . $props . '</d:prop></d:propfind>', 'application/xml')
                ->send('PROPFIND', $url);
        } catch (\Throwable) {
            return null;
        }
        if ($r->status() === 401) {
            throw new \RuntimeException('старый сервер не принял логин/пароль для DAV');
        }

        return $r->status() === 207 ? $r->body() : null;
    }

    private function abs(string $href): string
    {
        if (preg_match('~^https?://~i', $href)) {
            return $href;
        }
        $u = parse_url($this->base);

        return $u['scheme'] . '://' . $u['host'] . (isset($u['port']) ? ':' . $u['port'] : '') . '/' . ltrim($href, '/');
    }
}
