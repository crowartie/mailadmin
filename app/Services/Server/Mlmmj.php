<?php

namespace App\Services\Server;

use App\Models\Vmail\Maillist;
use Illuminate\Support\Facades\Cache;

/**
 * Рассылки mlmmj через штатный инструмент iRedMail (maillist_admin.py → mlmmjadmin API + таблица vmail.maillists).
 * Всё — через mailadmin-ctl mlmmj-admin; настройки читаются как key=value.
 */
class Mlmmj
{
    /** Понятные названия настроек, которые показываем администратору. */
    public const OPTIONS = [
        'only_subscriber_can_post' => 'Писать могут только подписчики',
        'only_moderator_can_post' => 'Писать могут только модераторы',
        'moderated' => 'Каждое письмо подтверждает модератор',
        'moderate_non_subscriber_post' => 'Письма не подписчиков — на подтверждение',
        'disable_subscription' => 'Запретить самоподписку по письму',
        'disable_archive' => 'Не хранить архив',
        'disable_send_copy_to_sender' => 'Не отправлять копию автору',
        'notify_sender_when_moderated' => 'Сообщать автору, что письмо ждёт модератора',
    ];

    /** @return array<int,array<string,mixed>> */
    public function all(): array
    {
        return Maillist::query()->orderBy('address')->get()->map(function (Maillist $l) {
            $subs = Cache::remember('mlmmj.subs.' . $l->address, 120, fn () => $this->subscribers($l->address));
            $mod = Cache::remember('mlmmj.mod.' . $l->address, 60, fn () => count($this->moderation($l->address)));

            return [
                'address' => $l->address, 'name' => $l->name, 'description' => $l->description, 'active' => (bool) $l->active,
                'domain' => $l->domain, 'newsletter' => (bool) $l->is_newsletter, 'subscribers' => count($subs), 'pending' => $mod,
                'created' => $l->created?->toIso8601String(),
            ];
        })->all();
    }

    /** @return array<string,mixed>|null */
    public function info(string $address): ?array
    {
        [$code, $out] = Ctl::run('mlmmj-admin', ['info', $address], 30);
        if ($code !== 0) {
            return null;
        }
        $kv = [];
        foreach (preg_split('/\r?\n/', trim($out)) as $line) {
            if (str_contains($line, '=')) {
                [$k, $v] = explode('=', $line, 2);
                $kv[trim($k)] = trim($v);
            }
        }
        $list = fn (string $key) => array_values(array_filter(array_map(fn ($s) => trim($s, " '\""), explode(',', trim((string) ($kv[$key] ?? ''), '[]')))));
        $options = [];
        foreach (array_keys(self::OPTIONS) as $o) {
            $options[$o] = ($kv[$o] ?? 'no') === 'yes';
        }

        return [
            'options' => $options,
            'owners' => $list('owners') ?: $list('owner'),
            'moderators' => $list('moderators'),
            'subject_prefix' => preg_replace('/^b?[\'"](.*)[\'"]$/', '$1', (string) ($kv['subject_prefix'] ?? '')),
            'max_message_size' => (string) ($kv['max_message_size'] ?? ''),
        ];
    }

    public function create(string $address, string $name, array $options = [], string $description = ''): void
    {
        $args = ['create', $address, 'name=' . $this->safe($name)];
        foreach ($options as $k => $v) {
            if (isset(self::OPTIONS[$k])) {
                $args[] = $k . '=' . ($v ? 'yes' : 'no');
            }
        }
        Ctl::out('mlmmj-admin', $args, 60);
        if ($description !== '') {
            Maillist::query()->where('address', $address)->update(['description' => $description]);
        }
        $this->forget($address);
    }

    public function update(string $address, array $options, ?string $name = null, ?array $moderators = null, ?string $subjectPrefix = null): void
    {
        $args = ['update', $address];
        foreach ($options as $k => $v) {
            if (isset(self::OPTIONS[$k])) {
                $args[] = $k . '=' . ($v ? 'yes' : 'no');
            }
        }
        if ($name !== null) {
            $args[] = 'name=' . $this->safe($name);
        }
        if ($moderators !== null) {
            $args[] = 'moderators=' . implode(',', array_map('strtolower', $moderators));
        }
        if ($subjectPrefix !== null) {
            $args[] = 'subject_prefix=' . $this->safe($subjectPrefix);
        }
        if (count($args) > 2) {
            Ctl::out('mlmmj-admin', $args, 60);
        }
        $this->forget($address);
    }

    public function delete(string $address): void
    {
        Ctl::out('mlmmj-admin', ['delete', $address], 60);
        $this->forget($address);
    }

    /** @return string[] */
    public function subscribers(string $address): array
    {
        [$code, $out] = Ctl::run('mlmmj-admin', ['subscribers', $address], 30);
        if ($code !== 0) {
            return [];
        }
        $rows = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $out)), fn ($l) => str_contains($l, '@')));
        sort($rows);

        return $rows;
    }

    /** @param string[] $emails */
    public function addSubscribers(string $address, array $emails): void
    {
        Ctl::out('mlmmj-admin', array_merge(['add_subscribers', $address], $emails), 60);
        Cache::forget('mlmmj.subs.' . $address);
    }

    /** @param string[] $emails */
    public function removeSubscribers(string $address, array $emails): void
    {
        Ctl::out('mlmmj-admin', array_merge(['remove_subscribers', $address], $emails), 60);
        Cache::forget('mlmmj.subs.' . $address);
    }

    /** Письма, ждущие модератора. @return array<int,array{id:string,from:string,subject:string,date:string}> */
    public function moderation(string $address): array
    {
        [$code, $out] = Ctl::run('mlmmj-mod-list', [$address], 20);
        if ($code !== 0) {
            return [];
        }
        $rows = [];
        foreach (preg_split('/\r?\n/', trim($out)) as $line) {
            // формат: id<TAB>from<TAB>subject<TAB>date
            $p = explode("\t", $line);
            if (count($p) >= 2 && $p[0] !== '') {
                $rows[] = ['id' => $p[0], 'from' => $p[1] ?? '', 'subject' => $this->decode($p[2] ?? ''), 'date' => $p[3] ?? ''];
            }
        }

        return $rows;
    }

    public function approve(string $address, string $id): void
    {
        Ctl::out('mlmmj-mod-approve', [$address, $id], 60);
        Cache::forget('mlmmj.mod.' . $address);
    }

    public function reject(string $address, string $id): void
    {
        Ctl::out('mlmmj-mod-reject', [$address, $id], 30);
        Cache::forget('mlmmj.mod.' . $address);
    }

    /** Последние письма архива. @return array<int,array{file:string,subject:string,from:string,date:string}> */
    public function archive(string $address): array
    {
        [$code, $out] = Ctl::run('mlmmj-archive', [$address], 20);
        if ($code !== 0) {
            return [];
        }
        $rows = [];
        foreach (preg_split('/\r?\n/', trim($out)) as $line) {
            $p = explode("\t", $line);
            if ($p[0] !== '') {
                $rows[] = ['file' => $p[0], 'from' => $p[1] ?? '', 'subject' => $this->decode($p[2] ?? ''), 'date' => $p[3] ?? ''];
            }
        }

        return $rows;
    }

    private function forget(string $address): void
    {
        Cache::forget('mlmmj.subs.' . $address);
        Cache::forget('mlmmj.mod.' . $address);
        Cache::forget('nav.counts');
    }

    private function safe(string $s): string
    {
        return mb_substr(preg_replace('/[^\p{L}\p{N} .,_\-()«»"]/u', '', $s), 0, 120);
    }

    private function decode(string $s): string
    {
        $d = @iconv_mime_decode($s, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');

        return trim((string) ($d !== false ? $d : $s));
    }
}
