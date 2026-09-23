<?php

namespace App\Services\Cloud;

/**
 * Учёт личного облака на стороне почты: ссылки, загрузки, корзина.
 *
 * Интерфейс нужен, чтобы PersonalCloud можно было проверить тестами без базы
 * (ArrayCloudLedger), а в работе — хранить в MariaDB (DbCloudLedger).
 * Все пути — относительно папки сотрудника.
 */
interface CloudLedger
{
    // ── ссылки ──
    /** @return array<string,array{path:string,share_id:string,url:string,expires_at:?string,has_password:bool}> по пути */
    public function links(string $user): array;

    public function link(string $user, string $path): ?array;

    public function saveLink(string $user, array $link): void;

    public function forgetLink(string $user, string $path): void;

    /** Ссылки на сам путь и на всё внутри него. @return array<int,array> */
    public function linksUnder(string $user, string $path): array;

    // ── загрузки ──
    public function saveUpload(array $upload): void;

    public function upload(string $user, string $id): ?array;

    public function finishUpload(string $id, string $path): void;

    public function forgetUpload(string $id): void;

    /** @return array<int,array> последние законченные */
    public function recentUploads(string $user, int $limit): array;

    // ── корзина ──
    public function trash(string $user): array;

    public function trashItem(string $user, int $id): ?array;

    public function addTrash(array $row): int;

    public function forgetTrash(int $id): void;

    /** @return array<int,array> всё старше срока, у всех */
    public function trashOlderThan(\DateTimeInterface $before): array;

    // ── перенос: пути в учёте следуют за папкой ──
    public function movePrefix(string $user, string $from, string $to): void;
}
