<?php

namespace App\Http\Controllers;

use App\Models\AdminLogin;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SecurityController extends Controller
{
    public function index(Request $request): Response
    {
        $logins = AdminLogin::query()
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(fn (AdminLogin $l) => [
                'id' => $l->id,
                'at' => $l->created_at->format('d.m.Y H:i:s'),
                'email' => $l->email,
                'ip' => $l->ip,
                'result' => $l->result,
                'label' => match ($l->result) {
                    'ok' => 'вход',
                    'bad_password' => 'неверный пароль',
                    'bad_code' => 'неверный код',
                    'blocked' => 'заблокировано',
                    default => $l->result,
                },
                'kind' => $l->result === 'ok' ? 'ok' : ($l->result === 'blocked' ? 'no' : 'warn'),
                'agent' => $l->user_agent,
            ]);

        $admins = User::query()->orderBy('email')->get()->map(fn (User $u) => [
            'email' => $u->email,
            'name' => $u->name,
            'twoFactor' => $u->hasTwoFactor(),
            'active' => $u->is_active,
            'me' => $u->id === $request->user()->id,
        ]);

        return Inertia::render('Security/Index', [
            'logins' => $logins,
            'admins' => $admins,
            'failures15m' => AdminLogin::recentFailures($request->ip()),
        ]);
    }
}
