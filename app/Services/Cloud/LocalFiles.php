<?php

namespace App\Services\Cloud;

use App\Exceptions\MailException;
use App\Models\AppSetting;
use App\Models\Webmail\CloudFile;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Своё хранилище больших вложений — на диске почтового сервера, без Nextcloud.
 *
 * Файл крупнее порога не вкладывается в письмо, а кладётся сюда; в письмо уходит ссылка
 * вида https://files.<домен>/<токен>/<имя> — как у Mail.ru, только облако наше. Ссылка
 * действует до срока и продлевается; файл при истечении срока не удаляется (только если
 * админ задал «удалять через N дней после истечения»).
 *
 * Отдельное имя files.<домен> — не украшение, а граница: у файлов нет ни общего
 * происхождения с почтой, ни её cookie, и загруженный HTML не может ни на что повлиять.
 * Файлы всегда отдаются только на скачивание (см. FilesController).
 */
final class LocalFiles
{
    public const GROUP = 'files';

    /** Виды файлов, которые почта умеет показать без скачивания. */
    private const OFFICE = ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'rtf'];

    /** @return array<string,mixed> */
    public static function settings(): array
    {
        return AppSetting::group(self::GROUP) + [
            'enabled' => false,
            'host' => 'files.' . config('areas.default_domain'),
            'threshold_mb' => 10,   // от какого размера файл уходит ссылкой
            'max_mb' => 500,        // предел одного файла
            'expire_days' => 30,    // срок ссылки
            'preview_mb' => 20,     // до какого размера показываем в почте
            'keep_days' => 0,       // удалять файл через N дней после истечения ссылки; 0 — не удалять
            'user_quota_gb' => 20,  // предел на одного человека
        ];
    }

    public static function enabled(): bool
    {
        $s = self::settings();

        return ! empty($s['enabled']) && filled($s['host']);
    }

    /** https://files.домен — без завершающего слэша. */
    public static function base(): string
    {
        return 'https://' . trim((string) self::settings()['host'], '/ ');
    }

    public static function threshold(): int
    {
        return max(1, (int) self::settings()['threshold_mb']) * 1048576;
    }

    public static function maxBytes(): int
    {
        return max(1, (int) self::settings()['max_mb']) * 1048576;
    }

    public static function root(): string
    {
        return storage_path('app/files');
    }

    /** Показывать ли файл в почте (картинка, PDF или офисный документ разумного размера). */
    public static function previewable(CloudFile $f): bool
    {
        if ($f->size > max(1, (int) self::settings()['preview_mb']) * 1048576) {
            return false;
        }
        $ext = strtolower(pathinfo($f->name, PATHINFO_EXTENSION));

        return str_starts_with($f->mime, 'image/') || $f->mime === 'application/pdf' || $ext === 'pdf' || in_array($ext, self::OFFICE, true);
    }

    public static function isOffice(CloudFile $f): bool
    {
        return in_array(strtolower(pathinfo($f->name, PATHINFO_EXTENSION)), self::OFFICE, true);
    }

    /**
     * Положить файл и вернуть ссылку.
     *
     * @return array{url:string,expires:?string,token:string}
     */
    public function publish(string $localFile, string $name, string $user, ?string $messageId = null): array
    {
        $size = (int) filesize($localFile);
        if ($size > self::maxBytes()) {
            throw MailException::tooLarge('«' . $name . '» больше ' . (int) self::settings()['max_mb'] . ' МБ — столько хранилище не принимает');
        }
        $this->guardQuota($user, $size);
        $this->scan($localFile, $name);

        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $rel = date('Y/m') . '/' . $token;
        $dir = self::root() . '/' . dirname($rel);
        if (! is_dir($dir) && ! mkdir($dir, 0750, true) && ! is_dir($dir)) {
            throw MailException::upstream('Хранилище недоступно: не удалось создать каталог');
        }
        if (! rename($localFile, self::root() . '/' . $rel) && ! copy($localFile, self::root() . '/' . $rel)) {
            throw MailException::upstream('Хранилище недоступно: не удалось сохранить файл');
        }
        chmod(self::root() . '/' . $rel, 0640);

        $days = (int) self::settings()['expire_days'];
        $mime = self::mimeOf($name, self::root() . '/' . $rel);
        $f = CloudFile::create([
            'user' => $user,
            'token' => $token,
            'name' => mb_substr($name, 0, 255),
            'size' => $size,
            'mime' => $mime,
            'path' => $rel,
            'message_id' => $messageId,
            'expires_at' => $days > 0 ? now()->addDays($days)->endOfDay() : null,
        ]);

        return ['url' => $f->url(), 'expires' => $f->expires_at?->toDateString(), 'token' => $token];
    }

    /** Продлить ссылку ещё на срок из настроек, считая от сегодня. */
    public function renew(CloudFile $f): CloudFile
    {
        $days = max(1, (int) self::settings()['expire_days']);
        $f->expires_at = now()->addDays($days)->endOfDay();
        $f->save();

        return $f;
    }

    /**
     * Удалить с диска файлы, у которых срок ссылки истёк больше keep_days назад.
     * При keep_days = 0 ничего не удаляется — так по умолчанию.
     *
     * @return int сколько удалено
     */
    public function purge(): int
    {
        $keep = (int) self::settings()['keep_days'];
        if ($keep <= 0) {
            return 0;
        }
        $n = 0;
        foreach (CloudFile::query()->whereNotNull('expires_at')->where('expires_at', '<', now()->subDays($keep))->cursor() as $f) {
            @unlink($f->fullPath());
            $f->delete();
            $n++;
        }

        return $n;
    }

    /** Сколько места занято файлами человека. */
    public static function usedBy(string $user): int
    {
        return (int) CloudFile::query()->where('user', $user)->sum('size');
    }

    /**
     * Файлы хранилища, на которые ссылается разметка письма, — карточками для интерфейса.
     * Ищем ссылки только на свой хост, порядок — как в письме, без повторов.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function cardsIn(?string $html, string $viewer): array
    {
        $host = trim((string) self::settings()['host'], '/ ');
        if ($html === null || $html === '' || $host === '' || stripos($html, $host) === false) {
            return [];
        }
        preg_match_all('#https?://' . preg_quote($host, '#') . '/([A-Za-z0-9_-]{20,64})(?:[/"\'\s<>?]|$)#i', $html, $m);
        $tokens = array_values(array_unique($m[1] ?? []));
        if (! $tokens) {
            return [];
        }
        $rows = CloudFile::query()->whereIn('token', $tokens)->get()->keyBy('token');

        $out = [];
        foreach ($tokens as $t) {
            if ($rows->has($t)) {
                $out[] = $rows[$t]->toCard($viewer);
            }
        }

        return $out;
    }

    private function guardQuota(string $user, int $add): void
    {
        $quota = (int) self::settings()['user_quota_gb'] * 1073741824;
        if ($quota > 0 && self::usedBy($user) + $add > $quota) {
            throw MailException::tooLarge('В хранилище не осталось места: у вас занято ' . \App\Support\Format::size(self::usedBy($user)) . ' из ' . (int) self::settings()['user_quota_gb'] . ' ГБ. Удалите старые файлы в «Мои файлы».');
        }
    }

    /**
     * Проверка антивирусом — тем же ClamAV, что смотрит входящую почту.
     * Нет clamdscan — пропускаем молча: это не повод не отправлять письмо.
     */
    private function scan(string $file, string $name): void
    {
        $bin = trim((string) shell_exec('command -v clamdscan 2>/dev/null'));
        if ($bin === '') {
            return;
        }
        $p = new Process([$bin, '--no-summary', '--fdpass', $file], null, null, null, 120);
        $p->run();
        if ($p->getExitCode() === 1) {
            Log::warning('files: вирус в «' . $name . '»: ' . trim($p->getOutput()));
            throw MailException::invalid('«' . $name . '» не пропустил антивирус: ' . trim(preg_replace('/^.*?:\s*/', '', trim($p->getOutput()))));
        }
        // Код 2 — сам сканер не смог (демон не запущен, нет прав): не блокируем, но пишем.
        if ($p->getExitCode() === 2) {
            Log::warning('files: антивирус не смог проверить «' . $name . '»: ' . trim($p->getErrorOutput() . ' ' . $p->getOutput()));
        }
    }

    private static function mimeOf(string $name, string $path): string
    {
        $mime = '';
        try {
            $mime = (string) (mime_content_type($path) ?: '');
        } catch (\Throwable) {
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        // По расширению надёжнее для офисных: mime_content_type видит в docx просто zip.
        $byExt = [
            'pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp',
            'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ppt' => 'application/vnd.ms-powerpoint', 'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'zip' => 'application/zip', 'rar' => 'application/vnd.rar', '7z' => 'application/x-7z-compressed', 'txt' => 'text/plain', 'csv' => 'text/csv',
        ];

        return $byExt[$ext] ?? ($mime !== '' ? $mime : 'application/octet-stream');
    }
}
