<?php

namespace App\Services\Mail;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;

/**
 * Соединение с IMAP от имени вошедшего пользователя.
 *
 * Пароль нужен на каждый запрос (IMAP без него не работает), поэтому он лежит
 * в сессии зашифрованным ключом приложения; в базу не попадает никогда.
 * Каркас: одно соединение на запрос. Кэш метаданных и IDLE — следующий шаг.
 */
class ImapSession
{
    private ?Client $client = null;

    public function __construct(private readonly Request $request)
    {
    }

    public static function login(Request $request, string $username, string $password): void
    {
        $client = self::make($username, $password);
        $client->connect(); // бросит исключение при неверном пароле
        $client->disconnect();

        $request->session()->regenerate();
        $request->session()->put('mail.user', strtolower($username));
        $request->session()->put('mail.secret', Crypt::encryptString($password));
    }

    public function user(): string
    {
        return (string) $this->request->session()->get('mail.user');
    }

    public function client(): Client
    {
        if ($this->client === null) {
            $this->client = self::make(
                $this->user(),
                Crypt::decryptString($this->request->session()->get('mail.secret')),
            );
            $this->client->connect();
        }

        return $this->client;
    }

    private static function make(string $username, string $password): Client
    {
        $imap = config('areas.imap');

        return (new ClientManager())->make([
            'host' => $imap['host'],
            'port' => $imap['port'],
            'encryption' => $imap['encryption'],
            'validate_cert' => $imap['validate_cert'],
            'username' => $username,
            'password' => $password,
            'protocol' => 'imap',
            'timeout' => 15,
        ]);
    }

    public function __destruct()
    {
        try {
            $this->client?->disconnect();
        } catch (\Throwable) {
            // соединение уже закрыто сервером — не наша проблема
        }
    }
}
