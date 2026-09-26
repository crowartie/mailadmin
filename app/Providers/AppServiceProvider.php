<?php

namespace App\Providers;

use App\Services\Cloud\CloudLedger;
use App\Services\Cloud\DbCloudLedger;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Учёт облака (ссылки, загрузки, корзина) — в базе; тесты подставляют ArrayCloudLedger.
        $this->app->bind(CloudLedger::class, DbCloudLedger::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
