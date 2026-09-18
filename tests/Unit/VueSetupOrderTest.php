<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * В `<script setup>` нельзя пользоваться значением до того, как оно объявлено через const.
 *
 * Это самая дорогая ошибка в нашем интерфейсе: страница не рисуется вовсе, в журнале
 * сервера пусто, а в консоли браузера одна строчка «Cannot access … before initialization».
 * За время работы она случилась трижды — теряли страницу почты, страницу настроек и чуть
 * не потеряли календарь. Сборка Vite её не ловит: код формально верный.
 *
 * Проверяем два случая, на которых мы и спотыкались, — оба выполняются прямо при
 * подготовке страницы:
 *   1) вызов на верхнем уровне: `if (…) loadSecurity();`
 *   2) передача значений в композабл: `= useXxx({ busy, say, ask })`
 *
 * Функции, объявленные через `function`, поднимаются наверх сами и в счёт не идут.
 */
class VueSetupOrderTest extends TestCase
{
    public function test_setup_does_not_use_values_before_they_exist(): void
    {
        $problems = [];
        foreach ($this->vueFiles() as $file) {
            $script = $this->setupScript((string) file_get_contents($file));
            if ($script === null) {
                continue;
            }
            $short = basename($file);
            $declared = $this->topLevelConsts($script);
            $lines = explode("\n", $script);

            foreach ($this->topLevelLines($script) as $no => $line) {
                foreach ($this->usedNames($line) as $name) {
                    if (isset($declared[$name]) && $declared[$name] > $no) {
                        $problems[] = "{$short}: строка " . ($no + 1) . " — «{$name}» объявлено ниже (строка "
                            . ($declared[$name] + 1) . '): ' . trim($lines[$no]);
                    }
                }
            }
        }

        $this->assertSame([], $problems, "обращение к значению до его объявления:\n" . implode("\n", $problems));
    }

    /** @return string[] */
    private function vueFiles(): array
    {
        $root = __DIR__ . '/../../resources/js';
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'vue') {
                $out[] = $f->getPathname();
            }
        }
        sort($out);

        return $out;
    }

    private function setupScript(string $src): ?string
    {
        if (! preg_match('/<script setup>(.*?)<\/script>/s', $src, $m)) {
            return null;
        }

        return $m[1];
    }

    /**
     * Имена, объявленные через const/let на верхнем уровне, и номера их строк.
     * Раскрываем и разбор объекта: `const { a, b } = useX()`.
     *
     * @return array<string,int>
     */
    private function topLevelConsts(string $script): array
    {
        $out = [];
        $depth = 0;
        $lines = explode("\n", $script);
        // Разбор объекта бывает многострочным. Пока он не закрыт, глубину не считаем
        // и строки собираем: иначе имена из `const {\n a,\n b,\n} = useX()` теряются —
        // первая же версия этой проверки так и не увидела ошибку, ради которой писалась.
        $pending = null;
        foreach ($lines as $no => $line) {
            if ($pending !== null) {
                $pending['text'] .= ' ' . $line;
                if (str_contains($line, '}')) {
                    foreach (preg_split('/[,\s]+/', explode('}', $pending['text'])[0]) as $name) {
                        $name = trim($name);
                        if ($name !== '' && preg_match('/^\w+$/', $name)) {
                            $out[$name] = $pending['no'];
                        }
                    }
                    $pending = null;
                    $depth = 0;
                }

                continue;
            }
            if ($depth === 0 && preg_match('/^(?:const|let|var)\s+\{(.*)$/', $line, $m)) {
                if (str_contains($m[1], '}')) {
                    foreach (preg_split('/[,\s]+/', explode('}', $m[1])[0]) as $name) {
                        $name = trim($name);
                        if ($name !== '' && preg_match('/^\w+$/', $name)) {
                            $out[$name] = $no;
                        }
                    }
                } else {
                    $pending = ['no' => $no, 'text' => $m[1]];

                    continue;
                }
            } elseif ($depth === 0 && preg_match('/^(?:const|let|var)\s+(\w+)/', $line, $m)) {
                $out[$m[1]] = $no;
            }
            $depth += substr_count($line, '{') + substr_count($line, '(') + substr_count($line, '[')
                - substr_count($line, '}') - substr_count($line, ')') - substr_count($line, ']');
            $depth = max(0, $depth);
        }

        return $out;
    }

    /**
     * Строки верхнего уровня, которые выполняются при подготовке страницы.
     *
     * @return array<int,string>
     */
    private function topLevelLines(string $script): array
    {
        $out = [];
        $depth = 0;
        foreach (explode("\n", $script) as $no => $line) {
            if ($depth === 0) {
                $out[$no] = $line;
            }
            $depth += substr_count($line, '{') + substr_count($line, '(') + substr_count($line, '[')
                - substr_count($line, '}') - substr_count($line, ')') - substr_count($line, ']');
            $depth = max(0, $depth);
        }

        return $out;
    }

    /**
     * Имена, которые строка использует прямо сейчас: вызов на верхнем уровне и
     * значения, переданные в композабл коротким видом `{ a, b }`.
     *
     * @return string[]
     */
    private function usedNames(string $line): array
    {
        $names = [];
        $trim = trim($line);
        if (preg_match('/^(?:if\s*\(.*?\)\s*)?(\w+)\s*\(/', $trim, $m) && ! in_array($m[1], ['if', 'for', 'while', 'switch', 'function', 'return', 'const', 'let'], true)) {
            $names[] = $m[1];
        }
        // Значения, переданные в композабл: их читают сразу, а не при вызове.
        if (preg_match('/=\s*use\w+\(\s*\{(.*?)\}/s', $trim, $m)) {
            foreach (preg_split('/[,\s]+/', $m[1]) as $piece) {
                $piece = trim(explode(':', $piece)[0]);
                if ($piece !== '' && preg_match('/^\w+$/', $piece)) {
                    $names[] = $piece;
                }
            }
        }
        // И позиционные доводы вида useX(a, b)
        if (preg_match('/=\s*use\w+\(([^{}]*?)\)/s', $trim, $m)) {
            foreach (explode(',', $m[1]) as $piece) {
                $piece = trim($piece);
                if ($piece !== '' && preg_match('/^\w+$/', $piece)) {
                    $names[] = $piece;
                }
            }
        }

        return array_unique($names);
    }
}
