<?php

namespace App\Http\Controllers;

use App\Models\AdminAction;
use App\Models\Vmail\Domain;
use App\Models\Vmail\Mailbox;
use App\Models\Vmail\Maillist;
use App\Services\Server\Mlmmj;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Рассылки mlmmj: список, подписчики, кто может писать, модерация, архив. */
class MaillistsController extends Controller
{
    public function __construct(private readonly Mlmmj $mlmmj)
    {
    }

    public function index(Request $request, ?string $list = null): Response
    {
        $lists = $this->mlmmj->all();
        $open = null;
        if ($list) {
            $row = Maillist::query()->where('address', strtolower($list))->firstOrFail();
            $info = $this->mlmmj->info($row->address);
            $subs = $this->mlmmj->subscribers($row->address);
            $names = Mailbox::query()->whereIn('username', $subs)->pluck('name', 'username');
            $open = [
                'address' => $row->address, 'name' => $row->name, 'description' => $row->description, 'active' => (bool) $row->active,
                'options' => $info['options'] ?? [], 'moderators' => $info['moderators'] ?? [], 'owners' => $info['owners'] ?? [], 'subject_prefix' => $info['subject_prefix'] ?? '',
                'subscribers' => array_map(fn ($s) => ['mail' => $s, 'name' => $names[$s] ?? null, 'external' => ! isset($names[$s]), 'moderator' => in_array($s, $info['moderators'] ?? [], true)], $subs),
                'moderation' => array_map(fn ($m) => $m + ['when' => $m['date'] ? date(DATE_ATOM, (int) $m['date']) : null], $this->mlmmj->moderation($row->address)),
                'archive' => array_map(fn ($m) => $m + ['when' => $m['date'] ? date(DATE_ATOM, (int) $m['date']) : null], $this->mlmmj->archive($row->address)),
            ];
        }

        return Inertia::render('Maillists/Index', [
            'lists' => $lists,
            'open' => $open,
            'options' => Mlmmj::OPTIONS,
            'domains' => Domain::query()->where('active', 1)->orderBy('domain')->pluck('domain'),
            'employees' => Mailbox::query()->where('active', 1)->orderBy('name')->get(['username', 'name'])->map(fn ($m) => ['username' => $m->username, 'name' => $m->name ?: $m->username]),
            'ctl' => \App\Services\Server\Ctl::available(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'local_part' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9._-]*$/i'],
            'domain' => ['required', Rule::exists('vmail.domain', 'domain')],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            'options' => ['array'],
            'subscribers' => ['array'], 'subscribers.*' => ['email'],
        ]);
        $address = strtolower($data['local_part'] . '@' . $data['domain']);
        if (Mailbox::query()->where('username', $address)->exists() || Maillist::query()->where('address', $address)->exists()) {
            return back()->with('error', 'Адрес ' . $address . ' уже занят');
        }
        try {
            $this->mlmmj->create($address, $data['name'], $data['options'] ?? [], $data['description'] ?? '');
            if (! empty($data['subscribers'])) {
                $this->mlmmj->addSubscribers($address, array_map('strtolower', $data['subscribers']));
            }
        } catch (\RuntimeException $e) {
            return back()->with('error', 'Рассылка не создана: ' . mb_substr($e->getMessage(), 0, 300));
        }
        AdminAction::log('list.create', $address, $data['name']);

        return redirect('/maillists/' . $address)->with('success', 'Рассылка ' . $address . ' создана');
    }

    public function update(Request $request, string $list): RedirectResponse
    {
        $row = Maillist::query()->where('address', strtolower($list))->firstOrFail();
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'], 'description' => ['nullable', 'string', 'max:255'],
            'options' => ['sometimes', 'array'], 'moderators' => ['sometimes', 'array'], 'moderators.*' => ['email'], 'subject_prefix' => ['nullable', 'string', 'max:40'],
            'active' => ['sometimes', 'boolean'],
        ]);
        try {
            $this->mlmmj->update($row->address, $data['options'] ?? [], $data['name'] ?? null, $data['moderators'] ?? null, $data['subject_prefix'] ?? null);
        } catch (\RuntimeException $e) {
            return back()->with('error', 'Не сохранено: ' . mb_substr($e->getMessage(), 0, 300));
        }
        $upd = [];
        if (array_key_exists('description', $data)) {
            $upd['description'] = $data['description'] ?? '';
        }
        if (array_key_exists('active', $data)) {
            $upd['active'] = (bool) $data['active'];
        }
        if ($upd) {
            Maillist::query()->where('address', $row->address)->update($upd + ['modified' => now()]);
        }
        AdminAction::log('list.update', $row->address);

        return back()->with('success', 'Рассылка сохранена');
    }

    public function destroy(string $list): RedirectResponse
    {
        $row = Maillist::query()->where('address', strtolower($list))->firstOrFail();
        try {
            $this->mlmmj->delete($row->address);
        } catch (\RuntimeException $e) {
            return back()->with('error', 'Не удалена: ' . mb_substr($e->getMessage(), 0, 300));
        }
        AdminAction::log('list.delete', $row->address);

        return redirect('/maillists')->with('success', 'Рассылка ' . $row->address . ' удалена');
    }

    public function subscribe(Request $request, string $list): RedirectResponse
    {
        $data = $request->validate(['emails' => ['required', 'string', 'max:5000']]);
        $emails = array_values(array_unique(array_filter(array_map('strtolower', array_map('trim', preg_split('/[\s,;]+/', $data['emails']))), fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL))));
        if (! $emails) {
            return back()->with('error', 'Не нашли ни одного адреса');
        }
        try {
            $this->mlmmj->addSubscribers(strtolower($list), $emails);
        } catch (\RuntimeException $e) {
            return back()->with('error', mb_substr($e->getMessage(), 0, 300));
        }
        AdminAction::log('list.update', $list, 'добавлено ' . count($emails));

        return back()->with('success', 'Подписано: ' . count($emails));
    }

    public function unsubscribe(Request $request, string $list): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        try {
            $this->mlmmj->removeSubscribers(strtolower($list), [strtolower($data['email'])]);
        } catch (\RuntimeException $e) {
            return back()->with('error', mb_substr($e->getMessage(), 0, 300));
        }
        AdminAction::log('list.update', $list, 'отписан ' . $data['email']);

        return back()->with('success', 'Отписан: ' . $data['email']);
    }

    public function moderate(Request $request, string $list): RedirectResponse
    {
        $data = $request->validate(['id' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'], 'action' => ['required', Rule::in(['approve', 'reject'])]]);
        try {
            $data['action'] === 'approve' ? $this->mlmmj->approve(strtolower($list), $data['id']) : $this->mlmmj->reject(strtolower($list), $data['id']);
        } catch (\RuntimeException $e) {
            return back()->with('error', mb_substr($e->getMessage(), 0, 300));
        }
        AdminAction::log($data['action'] === 'approve' ? 'list.approve' : 'list.reject', $list, $data['id']);

        return back()->with('success', $data['action'] === 'approve' ? 'Письмо отправлено подписчикам' : 'Письмо отклонено');
    }
}
