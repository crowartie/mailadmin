<?php

namespace App\Dav;

use Sabre\CalDAV\Backend\PDO;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Sharing\Plugin as Sharing;

/**
 * Календари: личные и расшаренные (штатный механизм sabre — строки calendarinstances
 * с уровнем доступа) плюс общий «Календарь компании» системного principal'а,
 * который каждый видит только для чтения; пишет в него админка (systemWrites = true).
 */
class CalBackend extends PDO
{
    public const SYSTEM = 'principals/system';

    public bool $systemWrites = false;

    /** @var int[]|null */
    private ?array $systemCalendarIds = null;

    public function __construct(\PDO $pdo)
    {
        parent::__construct($pdo);
        $this->calendarTableName = 'dav_calendars';
        $this->calendarInstancesTableName = 'dav_calendarinstances';
        $this->calendarObjectTableName = 'dav_calendarobjects';
        $this->calendarChangesTableName = 'dav_calendarchanges';
        $this->schedulingObjectTableName = 'dav_schedulingobjects';
        $this->calendarSubscriptionsTableName = 'dav_calendarsubscriptions';
    }

    /** @return int[] */
    public function systemCalendars(): array
    {
        if ($this->systemCalendarIds === null) {
            $stmt = $this->pdo->prepare("SELECT calendarid FROM {$this->calendarInstancesTableName} WHERE principaluri = ? AND access = 1");
            $stmt->execute([self::SYSTEM]);
            $this->systemCalendarIds = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
        }

        return $this->systemCalendarIds;
    }

    public function isSystem(mixed $calendarId): bool
    {
        $id = is_array($calendarId) ? (int) $calendarId[0] : (int) $calendarId;

        return in_array($id, $this->systemCalendars(), true);
    }

    public function getCalendarsForUser($principalUri)
    {
        $calendars = parent::getCalendarsForUser($principalUri);
        if ($principalUri === self::SYSTEM) {
            return $calendars;
        }

        $stmt = $this->pdo->prepare(
            "SELECT i.id, i.calendarid, i.uri, i.displayname, i.description, i.calendarorder, i.calendarcolor, i.timezone, c.synctoken, c.components
             FROM {$this->calendarInstancesTableName} i JOIN {$this->calendarTableName} c ON c.id = i.calendarid
             WHERE i.principaluri = ? AND i.access = 1 ORDER BY i.calendarorder"
        );
        $stmt->execute([self::SYSTEM]);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $components = $row['components'] ? explode(',', $row['components']) : ['VEVENT'];
            $calendars[] = [
                'id' => [(int) $row['calendarid'], (int) $row['id']],
                'uri' => $row['uri'],
                'principaluri' => $principalUri,
                '{' . \Sabre\CalDAV\Plugin::NS_CALENDARSERVER . '}getctag' => 'http://sabre.io/ns/sync/' . ($row['synctoken'] ?: '0'),
                '{http://sabredav.org/ns}sync-token' => $row['synctoken'] ?: '0',
                '{' . \Sabre\CalDAV\Plugin::NS_CALDAV . '}supported-calendar-component-set' => new \Sabre\CalDAV\Xml\Property\SupportedCalendarComponentSet($components),
                '{' . \Sabre\CalDAV\Plugin::NS_CALDAV . '}schedule-calendar-transp' => new \Sabre\CalDAV\Xml\Property\ScheduleCalendarTransp('transparent'),
                'share-resource-uri' => '/ns/share/' . $row['calendarid'],
                'share-access' => Sharing::ACCESS_READ,
                'read-only' => true,
                '{http://sabredav.org/ns}read-only' => true,
                '{DAV:}displayname' => $row['displayname'],
                '{urn:ietf:params:xml:ns:caldav}calendar-description' => $row['description'],
                '{urn:ietf:params:xml:ns:caldav}calendar-timezone' => $row['timezone'],
                '{http://apple.com/ns/ical/}calendar-order' => (int) $row['calendarorder'],
                '{http://apple.com/ns/ical/}calendar-color' => $row['calendarcolor'],
                'system' => true,
            ];
        }

        return $calendars;
    }

    private function guard(mixed $calendarId): void
    {
        if (! $this->systemWrites && $this->isSystem($calendarId)) {
            throw new Forbidden('Календарь компании — только для чтения; события в него добавляет администратор.');
        }
    }

    public function updateCalendar($calendarId, \Sabre\DAV\PropPatch $propPatch)
    {
        $this->guard($calendarId);
        parent::updateCalendar($calendarId, $propPatch);
    }

    public function deleteCalendar($calendarId)
    {
        $this->guard($calendarId);
        parent::deleteCalendar($calendarId);
    }

    public function createCalendarObject($calendarId, $objectUri, $calendarData)
    {
        $this->guard($calendarId);

        return parent::createCalendarObject($calendarId, $objectUri, $calendarData);
    }

    public function updateCalendarObject($calendarId, $objectUri, $calendarData)
    {
        $this->guard($calendarId);

        return parent::updateCalendarObject($calendarId, $objectUri, $calendarData);
    }

    public function deleteCalendarObject($calendarId, $objectUri)
    {
        $this->guard($calendarId);
        parent::deleteCalendarObject($calendarId, $objectUri);
    }
}
