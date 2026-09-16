<?php

namespace App\Http\Controllers\Mail\Api;

use App\Http\Controllers\Controller;
use App\Models\AppPassword;
use App\Models\AppSetting;
use App\Models\EmployeeProfile;
use App\Models\MailLogin;
use App\Models\Webmail\Setting;
use App\Services\Mail\ImapSession;
use App\Services\Server\Sessions;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use PragmaRX\Google2FA\Google2FA;

/** Кабинет сотрудника: двухфакторная защита, пароли приложений, сеансы, история входов. */
class SecurityController extends Controller
{
    public function __construct(private readonly Google2FA $google2fa, private readonly Sessions $sessions)
    {
    }

    /** Сводка для раздела «Безопасность» в настройках. */
    public function show(Request $request, ImapSession $imap): JsonResponse
    {
        $user = $imap->user();
        $profile = EmployeeProfile::for($user);

        return response()->json([
            'totp' => (bool) (Setting::for($user)['totp_enabled'] ?? false),
            'required' => (bool) $profile->require_2fa,
            'appPasswordsAllowed' => (bool) (AppSetting::group('security')['app_passwords'] ?? true),
            'appPasswords' => AppPassword::query()->where('username', $user)->orderByDesc('id')->get()->map(fn (AppPassword $p) => ['id' => $p->id, 'name' => $p->name, 'created' => $p->created_at?->toIso8601String(), 'lastUsed' => $p->last_used_at?->toIso8601String()]),
            'sessions' => $this->sessions->all($user, $request->session()->getId()),
            'logins' => MailLogin::query()->where('user', $user)->orderByDesc('id')->limit(15)->get()->map(fn (MailLogin $l) => ['at' => $l->created_at->toIso8601String(), 'ip' => $l->ip, 'result' => $l->result, 'device' => \App\Models\MailSession::device($l->agent)]),
            'minPassword' => (int) (AppSetting::group('security')['min_password'] ?? 10),
        ]);
    }

    // ── Двухфакторная защита ──────────────────────────────────────────

    /** Новый секрет и QR-код; секрет живёт в сессии до подтверждения первым кодом. */
    public function twofaSetup(Request $request, ImapSession $imap): JsonResponse
    {
        $secret = $this->google2fa->generateSecretKey(32);
        $request->session()->put('mail.2fa.setup', Crypt::encryptString($secret));
        $url = $this->google2fa->getQRCodeUrl('Почта ' . config('areas.default_domain'), $imap->user(), $secret);
        $svg = (new Writer(new ImageRenderer(new RendererStyle(200, 0), new SvgImageBackEnd())))->writeString($url);

        return response()->json(['secret' => $secret, 'qr' => 'data:image/svg+xml;base64,' . base64_encode($svg)]);
    }

    public function twofaEnable(Request $request, ImapSession $imap): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'digits:6']]);
        $enc = $request->session()->get('mail.2fa.setup');
        abort_if(! $enc, 422, 'Сначала получите QR-код');
        $secret = Crypt::decryptString($enc);
        if (! $this->google2fa->verifyKey($secret, $data['code'], 1)) {
            return response()->json(['message' => 'Код не подошёл. Проверьте время на телефоне и попробуйте ещё раз.'], 422);
        }
        Setting::patch($imap->user(), ['totp_secret' => Crypt::encryptString($secret), 'totp_enabled' => true]);
        $request->session()->forget(['mail.2fa.setup', 'mail.force2fa']);
        $profile = EmployeeProfile::for($imap->user());
        if ($profile->exists && $profile->require_2fa) {
            $profile->require_2fa = false;
            $profile->save();
        }

        return response()->json(['ok' => true]);
    }

    public function twofaDisable(Request $request, ImapSession $imap): JsonResponse
    {
        $data = $request->validate(['password' => ['required', 'string']]);
        abort_unless(hash_equals($imap->password(), $data['password']), 422, 'Пароль указан неверно');
        Setting::patch($imap->user(), ['totp_secret' => null, 'totp_enabled' => false]);

        return response()->json(['ok' => true]);
    }

    // ── Пароли приложений ─────────────────────────────────────────────

    public function storeAppPassword(Request $request, ImapSession $imap): JsonResponse
    {
        abort_unless((bool) (AppSetting::group('security')['app_passwords'] ?? true), 403, 'Пароли приложений отключены администратором');
        $data = $request->validate(['name' => ['required', 'string', 'max:60'], 'password' => ['required', 'string']]);
        abort_unless(hash_equals($imap->password(), $data['password']), 422, 'Пароль указан неверно');
        abort_if(AppPassword::query()->where('username', $imap->user())->count() >= 10, 422, 'Слишком много паролей приложений — отзовите ненужные');
        $gen = AppPassword::generate();
        $row = AppPassword::create(['username' => $imap->user(), 'name' => $data['name'], 'password' => $gen['hash'], 'active' => true]);

        return response()->json(['id' => $row->id, 'name' => $row->name, 'plain' => implode(' ', str_split($gen['plain'], 4))], 201);
    }

    public function destroyAppPassword(ImapSession $imap, int $id): JsonResponse
    {
        AppPassword::query()->where('username', $imap->user())->findOrFail($id)->delete();

        return response()->json(['ok' => true]);
    }

    // ── Сеансы ────────────────────────────────────────────────────────

    public function kick(Request $request, ImapSession $imap): JsonResponse
    {
        $data = $request->validate(['id' => ['required', 'string', 'max:200']]);
        // Только свои сеансы.
        $mine = array_column($this->sessions->all($imap->user()), 'id');
        abort_unless(in_array($data['id'], $mine, true), 403);
        $this->sessions->kick($data['id']);

        return response()->json(['sessions' => $this->sessions->all($imap->user(), $request->session()->getId())]);
    }

    public function kickOthers(Request $request, ImapSession $imap): JsonResponse
    {
        foreach ($this->sessions->all($imap->user(), $request->session()->getId()) as $s) {
            if (! $s['me']) {
                $this->sessions->kick($s['id']);
            }
        }

        return response()->json(['sessions' => $this->sessions->all($imap->user(), $request->session()->getId())]);
    }
}
