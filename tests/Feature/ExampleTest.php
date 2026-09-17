<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Заглушка от Laravel проверяла, что «/» отдаёт 200, и падала с самого начала:
 * корень — это админка, и неавторизованного она уводит на вход.
 * Заодно закрепляем главное правило разделения зон: админка живёт на своём порту,
 * и через порт веб-почты её маршрутов просто нет (404, а не «нет доступа»).
 */
class ExampleTest extends TestCase
{
    /** Порты зон задаём сами, чтобы проверка не зависела от .env сервера, где её запускают. */
    private function ports(): void
    {
        config(['areas.admin_port' => 8080, 'areas.mail_port' => 80]);
    }

    public function test_админка_уводит_неавторизованного_на_вход(): void
    {
        $this->ports();
        $this->withServerVariables(['SERVER_PORT' => 8080]);

        $this->get('/')->assertRedirectContains('/login');
    }

    public function test_через_порт_вебпочты_админки_не_существует(): void
    {
        $this->ports();
        $this->withServerVariables(['SERVER_PORT' => 80]);

        $this->get('/')->assertNotFound();
    }
}
