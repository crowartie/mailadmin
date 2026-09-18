<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\AdminAction;
use App\Models\User;
use App\Models\Vmail\Mailbox;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Администраторы: кого пускать в админку, с какой ролью и с какой проверкой входа.
 *
 * Последнего действующего владельца удалить или разжаловать нельзя — иначе в админку
 * не войдёт никто.
 */
class AdminsController extends Controller
{
    public function storeAdmin(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'name' => ['required', 'string', 'max:120'],
            'role' => ['required', Rule::in(array_keys(User::ROLES))],
            'imap' => ['boolean'],
            'password' => ['nullable', 'string', 'min:10', 'max:200'],
        ]);
        $imap = (bool) ($data['imap'] ?? false);
        if ($imap && ! Mailbox::query()->where('username', strtolower($data['email']))->exists()) {
            return back()->with('error', 'Для входа паролем ящика адрес должен быть почтовым ящиком на этом сервере');
        }
        if (! $imap && blank($data['password'] ?? null)) {
            return back()->with('error', 'Задайте пароль или включите вход паролем почтового ящика');
        }
        $u = User::create([
            'email' => strtolower($data['email']), 'name' => $data['name'], 'role' => $data['role'], 'is_active' => true,
            'imap_auth' => $imap, 'password' => $imap ? null : $data['password'],
        ]);
        AdminAction::log('admin.create', $u->email, $u->roleTitle());

        return back()->with('success', $u->name . ' — ' . $u->roleTitle());
    }

    public function updateAdmin(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'], 'role' => ['sometimes', Rule::in(array_keys(User::ROLES))],
            'active' => ['sometimes', 'boolean'], 'password' => ['nullable', 'string', 'min:10', 'max:200'], 'reset2fa' => ['sometimes', 'boolean'],
        ]);
        if ($user->id === auth()->id() && (($data['active'] ?? true) === false || (isset($data['role']) && $data['role'] !== $user->role))) {
            return back()->with('error', 'Себе нельзя менять роль и отключать доступ — попросите другого администратора');
        }
        if (isset($data['role']) && $user->role === 'owner' && $data['role'] !== 'owner' && User::query()->where('role', 'owner')->where('id', '!=', $user->id)->doesntExist()) {
            return back()->with('error', 'Должен остаться хотя бы один главный администратор');
        }
        $user->fill(array_intersect_key($data, array_flip(['name', 'role'])));
        if (array_key_exists('active', $data)) {
            $user->is_active = (bool) $data['active'];
        }
        if (filled($data['password'] ?? null)) {
            $user->password = $data['password'];
            $user->imap_auth = false;
        }
        if ($data['reset2fa'] ?? false) {
            $user->totp_secret = null;
            $user->totp_enabled_at = null;
        }
        $user->save();
        AdminAction::log('admin.update', $user->email, $user->roleTitle() . ($user->is_active ? '' : ', отключён'));

        return back()->with('success', 'Сохранено: ' . $user->name);
    }

    public function destroyAdmin(User $user): RedirectResponse
    {
        if ($user->id === auth()->id()) {
            return back()->with('error', 'Себя снять нельзя');
        }
        if ($user->role === 'owner' && User::query()->where('role', 'owner')->where('id', '!=', $user->id)->doesntExist()) {
            return back()->with('error', 'Должен остаться хотя бы один главный администратор');
        }
        $user->delete();
        AdminAction::log('admin.delete', $user->email);

        return back()->with('success', 'Доступ снят: ' . $user->email);
    }
}
