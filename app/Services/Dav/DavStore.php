<?php

namespace App\Services\Dav;

use App\Dav\CardBackend;
use App\Models\Unit;
use DateTimeInterface;

/**
 * Книги и календари для интерфейса — одной точкой входа.
 *
 * Работа разложена по частям, каждая отвечает за своё:
 *   DavAccess       — подключение, признак администратора, вызов DAV-сервера;
 *   ContactBooks    — адресные книги и карточки;
 *   Calendars       — календари и события;
 *   DavTasks        — задачи (VTODO);
 *   DavSharing      — общий доступ и занятость участников;
 *   DavProvisioning — заведение и удаление пользователей и подразделений.
 *
 * Здесь остаются вызовы той же формы, что и раньше: разделение не потребовало
 * трогать контроллеры, планировщик и книгу сотрудников.
 */
class DavStore
{
    /** @see DavAccess::PERSONAL */
    public const PERSONAL = DavAccess::PERSONAL;

    private readonly DavAccess $access;

    private readonly ContactBooks $books;

    private readonly Calendars $cals;

    private readonly DavTasks $tasks;

    private readonly DavSharing $sharing;

    private readonly DavProvisioning $people;

    public function __construct()
    {
        $this->access = new DavAccess();
        $this->books = new ContactBooks($this->access);
        $this->cals = new Calendars($this->access);
        $this->tasks = new DavTasks($this->access, $this->cals);
        $this->sharing = new DavSharing($this->access, $this->cals);
        $this->people = new DavProvisioning($this->access);
    }

    /** Глобальный администратор почты (domain_admins ALL) правит общие книги и календарь компании. */
    public function isAdmin(string $user): bool
    {
        return $this->access->isAdmin($user);
    }

    public function displayName(string $user): string
    {
        return $this->access->displayName($user);
    }

    /** Principal, личная книга и личный календарь — создаются при первом входе. */
    public function ensureUser(string $user): void
    {
        $this->access->ensureUser($user);
    }

    /** @return array<int,array<string,mixed>> */
    public function books(string $user): array
    {
        return $this->books->books($user);
    }

    /** @return array<int,array<string,mixed>> */
    public function cards(string $user, ?string $bookUri = null, string $q = ''): array
    {
        return $this->books->cards($user, $bookUri, $q);
    }

    public function card(string $user, string $bookUri, string $uri): array
    {
        return $this->books->card($user, $bookUri, $uri);
    }

    /** Создать или обновить карточку. @return array карточка после сохранения */
    public function saveCard(string $user, string $bookUri, ?string $uri, array $data): array
    {
        return $this->books->saveCard($user, $bookUri, $uri, $data);
    }

    public function deleteCard(string $user, string $bookUri, string $uri): void
    {
        $this->books->deleteCard($user, $bookUri, $uri);
    }

    /** Перенести карточку в другую книгу (копия + удаление). */
    public function moveCard(string $user, string $fromBook, string $uri, string $toBook): array
    {
        return $this->books->moveCard($user, $fromBook, $uri, $toBook);
    }

    /** Импорт .vcf (одна или много карточек). @return int сколько добавлено */
    public function importCards(string $user, string $bookUri, string $vcfText): array
    {
        return $this->books->importCards($user, $bookUri, $vcfText);
    }

    public function exportCards(string $user, ?string $bookUri): string
    {
        return $this->books->exportCards($user, $bookUri);
    }

    /** Все адреса из книг пользователя для автодополнения. @return array<int,array{mail:string,name:string,kind:string}> */
    public function suggest(string $user, string $q, int $limit = 8): array
    {
        return $this->books->suggest($user, $q, $limit);
    }

    /** @return array<int,array<string,mixed>> */
    public function calendars(string $user): array
    {
        return $this->cals->calendars($user);
    }

    public function createCalendar(string $user, string $name, string $color): array
    {
        return $this->cals->createCalendar($user, $name, $color);
    }

    public function updateCalendar(string $user, string $uri, ?string $name, ?string $color): array
    {
        return $this->cals->updateCalendar($user, $uri, $name, $color);
    }

    public function deleteCalendar(string $user, string $uri): void
    {
        $this->cals->deleteCalendar($user, $uri);
    }

    /**
    * События всех видимых календарей в интервале.
    *
    * @param  string[]  $calendarUris  пустой список — все
    * @return array<int,array<string,mixed>>
    */
    public function events(string $user, DateTimeInterface $from, DateTimeInterface $to, array $calendarUris = []): array
    {
        return $this->cals->events($user, $from, $to, $calendarUris);
    }

    public function event(string $user, string $calUri, string $objUri): array
    {
        return $this->cals->event($user, $calUri, $objUri);
    }

    /** Создать или обновить событие. */
    public function saveEvent(string $user, string $calUri, ?string $objUri, array $data): array
    {
        return $this->cals->saveEvent($user, $calUri, $objUri, $data);
    }

    /**
    * Перенести событие в другой календарь, оставив его тем же событием.
    *
    * Раньше перенос делался «создать заново и удалить»: у копии был новый UID, поэтому
    * ответы участников обнулялись, всем уходило повторное приглашение, а старое событие
    * оставалось в их календарях навсегда. Пишем тот же файл под тем же именем в другой
    * календарь и только потом убираем из прежнего.
    *
    * @param  array<string,mixed>  $data
    * @return array<string,mixed>
    */
    public function moveEvent(string $user, string $fromCal, string $toCal, string $objUri, array $data): array
    {
        return $this->cals->moveEvent($user, $fromCal, $toCal, $objUri, $data);
    }

    /** Удалить событие или одно его вхождение ($occurrence — ISO-дата вхождения). */
    public function deleteEvent(string $user, string $calUri, string $objUri, ?string $occurrence = null): void
    {
        $this->cals->deleteEvent($user, $calUri, $objUri, $occurrence);
    }

    /** Все задачи из календарей, где можно писать (свой личный, свои, отдела). @return array<int,array<string,mixed>> */
    public function tasks(string $user): array
    {
        return $this->tasks->tasks($user);
    }

    public function saveTask(string $user, string $calUri, ?string $objUri, array $data): array
    {
        return $this->tasks->saveTask($user, $calUri, $objUri, $data);
    }

    public function deleteTask(string $user, string $calUri, string $objUri): void
    {
        $this->tasks->deleteTask($user, $calUri, $objUri);
    }

    /** Ответ на приглашение: ACCEPTED / DECLINED / TENTATIVE. */
    public function respond(string $user, string $calUri, string $objUri, string $partstat): array
    {
        return $this->cals->respond($user, $calUri, $objUri, $partstat);
    }

    /**
    * Занятость сотрудников: только интервалы, без названий (политика компании — занятость видна всем).
    *
    * @param  string[]  $users
    * @return array<string,array<int,array{start:string,end:string}>|null> null — адрес не наш, занятость неизвестна
    */
    public function freeBusy(array $users, DateTimeInterface $from, DateTimeInterface $to): array
    {
        return $this->sharing->freeBusy($users, $from, $to);
    }

    /** @return array<int,array{mail:string,name:string,level:string}> */
    public function shares(string $user, string $calUri): array
    {
        return $this->sharing->shares($user, $calUri);
    }

    public function share(string $user, string $calUri, string $with, string $level): array
    {
        return $this->sharing->share($user, $calUri, $with, $level);
    }

    public function unshare(string $user, string $calUri, string $with): array
    {
        return $this->sharing->unshare($user, $calUri, $with);
    }

    /** Удалить всё DAV-хозяйство пользователя: principal, личные книги с карточками, календари с событиями, его доли в чужих. */
    public function removeUser(string $user): void
    {
        $this->people->removeUser($user);
    }

    public static function unitPrincipal(int $unitId): string
    {
        return DavProvisioning::unitPrincipal($unitId);
    }

    /** Principal отдела, его календарь и книга — создаются при первом обращении. */
    public function ensureUnitResources(\App\Models\Unit $unit): void
    {
        $this->people->ensureUnitResources($unit);
    }

    public function renameUnitResources(\App\Models\Unit $unit): void
    {
        $this->people->renameUnitResources($unit);
    }

    /** Доступ к календарю отдела на запись — ровно текущим членам. @param string[] $members */
    public function setUnitMembers(\App\Models\Unit $unit, array $members): void
    {
        $this->people->setUnitMembers($unit, $members);
    }

    public function deleteUnitResources(\App\Models\Unit $unit): void
    {
        $this->people->deleteUnitResources($unit);
    }

    public function systemCards(): CardBackend
    {
        return $this->books->systemCards();
    }

    public function systemBookId(string $uri): int
    {
        return $this->books->systemBookId($uri);
    }
}
