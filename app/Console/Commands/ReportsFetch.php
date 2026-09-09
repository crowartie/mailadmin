<?php

namespace App\Console\Commands;

use App\Models\AppSetting;
use App\Services\Mail\ImapSession;
use App\Services\Mail\MailStore;
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
        $configured = strtolower((string) ($this->option('mailbox') ?: (AppSetting::group('reports')['mailbox'] ?? '')));
        $mailboxes = array_values(array_unique(array_filter([$configured, 'postmaster@' . config('areas.default_domain')])));
        $rc = self::SUCCESS;
        foreach ($mailboxes as $mailbox) {
            if ($this->fetchFrom($mailbox, $reports) !== self::SUCCESS) {
                $rc = self::FAILURE;
            }
        }

        return $rc;
    }

    private function fetchFrom(string $mailbox, Reports $reports): int
    {
        try {
            $client = ImapSession::master($mailbox);
        } catch (\Throwable $e) {
            $this->error($mailbox . ' IMAP: ' . $e->getMessage());

            return self::FAILURE;
        }
        $inbox = $client->getFolder('INBOX');
        $store = new MailStore($client);
        $target = null;
        if (! $this->option('keep')) {
            try {
                $has = collect($store->folders())->contains(fn ($f) => strcasecmp($f['path'], 'Reports') === 0);
                $target = $has ? 'Reports' : $store->createFolder('Reports');
            } catch (\Throwable $e) {
                $this->warn('Папка Reports: ' . $e->getMessage());
                $target = null;
            }
        }
        $toMove = [];
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
                $toMove[] = (int) $m->getUid();
            }
        }
        if ($target && $toMove) {
            try {
                $store->move('INBOX', $toMove, $target);
            } catch (\Throwable $e) {
                $this->warn('Не переложил в Reports: ' . $e->getMessage());
            }
        }
        $this->info("{$mailbox}: писем с отчётами {$found}; новых DMARC: {$parsed['dmarc']}, TLS-RPT: {$parsed['tls']}");

        return self::SUCCESS;
    }
}
