<?php

namespace App\Console\Commands;

use App\Models\AppSetting;
use App\Services\Mail\ImapSession;
use App\Services\Server\Reports;
use Illuminate\Console\Command;

/**
 * Забрать отчёты DMARC/TLS-RPT из ящика (по умолчанию postmaster — туда указывает rua в DNS),
 * разобрать и переложить письма в папку «Reports», чтобы не мешались во «Входящих».
 */
class ReportsFetch extends Command
{
    protected $signature = 'reports:fetch {--mailbox=} {--keep : не перекладывать письма}';

    protected $description = 'Разбор отчётов DMARC и TLS-RPT из почтового ящика';

    public function handle(Reports $reports): int
    {
        $mailbox = strtolower((string) ($this->option('mailbox') ?: (AppSetting::group('reports')['mailbox'] ?? '') ?: 'postmaster@' . config('areas.default_domain')));
        try {
            $client = ImapSession::master($mailbox);
        } catch (\Throwable $e) {
            $this->error('IMAP: ' . $e->getMessage());

            return self::FAILURE;
        }
        $inbox = $client->getFolder('INBOX');
        $target = null;
        if (! $this->option('keep')) {
            try {
                $target = $client->getFolder('Reports') ?: $client->createFolder('Reports');
            } catch (\Throwable) {
                $target = null;
            }
        }
        $found = 0;
        $parsed = ['dmarc' => 0, 'tls' => 0];
        $messages = $inbox->query()->since(now()->subDays(60))->leaveUnread()->get();
        foreach ($messages as $m) {
            $subject = (string) $m->getSubject();
            if (! preg_match('/report domain|report-id|dmarc|tls[- ]?rpt|tls report/i', $subject)) {
                continue;
            }
            $got = ['dmarc' => 0, 'tls' => 0];
            foreach ($m->getAttachments() as $a) {
                $r = $reports->ingest((string) $a->getName(), (string) $a->getContent());
                $got['dmarc'] += $r['dmarc'];
                $got['tls'] += $r['tls'];
            }
            // Некоторые шлют JSON в теле письма без вложения.
            if (! $got['dmarc'] && ! $got['tls']) {
                $body = trim((string) $m->getTextBody());
                if (str_starts_with($body, '{')) {
                    $r = $reports->ingest('report.json', $body);
                    $got['tls'] += $r['tls'];
                }
            }
            if ($got['dmarc'] || $got['tls'] || preg_match('/report domain|tls[- ]?rpt/i', $subject)) {
                $found++;
                $parsed['dmarc'] += $got['dmarc'];
                $parsed['tls'] += $got['tls'];
                if ($target) {
                    try {
                        $m->move('Reports');
                    } catch (\Throwable) {
                    }
                }
            }
        }
        $this->info("Писем с отчётами: {$found}; новых DMARC: {$parsed['dmarc']}, TLS-RPT: {$parsed['tls']}");

        return self::SUCCESS;
    }
}
