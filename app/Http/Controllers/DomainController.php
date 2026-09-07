<?php

namespace App\Http\Controllers;

use App\Models\Vmail\Domain;
use Inertia\Inertia;
use Inertia\Response;

class DomainController extends Controller
{
    public function index(): Response
    {
        $domains = Domain::query()
            ->withCount(['mailboxes', 'aliases'])
            ->orderBy('domain')
            ->get()
            ->map(fn (Domain $domain) => [
                'domain' => $domain->domain,
                'description' => $domain->description,
                'mailboxCount' => $domain->mailboxes_count,
                'aliasCount' => $domain->aliases_count,
                // В схеме это лимиты: -1 — создание запрещено, 0 — без ограничения.
                'mailboxLimit' => $domain->mailboxes,
                'aliasLimit' => $domain->aliases,
                'maxQuotaMb' => $domain->maxquota ?: null,
                'transport' => $domain->transport,
                'backupmx' => $domain->backupmx,
                'active' => $domain->active,
            ]);

        return Inertia::render('Domains/Index', [
            'domains' => $domains,
        ]);
    }
}
