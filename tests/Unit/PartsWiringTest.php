<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Крупные классы разложены на части, которые зовут друг друга через свойства из
 * конструктора. Проверяем, что каждый такой вызов разрешается: метод есть либо у самой
 * части, либо у соседа.
 *
 * Тест написан по живой поломке: при разделении DavStore метод calendars() уехал в
 * Calendars, а DavTasks продолжал звать $this->calendars() — календарь и задачи отдавали
 * 500. PHP такие вызовы заранее не проверяет, живых тестов на DAV и IMAP у нас нет,
 * поэтому проверяем по исходному тексту.
 */
class PartsWiringTest extends TestCase
{
    /** Все части, на которые разложены крупные классы, и их фасады. */
    private const CLASSES = [
        'App\\Services\\Dav\\DavAccess', 'App\\Services\\Dav\\ContactBooks', 'App\\Services\\Dav\\Calendars',
        'App\\Services\\Dav\\DavTasks', 'App\\Services\\Dav\\DavSharing', 'App\\Services\\Dav\\DavProvisioning',
        'App\\Services\\Dav\\DavStore',
        'App\\Services\\Mail\\MessageFetch', 'App\\Services\\Mail\\MessageBody', 'App\\Services\\Mail\\ThreadBuilder',
        'App\\Services\\Mail\\MailAttachments', 'App\\Services\\Mail\\MessageReader', 'App\\Services\\Mail\\MailStore',
        'App\\Services\\Mail\\ImapQuery', 'App\\Services\\Mail\\MessagePage', 'App\\Services\\Mail\\MessageListing',
        'App\\Services\\Mail\\MailActions', 'App\\Services\\Mail\\FolderTree', 'App\\Services\\Mail\\MessageSummary',
    ];

    public function test_every_call_between_parts_resolves(): void
    {
        $unresolved = [];
        foreach (self::CLASSES as $name) {
            $class = new ReflectionClass($name);
            $src = (string) file_get_contents((string) $class->getFileName());
            $mine = $this->methodsOf($class);
            $neighbours = $this->neighboursOf($class);
            $short = $class->getShortName();

            preg_match_all('/\$this->(\w+)(?:->(\w+))?\(/', $src, $hits, PREG_SET_ORDER);
            foreach ($hits as $hit) {
                $first = $hit[1];
                $second = $hit[2] ?? null;
                if ($second === null) {
                    if (! in_array($first, $mine, true)) {
                        $unresolved[] = "{$short}: \$this->{$first}() — нет такого метода";
                    }

                    continue;
                }
                if (! isset($neighbours[$first])) {
                    $unresolved[] = "{$short}: \$this->{$first}->… — нет такого свойства";

                    continue;
                }
                $target = $neighbours[$first];
                if (! class_exists($target)) {
                    continue;   // библиотечный объект — его устройство не наше дело
                }
                if (! in_array($second, $this->methodsOf(new ReflectionClass($target)), true)) {
                    $t = (new ReflectionClass($target))->getShortName();
                    $unresolved[] = "{$short}: \$this->{$first}->{$second}() — у {$t} нет такого метода";
                }
            }
        }

        $this->assertSame([], $unresolved, "вызовы, которые не разрешаются:\n" . implode("\n", $unresolved));
    }

    /**
     * Ссылки на константы тоже должны разрешаться.
     *
     * Вызовы методов эта проверка ловила с самого начала, а константы — нет, и разделение
     * MessageListing оставило две мёртвые ссылки: SEARCH_TIMEOUT уехала в ImapQuery,
     * PAGE — в MessagePage. «Поиск во всех папках» из-за этого отвечал 500, а запасной
     * путь сборки страницы упал бы при первом обращении к папке, где сервер не умеет SORT.
     */
    public function test_every_constant_reference_resolves(): void
    {
        $unresolved = [];
        foreach (self::CLASSES as $name) {
            $class = new ReflectionClass($name);
            $src = (string) file_get_contents((string) $class->getFileName());
            $short = $class->getShortName();
            $mine = array_keys($class->getConstants());

            preg_match_all('/\bself::([A-Z][A-Z0-9_]*)\b/', $src, $own);
            foreach (array_unique($own[1]) as $c) {
                if (! in_array($c, $mine, true)) {
                    $unresolved[] = "{$short}: self::{$c} — нет такой константы";
                }
            }

            // И ссылки вида Класс::КОНСТАНТА на соседей из того же пространства имён.
            preg_match_all('/\b([A-Z]\w+)::([A-Z][A-Z0-9_]*)\b/', $src, $hits, PREG_SET_ORDER);
            foreach ($hits as [, $target, $c]) {
                $fq = $class->getNamespaceName() . '\\' . $target;
                if ($target === 'self' || ! class_exists($fq)) {
                    continue;
                }
                if (! array_key_exists($c, (new ReflectionClass($fq))->getConstants())) {
                    $unresolved[] = "{$short}: {$target}::{$c} — у {$target} нет такой константы";
                }
            }
        }

        $this->assertSame([], $unresolved, "ссылки на константы, которые не разрешаются:\n" . implode("\n", $unresolved));
    }

    /** Части не должны знать про свой фасад: иначе разделение мнимое и правка по кругу. */
    public function test_parts_do_not_depend_on_their_facade(): void
    {
        $facades = ['App\\Services\\Dav\\DavStore' => 'DavStore', 'App\\Services\\Mail\\MessageReader' => 'MessageReader'];
        foreach (self::CLASSES as $name) {
            if (isset($facades[$name]) || str_ends_with($name, 'MailStore')) {
                continue;
            }
            $src = (string) file_get_contents((string) (new ReflectionClass($name))->getFileName());
            foreach ($facades as $facade) {
                $this->assertStringNotContainsString(
                    $facade,
                    $src,
                    (new ReflectionClass($name))->getShortName() . " ссылается на фасад {$facade}"
                );
            }
        }
    }

    /** @return string[] */
    private function methodsOf(ReflectionClass $class): array
    {
        return array_map(fn ($m) => $m->getName(), $class->getMethods());
    }

    /** @return array<string,string> имя свойства → класс */
    private function neighboursOf(ReflectionClass $class): array
    {
        $out = [];
        foreach ($class->getProperties() as $p) {
            $type = $p->getType();
            if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()) {
                $out[$p->getName()] = $type->getName();
            }
        }

        return $out;
    }
}
