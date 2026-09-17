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
    /** Сколько предыдущих писем печатаем целиком. */
    private const THREAD_LIMIT = 10;

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
        // 320: в блок «Ранее в переписке» попадали и более новые письма — распечатка
        // старого письма утверждала, что будущие письма были раньше.
        $ts = static function (?string $d): int {
            $t = $d ? strtotime($d) : false;

            return $t === false ? 0 : $t;
        };
        $mine = $ts($m['date'] ?? null);
        $thread = array_values(array_filter($thread, fn ($t) => $ts($t['date'] ?? null) > 0 && $ts($t['date'] ?? null) < $mine));
        // 321: сравнение строк давало другой порядок, чем на экране, — у писем разные
        // часовые пояса в ISO-дате. Сортируем по времени, как список цепочки.
        usort($thread, fn ($a, $b) => $ts($b['date'] ?? null) <=> $ts($a['date'] ?? null));
        // 319: печатались только заголовки предыдущих писем — тексты пропадали целиком,
        // и распечатка переписки не годилась ни для дела, ни для суда. Дочитываем тела;
        // больше десяти писем не берём, чтобы печать не превращалась в выгрузку ящика,
        // а о непечатаемом остатке говорим прямо в документе.
        $full = [];
        foreach (array_slice($thread, 0, self::THREAD_LIMIT) as $t) {
            $tp = (string) ($t['folder'] ?? $folder);
            $tu = (int) ($t['uid'] ?? 0);
            try {
                $full[] = $tu > 0 ? $store->message($tp, $tu, false) + ['folder' => $tp] : $t;
            } catch (\Throwable) {
                $full[] = $t;   // письмо успели убрать или папка закрыта — печатаем что знаем
            }
        }
        $rest = max(0, count($thread) - count($full));
        $attachments = array_values(array_filter($m['attachments'] ?? [], fn ($a) => empty($a['inline'])));

        return view('mail.print', [
            'm' => $m,
            'thread' => $full,
            'threadRest' => $rest,
            'attachments' => $attachments,
            'user' => $imap->user(),
            'printedAt' => now()->format('d.m.Y, H:i'),
        ]);
    }
}
