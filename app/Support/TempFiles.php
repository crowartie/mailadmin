<?php

namespace App\Support;

/**
 * Временные файлы в системном /tmp: удалить после ответа и подобрать брошенные.
 *
 * Под Octane с RoadRunner ответ уходит через мост PSR-7, который читает файл сам и не вызывает
 * BinaryFileResponse::sendContent() — а именно там deleteFileAfterSend() удаляет файл. Архивы
 * «Скачать все вложения» и «Файлы из облака» поэтому оставались в /tmp навсегда. Тот же мост
 * кладёт каждый загруженный файл в /tmp/symfonyXXXXXX и не убирает за собой.
 */
final class TempFiles
{
    /**
     * Имена, которые создаёт tempnam() в приложении (att — вложения ZIP, cloud/ncz — файлы из облака,
     * nc — проверка облака, rep — отчёты DMARC) и мост PSR-7 (symfony). Суффикс tempnam — ровно шесть знаков.
     */
    public const PATTERN = '/^(symfony|att|cloud|ncz|nc|rep)[A-Za-z0-9]{6}$/';

    /** Удалить файл, когда ответ уже отдан: terminating-колбэки Octane вызывает после отправки. */
    public static function deleteAfterResponse(string $path): void
    {
        app()->terminating(static function () use ($path) {
            if (is_file($path)) {
                @unlink($path);
            }
        });
    }

    /** Удалить брошенные временные файлы старше $age секунд. Возвращает, сколько удалено. */
    public static function prune(?string $dir = null, int $age = 86400): int
    {
        $dir ??= sys_get_temp_dir();
        $n = 0;
        foreach (scandir($dir) ?: [] as $name) {
            $f = $dir . '/' . $name;
            if (preg_match(self::PATTERN, $name) && is_file($f) && ! is_link($f) && filemtime($f) < time() - $age && @unlink($f)) {
                $n++;
            }
        }

        return $n;
    }
}
