<?php

namespace App\Http\Controllers\Mail;

use App\Http\Controllers\Controller;
use App\Services\Mail\MobileRelease;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Приложение «Почта» для Android со своего сервера: страница /app (скачать, как установить, что нового),
 * файл /app/pochta.apk и /api/v1/app/latest — у него установленное приложение спрашивает, есть ли новая версия.
 * Без входа: поставить приложение нужно до того, как в нём войти, а в файле нет ничего секретного.
 */
class MobileAppController extends Controller
{
    public function page(): Response
    {
        $r = MobileRelease::latest();
        $url = url('/app');
        // QR — чтобы с экрана компьютера открыть страницу на телефоне.
        $qr = (new Writer(new ImageRenderer(new RendererStyle(170, 0), new SvgImageBackEnd())))->writeString($url);

        return response()->view('mail.app', ['r' => $r, 'qr' => $qr, 'url' => $url], $r ? 200 : 404);
    }

    public function download(): BinaryFileResponse
    {
        $apk = MobileRelease::apkPath();
        abort_unless($apk, 404, 'Приложение ещё не выложено');
        $v = MobileRelease::latest()['version'] ?? '';

        return response()->download($apk, 'Pochta-' . ($v ?: 'latest') . '.apk', [
            'Content-Type' => 'application/vnd.android.package-archive',
            // Новый выпуск кладётся под тем же адресом — кэшировать нельзя, иначе скачается старый.
            'Cache-Control' => 'no-cache, no-store',
        ]);
    }

    public function latest(): JsonResponse
    {
        $r = MobileRelease::latest();
        abort_unless($r, 404, 'Приложение ещё не выложено');

        return response()->json($r + ['url' => url('/app/pochta.apk'), 'page' => url('/app')]);
    }
}
