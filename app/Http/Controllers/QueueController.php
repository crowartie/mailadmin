<?php

namespace App\Http\Controllers;

use App\Models\AdminAction;
use App\Services\Server\Ctl;
use App\Services\Server\PostfixQueue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Очередь Postfix: список, подробности письма, повтор/удержание/удаление, скачать .eml. */
class QueueController extends Controller
{
    public function __construct(private readonly PostfixQueue $queue)
    {
    }

    public function index(Request $request): Response
    {
        $rows = $this->queue->all();

        return Inertia::render('Queue/Index', [
            'rows' => $rows,
            'available' => Ctl::available(),
            'filters' => ['state' => $request->query('state', 'all'), 'q' => $request->query('q', '')],
        ]);
    }

    /** Живое обновление списка. */
    public function list(): JsonResponse
    {
        return response()->json($this->queue->all());
    }

    public function show(string $id): JsonResponse
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{6,20}$/', $id), 404);

        return response()->json($this->queue->details($id));
    }

    public function raw(string $id)
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{6,20}$/', $id), 404);
        $raw = $this->queue->raw($id);
        // postcat выводит служебные строки — оставляем только само письмо после «*** MESSAGE CONTENTS».
        if (preg_match('/\*\*\* MESSAGE CONTENTS[^\n]*\n(.*)\*\*\* HEADER EXTRACTED/s', $raw, $m)) {
            $raw = $m[1];
        }
        AdminAction::log('queue.download', $id);

        return response($raw, 200, ['Content-Type' => 'message/rfc822', 'Content-Disposition' => "attachment; filename=\"{$id}.eml\""]);
    }

    public function action(Request $request): RedirectResponse|JsonResponse
    {
        $data = $request->validate(['op' => ['required', 'in:retry,hold,release,delete,flush'], 'ids' => ['array'], 'ids.*' => ['string', 'max:20']]);
        try {
            $this->queue->action($data['op'], $data['ids'] ?? []);
        } catch (\RuntimeException $e) {
            return $request->wantsJson() ? response()->json(['message' => $e->getMessage()], 500) : back()->with('error', $e->getMessage());
        }
        AdminAction::log('queue.' . $data['op'], implode(',', $data['ids'] ?? []), $data['op'] === 'flush' ? 'вся очередь' : count($data['ids'] ?? []) . ' писем');
        $msg = ['retry' => 'Повторная отправка запущена', 'hold' => 'Письма поставлены на удержание', 'release' => 'Письма сняты с удержания', 'delete' => 'Письма удалены из очереди', 'flush' => 'Очередь отправляется'][$data['op']];

        return $request->wantsJson() ? response()->json(['ok' => true, 'message' => $msg, 'rows' => $this->queue->all()]) : back()->with('success', $msg);
    }
}
