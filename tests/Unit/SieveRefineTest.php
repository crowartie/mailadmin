<?php

namespace Tests\Unit;

use App\Services\Mail\SieveBuilder;
use Tests\TestCase;

/**
 * Сложные правила (просьба Носкова): папка по имени отправителя, уточнения внутри правила,
 * свой скрипт Sieve рядом с правилами конструктора.
 */
class SieveRefineTest extends TestCase
{
    private function rule(array $extra = []): array
    {
        return [[
            'id' => 1, 'name' => 'Дельта', 'enabled' => true, 'match' => 'all', 'stop' => true,
            'conditions' => [['field' => 'from', 'op' => 'ends', 'value' => '@delta.test']],
            'actions' => [['type' => 'move', 'value' => 'Delta']],
        ] + $extra];
    }

    public function test_папка_по_имени_отправителя_с_запасом_по_адресу(): void
    {
        $s = (new SieveBuilder)->build([['id' => 1, 'enabled' => true, 'conditions' => [], 'actions' => [['type' => 'move_by_name', 'value' => 'Delta']]]], null);

        $this->assertStringContainsString('if header :matches "from" "\\"*\\" <*>" { set "n" "${1}"; }', $s, 'имя в кавычках');
        $this->assertStringContainsString('elsif header :matches "from" "* <*>" { set "n" "${1}"; }', $s, 'имя без кавычек');
        $this->assertStringContainsString('if string :matches "${n}" ["", "*.*", "*/*", " *", "* "] {', $s, 'нет имени или в нём разделитель папок — по адресу');
        $this->assertStringContainsString('address :matches :localpart "from" "*.*.*" { set "n" "${1}-${2}-${3}"; }', $s);
        $this->assertStringContainsString('fileinto :create "Delta/${n}";', $s);
    }

    public function test_уточнения_становятся_ветками_if_elsif_else(): void
    {
        $rules = $this->rule(['refine' => [
            ['match' => 'all', 'conditions' => [['field' => 'subject', 'op' => 'contains', 'value' => 'счёт']], 'actions' => [['type' => 'move', 'value' => 'Delta/Bills']]],
            ['match' => 'any', 'conditions' => [['field' => 'from', 'op' => 'is', 'value' => 'boss@delta.test'], ['field' => 'from', 'op' => 'is', 'value' => 'ceo@delta.test']], 'actions' => [['type' => 'flag'], ['type' => 'move', 'value' => 'Delta/Boss']]],
            ['match' => 'all', 'conditions' => [], 'actions' => [['type' => 'seen']]],   // без условий — «остальным»
            ['match' => 'all', 'conditions' => [], 'actions' => []],                       // пустое — пропускается
        ]]);
        $s = (new SieveBuilder)->build($rules, null);

        $this->assertStringContainsString('if address :matches "from" "*@delta.test" {', $s);
        $this->assertMatchesRegularExpression('/if header :contains "subject" "счёт" \{\s+fileinto :create "Delta\/Bills";\s+\} elsif anyof\(address :is "from" "boss@delta.test", address :is "from" "ceo@delta.test"\) \{\s+addflag "\\\\\\\\Flagged";\s+fileinto :create "Delta\/Boss";\s+\} elsif true \{\s+addflag "\\\\\\\\Seen";\s+\} else \{\s+fileinto :create "Delta";\s+\}\s+stop;/s', $s);
    }

    public function test_без_уточнений_скрипт_прежний(): void
    {
        $s = (new SieveBuilder)->build($this->rule(['refine' => []]), null);
        $this->assertStringContainsString("if address :matches \"from\" \"*@delta.test\" {\n    fileinto :create \"Delta\";\n    stop;\n}", $s);
        $this->assertStringNotContainsString('else', $s);
    }

    public function test_свой_скрипт_после_правил_а_его_require_в_общей_шапке(): void
    {
        $custom = "require [\"fileinto\", \"regex\"];\nrequire \"envelope\";\n# моё\nif envelope :regex \"from\" \"^x\" { fileinto \"X\"; }";
        $s = (new SieveBuilder)->build($this->rule(), null, [], $custom);

        $this->assertMatchesRegularExpression('/^require \[.*"regex".*"envelope".*\];/', $s, 'расширения слились в одну шапку');
        $this->assertSame(1, substr_count($s, 'require ['), 'require только один — в начале');
        $this->assertStringContainsString("# ── Свой скрипт ──\n# моё\nif envelope :regex", $s);
        $this->assertGreaterThan(strpos($s, 'fileinto :create "Delta"'), strpos($s, '# моё'), 'свой скрипт идёт после правил');
        $this->assertSame([['fileinto', 'regex'], 'x'], SieveBuilder::splitRequire("require [\"fileinto\",\"regex\"];\nx"));
        $this->assertSame([[], ''], SieveBuilder::splitRequire('   '));
    }
}
