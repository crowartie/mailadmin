<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\AdminAction;
use App\Services\Server\Certificate;
use Illuminate\Http\RedirectResponse;

/** Сертификат почтового узла: продление вручную, когда автоматическое не сработало. */
class CertController extends Controller
{
    public function __construct(
        private readonly Certificate $cert,
    ) {
    }

    public function renewCert(): RedirectResponse
    {
        $r = $this->cert->renew();
        AdminAction::log('cert.renew', $this->mailHost(), $r['ok'] ? 'успешно' : 'ошибка');

        return back()->with($r['ok'] ? 'success' : 'error', $r['ok'] ? 'Сертификат проверен и продлён, службы перечитали его' : 'Продление не удалось')->with('certOutput', $r['output']);
    }
}
