<?php

namespace App\Services\Dav;

use App\Dav\CalBackend;
use App\Dav\CardBackend;
use App\Dav\Server;
use App\Models\Vmail\Mailbox;
use Illuminate\Support\Facades\DB;
use Sabre\CalDAV\Xml\Property\SupportedCalendarComponentSet;

/**
 * Общая опора для работы с DAV: подключение к таблицам, признак администратора,
 * заведение principal'а и вызов встроенного DAV-сервера.
 *
 * Части (книги, календари, задачи, доступ) держат её у себя, а не наследуют: так видно,
 * чем именно каждая пользуется, и правка одной части не заставляет перечитывать остальные.
 */
class DavAccess
{
    public const PERSONAL = 'personal';

    public readonly CardBackend $cards;

    public readonly CalBackend $cals;

    public function __construct()
    {
        $pdo = DB::connection()->getPdo();
        $this->cards = new CardBackend($pdo);
        $this->cals = new CalBackend($pdo);
    }


    // ── Пользователи ─────────────────────────────────────────────────────

    /** Глобальный администратор почты (domain_admins ALL) правит общие книги и календарь компании. */
    public function isAdmin(string $user): bool
    {
        return DB::connection('vmail')->table('domain_admins')->where('username', strtolower($user))->where('active', 1)
            ->where(fn ($q) => $q->where('domain', 'ALL')->orWhere('domain', explode('@', $user)[1] ?? ''))->exists();
    }


    public function displayName(string $user): string
    {
        $name = Mailbox::query()->where('username', strtolower($user))->value('name');

        return trim((string) $name) ?: explode('@', $user)[0];
    }


    /** @var array<string,bool> */
    private static array $ensured = [];


    public function ensureOnce(string $user): void
    {
        $user = strtolower($user);
        if (! isset(self::$ensured[$user])) {
            self::$ensured[$user] = true;
            $this->ensureUser($user);
        }
    }


    /** Principal, личная книга и личный календарь — создаются при первом входе. */
    public function ensureUser(string $user): void
    {
        $user = strtolower($user);
        $principal = Server::principal($user);
        $name = $this->displayName($user);

        $exists = DB::table('dav_principals')->where('uri', $principal)->exists();
        if (! $exists) {
            DB::table('dav_principals')->insert(['uri' => $principal, 'email' => $user, 'displayname' => $name]);
        } else {
            DB::table('dav_principals')->where('uri', $principal)->update(['email' => $user, 'displayname' => $name]);
        }

        if (! DB::table('dav_addressbooks')->where('principaluri', $principal)->where('uri', self::PERSONAL)->exists()) {
            $this->cards->createAddressBook($principal, self::PERSONAL, ['{DAV:}displayname' => 'Мои контакты']);
        }
        if (! DB::table('dav_calendarinstances')->where('principaluri', $principal)->where('uri', self::PERSONAL)->exists()) {
            $this->cals->createCalendar($principal, self::PERSONAL, [
                '{DAV:}displayname' => 'Мой календарь',
                '{http://apple.com/ns/ical/}calendar-color' => '#2F6FEB',
                '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set' => new SupportedCalendarComponentSet(['VEVENT', 'VTODO']),
            ]);
        }
    }


    // ── DAV изнутри ──────────────────────────────────────────────────────

    /** @return array{0:int,1:string,2:array} */
    public function dav(string $user, string $method, string $path, string $body = '', array $headers = []): array
    {
        $result = Server::call($user, $method, $path, $body, $headers, $this->isAdmin($user));
        if ($result[0] >= 400) {
            throw new DavException(Server::errorMessage($result[1], $result[0]), $result[0]);
        }

        return $result;
    }
}
