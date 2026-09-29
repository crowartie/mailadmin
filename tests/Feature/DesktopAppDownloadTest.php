<?php

namespace Tests\Feature;

use App\Services\Mail\DesktopRelease;
use Tests\TestCase;

/**
 * Приложение для Windows со своего сервера (desktop/): /app/windows/* для самообновления,
 * постоянная ссылка на установщик и карточка на странице /app. Имена файлов — только из списка.
 */
class DesktopAppDownloadTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        config(['areas.admin_port' => 8080, 'areas.mail_port' => 80]);
        $this->dir = sys_get_temp_dir() . '/desktop-release-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        config(['mailadmin.desktop_release_dir' => $this->dir, 'mailadmin.mobile_release_dir' => $this->dir . '/нет']);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function publish(string $version = '1.0.0'): string
    {
        $exe = 'MZ' . str_repeat('X', 3000);
        $name = "Pochta-Setup-$version.exe";
        file_put_contents("$this->dir/$name", $exe);
        file_put_contents("$this->dir/$name.blockmap", 'blockmap');
        file_put_contents("$this->dir/latest.yml", "version: $version\nfiles:\n  - url: $name\n    sha512: abc==\n    size: 3002\npath: $name\nsha512: abc==\nreleaseDate: '2026-09-30T02:10:00.000Z'\n");
        file_put_contents("$this->dir/notes.txt", "- Значок в трее со счётчиком\n  и кружок на панели задач.\n- Уведомления о письмах");

        return $exe;
    }

    public function test_без_выпуска_ничего_не_отдаём(): void
    {
        $this->assertNull(DesktopRelease::latest());
        $this->get('http://localhost/app/windows/latest.yml')->assertNotFound();
        $this->get('http://localhost/app/pochta-setup.exe')->assertNotFound();
        $this->get('http://localhost/app')->assertNotFound();
    }

    public function test_описание_выпуска_из_latest_yml(): void
    {
        $this->publish('1.2.3');
        $r = DesktopRelease::latest();
        $this->assertSame('1.2.3', $r['version']);
        $this->assertSame('Pochta-Setup-1.2.3.exe', $r['file']);
        $this->assertSame(3002, $r['size']);
        $this->assertSame('2026-09-30', $r['date']);
        $this->assertStringContainsString('Значок в трее', $r['notes']);

        unlink("$this->dir/Pochta-Setup-1.2.3.exe");
        $this->assertNull(DesktopRelease::latest(), 'описание без установщика — выпуск не готов');
    }

    public function test_файлы_самообновления_и_постоянная_ссылка(): void
    {
        $exe = $this->publish();
        $yml = $this->get('http://localhost/app/windows/latest.yml');
        $yml->assertOk();
        $this->assertStringStartsWith('text/yaml', (string) $yml->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', (string) $yml->headers->get('Cache-Control'));
        $this->assertStringContainsString('version: 1.0.0', $yml->streamedContent());

        $bin = $this->get('http://localhost/app/windows/Pochta-Setup-1.0.0.exe');
        $bin->assertOk();
        $this->assertSame($exe, $bin->streamedContent());
        $this->get('http://localhost/app/windows/Pochta-Setup-1.0.0.exe.blockmap')->assertOk();

        $dl = $this->get('http://localhost/app/pochta-setup.exe');
        $dl->assertOk();
        $this->assertStringContainsString('Pochta-Setup-1.0.0.exe', (string) $dl->headers->get('Content-Disposition'));
    }

    public function test_чужие_имена_и_пути_не_отдаются(): void
    {
        $this->publish();
        file_put_contents("$this->dir/secret.txt", 'нельзя');
        foreach (['secret.txt', 'notes.txt', 'Pochta-Setup-1.0.exe', 'pochta-setup-1.0.0.exe', '..%2F..%2F.env', 'latest.yml.bak'] as $name) {
            $this->get('http://localhost/app/windows/' . $name)->assertNotFound();
        }
        $this->assertNull(DesktopRelease::path('../latest.yml'));
        $this->assertNull(DesktopRelease::path('latest.yml/../../x'));
    }

    public function test_страница_приложений_показывает_windows(): void
    {
        $this->publish('1.0.0');
        $page = $this->get('http://localhost/app');
        $page->assertOk();
        $page->assertSee('Почта для Windows');
        $page->assertSee('Версия 1.0.0', false);
        $page->assertSee('/app/pochta-setup.exe', false);
        $page->assertSee('Значок в трее со счётчиком и кружок на панели задач.', false);
        $page->assertSee('На экран “Домой”', false);
    }
}
