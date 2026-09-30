<?php

namespace Tests\Unit;

use App\Support\TempFiles;
use Tests\TestCase;

/**
 * Под Octane архивы для скачивания и копии загрузок оставались в /tmp навсегда (сотни мегабайт за неделю).
 * Ночная уборка удаляет только свои имена и только старые — чужие файлы и идущие загрузки не трогает.
 */
class TempFilesTest extends TestCase
{
    public function test_уборка_только_своих_и_старых(): void
    {
        $dir = sys_get_temp_dir() . '/tf-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $old = time() - 2 * 86400;
        foreach (['attAb12Cd', 'symfonyXy34Zq', 'cloudQwErTy', 'ncz123456', 'rep000aaa'] as $n) {
            touch("$dir/$n", $old);
        }
        touch("$dir/attFresh1");                       // идёт скачивание — свежий
        touch("$dir/attachment.pdf", $old);            // чужое имя
        touch("$dir/symfony-cache", $old);             // не суффикс tempnam
        mkdir("$dir/attDir001");                       // каталог
        touch("$dir/attDir001", $old);

        $this->assertSame(5, TempFiles::prune($dir));
        $left = array_values(array_diff(scandir($dir), ['.', '..']));
        sort($left);
        $this->assertSame(['attDir001', 'attFresh1', 'attachment.pdf', 'symfony-cache'], $left);

        array_map('unlink', ["$dir/attFresh1", "$dir/attachment.pdf", "$dir/symfony-cache"]);
        rmdir("$dir/attDir001");
        rmdir($dir);
    }

    public function test_удаление_после_ответа(): void
    {
        $f = tempnam(sys_get_temp_dir(), 'att');
        TempFiles::deleteAfterResponse($f);
        $this->assertFileExists($f, 'до конца запроса файл нужен');
        app()->terminate();
        $this->assertFileDoesNotExist($f);
    }
}
