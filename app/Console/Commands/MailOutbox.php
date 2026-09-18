<?php

namespace App\Console\Commands;

use App\Models\Webmail\Outbox;
use App\Services\Mail\ImapSession;
use App\Services\Mail\MailStore;
use App\Services\Mail\Outgoing;
use App\Services\Server\Alerts;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/** Раз в минуту: отправить письма, у которых подошло время («Отправить позже»). */
class MailOutbox extends Command
{
    /** Сколько раз пробуем, прежде чем признать отправку несостоявшейся. */
    private const TRIES = 6;

    protected $signature = 'mail:outbox';

    protected $description = 'Отправить письма из очереди отложенной отправки';

    public function handle(Alerts $alerts): int
    {
        foreach (Outbox::where('status', 'scheduled')->where('send_at', '<=', now())->get() as $row) {
            $client = null;
            try {
                $raw = Storage::disk('local')->get($row->path);
                if ($raw === null) {
                    throw new \RuntimeException('файл письма не найден');
                }
                $client = ImapSession::master($row->user);
                // Копия в «Отправленные» живёт внутри sendRaw и своей очереди повторов:
                // её сбой сюда не долетает, иначе ушедшее письмо считалось бы неотправленным.
                Outgoing::sendRaw($raw, $row->from, $row->recipients, ImapSession::smtpLocal(), new MailStore($client));
                Storage::disk('local')->delete($row->path);
                $row->update(['status' => 'sent', 'error' => null]);
                $this->line("{$row->user}: отправлено «{$row->subject}»");
            } catch (\Throwable $e) {
                $row->increment('attempts');
                $this->error("{$row->user}: {$e->getMessage()}");
                if ($row->attempts < self::TRIES) {
                    // Сбой бывает временным — отойдём и попробуем ещё, откладывая всё дальше.
                    $row->update([
                        'error' => mb_substr($e->getMessage(), 0, 2000),
                        'send_at' => now()->addMinutes(2 * $row->attempts),
                    ]);

                    continue;
                }
                // Дальше пробовать бессмысленно. Само письмо на диске остаётся — его можно
                // отправить руками; но молчать нельзя: черновика у человека уже нет.
                $row->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 2000)]);
                $alerts->send('⚠️ Отложенное письмо не отправлено: ' . $row->user . ' → «'
                    . $row->subject . '» (' . self::TRIES . ' попытки). ' . mb_substr($e->getMessage(), 0, 200)
                    . '. Текст письма сохранён: ' . $row->path);
            } finally {
                if ($client) {
                    try {
                        $client->disconnect();
                    } catch (\Throwable) {
                    }
                }
            }
        }

        return self::SUCCESS;
    }
}
