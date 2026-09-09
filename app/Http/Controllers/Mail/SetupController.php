<?php

namespace App\Http\Controllers\Mail;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Services\Server\Certificate;
use App\Services\Server\Ctl;
use App\Support\Area;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * «Подключить телефон и программы» — открытая страница со входа (как «Интеграция с устройством» в Kerio):
 * профиль для iPhone/iPad/Mac, инструкции для Android и Outlook, параметры серверов, сертификат, QR-код.
 */
class SetupController extends Controller
{
    public function index(Request $request): Response
    {
        $domain = (string) config('areas.default_domain');
        $base = Area::mailBase();
        $email = strtolower(trim((string) $request->query('email', (string) $request->session()->get('mail.user', ''))));
        if ($email !== '' && ! preg_match('/^[^\s@]+@[^\s@]+$/', $email)) {
            $email = '';
        }
        $url = $base . '/mail/setup' . ($email !== '' ? '?email=' . rawurlencode($email) : '');
        $qr = '';
        try {
            $qr = (new Writer(new ImageRenderer(new RendererStyle(180, 0), new SvgImageBackEnd())))->writeString($url);
            $qr = preg_replace('/<\?xml[^>]*\?>\s*/', '', $qr);
        } catch (\Throwable) {
        }
        $cert = null;
        try {
            $cert = app(Certificate::class)->info();
        } catch (\Throwable) {
        }
        $issuer = (string) ($cert['issuer'] ?? '');
        $security = AppSetting::group('security');

        return Inertia::render('Mail/Setup', [
            'user' => (string) $request->session()->get('mail.user', ''),
            'settings' => ['theme' => 'system'],
            'domain' => $domain,
            'base' => $base,
            'email' => $email,
            'hosts' => ['imap' => 'imap.' . $domain, 'smtp' => 'smtp.' . $domain, 'dav' => $base . '/dav/', 'web' => parse_url($base, PHP_URL_HOST)],
            'qr' => $qr,
            'pageUrl' => $url,
            'cert' => $cert ? ['issuer' => $issuer, 'until' => $cert['to'] ?? null, 'trusted' => (bool) preg_match('/Let\'s Encrypt|DigiCert|GlobalSign|Sectigo|ZeroSSL|Thawte|GeoTrust|Comodo/i', $issuer)] : null,
            'appPasswords' => (bool) ($security['app_passwords'] ?? true),
        ]);
    }

    /** Сертификат сервера (открытая часть) — для устройств, которым нужно доверие вручную. */
    public function certificate(): HttpResponse
    {
        try {
            $pem = Ctl::out('cert-pem');
        } catch (\Throwable $e) {
            abort(503, 'Сертификат недоступен: ' . $e->getMessage());
        }
        abort_if(! str_contains($pem, 'BEGIN CERTIFICATE'), 503, 'Сертификат недоступен');
        $name = 'mail.' . config('areas.default_domain') . '.crt';

        return response($pem, 200, [
            'Content-Type' => 'application/x-x509-ca-cert',
            'Content-Disposition' => 'attachment; filename="' . $name . '"',
        ]);
    }
}
