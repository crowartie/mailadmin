<?php

namespace Tests\Unit;

use App\Services\Mail\SheetPreview;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Таблица в просмотрщике таблицей (SheetPreview): значения — как в Excel (формат чисел и дат, результат формул
 * из файла), ширины, высоты, объединения, закрепление, скрытые строки и столбцы, оформление; xls, xlsx, csv в cp1251.
 */
class SheetPreviewTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/sheet-test-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function book(): Spreadsheet
    {
        $b = new Spreadsheet();
        $ws = $b->getActiveSheet();
        $ws->setTitle('Счёт');
        $ws->setCellValue('A1', 'Счёт на оплату № 12');
        $ws->mergeCells('A1:D1');
        $ws->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $ws->getStyle('A1')->getAlignment()->setHorizontal('center');
        $ws->fromArray([['Товар', 'Кол-во', 'Цена', 'Сумма']], null, 'A3');
        $ws->getStyle('A3:D3')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFF2CC');
        $ws->getStyle('A3:D3')->getBorders()->getBottom()->setBorderStyle(Border::BORDER_MEDIUM);
        $ws->setCellValue('A4', 'Сетка ПУ');
        $ws->setCellValue('B4', 3);
        $ws->setCellValue('C4', 1234.5);
        $ws->setCellValue('D4', '=B4*C4');
        $ws->getStyle('C4:D4')->getNumberFormat()->setFormatCode('#,##0.00');
        $ws->setCellValue('A5', 'Дата');
        $ws->setCellValue('B5', 46295);   // 30.09.2026
        $ws->getStyle('B5')->getNumberFormat()->setFormatCode('dd.mm.yyyy');
        $ws->setCellValue('A6', 'Доля');
        $ws->setCellValue('B6', 0.125);
        $ws->getStyle('B6')->getNumberFormat()->setFormatCode('0.0%');
        $ws->setCellValue('A7', true);
        $ws->getColumnDimension('A')->setWidth(30);
        $ws->getColumnDimension('E')->setVisible(false);
        $ws->setCellValue('E4', 'скрыто');
        $ws->getRowDimension(3)->setRowHeight(24);
        $ws->freezePane('B4');
        $b->createSheet()->setTitle('Пустой');

        return $b;
    }

    public function test_xlsx_значения_и_оформление(): void
    {
        $b = $this->book();
        $b->setActiveSheetIndex(0);
        $b->getActiveSheet()->getCell('D4')->setCalculatedValue(3703.5);
        (new Xlsx($b))->setPreCalculateFormulas(true)->save($this->dir . '/a.xlsx');
        $d = SheetPreview::build($this->dir . '/a.xlsx');

        $this->assertSame(['Счёт', 'Пустой'], array_column($d['sheets'], 'name'));
        $this->assertSame(0, $d['active'], 'открывается лист, который был открыт у автора, а не последний разобранный');
        $s = $d['sheets'][0];
        $cells = [];
        foreach ($s['cells'] as [$r, $c, $text, $st, $t]) {
            $cells["$r:$c"] = ['text' => $text, 'st' => $d['styles'][$st], 't' => $t];
        }
        $this->assertSame('Счёт на оплату № 12', $cells['1:1']['text']);
        $this->assertSame(1, $cells['1:1']['st']['b']);
        $this->assertSame('center', $cells['1:1']['st']['ha']);
        $this->assertEquals(14, $cells['1:1']['st']['fs']);
        $this->assertContains([1, 1, 1, 4], $s['merges']);
        $this->assertSame('#FFF2CC', $cells['3:2']['st']['bg']);
        $this->assertStringStartsWith('2px solid', $cells['3:2']['st']['bbo']);
        $this->assertSame("1\u{00A0}234,50", $cells['4:3']['text'], 'формат #,##0.00 — с пробелом тысяч и запятой');
        $this->assertSame("3\u{00A0}703,50", $cells['4:4']['text'], 'формула — сохранённый результат в её формате');
        $this->assertSame('n', $cells['4:4']['t']);
        $this->assertSame('30.09.2026', $cells['5:2']['text']);
        $this->assertSame('12,5%', $cells['6:2']['text']);
        $this->assertSame('ИСТИНА', $cells['7:1']['text']);
        $this->assertSame('b', $cells['7:1']['t']);
        $this->assertSame([3, 1], $s['freeze'], 'закреплено по B4: 3 строки, 1 столбец');
        $this->assertContains(5, $s['hiddenCols']);
        $this->assertSame(215, $s['widths']->{1} ?? $s['widths'][1] ?? null, 'ширина 30 символов → 215 px');
        $this->assertSame(32, $s['heights']->{3} ?? $s['heights'][3] ?? null, 'высота 24 pt → 32 px');
        $this->assertFalse($d['truncated']);
        $this->assertSame([], $d['sheets'][1]['cells']);
    }

    public function test_старый_xls(): void
    {
        $b = $this->book();
        (new Xls($b))->save($this->dir . '/a.xls');
        $d = SheetPreview::build($this->dir . '/a.xls');
        $texts = array_column($d['sheets'][0]['cells'], 2);
        $this->assertContains('Счёт на оплату № 12', $texts);
        $this->assertContains("1\u{00A0}234,50", $texts);
        $this->assertContains('30.09.2026', $texts);
    }

    public function test_csv_из_1с_в_cp1251_через_точку_с_запятой(): void
    {
        file_put_contents($this->dir . '/a.csv', mb_convert_encoding("Номенклатура;Количество\nСетка;12\nПолиуретан;3\n", 'CP1251', 'UTF-8'));
        $d = SheetPreview::build($this->dir . '/a.csv');
        $rows = [];
        foreach ($d['sheets'][0]['cells'] as [$r, $c, $text]) {
            $rows[$r][$c] = $text;
        }
        $this->assertSame('Номенклатура', $rows[1][1]);
        $this->assertSame('Количество', $rows[1][2]);
        $this->assertSame('12', $rows[2][2]);
        $this->assertSame('Полиуретан', $rows[3][1]);
    }

    public function test_большой_лист_обрезается(): void
    {
        $b = new Spreadsheet();
        $ws = $b->getActiveSheet();
        $ws->setCellValue('A' . (SheetPreview::MAX_ROWS + 50), 'далеко');
        $ws->setCellValue('A1', 'начало');
        (new Xlsx($b))->save($this->dir . '/big.xlsx');
        $d = SheetPreview::build($this->dir . '/big.xlsx');
        $this->assertTrue($d['truncated']);
        $this->assertSame(1, $d['sheets'][0]['rows'], 'пустые строки до дальней ячейки не рисуем — только пометка «показана не вся таблица»');
        $this->assertSame(['начало'], array_column($d['sheets'][0]['cells'], 2));
    }

    public function test_что_показываем_таблицей(): void
    {
        foreach (['a.xlsx', 'B.XLS', 'c.ods', 'd.csv', 'e.xlsm'] as $n) {
            $this->assertTrue(SheetPreview::supports($n), $n);
        }
        foreach (['a.docx', 'b.pdf', 'xlsx', 'c.xlsb'] as $n) {
            $this->assertFalse(SheetPreview::supports($n), $n);
        }
    }
}
