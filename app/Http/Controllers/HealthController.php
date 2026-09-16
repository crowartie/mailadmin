<?php

namespace App\Http\Controllers;

use App\Services\Server\Ctl;
use App\Services\Server\HealthChecks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

/** Страница «Состояние»: нагрузка, диски, очередь и список проверок с подсказками. */
class HealthController extends Controller
{
    public function __construct(private readonly HealthChecks $checks)
    {
    }

    public function index(): Response
    {
        return Inertia::render('Health/Index', $this->payload() + ['available' => Ctl::available()]);
    }

    public function json(Request $request): JsonResponse
    {
        if ($request->boolean('fresh')) {
            foreach (['health.sysinfo', 'health.services', 'antispam.bayes', 'antispam.net', 'disk.' . md5('/var/vmail'), 'disk.' . md5('/')] as $k) {
                Cache::forget($k);
            }
        }

        return response()->json($this->payload());
    }

    private function payload(): array
    {
        $si = $this->checks->sysinfo();
        $checks = $this->checks->checks($si);
        $bad = count(array_filter($checks, fn ($c) => $c['kind'] === 'no'));
        $warn = count(array_filter($checks, fn ($c) => $c['kind'] === 'warn'));

        return [
            'tiles' => $this->checks->tiles($si),
            'checks' => $checks,
            'summary' => ['bad' => $bad, 'warn' => $warn, 'total' => count($checks)],
            'at' => now()->format('H:i'),
        ];
    }
}
