<?php

namespace App\Services\Mail;

/**
 * Минимальный клиент ManageSieve (RFC 5804): положить скрипт, сделать активным, прочитать.
 * Dovecot в iRedMail слушает 127.0.0.1:4190 и требует STARTTLS перед AUTHENTICATE.
 */
class ManageSieveClient
{
    /** @var resource|null */
    private $sock = null;

    private array $capabilities = [];

    public function __construct(private readonly string $host = '127.0.0.1', private readonly int $port = 4190)
    {
    }

    public static function forUser(string $user, string $password): self
    {
        $sieve = config('areas.sieve');
        $c = new self($sieve['host'] ?? '127.0.0.1', (int) ($sieve['port'] ?? 4190));
        $c->connect();
        $c->authenticate($user, $password);

        return $c;
    }

    public function connect(): void
    {
        $this->sock = @stream_socket_client("tcp://{$this->host}:{$this->port}", $errno, $errstr, 10);
        if (! $this->sock) {
            throw new \RuntimeException("ManageSieve недоступен: {$errstr}");
        }
        stream_set_timeout($this->sock, 15);
        $this->readResponse(); // приветствие с возможностями

        if (isset($this->capabilities['STARTTLS'])) {
            $this->send('STARTTLS');
            $this->readResponse();
            stream_context_set_option($this->sock, ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]]);
            if (! stream_socket_enable_crypto($this->sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new \RuntimeException('ManageSieve: не удалось включить TLS');
            }
            $this->readResponse(); // сервер повторяет возможности
        }
    }

    public function authenticate(string $user, string $password): void
    {
        $token = base64_encode("\0{$user}\0{$password}");
        $this->send('AUTHENTICATE "PLAIN" ' . $this->literal($token));
        $this->readResponse();
    }

    public function putScript(string $name, string $script): void
    {
        $this->send('PUTSCRIPT ' . $this->quote($name) . ' ' . $this->literal($script));
        $this->readResponse();
    }

    public function setActive(string $name): void
    {
        $this->send('SETACTIVE ' . $this->quote($name));
        $this->readResponse();
    }

    public function getScript(string $name): ?string
    {
        $this->send('GETSCRIPT ' . $this->quote($name));
        try {
            $r = $this->readResponse();
        } catch (\RuntimeException) {
            return null;
        }

        return $r['literal'] ?? '';
    }

    /** @return array<string,bool> имя → активен */
    public function listScripts(): array
    {
        $this->send('LISTSCRIPTS');
        $r = $this->readResponse();
        $out = [];
        foreach ($r['lines'] as $line) {
            if (preg_match('/^"([^"]*)"(\s+ACTIVE)?/', $line, $m)) {
                $out[$m[1]] = isset($m[2]);
            }
        }

        return $out;
    }

    public function deleteScript(string $name): void
    {
        $this->send('DELETESCRIPT ' . $this->quote($name));
        $this->readResponse();
    }

    public function logout(): void
    {
        if ($this->sock) {
            try {
                $this->send('LOGOUT');
            } catch (\Throwable) {
            }
            fclose($this->sock);
            $this->sock = null;
        }
    }

    public function __destruct()
    {
        $this->logout();
    }

    // ── протокол ──────────────────────────────────────────────────────────

    private function send(string $line): void
    {
        fwrite($this->sock, $line . "\r\n");
    }

    /**
     * Читает ответ до строки OK/NO/BYE. Литералы {n+} читаются целиком.
     *
     * @return array{lines:array,literal:?string,ok:string}
     */
    private function readResponse(): array
    {
        $lines = [];
        $literal = null;
        while (true) {
            $line = fgets($this->sock);
            if ($line === false) {
                throw new \RuntimeException('ManageSieve: соединение оборвано');
            }
            $line = rtrim($line, "\r\n");

            if (preg_match('/^\{(\d+)\+?\}$/', $line, $m)) {
                $literal = $this->readBytes((int) $m[1]);
                continue;
            }
            if (preg_match('/^OK\b/', $line)) {
                return ['lines' => $lines, 'literal' => $literal, 'ok' => $line];
            }
            if (preg_match('/^(NO|BYE)\b(.*)$/', $line, $m)) {
                throw new \RuntimeException('ManageSieve: ' . trim($m[2]) ?: $line);
            }
            // Возможности сервера: "IMPLEMENTATION" "Dovecot", "STARTTLS", "SASL" "PLAIN" …
            if (preg_match('/^"([A-Z0-9-]+)"(?:\s+"(.*)")?$/i', $line, $m)) {
                $this->capabilities[strtoupper($m[1])] = $m[2] ?? true;
            }
            $lines[] = $line;
        }
    }

    private function readBytes(int $n): string
    {
        $buf = '';
        while (strlen($buf) < $n) {
            $chunk = fread($this->sock, $n - strlen($buf));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $buf .= $chunk;
        }
        // после литерала идёт CRLF
        fgets($this->sock);

        return $buf;
    }

    private function quote(string $s): string
    {
        return '"' . addcslashes($s, '"\\') . '"';
    }

    private function literal(string $s): string
    {
        return '{' . strlen($s) . "+}\r\n" . $s;
    }
}
