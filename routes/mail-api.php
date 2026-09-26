<?php

use App\Http\Controllers\Mail\Api\ActionController;
use App\Http\Controllers\Mail\Api\CalendarController;
use App\Http\Controllers\Mail\Api\ContactsController;
use App\Http\Controllers\Mail\Api\ComposeController;
use App\Http\Controllers\Mail\Api\FolderController;
use App\Http\Controllers\Mail\Api\MessageController;
use App\Http\Controllers\Mail\Api\RulesController;
use App\Http\Controllers\Mail\Api\SecurityController as MailSecurityController;
use App\Http\Controllers\Mail\Api\SettingsController;
use App\Http\Controllers\Mail\Api\SuggestController;
use Illuminate\Support\Facades\Route;

/*
 * API веб-почты — один набор маршрутов на двух входах:
 *   /mail/api/* — веб-почта (сессия, cookie, CSRF), routes/mail.php;
 *   /api/v1/*   — мобильное приложение (токен, MobileToken), routes/mobile.php.
 * Контроллеры одни и те же: они получают ImapSession и не знают, откуда пришёл запрос.
 * Новый маршрут, добавленный сюда, сразу есть и у приложения — поля в ответах только
 * добавлять, не переименовывать (см. docs/mobile-api.md, «версия заморожена»).
 */

Route::post('activity', [\App\Http\Controllers\Mail\Api\ActivityController::class, 'store'])->middleware('throttle:30,1');
Route::get('folders', [FolderController::class, 'index']);
Route::get('status', [FolderController::class, 'status']);
Route::get('tasks', [\App\Http\Controllers\Mail\Api\TasksController::class, 'index']);
Route::post('tasks', [\App\Http\Controllers\Mail\Api\TasksController::class, 'store']);
Route::patch('tasks/{calendar}/{uri}', [\App\Http\Controllers\Mail\Api\TasksController::class, 'update']);
Route::delete('tasks/{calendar}/{uri}', [\App\Http\Controllers\Mail\Api\TasksController::class, 'destroy']);
Route::get('quarantine', [\App\Http\Controllers\Mail\QuarantineController::class, 'list']);
Route::post('quarantine/{id}/release', [\App\Http\Controllers\Mail\QuarantineController::class, 'release']);
Route::delete('quarantine/{id}', [\App\Http\Controllers\Mail\QuarantineController::class, 'destroy']);
Route::post('folders', [FolderController::class, 'store']);
// Общий доступ — раньше маршрутов с {folder}=.*, иначе «INBOX/shares» уходит в delete/update.
Route::get('folders/{folder}/shares', [FolderController::class, 'shares'])->where('folder', '.*');
Route::post('folders/{folder}/shares', [FolderController::class, 'share'])->where('folder', '.*');
Route::delete('folders/{folder}/shares', [FolderController::class, 'unshare'])->where('folder', '.*');
Route::patch('folders/{folder}', [FolderController::class, 'update'])->where('folder', '.*');
Route::delete('folders/{folder}', [FolderController::class, 'destroy'])->where('folder', '.*');
Route::post('folders/{folder}/empty', [FolderController::class, 'empty'])->where('folder', '.*');

Route::get('list-at/{folder}', [MessageController::class, 'listAt'])->where('folder', '.*');
Route::get('list/{folder}', [MessageController::class, 'list'])->where('folder', '.*');
Route::get('message/{folder}/{uid}/attachment/{index}', [MessageController::class, 'attachment'])->where('folder', '.*')->where('uid', '[1-9][0-9]*')->whereNumber('index');
Route::get('message/{folder}/{uid}/attachments.zip', [MessageController::class, 'attachmentsZip'])->where('folder', '.*')->where('uid', '[1-9][0-9]*');
Route::get('message/{folder}/{uid}/cloud.zip', [MessageController::class, 'cloudZip'])->where('folder', '.*')->where('uid', '[1-9][0-9]*');
Route::get('message/{folder}/{uid}/attachment/{index}/preview.pdf', [MessageController::class, 'attachmentPreview'])->where('folder', '.*')->where('uid', '[1-9][0-9]*')->whereNumber('index');
// Письмо, приложенное к письму (.eml): разобранное письмо и его собственные вложения.
Route::get('message/{folder}/{uid}/attachment/{index}/message', [MessageController::class, 'attachedMessage'])->where('folder', '.*')->where('uid', '[1-9][0-9]*')->whereNumber('index');
Route::get('message/{folder}/{uid}/attachment/{index}/message/{sub}', [MessageController::class, 'attachedPart'])->where('folder', '.*')->where('uid', '[1-9][0-9]*')->whereNumber('index')->whereNumber('sub');
// Своё хранилище больших вложений: мои файлы, продление, удаление, предпросмотр в почте.
Route::get('files', [\App\Http\Controllers\Mail\Api\CloudFilesController::class, 'index']);
// Личное облако сотрудника (раздел «Облако», файлы в Nextcloud).
Route::prefix('cloud')->group(function () {
    Route::get('list', [\App\Http\Controllers\Mail\Api\PersonalCloudController::class, 'list']);
    Route::get('folders', [\App\Http\Controllers\Mail\Api\PersonalCloudController::class, 'folders']);
    Route::get('recent', [\App\Http\Controllers\Mail\Api\PersonalCloudController::class, 'recent']);
    Route::get('links', [\App\Http\Controllers\Mail\Api\PersonalCloudController::class, 'withLinks']);
    Route::post('folder', [\App\Http\Controllers\Mail\Api\PersonalCloudController::class, 'mkdir']);
    Route::post('rename', [\App\Http\Controllers\Mail\Api\PersonalCloudController::class, 'rename']);
    Route::post('move', [\App\Http\Controllers\Mail\Api\PersonalCloudController::class, 'move']);
    Route::post('delete', [\App\Http\Controllers\Mail\Api\PersonalCloudController::class, 'delete']);
    Route::get('trash', [\App\Http\Controllers\Mail\Api\PersonalCloudController::class, 'trash']);
    Route::post('trash/{id}/restore', [\App\Http\Controllers\Mail\Api\PersonalCloudController::class, 'restore'])->whereNumber('id');
    Route::delete('trash/{id}', [\App\Http\Controllers\Mail\Api\PersonalCloudController::class, 'purge'])->whereNumber('id');
    Route::delete('trash', [\App\Http\Controllers\Mail\Api\PersonalCloudController::class, 'emptyTrash']);
    Route::post('uploads', [\App\Http\Controllers\Mail\Api\PersonalCloudController::class, 'uploadStart']);
    Route::get('uploads/{id}', [\App\Http\Controllers\Mail\Api\PersonalCloudController::class, 'uploadStatus'])->where('id', 'mc[0-9a-f]{30}');
    Route::put('uploads/{id}/{n}', [\App\Http\Controllers\Mail\Api\PersonalCloudController::class, 'uploadChunk'])->where('id', 'mc[0-9a-f]{30}')->whereNumber('n');
    Route::post('uploads/{id}/finish', [\App\Http\Controllers\Mail\Api\PersonalCloudController::class, 'uploadFinish'])->where('id', 'mc[0-9a-f]{30}');
    Route::delete('uploads/{id}', [\App\Http\Controllers\Mail\Api\PersonalCloudController::class, 'uploadAbort'])->where('id', 'mc[0-9a-f]{30}');
    Route::post('link', [\App\Http\Controllers\Mail\Api\PersonalCloudController::class, 'link']);
    // Изменить уже выданную ссылку (срок, пароль): 404, если ссылки нет — приложение не создаст её по ошибке.
    Route::put('link', [\App\Http\Controllers\Mail\Api\PersonalCloudController::class, 'relink']);
    Route::post('unlink', [\App\Http\Controllers\Mail\Api\PersonalCloudController::class, 'unlink']);
    Route::post('attach', [\App\Http\Controllers\Mail\Api\PersonalCloudController::class, 'attach']);
    Route::post('pin', [\App\Http\Controllers\Mail\Api\PersonalCloudController::class, 'pin']);
    Route::get('file', [\App\Http\Controllers\Mail\Api\PersonalCloudController::class, 'file']);
});
Route::get('files/{token}', [\App\Http\Controllers\Mail\Api\CloudFilesController::class, 'show'])->where('token', '[A-Za-z0-9_-]{20,64}');
Route::post('files/{token}/renew', [\App\Http\Controllers\Mail\Api\CloudFilesController::class, 'renew'])->where('token', '[A-Za-z0-9_-]{20,64}');
Route::delete('files/{token}', [\App\Http\Controllers\Mail\Api\CloudFilesController::class, 'destroy'])->where('token', '[A-Za-z0-9_-]{20,64}');
Route::get('files/{token}/content', [\App\Http\Controllers\Mail\Api\CloudFilesController::class, 'content'])->where('token', '[A-Za-z0-9_-]{20,64}');
Route::get('files/{token}/preview.pdf', [\App\Http\Controllers\Mail\Api\CloudFilesController::class, 'preview'])->where('token', '[A-Za-z0-9_-]{20,64}');
Route::get('message/{folder}/{uid}/raw', [MessageController::class, 'raw'])->where('folder', '.*')->where('uid', '[1-9][0-9]*');
Route::get('message/{folder}/{uid}/thread', [MessageController::class, 'thread'])->where('folder', '.*')->where('uid', '[1-9][0-9]*');
Route::get('message/{folder}/{uid}', [MessageController::class, 'show'])->where('folder', '.*')->where('uid', '[1-9][0-9]*');

Route::post('action', [ActionController::class, 'store']);

Route::post('send', [ComposeController::class, 'send']);
Route::post('draft', [ComposeController::class, 'draft']);
// Большой файл — в хранилище сразу при прикреплении, а не в момент «Отправить» (разбор журнала 26.09).
Route::post('compose/stage', [ComposeController::class, 'stage']);
Route::delete('compose/stage/{token}', [ComposeController::class, 'unstage'])->where('token', '[A-Za-z0-9_-]{20,64}');
Route::get('draft/{uid}', [ComposeController::class, 'openDraft'])->where('uid', '[1-9][0-9]*');
Route::get('outbox', [ComposeController::class, 'outbox']);
Route::delete('outbox/{id}', [ComposeController::class, 'cancel'])->whereNumber('id');

Route::get('suggest', SuggestController::class);
Route::get('check-domain', \App\Http\Controllers\Mail\Api\DomainCheckController::class);

Route::get('settings', [SettingsController::class, 'show']);
Route::put('settings', [SettingsController::class, 'update']);
Route::get('labels', [SettingsController::class, 'labels']);
Route::post('labels', [SettingsController::class, 'storeLabel']);
Route::patch('labels/{id}', [SettingsController::class, 'updateLabel'])->whereNumber('id');
Route::delete('labels/{id}', [SettingsController::class, 'destroyLabel'])->whereNumber('id');

Route::get('security', [MailSecurityController::class, 'show']);
Route::post('security/2fa/setup', [MailSecurityController::class, 'twofaSetup']);
Route::post('security/2fa/enable', [MailSecurityController::class, 'twofaEnable']);
Route::post('security/2fa/disable', [MailSecurityController::class, 'twofaDisable']);
Route::post('security/app-passwords', [MailSecurityController::class, 'storeAppPassword']);
Route::delete('security/app-passwords/{id}', [MailSecurityController::class, 'destroyAppPassword'])->whereNumber('id');
Route::post('security/sessions/kick', [MailSecurityController::class, 'kick']);
Route::post('security/sessions/kick-others', [MailSecurityController::class, 'kickOthers']);
Route::post('security/remember', [MailSecurityController::class, 'remember']);

Route::get('rules', [RulesController::class, 'show']);
Route::put('rules', [RulesController::class, 'update']);
Route::post('rules/apply', [RulesController::class, 'apply']);
Route::post('sender/mark', [\App\Http\Controllers\Mail\Api\SenderController::class, 'mark']);

// Контакты.
Route::get('contacts/books', [ContactsController::class, 'books']);
Route::get('contacts/groups', [ContactsController::class, 'groups']);
Route::get('contacts/export', [ContactsController::class, 'export']);
Route::get('contacts/history', [ContactsController::class, 'history']);
Route::delete('contacts/history/{email}', [ContactsController::class, 'forgetHistory']);
Route::post('contacts/import', [ContactsController::class, 'import']);
Route::get('contacts', [ContactsController::class, 'index']);
Route::post('contacts', [ContactsController::class, 'store']);
Route::get('contacts/{book}/{uri}', [ContactsController::class, 'show']);
Route::put('contacts/{book}/{uri}', [ContactsController::class, 'update']);
Route::delete('contacts/{book}/{uri}', [ContactsController::class, 'destroy']);
Route::post('contacts/{book}/{uri}/copy', [ContactsController::class, 'copy']);
Route::post('contacts/{book}/{uri}/suggest', [ContactsController::class, 'suggest']);

// Календарь.
Route::get('calendars', [CalendarController::class, 'calendars']);
Route::post('calendars', [CalendarController::class, 'storeCalendar']);
Route::patch('calendars/{calendar}', [CalendarController::class, 'updateCalendar']);
Route::delete('calendars/{calendar}', [CalendarController::class, 'destroyCalendar']);
Route::get('calendars/{calendar}/shares', [CalendarController::class, 'shares']);
Route::post('calendars/{calendar}/shares', [CalendarController::class, 'share']);
Route::delete('calendars/{calendar}/shares', [CalendarController::class, 'unshare']);
// Отписаться от чужого общего календаря (приложение): свой календарь этим не удалить.
Route::delete('calendars/{calendar}/subscription', [CalendarController::class, 'unsubscribe']);
Route::get('calendars/{calendar}/export', [CalendarController::class, 'export']);
Route::get('events', [CalendarController::class, 'events']);
Route::post('events', [CalendarController::class, 'store']);
Route::get('freebusy', [CalendarController::class, 'freebusy']);
Route::get('events/{calendar}/{uri}', [CalendarController::class, 'show']);
Route::put('events/{calendar}/{uri}', [CalendarController::class, 'update']);
Route::delete('events/{calendar}/{uri}', [CalendarController::class, 'destroy']);
Route::post('events/{calendar}/{uri}/respond', [CalendarController::class, 'respond']);
