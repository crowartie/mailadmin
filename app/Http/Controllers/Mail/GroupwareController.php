<?php

namespace App\Http\Controllers\Mail;

use App\Http\Controllers\Controller;
use App\Models\Webmail\Setting;
use App\Services\Dav\DavStore;
use App\Services\Mail\ImapSession;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Страницы «Контакты» и «Календарь» — первая отрисовка; дальше всё через /mail/api/*. */
class GroupwareController extends Controller
{
    public function contacts(Request $request, ImapSession $imap, DavStore $store): Response
    {
        $user = $imap->user();
        $store->ensureUser($user);
        $book = $request->query('book');
        // Группа — это не книга: раньше в адрес писалось «book=group:Отдел», и хранилище
        // такого значения не принимало — перезагрузка страницы на выбранной группе
        // отдавала ошибку сервера вместо контактов.
        $group = $request->query('group');
        if ($book !== null && str_starts_with((string) $book, 'group:')) {
            $group = substr((string) $book, 6);
            $book = null;
        }

        return Inertia::render('Mail/Contacts', [
            'user' => $user,
            'settings' => Setting::for($user),
            'isAdmin' => $store->isAdmin($user),
            'books' => $store->books($user),
            'book' => $group ? 'group:' . $group : $book,
            'contacts' => $store->cards($user, $book ?: null, (string) $request->query('q', '')),
            'query' => (string) $request->query('q', ''),
            'openUri' => $request->query('open'),
        ]);
    }

    public function calendar(Request $request, ImapSession $imap, DavStore $store): Response
    {
        $user = $imap->user();
        $store->ensureUser($user);

        return Inertia::render('Mail/Calendar', [
            'user' => $user,
            'userName' => $store->displayName($user),
            'settings' => Setting::for($user),
            'isAdmin' => $store->isAdmin($user),
            'calendars' => $store->calendars($user),
            'prefill' => $request->query('new') ? [
                'title' => (string) $request->query('title', ''),
                'attendees' => array_values(array_filter(array_map('trim', explode(',', (string) $request->query('attendees', ''))))),
                'description' => (string) $request->query('description', ''),
            ] : null,
        ]);
    }
}
