<?php

namespace App\Services\Dav;

use App\Dav\Server;
use App\Models\Vmail\Mailbox;
use Illuminate\Support\Facades\DB;
use Sabre\CalDAV\Xml\Property\SupportedCalendarComponentSet;
use Sabre\DAV\Sharing\Plugin as Sharing;
use Sabre\DAV\Xml\Element\Sharee;

/** Заведение и удаление: пользователь целиком, календарь и книга подразделения. */
class DavProvisioning
{
    public function __construct(
        private readonly DavAccess $a,
    ) {
    }


    /** Удалить всё DAV-хозяйство пользователя: principal, личные книги с карточками, календари с событиями, его доли в чужих. */
    public function removeUser(string $user): void
    {
        $user = strtolower($user);
        $principal = Server::principal($user);
        foreach (DB::table('dav_addressbooks')->where('principaluri', $principal)->pluck('id') as $id) {
            DB::table('dav_cards')->where('addressbookid', $id)->delete();
            DB::table('dav_addressbookchanges')->where('addressbookid', $id)->delete();
            DB::table('dav_addressbooks')->where('id', $id)->delete();
        }
        foreach (DB::table('dav_calendarinstances')->where('principaluri', $principal)->get(['id', 'calendarid', 'access']) as $inst) {
            if ((int) $inst->access === 1) {
                // владелец — календарь целиком, вместе с чужими долями
                DB::table('dav_calendarobjects')->where('calendarid', $inst->calendarid)->delete();
                DB::table('dav_calendarchanges')->where('calendarid', $inst->calendarid)->delete();
                DB::table('dav_calendarinstances')->where('calendarid', $inst->calendarid)->delete();
                DB::table('dav_calendars')->where('id', $inst->calendarid)->delete();
            } else {
                DB::table('dav_calendarinstances')->where('id', $inst->id)->delete();
            }
        }
        DB::table('dav_schedulingobjects')->where('principaluri', $principal)->delete();
        DB::table('dav_calendarsubscriptions')->where('principaluri', $principal)->delete();
        DB::table('dav_principals')->where('uri', $principal)->orWhere('uri', 'like', $principal . '/%')->delete();
    }


    // ── Подразделения: календарь и книга отдела ─────────────────────────

    public static function unitPrincipal(int $unitId): string
    {
        return 'principals/units/' . $unitId;
    }


    /** Principal отдела, его календарь и книга — создаются при первом обращении. */
    public function ensureUnitResources(\App\Models\Unit $unit): void
    {
        $principal = self::unitPrincipal($unit->id);
        $title = 'Отдел «' . $unit->name . '»';
        if (! DB::table('dav_principals')->where('uri', $principal)->exists()) {
            DB::table('dav_principals')->insert(['uri' => $principal, 'email' => $unit->address, 'displayname' => $title]);
        }
        if (! $unit->calendar_id || ! DB::table('dav_calendars')->where('id', $unit->calendar_id)->exists()) {
            $id = $this->a->cals->createCalendar($principal, 'unit-' . $unit->id, [
                '{DAV:}displayname' => $title,
                '{http://apple.com/ns/ical/}calendar-color' => '#0F9D58',
                '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set' => new SupportedCalendarComponentSet(['VEVENT', 'VTODO']),
            ]);
            $unit->calendar_id = is_array($id) ? (int) $id[0] : (int) $id;
        }
        if (! $unit->addressbook_id || ! DB::table('dav_addressbooks')->where('id', $unit->addressbook_id)->exists()) {
            $unit->addressbook_id = (int) $this->a->cards->createAddressBook($principal, 'unit-' . $unit->id, ['{DAV:}displayname' => $title]);
        }
        if ($unit->isDirty(['calendar_id', 'addressbook_id'])) {
            $unit->save();
        }
    }


    public function renameUnitResources(\App\Models\Unit $unit): void
    {
        $title = 'Отдел «' . $unit->name . '»';
        DB::table('dav_principals')->where('uri', self::unitPrincipal($unit->id))->update(['displayname' => $title, 'email' => $unit->address]);
        if ($unit->calendar_id) {
            DB::table('dav_calendarinstances')->where('calendarid', $unit->calendar_id)->update(['displayname' => $title]);
        }
        if ($unit->addressbook_id) {
            DB::table('dav_addressbooks')->where('id', $unit->addressbook_id)->update(['displayname' => $title]);
        }
    }


    /** Доступ к календарю отдела на запись — ровно текущим членам. @param string[] $members */
    public function setUnitMembers(\App\Models\Unit $unit, array $members): void
    {
        if (! $unit->calendar_id) {
            return;
        }
        $ownerInstance = (int) DB::table('dav_calendarinstances')->where('calendarid', $unit->calendar_id)->where('access', 1)->value('id');
        if (! $ownerInstance) {
            return;
        }
        $id = [(int) $unit->calendar_id, $ownerInstance];
        $current = [];
        foreach ($this->a->cals->getInvites($id) as $sharee) {
            if ($sharee->access !== Sharing::ACCESS_SHAREDOWNER) {
                $current[] = strtolower(preg_replace('/^mailto:/i', '', $sharee->href));
            }
        }
        $members = array_values(array_unique(array_map('strtolower', $members)));
        $sharees = [];
        foreach (array_diff($members, $current) as $m) {
            if (! Mailbox::query()->where('username', $m)->exists()) {
                continue;
            }
            $this->a->ensureUser($m);
            $sharees[] = new Sharee(['href' => 'mailto:' . $m, 'principal' => Server::principal($m), 'access' => Sharing::ACCESS_READWRITE, 'inviteStatus' => Sharing::INVITE_ACCEPTED, 'properties' => ['{DAV:}displayname' => $this->a->displayName($m)]]);
        }
        foreach (array_diff($current, $members) as $m) {
            $sharees[] = new Sharee(['href' => 'mailto:' . $m, 'principal' => Server::principal($m), 'access' => Sharing::ACCESS_NOACCESS]);
        }
        if ($sharees) {
            $this->a->cals->updateInvites($id, $sharees);
        }
    }


    public function deleteUnitResources(\App\Models\Unit $unit): void
    {
        if ($unit->calendar_id) {
            $ownerInstance = (int) DB::table('dav_calendarinstances')->where('calendarid', $unit->calendar_id)->where('access', 1)->value('id');
            if ($ownerInstance) {
                $this->a->cals->deleteCalendar([(int) $unit->calendar_id, $ownerInstance]);
            }
        }
        if ($unit->addressbook_id) {
            $this->a->cards->systemWrites = true;
            $this->a->cards->deleteAddressBook($unit->addressbook_id);
        }
        DB::table('dav_principals')->where('uri', self::unitPrincipal($unit->id))->delete();
    }
}
