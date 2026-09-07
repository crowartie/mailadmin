<?php

namespace App\Http\Controllers;

use App\Models\SieveRule;
use App\Models\Vmail\Alias;
use App\Models\Vmail\Domain;
use App\Models\Vmail\Forwarding;
use App\Models\Vmail\Mailbox;
use App\Models\Vmail\UsedQuota;
use App\Services\ServerHealth;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(ServerHealth $health): Response
    {
        $top = UsedQuota::query()->orderByDesc('bytes')->first();

        return Inertia::render('Dashboard', [
            'stats' => [
                'mailboxes' => Mailbox::count(),
                'active' => Mailbox::where('active', 1)->count(),
                'domains' => Domain::count(),
                'aliases' => Alias::count(),
                'forwardings' => Forwarding::where('is_forwarding', 1)->count(),
                'rules' => SieveRule::count(),
            ],
            'services' => $health->services(),
            'storage' => [
                'usedBytes' => (int) UsedQuota::sum('bytes'),
                'messages' => (int) UsedQuota::sum('messages'),
                'top' => $top ? sprintf('%s · %.1f ГБ', $top->username, $top->bytes / 1073741824) : null,
            ],
            'today' => now()->locale('ru')->translatedFormat('j F, l'),
        ]);
    }
}
