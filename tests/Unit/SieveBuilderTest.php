<?php

namespace Tests\Unit;

use App\Services\Mail\SieveBuilder;
use Tests\TestCase;

/**
 * Правило «папка по отправителю»: одно правило на домен, письма ложатся в «Родитель/<адрес до @>».
 * Точка в имени папки — разделитель Dovecot, поэтому части адреса собираются через «-».
 */
class SieveBuilderTest extends TestCase
{
    private function rule(array $action, string $parent = ''): array
    {
        return [[
            'id' => 1, 'name' => 'Полюс', 'enabled' => true, 'match' => 'all', 'stop' => true,
            'conditions' => [['field' => 'from', 'op' => 'contains', 'value' => '@polyus.com']],
            'actions' => [['type' => $action[0], 'value' => $parent]],
        ]];
    }

    public function test_папка_по_адресу_отправителя_внутри_родителя(): void
    {
        $s = (new SieveBuilder)->build($this->rule(['move_by_sender'], 'INBOX/&BB8EPgQ7BE4EQQ-'), null);

        $this->assertStringContainsString('"variables"', $s);
        $this->assertStringContainsString('address :contains "from" "@polyus.com"', $s);
        $this->assertStringContainsString('if address :matches :localpart "from" "*.*.*" { set "n" "${1}-${2}-${3}"; }', $s);
        $this->assertStringContainsString('elsif address :matches :localpart "from" "*.*" { set "n" "${1}-${2}"; }', $s);
        $this->assertStringContainsString('elsif address :matches :localpart "from" "*" { set "n" "${1}"; }', $s);
        // Родитель раскодирован из UTF-7 и стоит перед ${n}; сама переменная не экранирована.
        $this->assertStringContainsString('fileinto :create "INBOX/Полюс/${n}";', $s);
        $this->assertStringContainsString('stop;', $s);
    }

    public function test_папка_по_домену_в_корне(): void
    {
        $s = (new SieveBuilder)->build($this->rule(['move_by_domain']), null);

        $this->assertStringContainsString('address :matches :domain "from" "*.*.*" { set "n" "${1}-${2}"; }', $s);
        $this->assertStringContainsString('address :matches :domain "from" "*.*" { set "n" "${1}"; }', $s);
        $this->assertStringContainsString('fileinto :create "${n}";', $s);
    }

    public function test_обычный_перенос_не_изменился(): void
    {
        $s = (new SieveBuilder)->build($this->rule(['move'], 'INBOX/&BB8EPgQ7BE4EQQ-'), null);

        $this->assertStringContainsString('fileinto :create "INBOX/Полюс";', $s);
        $this->assertStringNotContainsString('${n}', $s);
    }
}
