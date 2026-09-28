<?php

namespace Tests\Unit;

use App\Services\Dav\ContactMerge;
use PHPUnit\Framework\TestCase;

/** Личная карточка того же человека вливается в карточку сотрудника: по почте или по имени в любом порядке слов. */
class ContactMergeTest extends TestCase
{
    private function employee(string $fn, string $mail): array
    {
        return ['uri' => 'e.vcf', 'fn' => $fn, 'emails' => [['value' => $mail, 'type' => 'work']], 'phones' => [], 'employee' => true, 'readonly' => true, 'book' => 'employees', 'bookName' => 'Сотрудники'];
    }

    private function personal(string $fn, array $phones = [], array $emails = []): array
    {
        return ['uri' => 'p.vcf', 'fn' => $fn, 'emails' => array_map(fn ($m) => ['value' => $m, 'type' => 'home'], $emails), 'phones' => array_map(fn ($p) => ['value' => $p, 'type' => 'cell'], $phones), 'employee' => false, 'readonly' => false, 'book' => 'personal', 'bookName' => 'Мои контакты'];
    }

    public function test_по_имени_в_другом_порядке_телефон_переезжает_к_сотруднику(): void
    {
        $out = ContactMerge::merge([$this->personal('Андрей Геннадьевич Вырупаев', ['+7 (902) 170-38-66']), $this->employee('Вырупаев Андрей Геннадьевич', 'viang@innotec.su')]);
        $this->assertCount(2, $out, 'личная карточка остаётся для своей книги');
        $this->assertSame('e.vcf', $out[0]['mergedInto']);
        $this->assertTrue($out[1]['employee']);
        $this->assertSame('+7 (902) 170-38-66', $out[1]['phones'][0]['value']);
        $this->assertSame('Мои контакты', $out[1]['phones'][0]['source']);
        $this->assertSame('p.vcf', $out[1]['merged'][0]['uri']);
    }

    public function test_по_почте_даже_при_другом_имени_и_без_дублей_номеров(): void
    {
        $e = $this->employee('Иванов Иван Иванович', 'ivan@innotec.su');
        $e['phones'] = [['value' => '+79025485650', 'type' => 'work']];
        $out = ContactMerge::merge([$e, $this->personal('Ваня', ['8 902 548 56 50', '+7 (999) 111-22-33'], ['ivan@innotec.su'])]);
        $this->assertSame('e.vcf', $out[1]['mergedInto']);
        $this->assertCount(2, $out[0]['phones'], 'тот же номер в другом написании не дублируется');
        $this->assertSame('+7 (999) 111-22-33', $out[0]['phones'][1]['value']);
    }

    public function test_одно_слово_имени_и_чужие_не_сливаются(): void
    {
        $cards = [$this->employee('Иванов Иван', 'ivan@innotec.su'), $this->personal('Иван', ['111']), $this->personal('Петров Пётр', ['222'])];
        $this->assertSame([], array_filter(ContactMerge::merge($cards), fn ($c) => isset($c['mergedInto'])));
        $this->assertSame('иван иванов', ContactMerge::nameKey(['fn' => 'Иван  Иванов']));
    }
}
