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
        // Офисный файл из облака в PDF не переводим (нужна копия на диске) — только картинки и PDF.
        if ($f->isCloud() && ! (str_starts_with($f->mime, 'image/') || $f->mime === 'application/pdf')) {
            return false;
        }
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
    public function publish(string $localFile, string $name, string $user, ?string $messageId = null, ?string $subject = null): array
    {
        clearstatcache(true, $localFile);
        $size = is_file($localFile) ? (int) filesize($localFile) : 0;
        if ($size <= 0) {
            // Файл не дочитался с диска отправителя или оборвалась загрузка: пустышку хранить нельзя.
            throw MailException::invalid('«' . $name . '» пустой — файл не догрузился. Приложите его заново и отправьте письмо ещё раз.');
        }
        if ($size > self::maxBytes()) {
            throw MailException::tooLarge('«' . $name . '» больше ' . (int) self::settings()['max_mb'] . ' МБ — столько хранилище не принимает');
        }
        $this->guardQuota($user, $size);
        $this->scan($localFile, $name);

        $token = self::token();
        $rel = date('Y/m') . '/' . $token;
        $dir = self::root() . '/' . dirname($rel);
        if (! is_dir($dir) && ! mkdir($dir, 0750, true) && ! is_dir($dir)) {
            throw MailException::upstream('Хранилище недоступно: не удалось создать каталог');
        }
        // Контрольная сумма считается до переноса, а после — проверяется, что на диске ровно то,
        // что прислал отправитель: побитый файл в хранилище хуже, чем честная ошибка.
        $sha = (string) hash_file('sha256', $localFile);
        $dest = self::root() . '/' . $rel;
        $moved = @rename($localFile, $dest);
        if (! $moved && ! @copy($localFile, $dest)) {
            throw MailException::upstream('Хранилище недоступно: не удалось сохранить файл');
        }
        clearstatcache(true, $dest);
        $ok = is_file($dest) && (int) filesize($dest) === $size && ($moved || hash_file('sha256', $dest) === $sha);
        if (! $ok) {
            @unlink($dest);
            Log::error('files: «' . $name . '» сохранился не целиком (ожидали ' . $size . ' байт)');
            throw MailException::upstream('«' . $name . '» сохранился не целиком — на сервере не хватило места или диск занят. Попробуйте ещё раз.');
        }
        chmod($dest, 0640);

        $days = (int) self::settings()['expire_days'];
        $mime = self::mimeOf($name, self::root() . '/' . $rel);
        $f = CloudFile::create([
            'user' => $user,
            'token' => $token,
            'name' => mb_substr($name, 0, 255),
            'size' => $size,
            'mime' => $mime,
            'path' => $rel,
            'sha256' => $sha,
            'message_id' => $messageId,
            'subject' => $subject !== null ? mb_substr($subject, 0, 255) : null,
            'expires_at' => $days > 0 ? now()->addDays($days)->endOfDay() : null,
        ]);

        return ['url' => $f->url(), 'expires' => $f->expires_at?->toDateString(), 'token' => $token];
    }

    /** Часть ссылки: 256 случайных бит, подобрать нельзя. */
    public static function token(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /**
     * Заранее положенные файлы человека, ещё не привязанные к письму.
     *
     * @param  string[]  $tokens
     * @return \Illuminate\Support\Collection<int,CloudFile>
     */
    public static function staged(string $user, array $tokens): \Illuminate\Support\Collection
    {
        if (! $tokens) {
            return collect();
        }

        return CloudFile::query()->whereIn('token', $tokens)->where('source', 'local')->where('user', strtolower($user))->whereNull('message_id')->get();
    }

    /** Письмо ушло: файлы — его, срок ссылки считается от отправки. @param string[] $tokens */
    public static function claimStaged(string $user, array $tokens, string $messageId, ?string $subject): int
    {
        $days = (int) self::settings()['expire_days'];
        $n = 0;
        foreach (self::staged($user, $tokens) as $f) {
            $f->message_id = trim($messageId, '<> ');
            $f->subject = $subject !== null ? mb_substr($subject, 0, 255) : null;
            $f->expires_at = $days > 0 ? now()->addDays($days)->endOfDay() : null;
            $f->save();
            $n++;
        }

        return $n;
    }

    /** Отложенную отправку отменили: файлы письма снова ждут отправки (черновик их покажет и сможет убрать). */
    public static function unclaim(string $user, string $messageId): int
    {
        $messageId = trim($messageId, '<> ');
        if ($messageId === '') {
            return 0;
        }

        return CloudFile::query()->where('source', 'local')->where('user', strtolower($user))->where('message_id', $messageId)
            ->update(['message_id' => null, 'subject' => null]);
    }

    /** Файл убрали из письма до отправки. @param string[] $tokens */
    public static function discardStaged(string $user, array $tokens): int
    {
        return self::discardTokens(self::staged($user, $tokens)->pluck('token')->all());
    }

    /** Убрать файлы по токенам (откат, когда письмо не ушло). @param string[] $tokens */
    public static function discardTokens(array $tokens): int
    {
        if (! $tokens) {
            return 0;
        }
        $n = 0;
        foreach (CloudFile::query()->whereIn('token', $tokens)->where('source', 'local')->get() as $f) {
            @unlink($f->fullPath());
            $f->delete();
            $n++;
        }

        return $n;
    }

    /**
     * Письмо не отправилось — его файлы в хранилище никому не нужны: ссылки на них
     * никуда не ушли, а при повторной отправке файлы лягут заново.
     */
    public static function discardForMessage(?string $messageId): int
    {
        $messageId = trim((string) $messageId, '<> ');
        if ($messageId === '') {
            return 0;
        }

        return self::discardTokens(CloudFile::query()->where('message_id', $messageId)->pluck('token')->all());
    }

    /**
     * Проверка целостности: файл на месте, размер сходится, при $hash — и контрольная сумма.
     * Заодно убирает с диска файлы, которых нет в базе (старше суток — свежий мог ещё не записаться).
     *
     * @return array{checked:int,broken:array<int,string>,orphans:int}
     */
    public function check(bool $hash = false): array
    {
        $broken = [];
        $checked = 0;
        $known = [];
        // Файлы облака лежат в Nextcloud — их целостность здесь не проверить.
        foreach (CloudFile::query()->where('source', 'local')->orderBy('id')->cursor() as $f) {
            $known[$f->path] = true;
            $checked++;
            $p = $f->fullPath();
            clearstatcache(true, $p);
            $why = null;
            if (! is_file($p)) {
                $why = 'файла нет на диске';
            } elseif ((int) filesize($p) !== (int) $f->size) {
                $why = 'размер ' . filesize($p) . ' вместо ' . $f->size;
            } elseif ($hash && $f->sha256 && hash_file('sha256', $p) !== $f->sha256) {
                $why = 'контрольная сумма не сходится';
            }
            if ($why !== null) {
                $broken[] = $f->user . ' «' . $f->name . '» (' . $f->token . '): ' . $why;
            }
            $f->forceFill(['checked_at' => now()])->saveQuietly();
        }
        $orphans = 0;
        $root = self::root();
        if (is_dir($root)) {
            foreach (glob($root . '/*/*/*') ?: [] as $file) {
                $rel = substr($file, strlen($root) + 1);
                if (is_file($file) && ! isset($known[$rel]) && filemtime($file) < time() - 86400) {
                    @unlink($file);
                    $orphans++;
                }
            }
        }
        if ($broken) {
            Log::error('files:check — повреждённых файлов: ' . count($broken) . "\n" . implode("\n", $broken));
        }

        return ['checked' => $checked, 'broken' => $broken, 'orphans' => $orphans];
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
    /** Сколько дней ждёт письма заранее положенный файл (черновик бросили) — потом удаляется. */
    public const STAGED_DAYS = 30;

    public function purge(): int
    {
        $n = 0;
        foreach (CloudFile::query()->where('source', 'local')->whereNull('message_id')->where('created_at', '<', now()->subDays(self::STAGED_DAYS))->cursor() as $f) {
            @unlink($f->fullPath());
            $f->delete();
            $n++;
        }
        $keep = (int) self::settings()['keep_days'];
        if ($keep <= 0) {
            return $n;
        }
        foreach (CloudFile::query()->where('source', 'local')->whereNotNull('expires_at')->where('expires_at', '<', now()->subDays($keep))->cursor() as $f) {
            @unlink($f->fullPath());
            $f->delete();
            $n++;
        }

        return $n;
    }

    /** Сколько места занято файлами человека. */
    public static function usedBy(string $user): int
    {
        return (int) CloudFile::query()->where('user', $user)->where('source', 'local')->sum('size');
    }

    /**
     * Файлы хранилища, на которые ссылается разметка письма, — карточками для интерфейса.
     * Ищем ссылки только на свой хост, порядок — как в письме, без повторов.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function cardsIn(?string $html, string $viewer): array
    {
        $tokens = self::tokensIn($html, (string) self::settings()['host']);
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

    /**
     * Токены ссылок на хранилище в разметке письма. Ссылки внутри цитаты (blockquote) не считаются:
     * в ответе цитируется исходное письмо вместе с его блоком ссылок, а карточки нужны только
     * файлам самого письма. Пересылка цитату не использует — там ссылки остаются.
     *
     * @return string[]
     */
    public static function tokensIn(?string $html, string $host): array
    {
        $host = trim($host, '/ ');
        if ($html === null || $html === '' || $host === '' || stripos($html, $host) === false) {
            return [];
        }
        // Вложенные цитаты убираем изнутри наружу, пока есть что убирать.
        for ($i = 0; $i < 20 && preg_match('#<blockquote\b#i', $html); $i++) {
            $stripped = preg_replace('#<blockquote\b[^>]*>(?:(?!<blockquote\b).)*?</blockquote>#is', '', $html);
            if ($stripped === null || $stripped === $html) {
                break;
            }
            $html = $stripped;
        }
        preg_match_all('#https?://' . preg_quote($host, '#') . '/([A-Za-z0-9_-]{20,64})(?:[/"\'\s<>?]|$)#i', $html, $m);

        return array_values(array_unique($m[1] ?? []));
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
