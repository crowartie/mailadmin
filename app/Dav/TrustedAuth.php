<?php

namespace App\Dav;

use Sabre\DAV\Auth\Backend\BackendInterface;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;

/** Пользователь уже вошёл в веб-почту — DAV-серверу изнутри его перепроверять не нужно. */
class TrustedAuth implements BackendInterface
{
    public function __construct(private readonly string $user)
    {
    }

    public function check(RequestInterface $request, ResponseInterface $response)
    {
        return [true, Server::principal($this->user)];
    }

    public function challenge(RequestInterface $request, ResponseInterface $response)
    {
    }
}
