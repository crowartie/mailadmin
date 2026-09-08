<?php

use App\Http\Controllers\AliasController;
use App\Http\Controllers\CompanyContactsController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DomainController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\MaillistsController;
use App\Http\Controllers\UnitsController;
use App\Http\Controllers\LogsController;
use App\Http\Controllers\QueueController;
use App\Http\Controllers\MailboxController;
use App\Http\Controllers\RulesController;
use App\Http\Controllers\SecurityController;
use App\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// ── Админка: только на своём порту (config/areas.php) ────────────────
Route::middleware('area:admin')->group(function () {

    Route::middleware('guest')->group(function () {
        Route::get('/login', [LoginController::class, 'create'])->name('login');
        Route::post('/login', [LoginController::class, 'store']);
    });
    Route::post('/logout', [LoginController::class, 'destroy']);

    // Второй шаг входа: пароль уже проверен, ждём код.
    Route::middleware('auth')->group(function () {
        Route::get('/login/code', [TwoFactorController::class, 'challenge']);
        Route::post('/login/code', [TwoFactorController::class, 'verify']);
    });

    Route::middleware(['auth', '2fa', 'role'])->group(function () {
        Route::get('/', DashboardController::class);

        Route::get('/domains', [DomainController::class, 'index']);

        Route::get('/mailboxes', [MailboxController::class, 'index']);
        Route::get('/mailboxes/create', [MailboxController::class, 'create']);
        Route::post('/mailboxes', [MailboxController::class, 'store']);
        // Адрес содержит @ и точки, поэтому параметр берём как есть, без ограничений маршрута.
        Route::post('/mailboxes/import/preview', [ImportController::class, 'preview']);
        Route::post('/mailboxes/import/check', [ImportController::class, 'check']);
        Route::post('/mailboxes/import/run', [ImportController::class, 'run']);
        Route::post('/mailboxes/{mailbox}/access', [EmployeeController::class, 'access'])->where('mailbox', '.*');
        Route::post('/mailboxes/{mailbox}/impersonate', [EmployeeController::class, 'impersonate'])->where('mailbox', '.*');
        Route::post('/mailboxes/{mailbox}/kick', [EmployeeController::class, 'kick'])->where('mailbox', '.*');
        Route::post('/mailboxes/{mailbox}/reset-2fa', [EmployeeController::class, 'reset2fa'])->where('mailbox', '.*');
        Route::get('/mailboxes/{mailbox}/shares', [EmployeeController::class, 'shares'])->where('mailbox', '.*');
        Route::post('/mailboxes/{mailbox}/shares', [EmployeeController::class, 'share'])->where('mailbox', '.*');
        Route::delete('/mailboxes/{mailbox}/shares', [EmployeeController::class, 'unshare'])->where('mailbox', '.*');
        Route::delete('/mailboxes/{mailbox}/app-passwords/{password}', [EmployeeController::class, 'revokeAppPassword'])->where('mailbox', '.*');
        Route::get('/mailboxes/{mailbox}/edit', [MailboxController::class, 'edit'])->where('mailbox', '.*');
        Route::put('/mailboxes/{mailbox}', [MailboxController::class, 'update'])->where('mailbox', '.*');
        Route::delete('/mailboxes/{mailbox}', [MailboxController::class, 'destroy'])->where('mailbox', '.*');

        Route::get('/rules', [RulesController::class, 'index']);

        Route::get('/queue', [QueueController::class, 'index']);
        Route::get('/queue/list', [QueueController::class, 'list']);
        Route::post('/queue/action', [QueueController::class, 'action']);
        Route::get('/queue/{id}/raw', [QueueController::class, 'raw'])->where('id', '[A-Za-z0-9]+');
        Route::get('/queue/{id}', [QueueController::class, 'show'])->where('id', '[A-Za-z0-9]+');

        Route::get('/logs', [LogsController::class, 'index']);
        Route::get('/logs/tail', [LogsController::class, 'tail']);
        Route::get('/logs/path', [LogsController::class, 'path']);
        Route::get('/logs/export', [LogsController::class, 'export']);

        Route::get('/aliases', [AliasController::class, 'index']);
        Route::get('/aliases/create', [AliasController::class, 'create']);
        Route::post('/aliases', [AliasController::class, 'store']);
        Route::get('/aliases/{alias}/edit', [AliasController::class, 'edit'])->where('alias', '.*');
        Route::put('/aliases/{alias}', [AliasController::class, 'update'])->where('alias', '.*');
        Route::delete('/aliases/{alias}', [AliasController::class, 'destroy'])->where('alias', '.*');

        Route::get('/company-contacts', [CompanyContactsController::class, 'index']);
        Route::post('/company-contacts', [CompanyContactsController::class, 'store']);
        Route::put('/company-contacts/{uri}', [CompanyContactsController::class, 'update']);
        Route::delete('/company-contacts/{uri}', [CompanyContactsController::class, 'destroy']);
        Route::post('/company-contacts/suggestions/{suggestion}/approve', [CompanyContactsController::class, 'approve']);
        Route::post('/company-contacts/suggestions/{suggestion}/reject', [CompanyContactsController::class, 'reject']);

        Route::get('/security', [SecurityController::class, 'index']);
        Route::post('/security/unban', [SecurityController::class, 'unban']);
        Route::post('/security/ban', [SecurityController::class, 'ban']);
        Route::post('/security/ignore', [SecurityController::class, 'ignore']);
        Route::post('/security/kick', [SecurityController::class, 'kick']);
        Route::post('/security/require-2fa', [SecurityController::class, 'require2fa']);
        Route::post('/security/reset-2fa', [SecurityController::class, 'reset2fa']);
        Route::post('/security/policies', [SecurityController::class, 'policies']);
        Route::delete('/security/app-passwords/{password}', [SecurityController::class, 'revokeAppPassword']);
        Route::get('/security/2fa', [TwoFactorController::class, 'setup']);
        Route::post('/security/2fa', [TwoFactorController::class, 'enable']);
        Route::delete('/security/2fa', [TwoFactorController::class, 'disable']);
        Route::get('/security/{tab}', [SecurityController::class, 'index'])->where('tab', 'overview|bans|logins|twofa|apppasswords|sessions');

        Route::get('/units', [UnitsController::class, 'index']);
        Route::post('/units', [UnitsController::class, 'store']);
        Route::post('/units/move', [UnitsController::class, 'move']);
        Route::get('/units/{unit}', [UnitsController::class, 'index'])->whereNumber('unit');
        Route::put('/units/{unit}', [UnitsController::class, 'update']);
        Route::delete('/units/{unit}', [UnitsController::class, 'destroy']);

        Route::get('/maillists', [MaillistsController::class, 'index']);
        Route::post('/maillists', [MaillistsController::class, 'store']);
        Route::get('/maillists/{list}', [MaillistsController::class, 'index'])->where('list', '.*@.*');
        Route::put('/maillists/{list}', [MaillistsController::class, 'update'])->where('list', '.*@.*');
        Route::delete('/maillists/{list}', [MaillistsController::class, 'destroy'])->where('list', '.*@.*');
        Route::post('/maillists/{list}/subscribe', [MaillistsController::class, 'subscribe'])->where('list', '.*@.*');
        Route::post('/maillists/{list}/unsubscribe', [MaillistsController::class, 'unsubscribe'])->where('list', '.*@.*');
        Route::post('/maillists/{list}/moderate', [MaillistsController::class, 'moderate'])->where('list', '.*@.*');

        Route::get('/settings', [SettingsController::class, 'index']);
        Route::get('/settings/{tab}', [SettingsController::class, 'index'])->where('tab', 'domains|spam|limits|cert|backup|admins|alerts|cloud');
        Route::post('/settings/dns/recheck', [SettingsController::class, 'recheckDns']);
        Route::post('/settings/domains/{domain}', [SettingsController::class, 'saveDomain']);
        Route::post('/settings/dkim/rotate', [SettingsController::class, 'rotateDkim']);
        Route::post('/settings/mtasts/enable', [SettingsController::class, 'enableMtaSts']);
        Route::post('/settings/mtasts/mode', [SettingsController::class, 'mtaStsMode']);
        Route::post('/settings/reports/fetch', [SettingsController::class, 'fetchReports']);
        Route::post('/settings/spam', [SettingsController::class, 'saveSpam']);
        Route::post('/settings/wblist', [SettingsController::class, 'addWblist']);
        Route::delete('/settings/wblist/{id}', [SettingsController::class, 'removeWblist']);
        Route::post('/settings/quarantine/policy', [SettingsController::class, 'saveQuarantine']);
        Route::post('/settings/quarantine/{id}/release', [SettingsController::class, 'releaseQuarantine']);
        Route::delete('/settings/quarantine/{id}', [SettingsController::class, 'deleteQuarantine']);
        Route::post('/settings/limits', [SettingsController::class, 'saveLimits']);
        Route::post('/settings/cert/renew', [SettingsController::class, 'renewCert']);
        Route::post('/settings/backup', [SettingsController::class, 'saveBackup']);
        Route::post('/settings/backup/run', [SettingsController::class, 'runBackup']);
        Route::post('/settings/backup/restore', [SettingsController::class, 'restoreBackup']);
        Route::post('/settings/admins', [SettingsController::class, 'storeAdmin']);
        Route::put('/settings/admins/{user}', [SettingsController::class, 'updateAdmin']);
        Route::delete('/settings/admins/{user}', [SettingsController::class, 'destroyAdmin']);
        Route::post('/settings/alerts', [SettingsController::class, 'saveAlerts']);
        Route::post('/settings/alerts/test', [SettingsController::class, 'testAlerts']);
        Route::post('/settings/cloud/start', [SettingsController::class, 'cloudStart']);
        Route::get('/settings/cloud/poll', [SettingsController::class, 'cloudPoll']);
        Route::post('/settings/cloud/manual', [SettingsController::class, 'cloudManual']);
        Route::post('/settings/cloud', [SettingsController::class, 'cloudSave']);
        Route::post('/settings/cloud/disconnect', [SettingsController::class, 'cloudDisconnect']);
        Route::post('/settings/cloud/test', [SettingsController::class, 'cloudTest']);

    });
});
