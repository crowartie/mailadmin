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
