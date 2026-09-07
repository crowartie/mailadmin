<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

// Веб-почта: вернуть отложенные письма, разослать напоминания, отправить письма по расписанию.
Schedule::command('mail:wake')->everyMinute()->withoutOverlapping();
Schedule::command('mail:outbox')->everyMinute()->withoutOverlapping();
// Общая книга «Сотрудники» — сверка с таблицей ящиков (правки через iRedAdmin или SQL).
Schedule::command('dav:sync-employees')->hourly()->withoutOverlapping();
// Отметка для обзора: планировщик жив.
Schedule::call(fn () => Cache::put('scheduler.last_run', time(), 3600))->everyMinute();
// Уведомления администраторам: проверки каждые 5 минут, сводка — по времени из настроек.
Schedule::command('alerts:check')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('alerts:check --digest')->everyMinute()->when(function () {
    try {
        return now()->format('H:i') === (\App\Models\AppSetting::group('alerts')['digest_time'] ?? '09:00');
    } catch (\Throwable) {
        return false;
    }
});
// Резервная копия по расписанию из настроек и чистка карантина по сроку хранения.
Schedule::command('backup:run --if-due')->everyMinute()->withoutOverlapping()->runInBackground();
Schedule::command('backup:run --purge-quarantine')->dailyAt('04:10');
