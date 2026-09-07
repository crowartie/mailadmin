<?php

use Illuminate\Support\Facades\Schedule;

// Веб-почта: вернуть отложенные письма, разослать напоминания, отправить письма по расписанию.
Schedule::command('mail:wake')->everyMinute()->withoutOverlapping();
Schedule::command('mail:outbox')->everyMinute()->withoutOverlapping();
