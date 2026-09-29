<?php

namespace Tests\Unit;

use App\Services\Server\ExternalSenders;
use Tests\TestCase;

/**
 * «Сайт компании» среди внешних отправителей: диапазон берётся из настроек сервера, а не из SPF,
 * чтобы форма сайта слала письма без пароля ящика в своём коде (обращение №41).
 */
class ExternalSendersSiteTest extends TestCase
{
    public function test_диапазоны_из_настроек_с_маской_по_умолчанию(): void
    {
        config(['areas.site_hosting_ips' => ['31.31.196.3', ' 10.0.0.0/8 ', '2001:db8::1', 'мусор', '']]);
        $this->assertSame(['31.31.196.3/32', '10.0.0.0/8', '2001:db8::1/128'], ExternalSenders::siteRanges());
        $this->assertSame(['31.31.196.3/32', '10.0.0.0/8', '2001:db8::1/128'], (new ExternalSenders)->ranges('site'));
        $this->assertSame(3, ExternalSenders::cachedCounts()['site']);
        $this->assertArrayHasKey('site', ExternalSenders::labels());
    }

    public function test_без_настройки_пусто(): void
    {
        config(['areas.site_hosting_ips' => []]);
        $this->assertSame([], ExternalSenders::siteRanges());
        $this->assertSame(0, ExternalSenders::cachedCounts()['site']);
    }
}
