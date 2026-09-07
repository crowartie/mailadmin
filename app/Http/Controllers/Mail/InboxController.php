<?php

namespace App\Http\Controllers\Mail;

use App\Http\Controllers\Controller;
use App\Models\Webmail\Label;
use App\Models\Webmail\Outbox;
use App\Models\Webmail\Setting;
use App\Services\Mail\ImapSession;
use App\Services\Mail\MailStore;
use App\Services\Mail\Outgoing;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Страницы веб-почты. Всё живое (списки, письма, действия) ходит через /mail/api/* —
 * см. контроллеры в Mail\Api. Здесь только первая отрисовка.
 */
class InboxController extends Controller
{
    public function index(Request $request, ImapSession $imap, string $folder = 'INBOX'): Response
    {
        $store = new MailStore($imap->client());
        $filter = (string) $request->query('filter', 'all');
        $q = $request->query('q');

        return Inertia::render('Mail/Inbox', [
            'user' => $imap->user(),
            'settings' => Setting::for($imap->user()),
            'identities' => (new Outgoing($imap, $store))->identities(),
            'folders' => $store->folders(),
            'labels' => Label::where('user', $imap->user())->orderBy('sort')->orderBy('id')->get(['id', 'name', 'color']),
            'folder' => $folder,
            'filter' => $filter,
            'query' => $q,
            'list' => $store->list($folder, (int) $request->query('page', 1), $filter, $q),
            'outbox' => Outbox::where('user', $imap->user())->where('status', 'scheduled')->count(),
            'openUid' => $request->query('uid') ? (int) $request->query('uid') : null,
        ]);
    }

    public function settings(Request $request, ImapSession $imap, string $section = 'general'): Response
    {
        $store = new MailStore($imap->client());

        return Inertia::render('Mail/Settings', [
            'user' => $imap->user(),
            'section' => $section,
            'settings' => Setting::for($imap->user()),
            'identities' => (new Outgoing($imap, $store))->identities(),
            'folders' => $store->folders(),
            'labels' => Label::where('user', $imap->user())->orderBy('sort')->orderBy('id')->get(['id', 'name', 'color']),
            'rules' => \App\Models\Webmail\RuleSet::find($imap->user())?->only(['rules', 'autoreply']) ?? ['rules' => [], 'autoreply' => null],
        ]);
    }
}
