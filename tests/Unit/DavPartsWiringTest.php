<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Части DAV зовут друг друга через свойства, полученные в конструктор. Проверяем, что
 * каждый такой вызов разрешается: метод есть либо у самой части, либо у соседа.
 *
 * Тест написан по живой поломке: при разделении DavStore метод calendars() уехал в
 * Calendars, а DavTasks продолжал звать $this->calendars() — календарь и задачи отдавали
 * 500. PHP такие вызовы не проверяет заранее, а живых тестов на DAV у нас нет, поэтому
 * проверку делаем по исходному тексту.
 */
class DavPartsWiringTest extends TestCase
{
    private const NS = 'App\\Services\\Dav\\';

    private const PARTS = ['DavAccess', 'ContactBooks', 'Calendars', 'DavTasks', 'DavSharing',
        'DavProvisioning', 'DavStore'];

    public function test_every_call_between_parts_resolves(): void
    {
        $unresolved = [];
        foreach (self::PARTS as $part) {
            $class = new ReflectionClass(self::NS . $part);
            $src = (string) file_get_contents((string) $class->getFileName());
            $mine = $this->methodsOf($class);
            $neighbours = $this->neighboursOf($class);

            preg_match_all('/\$this->(\w+)(?:->(\w+))?\(/', $src, $hits, PREG_SET_ORDER);
            foreach ($hits as $hit) {
                [$whole, $first] = [$hit[0], $hit[1]];
                $second = $hit[2] ?? null;
                if ($second === null) {
                    if (! in_array($first, $mine, true)) {
                        $unresolved[] = "{$part}: \$this->{$first}() — нет такого метода";
                    }

                    continue;
                }
                if (! isset($neighbours[$first])) {
                    // Это может быть обращение к чужому объекту (например, $this->a->cals->…),
                    // такие цепочки проверяем только на первом шаге.
                    $unresolved[] = "{$part}: \$this->{$first}->… — нет такого свойства";

                    continue;
                }
                $target = $neighbours[$first];
                if (str_starts_with($target, self::NS) && ! in_array($second, $this->methodsOf(new ReflectionClass($target)), true)) {
                    $short = (new ReflectionClass($target))->getShortName();
                    $unresolved[] = "{$part}: \$this->{$first}->{$second}() — у {$short} нет такого метода";
                }
            }
        }

        $this->assertSame([], $unresolved, "вызовы, которые не разрешаются:\n" . implode("\n", $unresolved));
    }

    /** @return string[] */
    private function methodsOf(ReflectionClass $class): array
    {
        return array_map(fn ($m) => $m->getName(), $class->getMethods());
    }

    /**
     * Свойства-соседи: и объявленные в теле класса, и продвинутые из конструктора.
     *
     * @return array<string,string> имя свойства → класс
     */
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
