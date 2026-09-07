<?php

namespace App\Dav;

use Illuminate\Support\Facades\DB;
use Sabre\CalDAV;
use Sabre\CardDAV;
use Sabre\DAV;
use Sabre\DAVACL;
use Sabre\HTTP;

/**
 * Встроенный CalDAV/CardDAV-сервер (sabre/dav) поверх таблиц dav_* в базе приложения.
 *
 * Один и тот же сервер обслуживает два входа:
 *  - HTTP /dav/… для телефонов и почтовых программ (Basic-авторизация паролем от почты);
 *  - вызовы «изнутри» из нашего JSON-API (доверенный пользователь из сессии веб-почты) —
 *    так приглашения, синхронизация и ETag'и работают одинаково, откуда бы ни пришла правка.
 */
class Server
{
    public const BASE = '/dav/';

    public static function principal(string $user): string
    {
        return 'principals/' . strtolower($user);
    }

    public static function make(?string $trustedUser = null, bool $systemWrites = false): DAV\Server
    {
        // В консоли (планировщик) у sapi нет REQUEST_URI — подставляем, чтобы конструктор не упал.
        $_SERVER['REQUEST_URI'] ??= '/';
        $_SERVER['REQUEST_METHOD'] ??= 'GET';

        $pdo = DB::connection()->getPdo();

        $principals = new DAVACL\PrincipalBackend\PDO($pdo);
        $principals->tableName = 'dav_principals';
        $principals->groupMembersTableName = 'dav_groupmembers';

        $cards = new CardBackend($pdo);
        $cards->systemWrites = $systemWrites;
        $calendars = new CalBackend($pdo);
        $calendars->systemWrites = $systemWrites;

        $server = new DAV\Server([
            new DAVACL\PrincipalCollection($principals),
            new CalDAV\CalendarRoot($principals, $calendars),
            new CardDAV\AddressBookRoot($principals, $cards),
        ]);
        $server->setBaseUri(self::BASE);
        $server->debugExceptions = (bool) config('app.debug');

        $server->addPlugin(new DAV\Auth\Plugin($trustedUser ? new TrustedAuth($trustedUser) : new AuthBackend()));

        $acl = new DAVACL\Plugin();
        $acl->allowUnauthenticatedAccess = false;
        $acl->hideNodesFromListings = true;
        $server->addPlugin($acl);

        $server->addPlugin(new CalDAV\Plugin());
        $server->addPlugin(new CardDAV\Plugin());
        $server->addPlugin(new CalDAV\Schedule\Plugin());
        $server->addPlugin(new IMip('noreply@' . config('areas.default_domain')));
        $server->addPlugin(new DAV\Sharing\Plugin());
        $server->addPlugin(new CalDAV\SharingPlugin());
        $server->addPlugin(new DAV\Sync\Plugin());
        $server->addPlugin(new CalDAV\ICSExportPlugin());
        $server->addPlugin(new CardDAV\VCFExportPlugin());

        $props = new DAV\PropertyStorage\Backend\PDO($pdo);
        $props->tableName = 'dav_propertystorage';
        $server->addPlugin(new DAV\PropertyStorage\Plugin($props));

        $locks = new DAV\Locks\Backend\PDO($pdo);
        $locks->tableName = 'dav_locks';
        $server->addPlugin(new DAV\Locks\Plugin($locks));

        return $server;
    }

    /**
     * Выполнить DAV-запрос изнутри приложения от имени пользователя.
     *
     * @return array{0:int,1:string,2:array<string,string>} статус, тело, заголовки
     */
    public static function call(string $user, string $method, string $path, string $body = '', array $headers = [], bool $systemWrites = false): array
    {
        $server = self::make($user, $systemWrites);
        $request = new HTTP\Request($method, self::BASE . ltrim($path, '/'), $headers, $body);
        $request->setBaseUrl(self::BASE);
        $response = new HTTP\Response();
        $server->httpRequest = $request;
        $server->httpResponse = $response;
        try {
            $server->invokeMethod($request, $response, false);
        } catch (DAV\Exception $e) {
            // invokeMethod (в отличие от exec) исключения не ловит — переводим в статус сами.
            return [$e->getHTTPCode(), '<s:message>' . htmlspecialchars($e->getMessage()) . '</s:message>', []];
        }

        $flat = [];
        foreach ($response->getHeaders() as $name => $values) {
            $flat[strtolower($name)] = implode(', ', (array) $values);
        }

        return [$response->getStatus(), $response->getBodyAsString(), $flat];
    }

    /** Текст ошибки из XML-ответа sabre (<s:message>), если он там есть. */
    public static function errorMessage(string $body, int $status): string
    {
        if (preg_match('~<s:message>(.*?)</s:message>~su', $body, $m)) {
            return html_entity_decode(trim($m[1]));
        }

        return match ($status) {
            403 => 'Нет прав на это действие',
            404 => 'Запись не найдена',
            412 => 'Запись изменена кем-то другим — обновите страницу',
            default => "Ошибка хранилища ({$status})",
        };
    }
}
