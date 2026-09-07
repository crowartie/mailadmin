<?php

namespace App\Dav;

use Sabre\CardDAV\Backend\PDO;
use Sabre\DAV\Exception\Forbidden;

/**
 * Адресные книги: личные книги пользователей плюс две общие («Сотрудники», «Контакты компании»),
 * которые принадлежат системному principal'у и видны каждому только для чтения.
 *
 * Общие книги подмешиваются в список каждого пользователя как его собственные (иначе ACL sabre
 * не пустит к чужой книге), а запись в них режется здесь — кроме вызовов из админки
 * и синхронизации сотрудников (systemWrites = true).
 */
class CardBackend extends PDO
{
    public const SYSTEM = 'principals/system';

    public bool $systemWrites = false;

    /** @var array<int,string>|null id общей книги → её uri */
    private ?array $systemIds = null;

    public function __construct(\PDO $pdo)
    {
        parent::__construct($pdo);
        $this->addressBooksTableName = 'dav_addressbooks';
        $this->cardsTableName = 'dav_cards';
        $this->addressBookChangesTableName = 'dav_addressbookchanges';
    }

    /** @return array<int,string> */
    public function systemBooks(): array
    {
        if ($this->systemIds === null) {
            $stmt = $this->pdo->prepare("SELECT id, uri FROM {$this->addressBooksTableName} WHERE principaluri = ?");
            $stmt->execute([self::SYSTEM]);
            $this->systemIds = [];
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $this->systemIds[(int) $row['id']] = $row['uri'];
            }
        }

        return $this->systemIds;
    }

    public function isSystem(int|string $addressBookId): bool
    {
        return isset($this->systemBooks()[(int) $addressBookId]);
    }

    public function getAddressBooksForUser($principalUri)
    {
        $books = parent::getAddressBooksForUser($principalUri);
        if ($principalUri === self::SYSTEM) {
            return $books;
        }

        $stmt = $this->pdo->prepare("SELECT id, uri, displayname, description, synctoken FROM {$this->addressBooksTableName} WHERE principaluri = ? ORDER BY id");
        $stmt->execute([self::SYSTEM]);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $books[] = [
                'id' => $row['id'],
                'uri' => $row['uri'],
                'principaluri' => $principalUri,
                '{DAV:}displayname' => $row['displayname'],
                '{urn:ietf:params:xml:ns:carddav}addressbook-description' => $row['description'],
                '{http://calendarserver.org/ns/}getctag' => $row['synctoken'],
                '{http://sabredav.org/ns}sync-token' => $row['synctoken'] ?: '0',
                '{http://sabredav.org/ns}read-only' => true,
                'shared' => true,
            ];
        }

        // Книги отделов: видны сотрудникам своего подразделения и вложенных в него, на запись.
        $mail = strtolower(substr($principalUri, strlen('principals/')));
        $unitId = (int) \Illuminate\Support\Facades\DB::table('employee_profiles')->where('username', $mail)->value('unit_id');
        if ($unitId) {
            $chain = [$unitId];
            $u = \App\Models\Unit::find($unitId);
            if ($u) {
                $chain = array_merge($chain, $u->parentChain());
            }
            $principals = array_map(fn ($id) => 'principals/units/' . $id, $chain);
            $in = implode(',', array_fill(0, count($principals), '?'));
            $stmt = $this->pdo->prepare("SELECT id, uri, displayname, description, synctoken FROM {$this->addressBooksTableName} WHERE principaluri IN ({$in}) ORDER BY id");
            $stmt->execute($principals);
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $books[] = [
                    'id' => $row['id'],
                    'uri' => $row['uri'],
                    'principaluri' => $principalUri,
                    '{DAV:}displayname' => $row['displayname'],
                    '{urn:ietf:params:xml:ns:carddav}addressbook-description' => $row['description'],
                    '{http://calendarserver.org/ns/}getctag' => $row['synctoken'],
                    '{http://sabredav.org/ns}sync-token' => $row['synctoken'] ?: '0',
                    'shared' => true,
                    'unit' => true,
                ];
            }
        }

        return $books;
    }

    private function guard(int|string $addressBookId): void
    {
        if (! $this->systemWrites && $this->isSystem($addressBookId)) {
            throw new Forbidden('Общая адресная книга — только для чтения. Внешний контакт можно предложить в общую через веб-почту.');
        }
    }

    public function updateAddressBook($addressBookId, \Sabre\DAV\PropPatch $propPatch)
    {
        $this->guard($addressBookId);
        parent::updateAddressBook($addressBookId, $propPatch);
    }

    public function deleteAddressBook($addressBookId)
    {
        $this->guard($addressBookId);
        parent::deleteAddressBook($addressBookId);
    }

    public function createCard($addressBookId, $cardUri, $cardData)
    {
        $this->guard($addressBookId);

        return parent::createCard($addressBookId, $cardUri, $cardData);
    }

    public function updateCard($addressBookId, $cardUri, $cardData)
    {
        $this->guard($addressBookId);

        return parent::updateCard($addressBookId, $cardUri, $cardData);
    }

    public function deleteCard($addressBookId, $cardUri)
    {
        $this->guard($addressBookId);

        return parent::deleteCard($addressBookId, $cardUri);
    }
}
