<?php

namespace App\Http\Controllers;

use App\Models\AdminAction;
use App\Services\Server\SystemReports;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/** Раздел «Отчёты»: текстовые отчёты сервера — посмотреть в админке, скачать, удалить. */
class SystemReportsController extends Controller
{
    public function __construct(private readonly SystemReports $reports)
    {
    }

    public function index(Request $request): Response
    {
        $kind = $request->query('kind');
        $kind = $kind && preg_match('/^[a-z0-9-]{1,40}$/', $kind) ? $kind : null;
        $file = (string) $request->query('file', '');
        $open = null;
        if ($kind && $file !== '') {
            $text = $this->reports->read($kind, $file);
            if ($text !== null) {
                $open = ['kind' => $kind, 'file' => $file, 'title' => SystemReports::title($kind), 'text' => mb_substr($text, 0, 400000), 'truncated' => mb_strlen($text) > 400000];
            }
        }

        return Inertia::render('Reports/Index', [
            'kinds' => $this->reports->kinds(),
            'kind' => $kind,
            'rows' => array_slice($this->reports->list($kind), 0, 300),
            'open' => $open,
            'systemDir' => SystemReports::SYSTEM_DIR,
            'systemDirOk' => is_dir(SystemReports::SYSTEM_DIR) && is_readable(SystemReports::SYSTEM_DIR),
        ]);
    }

    public function download(string $kind, string $file): HttpResponse
    {
        $p = $this->reports->path($kind, $file);
        abort_if(! $p, 404);

        return response()->download($p, $kind . '-' . $file, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    public function destroy(string $kind, string $file): RedirectResponse
    {
        $this->reports->delete($kind, $file);
        AdminAction::log('settings.update', 'отчёт удалён', $kind . '/' . $file);

        return redirect('/reports' . ($kind ? '?kind=' . $kind : ''))->with('success', 'Отчёт удалён');
    }
}
