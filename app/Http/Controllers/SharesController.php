<?php

namespace App\Http\Controllers;

use App\Models\AdminAction;
use App\Services\Mail\FolderShares;
use App\Services\Mail\ShareSummary;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;
use Inertia\Inertia;
use Inertia\Response;

/** Страница «Общий доступ»: все открытые папки всех ящиков — по ящикам, по сотрудникам и таблицей. */
class SharesController extends Controller
{
    public function __construct(private readonly FolderShares $shares)
    {
    }

    public function index(): Response
    {
        return Inertia::render('Shares/Index', $this->payload());
    }

    public function json(): JsonResponse
    {
        return response()->json($this->payload());
    }

    /** Доложить права на папки, появившиеся после выдачи (то же, что делает планировщик раз в 10 минут). */
    public function sync(): JsonResponse
    {
        Artisan::call('shares:sync');
        $out = trim(Artisan::output());
        AdminAction::log('shares.sync', 'all', $out !== '' ? mb_substr($out, -200) : 'запуск');

        return response()->json($this->payload() + ['output' => $out]);
    }

    private function payload(): array
    {
        $all = $this->shares->overviewWithFolders();

        return [
            // Строка на сотрудника с итогом по папкам (ShareSummary); сырые строки «папка → кому» — для вида «Таблица».
            'boxes' => ShareSummary::build($all['rows'], $all['folders']),
            'rows' => $all['rows'],
            'candidates' => FolderShares::candidates(''),
            // «Чей ящик» — и служебные ящики (info@, продажи): их как раз чаще всего и открывают.
            // «Кому» — только люди (candidates).
            'owners' => \App\Models\Vmail\Mailbox::query()->where('active', 1)->orderBy('name')->get(['username', 'name'])
                ->map(fn ($m) => ['mail' => $m->username, 'name' => $m->name ?: $m->username])->all(),
            'levels' => FolderShares::TITLES,
        ];
    }
}
