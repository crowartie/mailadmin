<?php

namespace App\Services\Mail;

/**
 * Сводка общего доступа для страницы «Общий доступ»: вместо сетки «папка × сотрудник» —
 * одна строка на сотрудника с итогом: какие папки («все», «все, кроме…», перечень) и один уровень
 * (или «разные», если по папкам права отличаются). Так на ящик с 16 папками и 12 людьми видно
 * 12 строк, а не 190 селектов; папки раскрываются только там, где права различаются.
 *
 * Чистая функция над строками FolderShares::overview() и списком папок ящика — проверяется тестами без сервера.
 */
final class ShareSummary
{
    /** Уровни по силе: «разные» считаем по наличию хотя бы двух разных. */
    public const ORDER = ['owner' => 3, 'editor' => 2, 'reader' => 1];

    /** Порядок папок: системные впереди, как в дереве почты. */
    private const ROLE_ORDER = ['inbox' => 0, 'drafts' => 1, 'sent' => 2, 'archive' => 3, 'lists' => 4, 'spam' => 5, 'trash' => 6];

    /**
     * @param array<int,array{owner:string,ownerName:string,folder:string,folderName:string,role:string,with:string,withName:string,level:string}> $rows
     * @param array<string,array<int,array{path:string,name:string,role:string}>> $folders  все папки каждого ящика (и без прав тоже)
     * @return array<int,array<string,mixed>>  по ящикам: owner, ownerName, error, folders, people[]
     */
    public static function build(array $rows, array $folders): array
    {
        $owners = [];
        foreach ($rows as $r) {
            $o = strtolower($r['owner']);
            $owners[$o] ??= ['owner' => $o, 'ownerName' => $r['ownerName'], 'error' => null, 'folders' => [], 'people' => []];
            if (($r['role'] ?? '') === 'error') {
                $owners[$o]['error'] = $r['level'];
                continue;
            }
            $w = strtolower($r['with']);
            $owners[$o]['people'][$w] ??= ['with' => $w, 'withName' => $r['withName'], 'perFolder' => []];
            $owners[$o]['people'][$w]['perFolder'][$r['folder']] = ['folder' => $r['folder'], 'name' => $r['folderName'], 'role' => $r['role'], 'level' => $r['level']];
        }

        $out = [];
        foreach ($owners as $o => $box) {
            $all = self::sortFolders($folders[$o] ?? self::foldersFromRows($box['people']));
            $box['folders'] = $all;
            $people = [];
            foreach ($box['people'] as $p) {
                $x = self::person($p, $all);
                $x['label'] = self::label($x);
                $people[] = $x;
            }
            // Исключения — первыми: их и надо видеть; стандартные («все папки, один уровень») — ниже, по алфавиту.
            usort($people, fn ($a, $b) => ($a['standard'] <=> $b['standard']) ?: strcoll($a['withName'], $b['withName']));
            $box['people'] = $people;
            $out[] = $box;
        }
        usort($out, fn ($a, $b) => strcoll($a['ownerName'], $b['ownerName']));

        return $out;
    }

    /** Итог по одному сотруднику: scope all|except|some, level или mixed, списки папок. */
    private static function person(array $p, array $all): array
    {
        $per = [];
        foreach ($all as $f) {
            if (isset($p['perFolder'][$f['path']])) {
                $per[] = $p['perFolder'][$f['path']];
            }
        }
        // Папка с правами, которой нет в списке ящика (удалена, но ACL остался), — всё равно показываем.
        foreach ($p['perFolder'] as $path => $x) {
            if (! in_array($path, array_column($all, 'path'), true)) {
                $per[] = $x;
            }
        }
        $levels = array_values(array_unique(array_column($per, 'level')));
        $level = count($levels) === 1 ? $levels[0] : 'mixed';
        $have = array_column($per, 'folder');
        $missing = array_values(array_filter($all, fn ($f) => ! in_array($f['path'], $have, true)));
        $n = count($all);
        if ($n > 0 && ! $missing) {
            $scope = 'all';
        } elseif ($n > 0 && count($missing) <= 3 && count($missing) * 2 < $n) {
            $scope = 'except';
        } else {
            $scope = 'some';
        }
        $hasInbox = (bool) array_filter($per, fn ($x) => strtoupper($x['folder']) === 'INBOX');

        return [
            'with' => $p['with'], 'withName' => $p['withName'],
            'scope' => $scope, 'level' => $level,
            'folders' => array_column($per, 'name'),
            'except' => array_column($missing, 'name'),
            'perFolder' => $per,
            'hasInbox' => $hasInbox,
            'standard' => $scope === 'all' && $level !== 'mixed',
        ];
    }

    /** Подпись итога словами: «все папки · владелец», «Входящие, Отправленные · читатель», «все, кроме Корзины · читатель». */
    public static function label(array $person): string
    {
        $lvl = $person['level'] === 'mixed' ? 'разные' : (FolderShares::TITLES[$person['level']] ?? $person['level']);
        $what = match ($person['scope']) {
            'all' => 'все папки',
            'except' => 'все, кроме ' . implode(', ', $person['except']),
            default => implode(', ', $person['folders']),
        };

        return $what . ' · ' . $lvl;
    }

    private static function sortFolders(array $folders): array
    {
        usort($folders, fn ($a, $b) => ((self::ROLE_ORDER[$a['role']] ?? 10) <=> (self::ROLE_ORDER[$b['role']] ?? 10)) ?: strcoll($a['name'], $b['name']));

        return array_values($folders);
    }

    /** Если список папок ящика не передан (старый вызов) — восстанавливаем из строк прав. */
    private static function foldersFromRows(array $people): array
    {
        $out = [];
        foreach ($people as $p) {
            foreach ($p['perFolder'] as $x) {
                $out[$x['folder']] = ['path' => $x['folder'], 'name' => $x['name'], 'role' => $x['role']];
            }
        }

        return array_values($out);
    }
}
