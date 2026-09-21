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
            // Отправители, для которых правило уже есть: по ним окно «класть сюда всегда?» не показываем.
            'senderRules' => \App\Models\Webmail\RuleSet::senderFolders($imap->user()),
            'folder' => $folder,
            'filter' => $filter,
            'query' => $q,
            // Порядок берём из адреса: страница, открытая по ссылке или после обновления,
            // должна показывать список в том же порядке.
            'list' => $store->list($folder, (int) $request->query('page', 1), $filter, $q, (string) $request->query('sort', 'date')),
            // Считаем и «не отправилось»: про неудачу человек должен узнать сам,
            // а не обнаружить через неделю, что письмо не ушло.
            'outbox' => Outbox::where('user', $imap->user())->whereIn('status', ['scheduled', 'failed'])->count(),
            'quarantine' => \App\Http\Controllers\Mail\QuarantineController::count($imap->user()),
            // Индикатор занятого места: разметка в панели папок была, данных не было.
            'quota' => $store->quota(),
            'cloud' => ['enabled' => \App\Services\Cloud\Cloud::enabled(), 'thresholdMb' => \App\Services\Cloud\Cloud::thresholdMb(), 'maxMb' => \App\Services\Cloud\Cloud::maxMb()],
            // Предупреждение о тяжёлом письме раньше срабатывало по зашитым 20 МБ и не было
            // связано с настоящим пределом почтового сервера. Отдаём его форме вместе
            // с пределом на число файлов, который проверяет ComposeController.
            'limits' => ['messageMb' => self::messageLimitMb(), 'maxFiles' => 20],
            'openUid' => $request->query('uid') ? (int) $request->query('uid') : null,
            'composeTo' => $request->query('compose') ? (string) $request->query('to', '') : null,
        ]);
    }

    /**
     * Предел на размер письма из настроек почтового сервера (message_size_limit).
     *
     * Спрашиваем отдельно, а не через current(): тот ради семи значений запускает семь
     * внешних программ и стоил странице 172 мс — больше, чем список папок и писем вместе.
     */
    private static function messageLimitMb(): int
    {
        try {
            $mb = app(\App\Services\Server\AmavisConfig::class)->messageSizeMb();
        } catch (\Throwable) {
            $mb = 0;
        }

        return $mb > 0 ? $mb : 25;
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
            'force2fa' => (bool) $request->session()->get('mail.force2fa'),
            // Один источник адресов на всё приложение: раньше «Телефон и программы»,
            // «Безопасность» и справка называли разные хосты и разные порты SMTP.
            'hosts' => \App\Http\Controllers\Mail\HelpController::hosts($imap->user()),
        ]);
    }
}
