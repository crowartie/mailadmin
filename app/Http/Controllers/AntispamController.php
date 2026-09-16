<?php

namespace App\Http\Controllers;

use App\Models\AdminAction;
use App\Services\Server\Antispam;
use App\Services\Server\Ctl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Страница «Антиспам»: что поймано за сутки, как учится фильтр, какие внешние базы отвечают, пороги. */
class AntispamController extends Controller
{
    public function __construct(private readonly Antispam $antispam)
    {
    }

    public function index(): Response
    {
        return Inertia::render('Antispam/Index', $this->payload() + ['available' => Ctl::available()]);
    }

    /** Обновление без перерисовки страницы. */
    public function json(Request $request): JsonResponse
    {
        if ($request->boolean('fresh')) {
            $this->antispam->forget();
        }

        return response()->json($this->payload());
    }

    public function levels(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tag2' => ['required', 'numeric', 'min:3', 'max:15'],
            'kill' => ['required', 'numeric', 'min:3', 'max:20'],
        ]);
        abort_if($data['kill'] < $data['tag2'], 422, 'Порог отбрасывания не может быть ниже порога пометки');
        try {
            $this->antispam->setLevels((float) $data['tag2'], (float) $data['kill']);
        } catch (\RuntimeException $e) {
            abort(500, $e->getMessage());
        }
        AdminAction::log('antispam.levels', 'amavis', "пометка {$data['tag2']}, отбрасывание {$data['kill']}");

        return response()->json(['levels' => $this->antispam->levels()]);
    }

    public function learn(): JsonResponse
    {
        $log = $this->antispam->learnNow();
        AdminAction::log('antispam.learn', 'bayes', 'обучение вручную');

        return response()->json(['bayes' => $this->antispam->bayes(), 'learning' => $this->antispam->learning(), 'tail' => $log]);
    }

    private function payload(): array
    {
        return [
            'stats' => $this->antispam->stats(),
            'bayes' => $this->antispam->bayes(),
            'learning' => $this->antispam->learning(),
            'net' => $this->antispam->net(),
            'levels' => $this->antispam->levels(),
            'rules' => $this->antispam->rules(),
        ];
    }
}
