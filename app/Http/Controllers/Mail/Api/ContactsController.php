<?php

namespace App\Http\Controllers\Mail\Api;

use App\Http\Controllers\Controller;
use App\Models\ContactSuggestion;
use App\Services\Dav\Cards;
use App\Services\Dav\DavException;
use App\Services\Dav\DavStore;
use App\Services\Mail\ImapSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ContactsController extends Controller
{
    public function __construct(private readonly DavStore $store)
    {
    }

    public function books(ImapSession $imap): JsonResponse
    {
        return response()->json($this->store->books($imap->user()));
    }

    public function index(Request $request, ImapSession $imap): JsonResponse
    {
        return $this->guard(fn () => $this->store->cards($imap->user(), $request->query('book') ?: null, (string) $request->query('q', '')));
    }

    public function show(ImapSession $imap, string $book, string $uri): JsonResponse
    {
        return $this->guard(function () use ($imap, $book, $uri) {
            $c = $this->store->card($imap->user(), $book, $uri);
            unset($c['raw']);

            return $c;
        });
    }

    public function store(Request $request, ImapSession $imap): JsonResponse
    {
        $data = $this->validated($request);

        return $this->guard(fn () => $this->strip($this->store->saveCard($imap->user(), $data['book'] ?? DavStore::PERSONAL, null, $data)), 201);
    }

    public function update(Request $request, ImapSession $imap, string $book, string $uri): JsonResponse
    {
        $data = $this->validated($request);

        return $this->guard(function () use ($imap, $book, $uri, $data) {
            $saved = $this->store->saveCard($imap->user(), $book, $uri, $data);
            if (! empty($data['book']) && $data['book'] !== $book) {
                $saved = $this->store->moveCard($imap->user(), $book, $uri, $data['book']);
            }

            return $this->strip($saved);
        });
    }

    public function destroy(ImapSession $imap, string $book, string $uri): JsonResponse
    {
        return $this->guard(function () use ($imap, $book, $uri) {
            $this->store->deleteCard($imap->user(), $book, $uri);

            return ['ok' => true];
        });
    }

    /** Скопировать контакт (например, сотрудника) к себе в личную книгу. */
    public function copy(Request $request, ImapSession $imap, string $book, string $uri): JsonResponse
    {
        return $this->guard(function () use ($request, $imap, $book, $uri) {
            $c = $this->store->card($imap->user(), $book, $uri);
            $c['uid'] = null;
            unset($c['employee']);
            $saved = $this->store->saveCard($imap->user(), (string) $request->input('to', DavStore::PERSONAL), null, $c);

            return $this->strip($saved);
        }, 201);
    }

    public function import(Request $request, ImapSession $imap): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:10240'], 'book' => ['nullable', 'string']]);
        $text = (string) file_get_contents($request->file('file')->getRealPath());

        return $this->guard(fn () => ['imported' => $this->store->importCards($imap->user(), (string) $request->input('book', DavStore::PERSONAL), $text)]);
    }

    public function export(Request $request, ImapSession $imap): Response
    {
        $book = $request->query('book') ?: null;
        $vcf = $this->store->exportCards($imap->user(), $book);

        return response($vcf, 200, [
            'Content-Type' => 'text/vcard; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . ($book ?: 'contacts') . '.vcf"',
        ]);
    }

    /** Предложить контакт в общую книгу компании — решает администратор. */
    public function suggest(Request $request, ImapSession $imap, string $book, string $uri): JsonResponse
    {
        return $this->guard(function () use ($request, $imap, $book, $uri) {
            $c = $this->store->card($imap->user(), $book, $uri);
            // Один адрес — одна заявка: если уже подал другой сотрудник или адрес уже в общей книге, говорим об этом.
            $mail = strtolower((string) ($c['email'] ?? ''));
            if ($mail !== '') {
                $other = ContactSuggestion::where('email', $mail)->where('status', 'pending')->where('user', '!=', $imap->user())->orderBy('id')->first();
                if ($other) {
                    $who = \App\Models\Vmail\Mailbox::query()->where('username', $other->user)->value('name') ?: $other->user;
                    abort(409, 'Заявку на ' . $mail . ' уже подал(а) ' . $who . ' ' . $other->created_at->format('d.m.Y') . ' — она ждёт администратора');
                }
                $inCompany = collect($this->store->cards($imap->user(), 'company', $mail))->contains(fn ($x) => collect($x['emails'] ?? [])->contains(fn ($e) => strtolower((string) ($e['value'] ?? '')) === $mail));
                abort_if($inCompany, 409, 'Адрес ' . $mail . ' уже есть в «Контактах компании»');
            }
            if ($this->store->isAdmin($imap->user())) {
                // Администратору посредник не нужен — сразу в общую.
                $this->store->saveCard($imap->user(), 'company', null, array_merge($c, ['uid' => null]));

                return ['status' => 'approved'];
            }
            $pending = ContactSuggestion::where('user', $imap->user())->where('status', 'pending')->where('vcard', $c['raw'])->exists();
            if (! $pending) {
                ContactSuggestion::create([
                    'user' => $imap->user(), 'fn' => $c['fn'], 'email' => $c['email'] ?: null,
                    'vcard' => $c['raw'], 'status' => 'pending', 'note' => mb_substr((string) $request->input('note', ''), 0, 500),
                ]);
            }

            return ['status' => 'pending'];
        });
    }

    /** История общения: адреса из переписки, которых ещё нет ни в одной книге пользователя. */
    public function history(ImapSession $imap): JsonResponse
    {
        $known = [strtolower($imap->user())];
        try {
            foreach ($this->store->cards($imap->user()) as $c) {
                foreach ($c['emails'] ?? [] as $e) {
                    $known[] = strtolower((string) ($e['value'] ?? ''));
                }
                if (! empty($c['email'])) {
                    $known[] = strtolower($c['email']);
                }
            }
        } catch (\Throwable) {
            // книги недоступны — покажем всё
        }
        $known = array_flip(array_filter($known));
        $rows = \App\Models\Webmail\Recent::query()->where('user', $imap->user())->orderByDesc('last_at')->limit(500)->get();
        $out = [];
        foreach ($rows as $r) {
            $mail = strtolower($r->email);
            if (isset($known[$mail]) || isset($out[$mail])) {
                continue;
            }
            $out[$mail] = ['email' => $mail, 'name' => (string) ($r->name ?: ''), 'uses' => (int) $r->uses, 'last_at' => $r->last_at?->toIso8601String()];
        }

        return response()->json(array_values($out));
    }

    public function forgetHistory(ImapSession $imap, string $email): JsonResponse
    {
        \App\Models\Webmail\Recent::query()->where('user', $imap->user())->where('email', strtolower($email))->delete();

        return $this->history($imap);
    }

    /** Группы (категории) по всем контактам пользователя. */
    public function groups(ImapSession $imap): JsonResponse
    {
        $groups = [];
        foreach ($this->store->cards($imap->user()) as $c) {
            foreach ($c['groups'] as $g) {
                $groups[$g] = ($groups[$g] ?? 0) + 1;
            }
        }
        ksort($groups, SORT_NATURAL | SORT_FLAG_CASE);

        return response()->json(array_map(fn ($name, $n) => ['name' => $name, 'count' => $n], array_keys($groups), $groups));
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'book' => ['nullable', 'string', 'max:100'],
            'fn' => ['nullable', 'string', 'max:255'],
            'first' => ['nullable', 'string', 'max:100'],
            'last' => ['nullable', 'string', 'max:100'],
            'middle' => ['nullable', 'string', 'max:100'],
            'nick' => ['nullable', 'string', 'max:100'],
            'org' => ['nullable', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:255'],
            'title' => ['nullable', 'string', 'max:255'],
            'emails' => ['array'], 'emails.*.value' => ['nullable', 'string', 'max:255'], 'emails.*.type' => ['nullable', 'string', 'max:10'],
            'phones' => ['array'], 'phones.*.value' => ['nullable', 'string', 'max:60'], 'phones.*.type' => ['nullable', 'string', 'max:10'],
            'addresses' => ['array'],
            'birthday' => ['nullable', 'string', 'max:10'],
            'url' => ['nullable', 'string', 'max:500'],
            'note' => ['nullable', 'string', 'max:5000'],
            'groups' => ['array'], 'groups.*' => ['string', 'max:60'],
            'favorite' => ['nullable', 'boolean'],
            'photo' => ['nullable', 'string', 'max:2000000'],
        ]);
    }

    private function strip(array $c): array
    {
        unset($c['raw']);

        return $c;
    }

    private function guard(callable $fn, int $status = 200): JsonResponse
    {
        try {
            return response()->json($fn(), $status);
        } catch (DavException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }
    }
}
