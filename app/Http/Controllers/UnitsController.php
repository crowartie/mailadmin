<?php

namespace App\Http\Controllers;

use App\Models\AdminAction;
use App\Models\EmployeeProfile;
use App\Models\Unit;
use App\Models\Vmail\Mailbox;
use App\Services\Units\UnitService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Подразделения: дерево, состав, адрес отдела, общий календарь и книга, перенос сотрудников. */
class UnitsController extends Controller
{
    public function __construct(private readonly UnitService $units)
    {
    }

    public function index(Request $request, ?int $unit = null): Response
    {
        $tree = $this->units->tree();
        $selected = $unit ? Unit::query()->findOrFail($unit) : null;
        $assigned = EmployeeProfile::query()->whereNotNull('unit_id')->pluck('username');
        $unassigned = Mailbox::query()->people()->whereNotIn('username', $assigned)->orderBy('name')->orderBy('username')->get(['username', 'name', 'rank'])
            ->map(fn (Mailbox $m) => ['username' => $m->username, 'name' => $m->name ?: $m->username, 'title' => $m->rank, 'active' => true, 'lead' => false])->values();

        return Inertia::render('Units/Index', [
            'tree' => $tree,
            'flat' => $this->units->flat(),
            'total' => EmployeeProfile::query()->whereNotNull('unit_id')->count(),
            'employees' => Mailbox::query()->people()->orderBy('name')->get(['username', 'name'])->map(fn ($m) => ['username' => $m->username, 'name' => $m->name ?: $m->username]),
            'selected' => $selected ? [
                'id' => $selected->id, 'name' => $selected->name, 'parent_id' => $selected->parent_id, 'address' => $selected->address, 'lead' => $selected->lead,
                'leadName' => $selected->lead ? (Mailbox::query()->where('username', $selected->lead)->value('name') ?: $selected->lead) : null,
                'parentName' => $selected->parent?->name,
                'members' => $this->units->members($selected),
                'totalMembers' => count($this->units->memberUsernames($selected)),
                'calendar' => (bool) $selected->calendar_id, 'book' => (bool) $selected->addressbook_id,
            ] : null,
            'unassigned' => $unassigned,
            'domain' => config('areas.default_domain'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        try {
            $unit = $this->units->create($data);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }
        AdminAction::log('unit.create', $unit->name, $unit->address);

        return redirect('/units/' . $unit->id)->with('success', 'Подразделение «' . $unit->name . '» создано');
    }

    public function update(Request $request, Unit $unit): RedirectResponse
    {
        $data = $this->validated($request, $unit);
        try {
            $this->units->update($unit, $data);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }
        AdminAction::log('unit.update', $unit->name);

        return back()->with('success', 'Подразделение сохранено');
    }

    public function destroy(Unit $unit): RedirectResponse
    {
        $name = $unit->name;
        $this->units->delete($unit);
        AdminAction::log('unit.delete', $name);

        return redirect('/units')->with('success', 'Подразделение «' . $name . '» удалено, сотрудники перенесены уровнем выше');
    }

    /** Перенести одного или нескольких сотрудников. */
    public function move(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'usernames' => ['required', 'array', 'min:1'], 'usernames.*' => ['email'],
            'unit_id' => ['nullable', 'integer', Rule::exists('units', 'id')],
        ]);
        foreach ($data['usernames'] as $u) {
            $this->units->move(strtolower($u), $data['unit_id'] ?? null);
        }
        $target = $data['unit_id'] ? Unit::find($data['unit_id'])?->name : 'без подразделения';
        AdminAction::log('unit.move', implode(', ', $data['usernames']), '→ ' . $target);

        return back()->with('success', count($data['usernames']) . ' сотр. → ' . $target);
    }

    private function validated(Request $request, ?Unit $unit = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'parent_id' => ['nullable', 'integer', Rule::exists('units', 'id'), $unit ? Rule::notIn([$unit->id]) : 'nullable'],
            'address' => ['nullable', 'string', 'max:120'],
            'lead' => ['nullable', 'email', Rule::exists('vmail.mailbox', 'username')],
        ]);
    }
}
