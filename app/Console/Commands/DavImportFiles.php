<?php

namespace App\Console\Commands;

use App\Dav\Server;
use App\Services\Dav\Cards;
use App\Services\Dav\DavStore;
use Illuminate\Console\Command;

/**
 * Импорт контактов и календаря из файлов на диске в личную книгу и личный календарь сотрудника.
 * Каталог: <dir>/contacts/*.vcf, <dir>/calendar/*.ics, <dir>/tasks/*.ics (например, выгрузка deploy/kerio-dav-extract.py).
 * Объект ложится под своим UID — повторный импорт перезапишет, а не удвоит.
 */
class DavImportFiles extends Command
{
    protected $signature = 'dav:import {user : адрес ящика} {dir : каталог с contacts/ calendar/ tasks/}';

    protected $description = 'Импорт .vcf/.ics из каталога в личную книгу и календарь сотрудника';

    public function handle(DavStore $store): int
    {
        $user = strtolower(trim((string) $this->argument('user')));
        $dir = rtrim((string) $this->argument('dir'), '/');
        if (! is_dir($dir)) {
            $this->error('Нет каталога ' . $dir);

            return self::FAILURE;
        }
        $store->ensureUser($user);
        $contacts = $events = $bad = 0;

        foreach (glob($dir . '/contacts/*.vcf') ?: [] as $file) {
            foreach (Cards::split((string) file_get_contents($file)) as $one) {
                $uid = preg_match('/^UID:(.+)$/m', $one, $mm) ? trim($mm[1]) : Cards::uid();
                $uri = preg_replace('/[^A-Za-z0-9@._-]/', '_', $uid) . '.vcf';
                try {
                    $normalized = Cards::build(Cards::parse($one), $one);
                    [$code, $body] = Server::call($user, 'PUT', "addressbooks/{$user}/" . DavStore::PERSONAL . "/{$uri}", $normalized, ['Content-Type' => 'text/vcard; charset=utf-8']);
                } catch (\Throwable $e) {
                    $code = 500;
                    $body = $e->getMessage();
                }
                if ($code < 300) {
                    $contacts++;
                } else {
                    $bad++;
                    $this->line('  контакт ' . basename($file) . ': ' . $code . ' ' . mb_substr(strip_tags($body), 0, 120));
                }
            }
        }

        foreach (array_merge(glob($dir . '/calendar/*.ics') ?: [], glob($dir . '/tasks/*.ics') ?: []) as $file) {
            $ics = (string) file_get_contents($file);
            $ics = preg_replace('/^METHOD:.*\R/m', '', $ics);
            // Kerio пропускает свойства с «_» в имени (X-YANDEX_MAIL_TYPE) — по RFC 5545 так нельзя, sabre их отвергает.
            $ics = preg_replace_callback('/^([A-Za-z0-9-]*_[A-Za-z0-9_-]*)(?=[;:])/m', fn ($m) => str_replace('_', '-', $m[1]), $ics);
            if (! preg_match('/^UID:(.+?)\R(?![ \t])/ms', $ics, $mm)) {
                $bad++;
                continue;
            }
            $uid = preg_replace('/\R[ \t]/', '', $mm[1]);
            $uri = preg_replace('/[^A-Za-z0-9@._-]/', '_', trim($uid)) . '.ics';
            try {
                [$code, $body] = Server::call($user, 'PUT', "calendars/{$user}/" . DavStore::PERSONAL . "/{$uri}", $ics, ['Content-Type' => 'text/calendar; charset=utf-8']);
            } catch (\Throwable $e) {
                $code = 500;
                $body = $e->getMessage();
            }
            if ($code < 300) {
                $events++;
            } else {
                $bad++;
                $this->line('  событие ' . basename($file) . ': ' . $code . ' ' . mb_substr(strip_tags($body), 0, 160));
            }
        }

        $this->info(sprintf('%s: контактов %d, событий и задач %d, не разобрано %d', $user, $contacts, $events, $bad));

        return self::SUCCESS;
    }
}
