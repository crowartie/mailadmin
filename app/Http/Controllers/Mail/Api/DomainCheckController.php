<?php

namespace App\Http\Controllers\Mail\Api;

use App\Http\Controllers\Controller;
use App\Services\Mail\DomainCheck;
use App\Services\Mail\ImapSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Проверка домена получателя из формы письма: {status, text, suggestion}. */
class DomainCheckController extends Controller
{
    public function __invoke(Request $request, ImapSession $imap, DomainCheck $check): JsonResponse
    {
        $domain = (string) $request->query('domain', '');
        if (str_contains($domain, '@')) {
            $domain = (string) (explode('@', $domain)[1] ?? '');
        }

        return response()->json($check->check($domain, $imap->user()));
    }
}
