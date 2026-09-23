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

        // Порт указываем прямо в адресе: за доверенным прокси Symfony берёт его
        // из Host, а не из SERVER_PORT, и подменять одну эту переменную бесполезно.
        $this->get('http://localhost:8080/')->assertRedirectContains('/login');
    }

    public function test_через_порт_вебпочты_админки_не_существует(): void
    {
        $this->ports();

        $this->get('http://localhost/')->assertNotFound();
    }

    public function test_запрос_веб_почты_без_входа_получает_401_а_не_страницу_входа(): void
    {
        $this->ports();

        // Раньше fetch уходил по переадресации на страницу входа, и её HTML показывался в окне «войдите заново».
        $this->get('http://localhost/mail/api/folders', ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertStatus(401)->assertJson(['message' => 'Сеанс закончился: почтой долго не пользовались']);
        $this->get('http://localhost/mail')->assertRedirectContains('/mail/login');
    }
}
