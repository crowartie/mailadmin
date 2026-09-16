<?php

namespace App\Services\Server;

use Illuminate\Support\Facades\DB;

/**
 * Отчёты о доставляемости: DMARC (aggregate XML) и TLS-RPT (JSON). Их присылают Mail.ru, Google, Яндекс и др.
 * на адрес из DNS (rua=mailto:…). Разбор — из вложений писем, итог — в таблицах dmarc_* и tls_reports.
 */
class Reports
{
    /** Разобрать вложение: zip/gz/xml/json. @return array{dmarc:int,tls:int} сколько отчётов записано */
    public function ingest(string $filename, string $content): array
    {
        $stats = ['dmarc' => 0, 'tls' => 0];
        foreach ($this->unpack($filename, $content) as [$name, $data]) {
            $data = trim($data);
            if ($data === '') {
                continue;
            }
            if (str_starts_with($data, '<')) {
                $stats['dmarc'] += $this->dmarc($data) ? 1 : 0;
            } elseif (str_starts_with($data, '{')) {
                $stats['tls'] += $this->tls($data) ? 1 : 0;
            }
        }

        return $stats;
    }

    /** @return array<int,array{0:string,1:string}> */
    private function unpack(string $filename, string $content): array
    {
        $lower = strtolower($filename);
        if (str_ends_with($lower, '.zip') || str_starts_with($content, "PK\x03\x04")) {
            $tmp = tempnam(sys_get_temp_dir(), 'rep');
            file_put_contents($tmp, $content);
            $zip = new \ZipArchive();
            $out = [];
            if ($zip->open($tmp) === true) {
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $n = $zip->getNameIndex($i);
                    $out[] = [$n, (string) $zip->getFromIndex($i)];
                }
                $zip->close();
            }
            @unlink($tmp);

            return $out;
        }
        if (str_ends_with($lower, '.gz') || str_starts_with($content, "\x1f\x8b")) {
            $d = @gzdecode($content);

            return $d === false ? [] : [[preg_replace('/\.gz$/', '', $filename), $d]];
        }

        return [[$filename, $content]];
    }

    private function dmarc(string $xml): bool
    {
        $prev = libxml_use_internal_errors(true);
        $x = simplexml_load_string($xml);
        libxml_use_internal_errors($prev);
        if (! $x || ! isset($x->report_metadata)) {
            return false;
        }
        $meta = $x->report_metadata;
        $reportId = (string) $meta->report_id;
        $org = (string) $meta->org_name;
        if ($reportId === '' || DB::table('dmarc_reports')->where('org', $org)->where('report_id', $reportId)->exists()) {
            return false;
        }
        $id = DB::table('dmarc_reports')->insertGetId([
            'org' => mb_substr($org, 0, 120), 'report_id' => mb_substr($reportId, 0, 200),
            'domain' => (string) ($x->policy_published->domain ?? ''), 'policy' => (string) ($x->policy_published->p ?? ''),
            'begin_at' => date('Y-m-d H:i:s', (int) $meta->date_range->begin), 'end_at' => date('Y-m-d H:i:s', (int) $meta->date_range->end),
            'received_at' => now(),
        ]);
        foreach ($x->record as $r) {
            DB::table('dmarc_records')->insert([
                'report_id' => $id,
                'source_ip' => (string) $r->row->source_ip,
                'count' => (int) $r->row->count,
                'disposition' => (string) ($r->row->policy_evaluated->disposition ?? ''),
                'dkim' => (string) ($r->row->policy_evaluated->dkim ?? ''),
                'spf' => (string) ($r->row->policy_evaluated->spf ?? ''),
                'header_from' => (string) ($r->identifiers->header_from ?? ''),
                'dkim_domain' => (string) ($r->auth_results->dkim->domain ?? ''),
                'spf_domain' => (string) ($r->auth_results->spf->domain ?? ''),
            ]);
        }

        return true;
    }

    private function tls(string $json): bool
    {
        $j = json_decode($json, true);
        if (! is_array($j) || ! isset($j['report-id'])) {
            return false;
        }
        $org = (string) ($j['organization-name'] ?? '');
        if (DB::table('tls_reports')->where('org', $org)->where('report_id', (string) $j['report-id'])->exists()) {
            return false;
        }
        $ok = 0;
        $fail = 0;
        $failures = [];
        foreach ($j['policies'] ?? [] as $p) {
            $ok += (int) ($p['summary']['total-successful-session-count'] ?? 0);
            $fail += (int) ($p['summary']['total-failure-session-count'] ?? 0);
            foreach ($p['failure-details'] ?? [] as $f) {
                $failures[] = ['type' => $f['result-type'] ?? '', 'ip' => $f['sending-mta-ip'] ?? '', 'mx' => $f['receiving-mx-hostname'] ?? '', 'count' => $f['failed-session-count'] ?? 0];
            }
        }
        $policy = $j['policies'][0]['policy']['policy-type'] ?? '';
        DB::table('tls_reports')->insert([
            'org' => mb_substr($org, 0, 120), 'report_id' => mb_substr((string) $j['report-id'], 0, 200), 'policy_type' => $policy,
            'begin_at' => date('Y-m-d H:i:s', strtotime($j['date-range']['start-datetime'] ?? 'now')), 'end_at' => date('Y-m-d H:i:s', strtotime($j['date-range']['end-datetime'] ?? 'now')),
            'success' => $ok, 'failure' => $fail, 'failures' => json_encode($failures, JSON_UNESCAPED_UNICODE), 'received_at' => now(),
        ]);

        return true;
    }

    /** Сводка за N дней для админки. */
    public function summary(int $days = 30): array
    {
        $since = now()->subDays($days);
        $rec = DB::table('dmarc_records')->join('dmarc_reports', 'dmarc_reports.id', '=', 'dmarc_records.report_id')->where('dmarc_reports.end_at', '>=', $since);
        $total = (int) (clone $rec)->sum('dmarc_records.count');
        $pass = (int) (clone $rec)->where(fn ($q) => $q->where('dmarc_records.dkim', 'pass')->orWhere('dmarc_records.spf', 'pass'))->sum('dmarc_records.count');
        $fail = (int) (clone $rec)->where('dmarc_records.dkim', '!=', 'pass')->where('dmarc_records.spf', '!=', 'pass')->sum('dmarc_records.count');
        $failing = (clone $rec)->where('dmarc_records.dkim', '!=', 'pass')->where('dmarc_records.spf', '!=', 'pass')
            ->selectRaw('dmarc_records.source_ip, SUM(dmarc_records.count) as n, MAX(dmarc_reports.org) as org, MAX(dmarc_records.disposition) as disposition')
            ->groupBy('dmarc_records.source_ip')->orderByDesc('n')->limit(10)->get()->map(fn ($r) => ['ip' => $r->source_ip, 'count' => (int) $r->n, 'org' => $r->org, 'disposition' => $r->disposition, 'ptr' => $this->ptr($r->source_ip)])->all();
        $orgs = DB::table('dmarc_reports')->where('end_at', '>=', $since)->selectRaw('org, COUNT(*) as n, MAX(end_at) as last')->groupBy('org')->orderByDesc('n')->limit(8)->get()->map(fn ($r) => ['org' => $r->org, 'reports' => (int) $r->n, 'last' => $r->last])->all();
        $tls = DB::table('tls_reports')->where('end_at', '>=', $since);
        $tlsOk = (int) (clone $tls)->sum('success');
        $tlsFail = (int) (clone $tls)->sum('failure');
        $tlsFailures = [];
        foreach ((clone $tls)->where('failure', '>', 0)->orderByDesc('end_at')->limit(5)->get() as $r) {
            foreach (json_decode((string) $r->failures, true) ?: [] as $f) {
                $tlsFailures[] = $f + ['org' => $r->org, 'at' => $r->end_at];
            }
        }
        $last = DB::table('dmarc_reports')->max('received_at');

        return [
            'days' => $days, 'total' => $total, 'pass' => $pass, 'fail' => $fail, 'passPct' => $total ? round($pass * 100 / $total) : null,
            'failing' => $failing, 'orgs' => $orgs, 'reports' => (int) DB::table('dmarc_reports')->where('end_at', '>=', $since)->count(),
            'tls' => ['reports' => (int) (clone $tls)->count(), 'ok' => $tlsOk, 'fail' => $tlsFail, 'failures' => array_slice($tlsFailures, 0, 10)],
            'lastReport' => $last,
        ];
    }

    private function ptr(string $ip): string
    {
        $h = @gethostbyaddr($ip);

        return $h && $h !== $ip ? $h : '';
    }
}
