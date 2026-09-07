<?php

namespace App\Http\Controllers;

use App\Http\Requests\AliasRequest;
use App\Models\Vmail\Alias;
use App\Models\Vmail\Domain;
use App\Models\Vmail\Forwarding;
use App\Models\Vmail\Mailbox;
use App\Services\Vmail\AddressResolver;
use App\Services\Vmail\AliasService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AliasController extends Controller
{
    public function __construct(
        private readonly AliasService $service,
        private readonly AddressResolver $resolver,
    ) {
    }

    public function index(Request $request): Response
    {
        return $this->renderList($request, null);
    }

    public function create(Request $request): Response
    {
        return $this->renderList($request, ['isNew' => true, 'address' => '', 'name' => '', 'targets' => [''], 'active' => true]);
    }

    public function edit(Request $request, string $alias): Response
    {
        $model = Alias::query()->findOrFail($alias);

        return $this->renderList($request, [
            'isNew' => false,
            'address' => $model->address,
            'name' => $model->name,
            'targets' => Forwarding::where('address', $model->address)->where('is_alias', 1)->pluck('forwarding')->all(),
            'active' => $model->active,
        ]);
    }

    public function store(AliasRequest $request): RedirectResponse
    {
        $alias = $this->service->create($request->validated());

        return redirect('/aliases')->with('success', "Псевдоним {$alias->address} создан");
    }

    public function update(AliasRequest $request, string $alias): RedirectResponse
    {
        $model = Alias::query()->findOrFail($alias);
        $this->service->update($model, $request->validated());

        return redirect('/aliases')->with('success', "Псевдоним {$model->address} сохранён");
    }

    public function destroy(string $alias): RedirectResponse
    {
        $model = Alias::query()->findOrFail($alias);
        $this->service->delete($model);

        return redirect('/aliases')->with('success', "Псевдоним {$model->address} удалён");
    }

    private function renderList(Request $request, ?array $editing): Response
    {
        $search = strtolower($request->string('search')->toString());
        $traceFor = strtolower($request->string('trace')->toString());

        // Именованные псевдонимы из `alias` плюс дополнительные адреса сотрудников,
        // у которых своей строки в `alias` нет — вместе это и есть «Псевдонимы» как в Kerio.
        $named = Alias::query()->get()->keyBy('address');

        $targets = Forwarding::query()
            ->where('is_alias', 1)
            ->orderBy('address')
            ->get()
            ->groupBy('address');

        $mailboxNames = Mailbox::query()->pluck('name', 'username');

        $rows = collect();
        foreach ($targets as $address => $group) {
            $alias = $named->get($address);
            $list = $group->pluck('forwarding')->values();
            $rows->push([
                'address' => $address,
                'named' => (bool) $alias,
                'name' => $alias?->name ?: ($list->count() === 1 ? 'Дополнительный адрес — ' . ($mailboxNames[$list[0]] ?? $list[0]) : ''),
                'targets' => $list->all(),
                'active' => $alias ? (bool) $alias->active : (bool) $group->first()->active,
            ]);
        }
        foreach ($named as $address => $alias) {
            if (! $targets->has($address)) {
                $rows->push(['address' => $address, 'named' => true, 'name' => $alias->name, 'targets' => [], 'active' => (bool) $alias->active]);
            }
        }

        $rows = $rows->sortBy('address')->values();
        $total = $rows->count();

        if ($search !== '') {
            $rows = $rows->filter(fn ($r) => str_contains($r['address'], $search)
                || str_contains(mb_strtolower($r['name']), $search)
                || collect($r['targets'])->contains(fn ($t) => str_contains($t, $search)))->values();
        }

        return Inertia::render('Aliases/Index', [
            'rows' => $rows,
            'total' => $total,
            'filters' => ['search' => $search],
            'editing' => $editing,
            'domains' => Domain::where('active', 1)->orderBy('domain')->pluck('domain')->all(),
            'trace' => $traceFor !== '' ? $this->resolver->trace($traceFor) : null,
            'traceFor' => $traceFor ?: null,
        ]);
    }
}
