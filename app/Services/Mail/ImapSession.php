<?php

namespace App\Services\Mail;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;

/**
 * Соединение с почтовым сервером от имени вошедшего пользователя.
 *
 * Пароль нужен на каждый запрос (IMAP без него не работает), поэтому он лежит
 * в сессии зашифрованным ключом приложения; в базу не попадает никогда.
 *
 * Для фоновых задач (вернуть отложенное письмо, отправить по расписанию) пароля
 * пользователя нет — используется master-пользователь Dovecot (MAIL_IMAP_MASTER_*).
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

    /** Проверить пароль входом в IMAP; бросает исключение, если пара неверна. */
    public static function verify(string $username, string $password): void
    {
        $client = self::make($username, $password);
        $client->connect();
        $client->disconnect();
    }

    public function user(): string
    {
        return (string) $this->request->session()->get('mail.user');
    }

    public function domain(): string
    {
        return (string) (explode('@', $this->user())[1] ?? config('areas.default_domain'));
    }

    public function password(): string
    {
        return Crypt::decryptString($this->request->session()->get('mail.secret'));
    }

    /** После смены пароля — обновить секрет в сессии, иначе следующий запрос не войдёт. */
    public function rotate(string $password): void
    {
        $this->request->session()->put('mail.secret', Crypt::encryptString($password));
    }

    public function client(): Client
    {
        if ($this->client === null) {
            $this->client = self::make($this->user(), $this->password());
            $this->client->connect();
        }

        return $this->client;
    }

    /** SMTP-транспорт с учётными данными пользователя: сервер сам проверит право писать от этого адреса. */
    public function smtp(): EsmtpTransport
    {
        $smtp = config('areas.smtp');
        $transport = new EsmtpTransport($smtp['host'], (int) $smtp['port'], false);
        $transport->setUsername($this->user())->setPassword($this->password());
        self::relaxTls($transport);

        return $transport;
    }

    /** Отправка из фоновой задачи: с localhost Postfix принимает без авторизации (mynetworks). */
    public static function smtpLocal(): EsmtpTransport
    {
        $transport = new EsmtpTransport('127.0.0.1', 25, false);
        self::relaxTls($transport);

        return $transport;
    }

    /** IMAP от имени пользователя через master-пароль Dovecot (для планировщика). */
    public static function master(string $user): Client
    {
        $imap = config('areas.imap');
        if (empty($imap['master_user']) || empty($imap['master_password'])) {
            throw new \RuntimeException('MAIL_IMAP_MASTER_USER / MAIL_IMAP_MASTER_PASSWORD не заданы');
        }

        $client = self::make($user . '*' . $imap['master_user'], $imap['master_password']);
        $client->connect();

        return $client;
    }

    private static function relaxTls(EsmtpTransport $transport): void
    {
        // Сервер — 127.0.0.1, а сертификат выписан на имя: проверку имени отключаем.
        $stream = $transport->getStream();
        if ($stream instanceof SocketStream) {
            $stream->setStreamOptions(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]]);
        }
    }

    private static function make(string $username, string $password): Client
    {
        $imap = config('areas.imap');

        // flags => null: иначе библиотека выбрасывает все ключевые слова, кроме стандартных, а на них держатся метки.
        return (new ClientManager(['flags' => null]))->make([
            'host' => $imap['host'],
            'port' => $imap['port'],
            'encryption' => $imap['encryption'],
            'validate_cert' => $imap['validate_cert'],
            'username' => $username,
            'password' => $password,
            'protocol' => 'imap',
            'timeout' => 20,
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
