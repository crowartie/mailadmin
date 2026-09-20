<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\AdminAction;
use App\Models\AppSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Облако для больших вложений: подключение к Nextcloud, проверка связи, порог размера.
 *
 * Подключение идёт по потоку авторизации Nextcloud, поэтому здесь есть и «начать»,
 * и «опросить», и ручной ввод пароля приложения на случай, если поток не прошёл.
 */
class CloudController extends Controller
{
    public function cloudStart(Request $request): \Illuminate\Http\JsonResponse
    {
        $url = $request->validate(['url' => ['required', 'string', 'max:200']])['url'];
        try {
            $flow = \App\Services\Cloud\Nextcloud::loginFlowStart($url);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        $request->session()->put('cloud.flow', $flow);

        return response()->json(['login' => $flow['login']]);
    }

    /** Шаг 2: опрос, пока администратор подтверждает доступ в Nextcloud. */
    public function cloudPoll(Request $request): \Illuminate\Http\JsonResponse
    {
        $flow = $request->session()->get('cloud.flow');
        if (! $flow) {
            return response()->json(['message' => 'Подключение не начато'], 422);
        }
        try {
            $r = \App\Services\Cloud\Nextcloud::loginFlowPoll($flow['endpoint'], $flow['token']);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        if (! $r) {
            return response()->json(['done' => false]);
        }
        \App\Services\Cloud\Nextcloud::saveCredentials($r['server'] ?: $flow['url'], $r['loginName'], $r['appPassword']);
        $request->session()->forget('cloud.flow');
        $nc = new \App\Services\Cloud\Nextcloud();
        $folderError = null;
        try {
            $nc->ensureFolder($nc->folder());
        } catch (\Throwable $e) {
            $folderError = $e->getMessage();
        }
        AdminAction::log('settings.update', 'облако', 'подключён ' . $r['loginName'] . ' @ ' . ($r['server'] ?: $flow['url']));

        return response()->json(['done' => true, 'user' => $r['loginName'], 'folderError' => $folderError]);
    }

    /** Ручное подключение: логин и пароль приложения, созданный в Nextcloud. */
    public function cloudManual(Request $request): RedirectResponse
    {
        $data = $request->validate(['url' => ['required', 'string', 'max:200'], 'login' => ['required', 'string', 'max:120'], 'app_password' => ['required', 'string', 'max:200']]);
        $url = rtrim(trim($data['url']), '/');
        if (! preg_match('~^https?://~i', $url)) {
            $url = 'https://' . $url;
        }
        \App\Services\Cloud\Nextcloud::saveCredentials($url, $data['login'], $data['app_password']);
        $nc = new \App\Services\Cloud\Nextcloud();
        $st = $nc->status();
        if (! $st['ok']) {
            \App\Services\Cloud\Nextcloud::disconnect();

            return back()->with('error', 'Не подключилось: ' . $st['message']);
        }
        try {
            $nc->ensureFolder($nc->folder());
        } catch (\Throwable $e) {
            return back()->with('error', 'Подключено, но папка не создана: ' . $e->getMessage());
        }
        AdminAction::log('settings.update', 'облако', 'подключён ' . $data['login']);

        return back()->with('success', 'Nextcloud подключён, папка «' . $nc->folder() . '» готова');
    }

    public function cloudSave(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['boolean'], 'folder' => ['required', 'string', 'max:120', 'regex:#^[^/\\:*?"<>|]+(/[^/\\:*?"<>|]+)*$#u'],
            'threshold_mb' => ['required', 'integer', 'min:1', 'max:1024'], 'expire_days' => ['required', 'integer', 'min:0', 'max:3650'], 'link_password' => ['nullable', 'string', 'max:64'],
        ]);
        AppSetting::put('cloud', ['enabled' => (bool) ($data['enabled'] ?? false), 'folder' => trim($data['folder'], '/'), 'threshold_mb' => $data['threshold_mb'], 'expire_days' => $data['expire_days'], 'link_password' => (string) ($data['link_password'] ?? '')]);
        if (\App\Services\Cloud\Nextcloud::enabled()) {
            try {
                $nc = new \App\Services\Cloud\Nextcloud();
                $nc->ensureFolder($nc->folder());
            } catch (\Throwable $e) {
                return back()->with('error', 'Сохранено, но папка не создана: ' . $e->getMessage());
            }
        }
        AdminAction::log('settings.update', 'облако');

        return back()->with('success', 'Настройки облака сохранены');
    }

    /** Своё хранилище больших вложений (files.<домен>): включение, адрес, пороги, срок. */
    public function filesSave(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['boolean'],
            'host' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/i'],
            'threshold_mb' => ['required', 'integer', 'min:1', 'max:1024'],
            'max_mb' => ['required', 'integer', 'min:1', 'max:2048'],
            'expire_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'preview_mb' => ['required', 'integer', 'min:0', 'max:200'],
            'keep_days' => ['required', 'integer', 'min:0', 'max:3650'],
            'user_quota_gb' => ['required', 'integer', 'min:1', 'max:1000'],
        ]);
        $enabled = (bool) ($data['enabled'] ?? false);
        if ($enabled && ! \App\Http\Controllers\Mail\FilesController::ready()) {
            return back()->with('error', 'Каталог хранилища не готов — выполните на сервере: sudo bash /opt/mailadmin/deploy/files-host.sh');
        }
        AppSetting::put(\App\Services\Cloud\LocalFiles::GROUP, [
            'enabled' => $enabled, 'host' => strtolower(trim($data['host'])),
            'threshold_mb' => (int) $data['threshold_mb'], 'max_mb' => (int) $data['max_mb'], 'expire_days' => (int) $data['expire_days'],
            'preview_mb' => (int) $data['preview_mb'], 'keep_days' => (int) $data['keep_days'], 'user_quota_gb' => (int) $data['user_quota_gb'],
        ]);
        AdminAction::log('settings.update', 'хранилище файлов');

        return back()->with('success', 'Настройки хранилища сохранены');
    }

    public function cloudDisconnect(): RedirectResponse
    {
        \App\Services\Cloud\Nextcloud::disconnect();
        AdminAction::log('settings.update', 'облако', 'отключено');

        return back()->with('success', 'Облако отключено — вложения снова уходят внутри писем');
    }

    /** Проверка: положить пробный файл и получить ссылку. */
    public function cloudTest(): RedirectResponse
    {
        try {
            $nc = new \App\Services\Cloud\Nextcloud();
            $tmp = tempnam(sys_get_temp_dir(), 'nc');
            file_put_contents($tmp, 'Проверка облака почтового сервера ' . config('areas.default_domain') . ' — ' . now()->format('d.m.Y H:i'));
            $r = $nc->publish($tmp, 'проверка.txt', 'admin');
            @unlink($tmp);
        } catch (\Throwable $e) {
            return back()->with('error', 'Проверка не прошла: ' . mb_substr($e->getMessage(), 0, 200));
        }

        return back()->with('success', 'Файл загружен, ссылка: ' . $r['url'] . ($r['expires'] ? ' (до ' . $r['expires'] . ')' : ''));
    }
}
