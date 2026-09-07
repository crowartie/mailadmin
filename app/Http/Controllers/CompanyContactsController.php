<?php

namespace App\Http\Controllers;

use App\Models\ContactSuggestion;
use App\Services\Dav\Cards;
use App\Services\Dav\DavStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Админка: общая книга «Контакты компании» и предложения сотрудников.
 * Книга «Сотрудники» здесь только показывается — её заполняет создание ящика.
 */
class CompanyContactsController extends Controller
{
    public function __construct(private readonly DavStore $store)
    {
    }

    public function index(Request $request): Response
    {
        $q = mb_strtolower(trim((string) $request->query('search', '')));
        $bookId = $this->store->systemBookId('company');
        $cards = [];
        foreach (DB::table('dav_cards')->where('addressbookid', $bookId)->get(['uri', 'carddata', 'etag']) as $row) {
            $c = Cards::parse((string) $row->carddata, $row->uri, $row->etag);
            unset($c['photo']);
            if ($q === '' || str_contains(mb_strtolower($c['fn'] . ' ' . $c['org'] . ' ' . implode(' ', array_column($c['emails'], 'value')) . ' ' . implode(' ', array_column($c['phones'], 'value'))), $q)) {
                $cards[] = $c;
            }
        }
        usort($cards, fn ($a, $b) => strcoll(mb_strtolower($a['fn']), mb_strtolower($b['fn'])));

        $suggestions = ContactSuggestion::query()->where('status', 'pending')->orderBy('id')->get()
            ->map(fn (ContactSuggestion $s) => ['id' => $s->id, 'user' => $s->user, 'created_at' => $s->created_at?->toIso8601String(), 'note' => $s->note] + Cards::parse($s->vcard))
            ->map(function ($c) { unset($c['photo']); return $c; })
            ->values();

        return Inertia::render('Contacts/Index', [
            'cards' => $cards,
            'suggestions' => $suggestions,
            'employees' => DB::table('dav_cards')->where('addressbookid', $this->store->systemBookId('employees'))->count(),
            'filters' => ['search' => $request->query('search', '')],
            'editing' => $request->query('edit') ? $this->card((string) $request->query('edit')) : null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $uri = Cards::uid() . '.vcf';
        $this->store->systemCards()->createCard($this->store->systemBookId('company'), $uri, Cards::build($data));

        return redirect('/company-contacts')->with('success', 'Контакт добавлен в общую книгу');
    }

    public function update(Request $request, string $uri): RedirectResponse
    {
        $data = $this->validated($request);
        $bookId = $this->store->systemBookId('company');
        $existing = DB::table('dav_cards')->where('addressbookid', $bookId)->where('uri', $uri)->value('carddata');
        abort_unless($existing, 404);
        $this->store->systemCards()->updateCard($bookId, $uri, Cards::build($data, (string) $existing));

        return redirect('/company-contacts')->with('success', 'Контакт обновлён');
    }

    public function destroy(string $uri): RedirectResponse
    {
        $this->store->systemCards()->deleteCard($this->store->systemBookId('company'), $uri);

        return redirect('/company-contacts')->with('success', 'Контакт удалён из общей книги');
    }

    public function approve(ContactSuggestion $suggestion): RedirectResponse
    {
        $uri = Cards::uid() . '.vcf';
        $this->store->systemCards()->createCard($this->store->systemBookId('company'), $uri, Cards::build(Cards::parse($suggestion->vcard), $suggestion->vcard));
        $suggestion->update(['status' => 'approved']);

        return redirect('/company-contacts')->with('success', "«{$suggestion->fn}» добавлен в общую книгу");
    }

    public function reject(ContactSuggestion $suggestion): RedirectResponse
    {
        $suggestion->update(['status' => 'rejected']);

        return redirect('/company-contacts')->with('success', 'Предложение отклонено');
    }

    private function card(string $uri): ?array
    {
        $row = DB::table('dav_cards')->where('addressbookid', $this->store->systemBookId('company'))->where('uri', $uri)->first();

        return $row ? Cards::parse((string) $row->carddata, $row->uri, $row->etag) : null;
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'fn' => ['nullable', 'string', 'max:255'], 'first' => ['nullable', 'string', 'max:100'], 'last' => ['nullable', 'string', 'max:100'],
            'middle' => ['nullable', 'string', 'max:100'], 'org' => ['nullable', 'string', 'max:255'], 'department' => ['nullable', 'string', 'max:255'],
            'title' => ['nullable', 'string', 'max:255'],
            'emails' => ['array'], 'emails.*.value' => ['nullable', 'string', 'max:255'], 'emails.*.type' => ['nullable', 'string', 'max:10'],
            'phones' => ['array'], 'phones.*.value' => ['nullable', 'string', 'max:60'], 'phones.*.type' => ['nullable', 'string', 'max:10'],
            'addresses' => ['array'], 'birthday' => ['nullable', 'string', 'max:10'], 'url' => ['nullable', 'string', 'max:500'],
            'note' => ['nullable', 'string', 'max:5000'], 'groups' => ['array'], 'groups.*' => ['string', 'max:60'],
        ]);
    }
}
