<?php

namespace Tests\Unit;

use App\Services\Dav\Calendars;
use App\Services\Dav\ContactBooks;
use App\Services\Dav\DavAccess;
use App\Services\Dav\DavProvisioning;
use App\Services\Dav\DavSharing;
use App\Services\Dav\DavStore;
use App\Services\Dav\DavTasks;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * DavStore после разделения — фасад: сам ничего не делает, но наружу обязан выглядеть
 * ровно как раньше. Проверяем это машинально, потому что живой проверки DAV у нас нет,
 * а расхождение в подписи всплывёт только на боевом сервере — так уже было со
 * скачиванием вложений, где у обёртки потерялось значение по умолчанию.
 */
class DavStoreFacadeTest extends TestCase
{
    /** Части, между которыми разложена работа. */
    private const PARTS = [DavAccess::class, ContactBooks::class, Calendars::class,
        DavTasks::class, DavSharing::class, DavProvisioning::class];

    /** Каждый метод фасада должен existовать у какой-то части с той же подписью. */
    public function test_every_facade_method_matches_a_part(): void
    {
        foreach ($this->facadeMethods() as $m) {
            $part = $this->partWith($m->getName());
            $this->assertNotNull($part, "метод {$m->getName()} не нашёлся ни в одной части");
            $this->assertSame(
                $this->signature($m),
                $this->signature($part),
                "подпись {$m->getName()} разошлась с " . $part->getDeclaringClass()->getShortName()
            );
        }
    }

    /** Фасад не должен ничего делать сам: у каждого метода одна строка — передача дальше. */
    public function test_facade_only_delegates(): void
    {
        $src = file(__DIR__ . '/../../app/Services/Dav/DavStore.php');
        foreach ($this->facadeMethods() as $m) {
            $lines = array_slice($src, $m->getStartLine(), $m->getEndLine() - $m->getStartLine() - 1);
            $code = array_values(array_filter(array_map('trim', $lines), fn ($l) => $l !== ''));
            $this->assertCount(1, $code, "в {$m->getName()} у фасада появилась своя логика: " . implode(' ', $code));
        }
    }

    /** Ни одна часть не тянет за собой фасад: иначе разделение мнимое. */
    public function test_parts_do_not_depend_on_the_facade(): void
    {
        foreach (self::PARTS as $part) {
            $file = (new ReflectionClass($part))->getFileName();
            $this->assertStringNotContainsString('DavStore', (string) file_get_contents($file),
                (new ReflectionClass($part))->getShortName() . ' ссылается на фасад');
        }
    }

    /** @return ReflectionMethod[] */
    private function facadeMethods(): array
    {
        return array_values(array_filter(
            (new ReflectionClass(DavStore::class))->getMethods(ReflectionMethod::IS_PUBLIC),
            fn (ReflectionMethod $m) => $m->getDeclaringClass()->getName() === DavStore::class && $m->getName() !== '__construct'
        ));
    }

    private function partWith(string $name): ?ReflectionMethod
    {
        foreach (self::PARTS as $part) {
            if ((new ReflectionClass($part))->hasMethod($name)) {
                return new ReflectionMethod($part, $name);
            }
        }

        return null;
    }

    /** Подпись одной строкой: имя, доводы с типами и значениями по умолчанию, тип ответа. */
    private function signature(ReflectionMethod $m): string
    {
        $args = [];
        foreach ($m->getParameters() as $p) {
            $type = $p->getType();
            $args[] = ($type instanceof ReflectionNamedType ? ($type->allowsNull() && $type->getName() !== 'mixed' ? '?' : '') . $type->getName() . ' ' : '')
                . '$' . $p->getName()
                . ($p->isDefaultValueAvailable() ? ' = ' . var_export($p->getDefaultValue(), true) : '');
        }
        $ret = $m->getReturnType();

        return ($m->isStatic() ? 'static ' : '') . $m->getName() . '(' . implode(', ', $args) . ')'
            . ($ret instanceof ReflectionNamedType ? ': ' . ($ret->allowsNull() && $ret->getName() !== 'mixed' ? '?' : '') . $ret->getName() : '');
    }
}
