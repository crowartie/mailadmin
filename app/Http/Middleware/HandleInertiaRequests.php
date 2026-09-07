<?php

namespace App\Http\Middleware;

use App\Models\SieveRule;
use App\Models\Vmail\Alias;
use App\Models\Vmail\Domain;
use App\Models\Vmail\Mailbox;
use App\Services\ServerHealth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Данные для каждой страницы: флеш-сообщения, кто вошёл, счётчики в меню, состояние сервера.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $isAdminArea = \App\Support\Area::isAdmin($request);

        return array_merge(parent::share($request), [
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
            'auth' => [
                'user' => fn () => $request->user() ? [
                    'email' => $request->user()->email,
                    'name' => $request->user()->name,
                    'twoFactor' => $request->user()->hasTwoFactor(),
                ] : null,
            ],
            'mailUser' => fn () => $request->session()->get('mail.user'),
            // Меню и состояние служб нужны только админке; веб-почте лишние запросы ни к чему.
            'nav' => $isAdminArea && $request->user() ? fn () => [
                'counts' => Cache::remember('nav.counts', 60, fn () => [
                    'mailboxes' => Mailbox::count(),
                    'aliases' => Alias::count(),
                    'domains' => Domain::count(),
                    'rules' => SieveRule::count(),
                ]),
                'health' => app(ServerHealth::class)->summary(),
            ] : null,
        ]);
    }
}
