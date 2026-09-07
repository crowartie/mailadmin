<?php

namespace App\Dav;

use App\Services\Dav\DavStore;
use App\Services\Mail\ImapSession;
use Illuminate\Support\Facades\Cache;
use Sabre\DAV\Auth\Backend\AbstractBasic;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;

/**
 * Basic-авторизация для телефонов и почтовых программ: тот же логин и пароль, что в почте.
 * Пароль проверяется входом в IMAP; удачная пара запоминается в кэше на 10 минут,
 * чтобы клиент, который шлёт десятки запросов подряд, не долбил Dovecot.
 */
class AuthBackend extends AbstractBasic
{
    protected $realm = 'Почта';

    public function check(RequestInterface $request, ResponseInterface $response)
    {
        $auth = new \Sabre\HTTP\Auth\Basic($this->realm, $request, $response);
        $userpass = $auth->getCredentials();
        if (! $userpass) {
            return [false, 'Нужны логин и пароль'];
        }

        $user = strtolower(trim($userpass[0]));
        if (! str_contains($user, '@')) {
            $user .= '@' . config('areas.default_domain');
        }
        if (! $this->validateUserPass($user, $userpass[1])) {
            return [false, 'Неверный логин или пароль'];
        }

        return [true, Server::principal($user)];
    }

    protected function validateUserPass($username, $password)
    {
        $key = 'dav.auth.' . hash('sha256', $username . "\0" . $password);
        if (Cache::get($key)) {
            return true;
        }

        try {
            ImapSession::verify($username, $password);
        } catch (\Throwable) {
            return false;
        }

        Cache::put($key, true, 600);
        app(DavStore::class)->ensureUser($username);

        return true;
    }
}
