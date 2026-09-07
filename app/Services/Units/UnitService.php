<?php

namespace App\Services\Units;

use App\Models\EmployeeProfile;
use App\Models\Unit;
use App\Models\Vmail\Alias;
use App\Models\Vmail\Mailbox;
use App\Services\Dav\DavStore;
use App\Services\Vmail\AliasService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Подразделения: дерево, состав, адрес отдела (псевдоним на всех членов),
 * общий календарь и книга отдела (DAV-ресурсы под principals/units/<id>, доступ членам на запись).
 */
class UnitService
{
    public function __construct(private readonly AliasService $aliases, private readonly DavStore $dav)
    {
    }

    /** Дерево с количеством сотрудников (включая вложенные). @return array<int,array<string,mixed>> */
    public function tree(): array
    {
        $units = Unit::query()->orderBy('sort')->orderBy('name')->get();
        $counts = EmployeeProfile::query()->whereNotNull('unit_id')->selectRaw('unit_id, count(*) as c')->groupBy('unit_id')->pluck('c', 'unit_id')->all();
        $byParent = $units->groupBy(fn (Unit $u) => $u->parent_id ?? 0);
        $build = function (int $parent, int $depth) use (&$build, $byParent, $counts): array {
            $out = [];
            foreach ($byParent->get($parent, collect()) as $u) {
                $children = $build($u->id, $depth + 1);
                $own = (int) ($counts[$u->id] ?? 0);
                $total = $own + array_sum(array_map(fn ($c) => $c['total'], $children));
                $out[] = ['id' => $u->id, 'parent_id' => $u->parent_id, 'name' => $u->name, 'address' => $u->address, 'lead' => $u->lead, 'depth' => $depth, 'own' => $own, 'total' => $total, 'children' => $children];
            }

            return $out;
        };

        return $build(0, 0);
    }

    /** Плоский список для выпадающих списков. @return array<int,array{id:int,name:string,depth:int}> */
    public function flat(): array
    {
        $out = [];
        $walk = function (array $nodes) use (&$walk, &$out) {
            foreach ($nodes as $n) {
                $out[] = ['id' => $n['id'], 'name' => $n['name'], 'depth' => $n['depth'], 'address' => $n['address']];
                $walk($n['children']);
            }
        };
        $walk($this->tree());

        return $out;
    }

    /** Сотрудники подразделения (без вложенных). @return array<int,array<string,mixed>> */
    public function members(Unit $unit): array
    {
        $profiles = EmployeeProfile::query()->where('unit_id', $unit->id)->get()->keyBy('username');
        if ($profiles->isEmpty()) {
            return [];
        }

        return Mailbox::query()->whereIn('username', $profiles->keys())->orderBy('name')->orderBy('username')->get()
            ->map(fn (Mailbox $m) => [
                'username' => $m->username, 'name' => $m->name ?: $m->username, 'title' => $profiles[$m->username]->title ?: $m->rank,
                'active' => (bool) $m->active, 'lead' => $unit->lead === $m->username,
            ])->values()->all();
    }

    public function create(array $data): Unit
    {
        $unit = Unit::create(['parent_id' => $data['parent_id'] ?? null, 'name' => trim($data['name']), 'address' => $this->addr($data['address'] ?? null), 'lead' => $data['lead'] ?? null]);
        $this->ensureResources($unit);
        $this->syncAddress($unit);
        Cache::forget('nav.counts');

        return $unit;
    }

    public function update(Unit $unit, array $data): Unit
    {
        $oldAddress = $unit->address;
        $unit->fill(['parent_id' => $data['parent_id'] ?? $unit->parent_id, 'name' => trim($data['name'] ?? $unit->name), 'lead' => $data['lead'] ?? $unit->lead]);
        if (array_key_exists('address', $data)) {
            $unit->address = $this->addr($data['address']);
        }
        if (($unit->parent_id === $unit->id) || ($unit->parent_id && in_array($unit->id, Unit::find($unit->parent_id)?->parentChain() ?? [], true))) {
            throw new \InvalidArgumentException('Подразделение нельзя вложить само в себя');
        }
        $unit->save();
        $this->ensureResources($unit);
        $this->renameResources($unit);
        if ($oldAddress && $oldAddress !== $unit->address) {
            $this->dropAlias($oldAddress);
        }
        $this->syncAddress($unit);

        return $unit;
    }

    public function delete(Unit $unit): void
    {
        foreach ($unit->children as $child) {
            $child->parent_id = $unit->parent_id;
            $child->save();
        }
        EmployeeProfile::query()->where('unit_id', $unit->id)->update(['unit_id' => $unit->parent_id]);
        if ($unit->address) {
            $this->dropAlias($unit->address);
        }
        $this->dav->deleteUnitResources($unit);
        $unit->delete();
        if ($unit->parent_id) {
            $this->syncMembers(Unit::find($unit->parent_id));
        }
        Cache::forget('nav.counts');
    }

    /** Перенести сотрудника (null — без подразделения). */
    public function move(string $username, ?int $unitId): void
    {
        $profile = EmployeeProfile::for($username);
        $from = $profile->unit_id ? Unit::find($profile->unit_id) : null;
        $profile->unit_id = $unitId;
        $profile->save();
        Mailbox::query()->where('username', $profile->username)->update(['department' => $unitId ? (string) Unit::find($unitId)?->name : '']);
        if ($from) {
            $this->syncMembers($from);
        }
        if ($unitId) {
            $this->syncMembers(Unit::findOrFail($unitId));
        }
    }

    /** Пересобрать псевдоним отдела и доступ к общему календарю по текущему составу. */
    public function syncMembers(Unit $unit): void
    {
        $this->syncAddress($unit);
        try {
            $this->dav->setUnitMembers($unit, $this->memberUsernames($unit));
        } catch (\Throwable $e) {
            Log::warning('Календарь отдела: доступ не обновлён', ['unit' => $unit->id, 'error' => $e->getMessage()]);
        }
    }

    /** @return string[] адреса членов подразделения и вложенных */
    public function memberUsernames(Unit $unit): array
    {
        return EmployeeProfile::query()->whereIn('unit_id', $unit->subtreeIds())->pluck('username')->map('strtolower')->unique()->values()->all();
    }

    private function syncAddress(Unit $unit): void
    {
        if (! $unit->address) {
            return;
        }
        $targets = $this->memberUsernames($unit);
        $alias = Alias::query()->find($unit->address);
        $data = ['name' => 'Отдел «' . $unit->name . '»', 'targets' => $targets, 'active' => true];
        if ($alias) {
            $this->aliases->update($alias, $data);
        } else {
            $this->aliases->create($data + ['address' => $unit->address]);
        }
        Cache::forget('nav.counts');
    }

    private function dropAlias(string $address): void
    {
        $alias = Alias::query()->find($address);
        if ($alias) {
            $this->aliases->delete($alias);
        }
    }

    private function ensureResources(Unit $unit): void
    {
        try {
            $this->dav->ensureUnitResources($unit);
            $this->dav->setUnitMembers($unit, $this->memberUsernames($unit));
        } catch (\Throwable $e) {
            Log::warning('Ресурсы отдела не созданы', ['unit' => $unit->id, 'error' => $e->getMessage()]);
        }
    }

    private function renameResources(Unit $unit): void
    {
        try {
            $this->dav->renameUnitResources($unit);
        } catch (\Throwable $e) {
            Log::warning('Ресурсы отдела не переименованы', ['unit' => $unit->id, 'error' => $e->getMessage()]);
        }
    }

    private function addr(?string $address): ?string
    {
        $address = strtolower(trim((string) $address));
        if ($address === '') {
            return null;
        }
        if (! str_contains($address, '@')) {
            $address .= '@' . config('areas.default_domain');
        }
        if (! filter_var($address, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Адрес отдела указан неверно');
        }
        if (Mailbox::query()->where('username', $address)->exists()) {
            throw new \InvalidArgumentException('Такой ящик уже есть — адрес отдела должен быть свободен');
        }
        $taken = Unit::query()->where('address', $address)->exists() || (Alias::query()->find($address) && ! Unit::query()->where('address', $address)->exists());
        if ($taken) {
            throw new \InvalidArgumentException('Адрес уже занят другим отделом или псевдонимом');
        }

        return $address;
    }
}
