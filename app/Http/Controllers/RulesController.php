<?php

namespace App\Http\Controllers;

use App\Models\SieveRule;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RulesController extends Controller
{
    public function index(Request $request): Response
    {
        $search = $request->string('search')->toString();
        $kind = $request->string('kind')->toString();

        $rules = SieveRule::query()
            ->search($search)
            ->ofKind($kind)
            ->orderByDesc('updated_at')
            ->orderBy('owner')
            ->orderBy('position')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (SieveRule $rule) => [
                'id' => $rule->id,
                'owner' => $rule->owner,
                'owner_name' => $rule->owner_name,
                'kind' => $rule->kind,
                'condition' => $rule->condition,
                'action' => $rule->action,
                'active' => $rule->active,
                'until' => $rule->until?->format('d.m.Y'),
            ]);

        $synced = SieveRule::max('synced_at');

        return Inertia::render('Rules/Index', [
            'rules' => $rules,
            'filters' => ['search' => $search, 'kind' => $kind ?: 'all'],
            'syncedAt' => $synced ? \Illuminate\Support\Carbon::parse($synced)->format('d.m.Y H:i') : null,
        ]);
    }
}
