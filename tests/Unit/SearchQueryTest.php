<?php

namespace Tests\Unit;

use App\Services\Mail\SearchQuery;
use Tests\TestCase;

/**
 * Разбор строки поиска. Операторы «файл:» и «есть:вложение» серверу условий не добавляют:
 * поиск по заголовкам при включённом полнотекстовом индексе не находит ничего, и такие
 * письма отбираются потом по структуре (см. MailStore::keepWithFile).
 */
class SearchQueryTest extends TestCase
{
    public function test_оператор_имени_файла(): void
    {
        $q = new SearchQuery('файл:счёт.pdf');
        $q->apply($this->emptyQuery());

        $this->assertSame('счёт.pdf', $q->fileName());
        $this->assertTrue($q->needsAttachment());
    }

    /** «текст:» ищет только по телу — отдельное условие BODY, а не общее TEXT. */
    public function test_оператор_текста_письма(): void
    {
        $q = (new SearchQuery('текст:договор body:акт'))->apply($this->emptyQuery());
        $criteria = json_encode($q->getQuery()->toArray(), JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString('BODY', $criteria);
        $this->assertStringContainsString('договор', $criteria);
        $this->assertStringContainsString('акт', $criteria);
        $this->assertStringNotContainsString('TEXT', $criteria);
    }

    /** Переключатель поля в интерфейсе шлёт по оператору на слово: оба слова должны совпасть. */
    public function test_несколько_операторов_одного_поля(): void
    {
        $q = (new SearchQuery('от:Иван от:Петров'))->apply($this->emptyQuery());
        $criteria = json_encode($q->getQuery()->toArray(), JSON_UNESCAPED_UNICODE);

        $this->assertSame(2, substr_count($criteria, 'FROM'));
    }

    public function test_английское_написание_и_синонимы(): void
    {
        foreach (['file:smeta', 'вложение:smeta', 'attachment:smeta'] as $text) {
            $q = new SearchQuery($text);
            $q->apply($this->emptyQuery());
            $this->assertSame('smeta', $q->fileName(), $text);
        }
    }

    public function test_есть_вложение_без_имени(): void
    {
        $q = new SearchQuery('есть:вложение');
        $q->apply($this->emptyQuery());

        $this->assertNull($q->fileName(), 'имя не спрашивали');
        $this->assertTrue($q->needsAttachment(), 'но письма нужны только с вложениями');
    }

    public function test_обычный_поиск_вложений_не_требует(): void
    {
        $q = new SearchQuery('счёт на оплату');
        $q->apply($this->emptyQuery());

        $this->assertNull($q->fileName());
        $this->assertFalse($q->needsAttachment());
    }

    /** Запрос библиотеки нам нужен только как приёмник условий — работаем без сервера. */
    private function emptyQuery(): \Webklex\PHPIMAP\Query\WhereQuery
    {
        return new \Webklex\PHPIMAP\Query\WhereQuery(new \Webklex\PHPIMAP\Client(\Webklex\PHPIMAP\Config::make()));
    }

    /** «переписка:» — одно условие «от, кому, копия или скрытая копия» в префиксной записи IMAP. */
    public function test_вся_переписка_с_человеком(): void
    {
        $q = (new SearchQuery('переписка:ivanov@example.ru'))->apply($this->emptyQuery());
        $raw = $q->generate_query();

        $this->assertStringStartsWith('OR FROM "ivanov@example.ru" OR TO "ivanov@example.ru" OR CC "ivanov@example.ru" BCC "ivanov@example.ru"', $raw);
    }
}
