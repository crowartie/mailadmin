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
}
