<?php

namespace App\Http\Controllers\Mail;

use App\Http\Controllers\Controller;
use App\Services\Mail\ImapSession;
use App\Services\Mail\MailStore;
use Illuminate\Contracts\View\View;

/**
 * Печатная форма письма: отдельная страница только с письмом — тема, поля, вложения, текст, переписка, подвал.
 * Открывается в новой вкладке и сама вызывает печать; браузерные колонтитулы убраны нулевыми полями @page.
 */
class PrintController extends Controller
{
    public function show(ImapSession $imap, string $folder, int $uid): View
    {
        $store = new MailStore($imap->client());
        $m = $store->message($folder, $uid, false);
        $thread = [];
        try {
            $thread = array_values(array_filter($store->threadOf($folder, $uid), fn ($t) => (int) ($t['uid'] ?? 0) !== $uid || ($t['folder'] ?? $folder) !== $folder));
        } catch (\Throwable) {
            // цепочка не обязательна для печати
        }
        usort($thread, fn ($a, $b) => strcmp((string) ($b['date'] ?? ''), (string) ($a['date'] ?? '')));
        $attachments = array_values(array_filter($m['attachments'] ?? [], fn ($a) => empty($a['inline'])));

        return view('mail.print', [
            'm' => $m,
            'thread' => $thread,
            'attachments' => $attachments,
            'user' => $imap->user(),
            'printedAt' => now()->format('d.m.Y, H:i'),
        ]);
    }
}
