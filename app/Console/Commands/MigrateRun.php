<?php

namespace App\Console\Commands;

use App\Models\AppSetting;
use App\Models\MailMigration;
use App\Services\Dav\DavStore;
use App\Services\Migration\DavImport;
use App\Services\Migration\Imapsync;
use Illuminate\Console\Command;

/** Фоновый перенос ящика: почта через imapsync, контакты и календарь по CardDAV/CalDAV. С --next разбирает очередь по одному. */
class MigrateRun extends Command
{
    protected $signature = 'migrate:run {id? : строка из mail_migrations} {--next : брать из очереди, пока она не опустеет}';

    protected $description = 'Перенос ящика со старого сервера';

    public function handle(Imapsync $imapsync, DavStore $store): int
    {
        if ($this->option('next')) {
            $rc = self::SUCCESS;
            while ($m = MailMigration::query()->where('status', 'queued')->orderBy('id')->first()) {
                if (! $this->runOne($m, $imapsync, $store)) {
                    $rc = self::FAILURE;
                }
            }

            return $rc;
        }
        $m = MailMigration::query()->find((int) $this->argument('id'));
        if (! $m) {
            $this->error('Нет такой строки');

            return self::FAILURE;
        }

        return $this->runOne($m, $imapsync, $store) ? self::SUCCESS : self::FAILURE;
    }

    private function runOne(MailMigration $m, Imapsync $imapsync, DavStore $store): bool
    {
        $m->update(['status' => 'running', 'error' => null, 'started_at' => now(), 'finished_at' => null]);
        $errors = [];
        if (in_array($m->what, ['mail', 'all'], true)) {
            $imapsync->runMail($m);
            $m->refresh();
            if ($m->status === 'failed') {
                $errors[] = 'Почта: ' . $m->error;
            }
        }
        if (in_array($m->what, ['dav', 'all'], true)) {
            $url = (string) (AppSetting::group('migration')['dav_url'] ?? '');
            if ($url === '') {
                $url = $m->source_host;
            }
            try {
                $r = (new DavImport($url, $m->source_login, $m->source_password))->run($m->target, $store);
                $m->dav_stats = $r;
                $m->save();
            } catch (\Throwable $e) {
                $errors[] = 'Контакты/календарь: ' . mb_substr($e->getMessage(), 0, 300);
            }
        }
        $m->update(['status' => $errors ? 'failed' : 'done', 'error' => $errors ? implode("\n", $errors) : null, 'finished_at' => now()]);
        $this->line($m->target . ': ' . ($errors ? implode("\n", $errors) : 'готово'));

        return ! $errors;
    }
}
