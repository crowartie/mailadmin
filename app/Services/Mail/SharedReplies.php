<?php

namespace App\Services\Mail;

use App\Exceptions\MailException;
use App\Models\Vmail\Mailbox;
use App\Models\Webmail\SharedReply;
use Symfony\Component\Mime\Email;

/**
 * «Кто ответил» в общих папках (обращение №57).
 *
 * Ответ на письмо из чужого ящика уходит в «Отправленные» того, кто отвечал, и коллегам в общей папке
 * не виден. Поэтому при отправке ответа из папки Shared/… запоминаем: чей ящик, Message-ID исходного,
 * кто и когда ответил, и кладём копию ответа файлом .eml рядом с приложением. В списке и в письме
 * показывается «Ответил: Иванов И.», щелчок открывает сам ответ (как письмо-вложение). Хранится год.
 */
class SharedReplies
{
    public const KEEP_DAYS = 365;

    /** Куда складывать копии ответов: по ящику-владельцу, чтобы было видно, чьё это. */
    public static function dir(): string
    {
        return (string) config('mailadmin.shared_replies_dir', storage_path('app/private/shared-replies'));
    }

    /**
     * Запомнить ответ, если он на письмо из общей папки. Зовётся после отправки; любая ошибка здесь —
     * только запись в журнал: письмо уже ушло, и «не отправилось» показывать нельзя.
     * @param array<string,mixed> $form  answeredFolder, inReplyTo — как пришли из окна письма
     */
    public static function record(string $user, array $form, Email $email, string $raw): ?SharedReply
    {
        $folder = (string) ($form['answeredFolder'] ?? '');
        $owner = $folder !== '' ? SharedReads::ownerOf($folder) : null;
        $key = SharedReads::key($form['inReplyTo'] ?? null);
        $user = strtolower($user);
        if ($owner === null || $key === '' || $user === '') {
            return null;
        }
        try {
            $owner = strtolower($owner);
            $sub = preg_replace('/[^a-z0-9@._-]+/i', '_', $owner) . '/' . date('Y-m');
            $dir = self::dir() . '/' . $sub;
            if (! is_dir($dir) && ! @mkdir($dir, 0770, true) && ! is_dir($dir)) {
                throw new \RuntimeException('не создать каталог ' . $dir);
            }
            $name = date('Ymd-His') . '-' . bin2hex(random_bytes(6)) . '.eml';
            if (file_put_contents($dir . '/' . $name, $raw) === false) {
                throw new \RuntimeException('не записать ' . $dir . '/' . $name);
            }

            return SharedReply::query()->create([
                'owner' => $owner,
                'folder' => SharedReads::ownerPath($folder),
                'message_id' => $key,
                'user' => $user,
                'reply_message_id' => mb_substr(trim((string) Outgoing::messageId($email), '<>'), 0, 190) ?: null,
                'subject' => mb_substr((string) $email->getSubject(), 0, 500),
                'file' => $sub . '/' . $name,
                'size' => strlen($raw),
                'replied_at' => now(),
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('ответ из общей папки не записан: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Кто ответил на каждое из писем: Message-ID → [{id, mail, name, at, subject}], по времени.
     * Один запрос на страницу списка.
     * @param array<int,string|null> $messageIds
     * @return array<string,array<int,array{id:int,mail:string,name:string,at:string,subject:string}>>
     */
    public static function forMessages(string $owner, array $messageIds): array
    {
        $keys = array_values(array_unique(array_filter(array_map([SharedReads::class, 'key'], $messageIds))));
        if (! $keys) {
            return [];
        }
        $rows = SharedReply::query()->where('owner', strtolower($owner))->whereIn('message_id', $keys)->orderBy('replied_at')->orderBy('id')->get();
        $names = self::names($rows->pluck('user')->unique()->all());
        $out = [];
        foreach ($rows as $r) {
            $out[$r->message_id][] = [
                'id' => (int) $r->id,
                'mail' => $r->user,
                'name' => $names[$r->user] ?? $r->user,
                'at' => $r->replied_at?->toIso8601String() ?? '',
                'subject' => (string) $r->subject,
            ];
        }

        return $out;
    }

    /** Приклеить к строкам списка общей папки, кто ответил. В своей папке ничего не делает. */
    public static function attach(array $list, string $folder): array
    {
        $owner = SharedReads::ownerOf($folder);
        if ($owner === null || empty($list['messages'])) {
            return $list;
        }
        $map = self::forMessages($owner, array_column($list['messages'], 'messageId'));
        foreach ($list['messages'] as &$row) {
            $row['replies'] = array_map(fn ($r) => ['id' => $r['id'], 'mail' => $r['mail'], 'name' => $r['name'], 'at' => $r['at']], $map[SharedReads::key($row['messageId'] ?? null)] ?? []);
        }
        unset($row);

        return $list;
    }

    /**
     * Запись ответа для показа сотруднику: владелец ящика, сам ответивший и все, кому открыта та папка.
     * Остальным — «не найдено», а не «нет прав»: чужие ответы не должны быть даже заметны.
     */
    public static function find(int $id, string $user): SharedReply
    {
        $user = strtolower($user);
        $r = SharedReply::query()->find($id);
        if (! $r) {
            throw MailException::notFound('Ответ не найден');
        }
        if ($r->owner !== $user && $r->user !== $user && ! array_key_exists($user, SharedReads::people($r->owner, $r->folder))) {
            throw MailException::notFound('Ответ не найден');
        }

        return $r;
    }

    /** Исходник ответа из файла. Файл могли убрать вручную — тогда честно «нет». */
    public static function raw(SharedReply $r): string
    {
        $path = self::dir() . '/' . $r->file;
        $raw = is_file($path) ? file_get_contents($path) : false;
        if ($raw === false || $raw === '') {
            throw MailException::notFound('Копия ответа не сохранилась — откройте её в «Отправленных» у ' . $r->user);
        }

        return $raw;
    }

    /** Разобранный ответ для окна «письмо во вложении» — тем же разборщиком, что и .eml-вложения. */
    public static function show(SharedReply $r): array
    {
        return AttachedMessage::parse(self::raw($r), AttachedMessage::fileName((string) $r->subject)) + ['id' => (int) $r->id];
    }

    /** Ответы старше года и их файлы — долой (в команде shared-reads:purge). */
    public static function purge(): int
    {
        $n = 0;
        foreach (SharedReply::query()->where('replied_at', '<', now()->subDays(self::KEEP_DAYS))->get() as $r) {
            @unlink(self::dir() . '/' . $r->file);
            $r->delete();
            $n++;
        }

        return $n;
    }

    /** «Ответили: Аносов М., Мусин Е.» или «никто» — для приложения и тестов. */
    public static function label(array $replies): string
    {
        return $replies ? SharedReads::label($replies) : 'никто';
    }

    /** Имена сотрудников по адресам; без базы ящиков (тесты) — адреса как есть. */
    private static function names(array $mails): array
    {
        if (! $mails) {
            return [];
        }
        $out = [];
        try {
            foreach (Mailbox::query()->whereIn('username', $mails)->get(['username', 'name']) as $m) {
                $out[strtolower($m->username)] = $m->name ?: $m->username;
            }
        } catch (\Throwable) {
            foreach ($mails as $m) {
                $out[strtolower($m)] = $m;
            }
        }

        return $out;
    }
}
