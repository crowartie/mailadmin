<?php

namespace App\Http\Controllers\Mail\Api;

use App\Http\Controllers\Controller;
use App\Models\Vmail\Mailbox;
use App\Models\Webmail\Recent;
use App\Services\Dav\DavStore;
use App\Services\Mail\ImapSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Автодополнение адресов: сотрудники домена (общая книга) + те, кому пользователь уже писал. */
class SuggestController extends Controller
{
    public function __invoke(Request $request, ImapSession $imap, DavStore $store): JsonResponse
    {
        $q = mb_strtolower(trim((string) $request->query('q', '')));
        $out = [];

        if (mb_strlen($q) >= 1) {
            $employees = Mailbox::query()->where('domain', $imap->domain())->where('active', 1)
                ->where(fn ($w) => $w->where('username', 'like', "%{$q}%")->orWhere('name', 'like', "%{$q}%"))
                ->orderBy('name')->limit(8)->get(['username', 'name', 'recovery_email']);
            $personal = \App\Models\EmployeeProfile::query()->whereIn('username', $employees->pluck('username'))->pluck('personal_email', 'username');
            foreach ($employees as $e) {
                $out[$e->username] = ['mail' => $e->username, 'name' => $e->name ?: $e->username, 'kind' => 'employee'];
                // Личная (резервная) почта — отдельным вариантом, чтобы выбор был явным.
                $p = strtolower((string) ($personal->get($e->username) ?: $e->recovery_email));
                if ($p !== '' && filter_var($p, FILTER_VALIDATE_EMAIL)) {
                    $out[$p] = ['mail' => $p, 'name' => $e->name ?: $e->username, 'kind' => 'personal'];
                }
            }

            try {
                foreach ($store->suggest($imap->user(), $q) as $c) {
                    $out[$c['mail']] ??= $c;
                }
            } catch (\Throwable) {
                // книги недоступны — обойдёмся сотрудниками и недавними
            }

            $recents = Recent::query()->where('user', $imap->user())
                ->where(fn ($w) => $w->where('email', 'like', "%{$q}%")->orWhere('name', 'like', "%{$q}%"))
                ->orderByDesc('uses')->orderByDesc('last_at')->limit(8)->get();
            foreach ($recents as $r) {
                if (! isset($out[$r->email])) {
                    $out[$r->email] = ['mail' => $r->email, 'name' => $r->name ?: $r->email, 'kind' => 'recent'];
                }
            }
        }

        // Себе не пишут: свой адрес и свои псевдонимы из подсказок убираем.
        unset($out[strtolower($imap->user())]);

        return response()->json(array_values(array_slice($out, 0, 10)));
    }
}
