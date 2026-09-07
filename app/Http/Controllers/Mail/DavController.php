<?php

namespace App\Http\Controllers\Mail;

use App\Dav\Server;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Sabre\HTTP;

/**
 * CalDAV/CardDAV для телефонов и почтовых программ: https://mail.<домен>/dav/
 * Laravel-запрос переупаковывается в sabre и обратно; сессии и CSRF здесь не участвуют.
 */
class DavController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $server = Server::make();

        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            $headers[$name] = implode(', ', $values);
        }
        $uri = $request->getRequestUri();
        $sabreRequest = new HTTP\Request($request->getMethod(), $uri, $headers, $request->getContent());
        $sabreRequest->setBaseUrl(Server::BASE);
        $sabreResponse = new HTTP\Response();
        $server->httpRequest = $sabreRequest;
        $server->httpResponse = $sabreResponse;
        try {
            $server->invokeMethod($sabreRequest, $sabreResponse, false);
        } catch (\Sabre\DAV\Exception $e) {
            $sabreResponse->setStatus($e->getHTTPCode());
            $sabreResponse->setHeader('Content-Type', 'application/xml; charset=utf-8');
            $sabreResponse->setBody($this->errorXml($server, $e));
            if ($e->getHTTPCode() === 401) {
                $sabreResponse->setHeader('WWW-Authenticate', 'Basic realm="Почта", charset="UTF-8"');
            }
        }

        $out = new Response($sabreResponse->getBodyAsString(), $sabreResponse->getStatus());
        foreach ($sabreResponse->getHeaders() as $name => $values) {
            if (in_array(strtolower($name), ['transfer-encoding', 'content-length'], true)) {
                continue;
            }
            $out->headers->set($name, $values, true);
        }

        return $out;
    }

    private function errorXml(\Sabre\DAV\Server $server, \Sabre\DAV\Exception $e): string
    {
        $dom = new \DOMDocument('1.0', 'utf-8');
        $dom->formatOutput = true;
        $root = $dom->createElementNS('DAV:', 'd:error');
        $root->setAttribute('xmlns:s', 'http://sabredav.org/ns');
        $dom->appendChild($root);
        $root->appendChild($dom->createElement('s:exception', get_class($e)));
        $root->appendChild($dom->createElement('s:message', htmlspecialchars($e->getMessage())));
        $e->serialize($server, $root);

        return (string) $dom->saveXML();
    }

    /** /.well-known/caldav и /.well-known/carddav → /dav/ (RFC 6764, автонастройка клиентов). */
    public function wellKnown(): \Illuminate\Http\RedirectResponse
    {
        return redirect(Server::BASE, 301);
    }
}
