<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Приложение для Android со своего сервера: страница /app, файл и /api/v1/app/latest для самообновления. */
class MobileAppDownloadTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/mobile-release-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        config(['mailadmin.mobile_release_dir' => $this->dir]);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function publish(): string
    {
        $apk = str_repeat('APK', 1000);
        file_put_contents($this->dir . '/pochta.apk', $apk);
        file_put_contents($this->dir . '/latest.json', json_encode([
            'version' => '1.2.1', 'code' => 6, 'sha256' => hash('sha256', $apk), 'date' => '2026-09-26',
            'notes' => "- Файлы больше 10 МБ уходят ссылкой\n  и отправка за секунды.\n- **Планшет** горизонтально",
        ], JSON_UNESCAPED_UNICODE));

        return $apk;
    }

    public function test_без_выпуска_понятно_что_ещё_нет(): void
    {
        $this->get('http://localhost/app')->assertNotFound()->assertSee('Приложение ещё не выложено');
        $this->get('http://localhost/api/v1/app/latest')->assertNotFound();
        $this->get('http://localhost/app/pochta.apk')->assertNotFound();
    }

    public function test_страница_версия_что_нового_и_qr(): void
    {
        $this->publish();
        $this->get('http://localhost/app')->assertOk()
            ->assertSee('Скачать приложение')
            ->assertSee('Версия 1.2.1')
            ->assertSee('Файлы больше 10 МБ уходят ссылкой и отправка за секунды.')   // продолжение строки склеено
            ->assertSee('Планшет горизонтально')                                       // **жирный** без звёздочек
            ->assertSee('<svg', false);
    }

    public function test_файл_и_описание_для_самообновления(): void
    {
        $apk = $this->publish();
        $r = $this->get('http://localhost/app/pochta.apk')->assertOk();
        $this->assertSame('application/vnd.android.package-archive', $r->headers->get('Content-Type'));
        $this->assertStringContainsString('Pochta-1.2.1.apk', (string) $r->headers->get('Content-Disposition'));

        $this->get('http://localhost/api/v1/app/latest')->assertOk()->assertJson([
            'version' => '1.2.1', 'code' => 6, 'size' => strlen($apk), 'sha256' => hash('sha256', $apk),
            'url' => 'http://localhost/app/pochta.apk',
        ]);
    }

    public function test_на_адресе_админки_страницы_нет(): void
    {
        config(['areas.admin_port' => 8080, 'areas.mail_port' => 80]);   // не зависим от .env машины, где идут тесты
        $this->publish();
        $this->get('http://localhost:8080/app')->assertNotFound();
    }
}
