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
        // % и _ в LIKE — шаблоны: запрос «%» выдавал всех подряд, а «_» — любую букву.
        // Экранируем, чтобы человек искал ровно то, что набрал.
        $like = addcslashes($q, '%_\\');
        $out = [];

        if (mb_strlen($q) >= 1) {
            $employees = Mailbox::query()->where('domain', $imap->domain())->where('active', 1)
                ->where(fn ($w) => $w->where('username', 'like', "%{$like}%")->orWhere('name', 'like', "%{$like}%"))
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
                ->where(fn ($w) => $w->where('email', 'like', "%{$like}%")->orWhere('name', 'like', "%{$like}%"))
                ->orderByDesc('uses')->orderByDesc('last_at')->limit(8)->get();
            $recentRows = [];
            foreach ($recents as $r) {
                if (! isset($out[$r->email])) {
                    $recentRows[$r->email] = ['mail' => $r->email, 'name' => $r->name ?: $r->email, 'kind' => 'recent'];
                }
            }
            // Те, кому человек реально писал, — впереди сотрудников: раньше список
            // резался по первым десяти, и недавние адресаты в него не попадали вовсе.
            $out = $recentRows + $out;
        }

        // Себе не пишут: свой адрес и свои псевдонимы из подсказок убираем.
        unset($out[strtolower($imap->user())]);

        return response()->json(array_values(array_slice($out, 0, 10)));
    }
}
