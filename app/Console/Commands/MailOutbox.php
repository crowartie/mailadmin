<?php

namespace App\Console\Commands;

use App\Models\Webmail\Outbox;
use App\Services\Mail\ImapSession;
use App\Services\Mail\MailStore;
use App\Services\Mail\Outgoing;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/** Раз в минуту: отправить письма, у которых подошло время («Отправить позже»). */
class MailOutbox extends Command
{
    protected $signature = 'mail:outbox';

    protected $description = 'Отправить письма из очереди отложенной отправки';

    public function handle(): int
    {
        foreach (Outbox::where('status', 'scheduled')->where('send_at', '<=', now())->get() as $row) {
            try {
                $raw = Storage::disk('local')->get($row->path);
                if ($raw === null) {
                    throw new \RuntimeException('файл письма не найден');
                }
                $client = ImapSession::master($row->user);
                Outgoing::sendRaw($raw, $row->from, $row->recipients, ImapSession::smtpLocal(), new MailStore($client));
                $client->disconnect();
                Storage::disk('local')->delete($row->path);
                $row->update(['status' => 'sent']);
                $this->line("{$row->user}: отправлено «{$row->subject}»");
            } catch (\Throwable $e) {
                $row->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 2000)]);
                $this->error("{$row->user}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
