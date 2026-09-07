<?php

namespace App\Services\Server;

use Illuminate\Support\Facades\DB;

/** Карантин Amavis (SQL): список, выпуск письма получателю, удаление, очистка по сроку. */
class Quarantine
{
    /** @return array<int,array<string,mixed>> */
    public function list(int $limit = 100, ?string $user = null): array
    {
        $db = DB::connection('amavisd');
        $q = $db->table('msgs')->join('msgrcpt', 'msgrcpt.mail_id', '=', 'msgs.mail_id')->join('maddr', 'maddr.id', '=', 'msgrcpt.rid')
            ->whereIn('msgs.quar_type', ['Q', 'F', 'Z'])
            ->when($user, fn ($qq) => $qq->where('maddr.email', $user))
            ->orderByDesc('msgs.time_num')->limit($limit)
            ->get(['msgs.mail_id', 'msgs.secret_id', 'msgs.time_num', 'msgs.from_addr', 'msgs.subject', 'msgs.spam_level', 'msgs.content', 'msgs.size', 'maddr.email as rcpt', 'msgrcpt.rs']);

        return $q->map(fn ($r) => [
            'id' => (string) $r->mail_id,
            'secret' => (string) $r->secret_id,
            'time' => date(DATE_ATOM, (int) $r->time_num),
            'from' => (string) $r->from_addr,
            'to' => (string) $r->rcpt,
            'subject' => $this->decode((string) $r->subject),
            'score' => $r->spam_level !== null ? round((float) $r->spam_level, 1) : null,
            'kind' => match ($r->content) { 'S' => 'спам', 'V' => 'вирус', 'B' => 'запрещённое вложение', 'H' => 'плохие заголовки', default => 'спам' },
            'size' => (int) $r->size,
            'released' => $r->rs === 'R',
        ])->all();
    }

    public function count(): int
    {
        return (int) DB::connection('amavisd')->table('msgs')->whereIn('quar_type', ['Q', 'F', 'Z'])->count();
    }

    public function release(string $mailId, string $secret): void
    {
        Ctl::out('amavis-release', [$mailId, $secret], 60);
        DB::connection('amavisd')->table('msgrcpt')->where('mail_id', $mailId)->update(['rs' => 'R']);
    }

    public function delete(string $mailId): void
    {
        $db = DB::connection('amavisd');
        $db->table('quarantine')->where('mail_id', $mailId)->delete();
        $db->table('msgrcpt')->where('mail_id', $mailId)->delete();
        $db->table('msgs')->where('mail_id', $mailId)->delete();
    }

    /** Удалить всё старше N дней. */
    public function purge(int $days): int
    {
        $db = DB::connection('amavisd');
        $ids = $db->table('msgs')->where('time_num', '<', time() - $days * 86400)->pluck('mail_id');
        foreach ($ids->chunk(200) as $chunk) {
            $db->table('quarantine')->whereIn('mail_id', $chunk)->delete();
            $db->table('msgrcpt')->whereIn('mail_id', $chunk)->delete();
            $db->table('msgs')->whereIn('mail_id', $chunk)->delete();
        }

        return $ids->count();
    }

    private function decode(string $s): string
    {
        $d = @iconv_mime_decode($s, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');

        return trim((string) ($d !== false ? $d : $s)) ?: '(без темы)';
    }
}
