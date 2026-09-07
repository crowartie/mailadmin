<?php

namespace App\Http\Middleware;

use App\Support\Area;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Одно приложение, две зоны: админка живёт на своём порту, веб-почта — на своём.
 * Маршрут одной зоны, запрошенный через порт другой, не существует — отдаём 404,
 * чтобы админку нельзя было открыть через «пользовательский» порт.
 */
class EnsureArea
{
    public function handle(Request $request, Closure $next, string $area): Response
    {
        if (Area::current($request) !== $area) {
            abort(404);
        }

        return $next($request);
    }
}
