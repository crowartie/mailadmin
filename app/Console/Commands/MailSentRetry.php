<?php

namespace App\Console\Commands;

use App\Models\Webmail\SentRetry;
use App\Services\Mail\ImapSession;
use App\Services\Mail\MailStore;
use App\Services\Server\Alerts;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Раз в минуту: доносим копии в «Отправленные», которые не легли туда при отправке.
 *
 * Письмо у получателя уже есть, поэтому спешить некуда — важно не потерять копию
 * и не положить её дважды. Перед каждой попыткой проверяем Message-ID: если копия
 * всё-таки появилась (её дослал почтовый клиент), очередь просто забывает запись.
 */
class MailSentRetry extends Command
{
    /** После скольких попыток перестаём пробовать и зовём администратора. */
    private const GIVE_UP = 12;

    protected $signature = 'mail:sent-retry';

    protected $description = 'Донести копии писем в «Отправленные» после сбоя';

    public function handle(Alerts $alerts): int
    {
        foreach (SentRetry::where('attempts', '<', self::GIVE_UP)->orderBy('id')->limit(50)->get() as $row) {
            $client = null;
            try {
                $raw = Storage::disk('local')->get($row->path);
                if ($raw === null) {
                    throw new \RuntimeException('файл письма не найден: ' . $row->path);
                }
                $client = ImapSession::master($row->user);
                $store = new MailStore($client);
                $folder = $row->folder ?: $store->rolePath('sent');

                // Копия могла появиться сама — тогда вторая нам не нужна.
                $already = $row->message_id ? $store->findByMessageId($folder, $row->message_id) : null;
                if (! $already) {
                    $store->append($folder, $raw, ['\\Seen'], $row->message_id);
                }
                Storage::disk('local')->delete($row->path);
                $row->delete();
                $this->line($row->user . ': копия ' . ($already ? 'уже была' : 'дослана') . ' — «' . $row->subject . '»');
            } catch (\Throwable $e) {
                $row->increment('attempts');
                $row->update(['error' => mb_substr($e->getMessage(), 0, 2000)]);
                $this->error($row->user . ': ' . $e->getMessage());
                if ($row->attempts >= self::GIVE_UP) {
                    // Письмо не теряем: оно остаётся файлом на диске, запись — в таблице.
                    $alerts->send('⚠️ Копия отправленного письма так и не легла в «Отправленные» у '
                        . $row->user . ' («' . $row->subject . '»): ' . mb_substr($e->getMessage(), 0, 200)
                        . '. Само письмо получателю ушло, копия лежит в ' . $row->path);
                }
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
