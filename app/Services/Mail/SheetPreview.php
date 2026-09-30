<?php

namespace App\Services\Mail;

use App\Exceptions\MailException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Symfony\Component\Process\Process;

/**
 * Таблица Excel в просмотрщике как таблица, а не как PDF на листах А4 (просьба 30.09.2026).
 *
 * Сервер разбирает книгу (xls, xlsx, xlsm, ods, csv) PhpSpreadsheet'ом и отдаёт браузеру листы: значения так,
 * как их видно в Excel (формат чисел и дат применён, у формул — сохранённый в файле результат), ширины столбцов,
 * высоты строк, объединённые ячейки, закреплённые области, шрифт, заливку, границы, выравнивание.
 * Рисует таблицу SheetViewer.vue. Картинки и диаграммы на листах не переносятся — для них остаётся вид «как
 * при печати» (PDF через LibreOffice).
 *
 * Разбор — в отдельном процессе (команда preview:sheet) с лимитом памяти и времени: большая книга не должна
 * занять рабочий процесс почты. Результат кэшируется по содержимому файла рядом с PDF-предпросмотрами.
 */
class SheetPreview
{
    public const TYPES = ['xls', 'xlsx', 'xlsm', 'xltx', 'xltm', 'ods', 'csv'];
    public const MAX_BYTES = 25 * 1024 * 1024;
    public const MAX_ROWS = 5000;
    public const MAX_COLS = 200;
    public const MAX_CELLS = 250000;
    public const MAX_SHEETS = 30;
    /** Версия разбора: меняется — старые кэши не используются (и у браузера, и на сервере). */
    public const VERSION = 2;
    private const TIMEOUT = 90;

    public static function supports(string $name): bool
    {
        return in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::TYPES, true);
    }

    /** Путь к готовому JSON (из кэша или только что сделанному). */
    public static function fromContent(string $content, string $name): string
    {
        if (! self::supports($name)) {
            throw MailException::unsupported('Это не таблица — скачайте файл');
        }
        if (strlen($content) > self::MAX_BYTES) {
            throw MailException::tooLarge('Таблица слишком большая для просмотра — скачайте её');
        }

        return self::make(fn () => $content, $name, sha1($content));
    }

    public static function fromFile(string $src, string $name): string
    {
        if (! self::supports($name)) {
            throw MailException::unsupported('Это не таблица — скачайте файл');
        }
        if (filesize($src) > self::MAX_BYTES) {
            throw MailException::tooLarge('Таблица слишком большая для просмотра — скачайте её');
        }

        return self::make(fn () => (string) file_get_contents($src), $name, sha1_file($src));
    }

    /** @param callable():string $content читается, только если в кэше ещё нет */
    private static function make(callable $content, string $name, string $hash): string
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $dir = storage_path('app/private/preview');
        if (! is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        $out = $dir . '/' . $hash . '.v' . self::VERSION . '.sheet.json';
        if (is_file($out) && filesize($out) > 0) {
            touch($out);

            return $out;
        }
        $lock = Cache::lock('sheet-preview', self::TIMEOUT + 10);
        if (! $lock->block(60)) {
            throw MailException::busy('Просмотр таблиц занят — попробуйте через минуту');
        }
        $work = $dir . '/tmp-' . bin2hex(random_bytes(6));
        try {
            if (is_file($out) && filesize($out) > 0) {
                return $out;
            }
            mkdir($work, 0750, true);
            $src = $work . '/in.' . $ext;
            file_put_contents($src, $content());
            $p = new Process([PHP_BINARY, '-d', 'memory_limit=1024M', base_path('artisan'), 'preview:sheet', $src, $work . '/out.json'], base_path(), null, null, self::TIMEOUT);
            $p->run();
            if (! $p->isSuccessful() || ! is_file($work . '/out.json')) {
                Log::warning('sheet-preview: ' . $name . ': ' . mb_substr(trim($p->getErrorOutput() . ' ' . $p->getOutput()), 0, 500));
                throw MailException::upstream('Не удалось открыть таблицу — посмотрите её «как при печати» или скачайте');
            }
            rename($work . '/out.json', $out);

            return $out;
        } finally {
            File::deleteDirectory($work);
            $lock->release();
        }
    }

    /**
     * Разбор книги — вызывается в отдельном процессе (команда preview:sheet).
     * @return array{sheets: array<int,array<string,mixed>>, styles: array<int,array<string,mixed>>, font: array{name:string,size:float}, truncated: bool}
     */
    public static function build(string $path): array
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $reader = IOFactory::createReader(match ($ext) {
            'xls' => 'Xls',
            'ods' => 'Ods',
            'csv' => 'Csv',
            default => 'Xlsx',
        });
        if ($reader instanceof \PhpOffice\PhpSpreadsheet\Reader\Csv) {
            // Выгрузки из 1С и банков — часто в cp1251 и через «;».
            $reader->setInputEncoding(\PhpOffice\PhpSpreadsheet\Reader\Csv::guessEncoding($path, 'CP1251'));
            $reader->setDelimiter(null);
        }
        $reader->setReadEmptyCells(true);   // пустые, но залитые или с рамкой ячейки — часть вида таблицы
        $reader->setReadFilter(new class implements IReadFilter {
            public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
            {
                return $row <= SheetPreview::MAX_ROWS && Coordinate::columnIndexFromString($columnAddress) <= SheetPreview::MAX_COLS;
            }
        });
        // Настоящий размер листов — до загрузки: фильтр чтения отбрасывает дальние строки, и после него
        // книга выглядит «маленькой», а человек должен знать, что показана не вся таблица.
        $sizes = [];
        try {
            foreach ($reader->listWorksheetInfo($path) as $info) {
                $sizes[$info['worksheetName']] = [(int) $info['totalRows'], (int) $info['totalColumns']];
            }
        } catch (\Throwable) {
            // Описание не прочиталось — обрезку определим по загруженному.
        }
        $book = $reader->load($path);
        // Запоминаем сразу: чтение оформления ячейки (getStyle) делает её лист активным, и после разбора
        // «активным» оказывался последний лист книги, а не тот, что был открыт у автора.
        $active = $book->getActiveSheetIndex();

        $styles = [];
        $styleIds = [];
        $truncated = false;
        $cellsLeft = self::MAX_CELLS;
        $sheets = [];
        foreach ($book->getWorksheetIterator() as $i => $ws) {
            if ($i >= self::MAX_SHEETS) {
                $truncated = true;
                break;
            }
            [$tr, $tc] = $sizes[$ws->getTitle()] ?? [0, 0];
            if ($tr > self::MAX_ROWS || $tc > self::MAX_COLS) {
                $truncated = true;
            }
            $sheet = self::sheet($ws, $styles, $styleIds, $cellsLeft, $truncated);
            $sheets[] = $sheet;
        }
        $df = $book->getDefaultStyle()->getFont();

        return [
            'sheets' => $sheets,
            'styles' => $styles,
            'font' => ['name' => (string) ($df->getName() ?: 'Calibri'), 'size' => (float) ($df->getSize() ?: 11)],
            'active' => $active,
            'truncated' => $truncated,
        ];
    }

    /** @param array<int,array<string,mixed>> $styles @param array<string,int> $styleIds */
    private static function sheet(Worksheet $ws, array &$styles, array &$styleIds, int &$cellsLeft, bool &$truncated): array
    {
        $highRow = $ws->getHighestRow();
        $highCol = Coordinate::columnIndexFromString($ws->getHighestColumn());
        $rows = min($highRow, self::MAX_ROWS);
        $cols = min($highCol, self::MAX_COLS);
        if ($highRow > self::MAX_ROWS || $highCol > self::MAX_COLS) {
            $truncated = true;
        }

        $defW = $ws->getDefaultColumnDimension()->getWidth();
        $defW = $defW > 0 ? $defW : 8.43;
        $widths = [];
        $hiddenCols = [];
        foreach ($ws->getColumnDimensions() as $letter => $dim) {
            $c = Coordinate::columnIndexFromString($letter);
            if ($c > $cols) {
                continue;
            }
            if (! $dim->getVisible()) {
                $hiddenCols[] = $c;
            }
            if ($dim->getWidth() >= 0) {
                $widths[$c] = self::colPx($dim->getWidth());
            }
        }
        $defH = $ws->getDefaultRowDimension()->getRowHeight();
        $defH = $defH > 0 ? $defH : 15;
        $heights = [];
        $hiddenRows = [];
        foreach ($ws->getRowDimensions() as $r => $dim) {
            if ($r > $rows) {
                continue;
            }
            if (! $dim->getVisible()) {
                $hiddenRows[] = $r;
            }
            if ($dim->getRowHeight() >= 0) {
                $heights[$r] = (int) round($dim->getRowHeight() * 4 / 3);
            }
        }

        $merges = [];
        foreach ($ws->getMergeCells() as $range) {
            [[$c1, $r1], [$c2, $r2]] = Coordinate::rangeBoundaries($range);
            if ($r1 > $rows || $c1 > $cols) {
                continue;
            }
            $merges[] = [(int) $r1, (int) $c1, (int) min($r2, $rows), (int) min($c2, $cols)];
        }

        $cells = [];
        $defaultKey = null;
        foreach ($ws->getRowIterator(1, max(1, $rows)) as $row) {
            $ci = $row->getCellIterator('A', Coordinate::stringFromColumnIndex(max(1, $cols)));
            $ci->setIterateOnlyExistingCells(true);
            foreach ($ci as $cell) {
                if ($cellsLeft <= 0) {
                    $truncated = true;
                    break 2;
                }
                [$text, $type] = self::text($cell);
                $style = self::style($cell->getStyle(), $type);
                $key = json_encode($style);
                if ($text === '' && $style === []) {
                    continue;   // пустая ячейка без оформления — рисовать нечего
                }
                if (! isset($styleIds[$key])) {
                    $styleIds[$key] = count($styles);
                    $styles[] = $style;
                }
                $cells[] = [$cell->getRow(), Coordinate::columnIndexFromString($cell->getColumn()), $text, $styleIds[$key], $type];
                $cellsLeft--;
            }
        }

        $freeze = $ws->getFreezePane();
        $fr = 0;
        $fc = 0;
        if ($freeze) {
            [$fcol, $frow] = Coordinate::coordinateFromString($freeze);
            $fr = max(0, (int) $frow - 1);
            $fc = max(0, Coordinate::columnIndexFromString($fcol) - 1);
        }
        $tab = $ws->getTabColor();

        return [
            'name' => $ws->getTitle(),
            'hidden' => $ws->getSheetState() !== Worksheet::SHEETSTATE_VISIBLE,
            'rows' => $rows,
            'cols' => $cols,
            'defW' => self::colPx($defW),
            'defH' => (int) round($defH * 4 / 3),
            'widths' => (object) $widths,
            'heights' => (object) $heights,
            'hiddenRows' => $hiddenRows,
            'hiddenCols' => $hiddenCols,
            'merges' => $merges,
            'freeze' => [$fr, $fc],
            'grid' => $ws->getShowGridlines(),
            'drawings' => count($ws->getDrawingCollection()) + count($ws->getChartCollection()),
            'tab' => $tab && $tab->getRGB() && $tab->getRGB() !== '000000' ? '#' . $tab->getRGB() : null,
            'cells' => $cells,
        ];
    }

    /** Ширина столбца Excel (в символах) → пиксели при 100 %. */
    private static function colPx(float $chars): int
    {
        return (int) max(0, round($chars * 7 + 5));
    }

    /** Текст ячейки так, как его видно в Excel, и тип для выравнивания по умолчанию: n — число, b — логическое, s — текст. */
    private static function text(\PhpOffice\PhpSpreadsheet\Cell\Cell $cell): array
    {
        $v = $cell->getValue();
        if ($cell->isFormula()) {
            // Пересчитывать формулы не нужно: в файле хранится результат, который видел автор.
            $v = $cell->getOldCalculatedValue();
        }
        if ($v instanceof RichText) {
            return [$v->getPlainText(), 's'];
        }
        if ($v === null || $v === '') {
            return ['', 's'];
        }
        if (is_bool($v)) {
            return [$v ? 'ИСТИНА' : 'ЛОЖЬ', 'b'];
        }
        if (is_int($v) || is_float($v)) {
            $fmt = $cell->getStyle()->getNumberFormat()->getFormatCode() ?: NumberFormat::FORMAT_GENERAL;
            try {
                $s = NumberFormat::toFormattedString($v, $fmt);
            } catch (\Throwable) {
                $s = (string) $v;
            }
            // «General» у дробных — не больше 11 значащих знаков, как в Excel; и запятая, как в русском Excel.
            if ($fmt === NumberFormat::FORMAT_GENERAL && is_float($v)) {
                $s = rtrim(rtrim(sprintf('%.10F', round($v, 10)), '0'), '.');
                if (abs($v) >= 1e11 || (abs($v) > 0 && abs($v) < 1e-9)) {
                    $s = sprintf('%.5E', $v);
                }
            }
            if (! Date::isDateTimeFormatCode($fmt)) {
                $s = self::ruNumber($s);
            }

            return [$s, 'n'];
        }

        return [(string) $v, 's'];
    }

    /** «1,234.50» → «1 234,50»: разделители, как в русском Excel. Текст внутри формата (₽, %) не трогаем. */
    private static function ruNumber(string $s): string
    {
        return (string) preg_replace_callback('/\d{1,3}(?:,\d{3})+(?:\.\d+)?|\d+\.\d+/', function ($m) {
            return strtr($m[0], [',' => "\u{00A0}", '.' => ',']);
        }, $s);
    }

    /** Оформление ячейки — только то, что отличается от обычного. */
    private static function style(\PhpOffice\PhpSpreadsheet\Style\Style $st, string $type): array
    {
        $out = [];
        $f = $st->getFont();
        if ($f->getBold()) {
            $out['b'] = 1;
        }
        if ($f->getItalic()) {
            $out['i'] = 1;
        }
        if ($f->getUnderline() && $f->getUnderline() !== 'none') {
            $out['u'] = 1;
        }
        if ($f->getStrikethrough()) {
            $out['x'] = 1;
        }
        if ($f->getSize() && abs($f->getSize() - 11) > 0.01) {
            $out['fs'] = (float) $f->getSize();
        }
        $fc = $f->getColor()->getRGB();
        if ($fc && strtoupper($fc) !== '000000') {
            $out['fc'] = '#' . $fc;
        }
        if ($f->getName() && ! in_array($f->getName(), ['Calibri', 'Arial', 'Arial Cyr'], true)) {
            $out['fn'] = $f->getName();
        }
        $fill = $st->getFill();
        if ($fill->getFillType() === Fill::FILL_SOLID || ($fill->getFillType() && $fill->getFillType() !== Fill::FILL_NONE)) {
            $bg = $fill->getStartColor()->getRGB();
            if ($fill->getFillType() !== Fill::FILL_SOLID && $fill->getEndColor()->getRGB()) {
                $bg = $fill->getStartColor()->getRGB() ?: $fill->getEndColor()->getRGB();
            }
            if ($bg && strtoupper($bg) !== 'FFFFFF') {
                $out['bg'] = '#' . $bg;
            }
        }
        foreach (['t' => $st->getBorders()->getTop(), 'r' => $st->getBorders()->getRight(), 'bo' => $st->getBorders()->getBottom(), 'l' => $st->getBorders()->getLeft()] as $side => $b) {
            $css = self::border($b);
            if ($css) {
                $out['b' . $side] = $css;
            }
        }
        $a = $st->getAlignment();
        $h = $a->getHorizontal();
        if ($h && $h !== 'general') {
            $out['ha'] = match ($h) {
                'center', 'centerContinuous' => 'center',
                'right' => 'right',
                'justify', 'distributed' => 'justify',
                default => 'left',
            };
        }
        $v = $a->getVertical();
        if ($v && $v !== 'bottom') {
            $out['va'] = $v === 'center' ? 'middle' : ($v === 'top' ? 'top' : 'bottom');
        }
        if ($a->getWrapText()) {
            $out['w'] = 1;
        }
        if ($a->getIndent()) {
            $out['in'] = (int) $a->getIndent();
        }

        return $out;
    }

    private static function border(\PhpOffice\PhpSpreadsheet\Style\Border $b): ?string
    {
        $s = $b->getBorderStyle();
        if (! $s || $s === Border::BORDER_NONE) {
            return null;
        }
        $w = match ($s) {
            Border::BORDER_MEDIUM, Border::BORDER_MEDIUMDASHED, Border::BORDER_MEDIUMDASHDOT, Border::BORDER_MEDIUMDASHDOTDOT, Border::BORDER_SLANTDASHDOT => 2,
            Border::BORDER_THICK => 3,
            Border::BORDER_DOUBLE => 3,
            default => 1,
        };
        $kind = match ($s) {
            Border::BORDER_DOTTED, Border::BORDER_HAIR => 'dotted',
            Border::BORDER_DASHED, Border::BORDER_MEDIUMDASHED, Border::BORDER_DASHDOT, Border::BORDER_MEDIUMDASHDOT, Border::BORDER_DASHDOTDOT, Border::BORDER_MEDIUMDASHDOTDOT, Border::BORDER_SLANTDASHDOT => 'dashed',
            Border::BORDER_DOUBLE => 'double',
            default => 'solid',
        };
        $c = $b->getColor()->getRGB() ?: '000000';

        return $w . 'px ' . $kind . ' #' . $c;
    }
}
