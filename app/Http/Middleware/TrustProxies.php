<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;

/**
 * Доверенные прокси из config/areas.php (TRUSTED_PROXIES). Список читается на каждом запросе через config(),
 * а не env() в bootstrap/app.php: при закэшированной конфигурации env() там пуст, и за Octane (nginx → 127.0.0.1)
 * приложение видело бы вместо клиента 127.0.0.1 и порт самого Octane.
 */
class TrustProxies extends Middleware
{
    protected function proxies()
    {
        $list = config('areas.trusted_proxies', []);

        return $list ? array_values($list) : null;
    }
}
