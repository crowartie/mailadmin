<?php

namespace App\Services\Cloud;

/**
 * Куда уходят большие вложения: в своё хранилище (LocalFiles) или в Nextcloud.
 * Окно письма и сборка письма работают через эту точку и не знают, что за ней.
 * Своё хранилище главнее: если включены оба, файлы идут в него.
 */
final class Cloud
{
    public static function provider(): ?string
    {
        if (LocalFiles::enabled()) {
            return 'local';
        }
        if (Nextcloud::enabled()) {
            return 'nextcloud';
        }

        return null;
    }

    public static function enabled(): bool
    {
        return self::provider() !== null;
    }

    /** Порог в мегабайтах, от которого файл уходит ссылкой. */
    public static function thresholdMb(): int
    {
        return match (self::provider()) {
            'local' => max(1, (int) LocalFiles::settings()['threshold_mb']),
            'nextcloud' => max(1, (int) (Nextcloud::settings()['threshold_mb'] ?? 10)),
            default => 10,
        };
    }

    /** Предел одного файла в мегабайтах — то, что окно письма показывает как «не влезет». */
    public static function maxMb(): int
    {
        return match (self::provider()) {
            'local' => max(1, (int) LocalFiles::settings()['max_mb']),
            'nextcloud' => 256,
            default => 50,
        };
    }

    /**
     * Положить файл и вернуть ссылку.
     *
     * @return array{url:string,expires:?string}
     */
    public static function publish(string $localFile, string $name, string $user, ?string $messageId = null): array
    {
        return match (self::provider()) {
            'local' => (new LocalFiles())->publish($localFile, $name, $user, $messageId),
            'nextcloud' => (new Nextcloud())->publish($localFile, $name, $user),
            default => throw \App\Exceptions\MailException::invalid('Хранилище для больших файлов не настроено'),
        };
    }
}
