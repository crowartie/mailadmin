<?php

namespace App\Http\Controllers\Mail\Api;

use App\Http\Controllers\Controller;
use App\Models\MailSession;
use App\Services\Mail\ActivityMap;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Действия, которых сервер иначе не видит: открыл окно письма и закрыл, не отправив,
 * открыл меню. Браузер копит их и присылает пачкой (resources/js/mail/track.js).
 * Названия — только из известного списка, подробность режется до 120 знаков.
 */
class ActivityController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'events' => ['required', 'array', 'max:50'],
            'events.*.a' => ['required', 'string', 'regex:/^[a-z][a-z.-]{1,39}$/'],
            'events.*.d' => ['nullable', 'string', 'max:120'],
            'events.*.ago' => ['nullable', 'integer', 'min:0', 'max:3600000'],
        ]);
        $user = mb_strtolower((string) $request->session()->get('mail.user', ''));
        $client = mb_substr(MailSession::device($request->userAgent()), 0, 40);
        $master = (bool) $request->session()->get('mail.master', false);
        $rows = [];
        foreach ($data['events'] as $e) {
            if (! isset(ActivityMap::LABELS[$e['a']])) {
                continue;
            }
            $rows[] = [
                'user' => $user, 'at' => now()->subMilliseconds((int) ($e['ago'] ?? 0)), 'action' => $e['a'],
                'folder' => null, 'detail' => isset($e['d']) && $e['d'] !== '' ? $e['d'] : null, 'ms' => 0, 'status' => 200,
                'client' => $client, 'source' => 'ui', 'master' => $master, 'error' => null,
            ];
        }
        if ($rows) {
            DB::table('webmail_activity')->insert($rows);
        }

        return response()->json(['ok' => true, 'saved' => count($rows)]);
    }
}
