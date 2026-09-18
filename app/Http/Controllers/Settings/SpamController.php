<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\AdminAction;
use App\Models\AppSetting;
use App\Models\Vmail\Domain;
use App\Services\Server\AmavisConfig;
use App\Services\Server\Quarantine;
use App\Services\Server\WbList;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Антиспам, карантин, списки «свой / чужой» и отправка с чужих серверов.
 *
 * Заявки сотрудников («это спам», «это рассылка», «это не спам») попадают сюда же:
 * администратор либо возводит их в общее правило, либо отклоняет.
 */
class SpamController extends Controller
{
    public function __construct(
        private readonly AmavisConfig $amavis,
        private readonly Quarantine $quarantine,
        private readonly WbList $wblist,
    ) {
    }

    public function addExternalSender(Request $request, \App\Services\Server\ExternalSenders $ext): RedirectResponse
    {
        $data = $request->validate([
            'address' => ['required', 'email:rfc', 'max:255'],
            'provider' => ['required', Rule::in(array_keys(\App\Services\Server\ExternalSenders::PROVIDERS))],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $address = strtolower($data['address']);
        $domain = substr(strrchr($address, '@'), 1);
        if (! Domain::query()->where('domain', $domain)->exists()) {
            return back()->with('error', 'Адрес должен быть в нашем домене — для чужих доменов исключение не нужно');
        }
        \App\Models\ExternalSender::updateOrCreate(['address' => $address], ['provider' => $data['provider'], 'note' => $data['note'] ?? null, 'created_by' => auth()->user()?->email]);
        AdminAction::log('settings.update', 'отправка с чужого сервера разрешена', $address . ' · ' . $data['provider']);
        try {
            $ext->apply();
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Разрешено: ' . $address);
    }

    public function removeExternalSender(\App\Models\ExternalSender $sender, \App\Services\Server\ExternalSenders $ext): RedirectResponse
    {
        $sender->delete();
        AdminAction::log('settings.update', 'отправка с чужого сервера запрещена', $sender->address);
        try {
            $ext->apply();
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Убрано: ' . $sender->address);
    }

    public function refreshExternalSenders(\App\Services\Server\ExternalSenders $ext): RedirectResponse
    {
        try {
            $ext->apply(fresh: true);
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Диапазоны серверов обновлены');
    }

    public function saveSenders(Request $request): RedirectResponse
    {
        $data = $request->validate(['ham_global' => ['boolean'], 'spam_votes' => ['required', 'integer', 'min:1', 'max:50'], 'lists_votes' => ['required', 'integer', 'min:1', 'max:50']]);
        $data['ham_global'] = (bool) ($data['ham_global'] ?? false);
        AppSetting::put('senders', $data);
        AdminAction::log('settings.update', 'решения сотрудников');

        return back()->with('success', 'Сохранено');
    }

    /** Сделать личную отметку общим правилом — не дожидаясь голосов. */
    public function promoteSender(Request $request, \App\Services\Mail\SenderRules $senders): RedirectResponse
    {
        $data = $request->validate(['kind' => ['required', 'in:ham,spam,lists'], 'match' => ['required', 'in:address,domain'], 'value' => ['required', 'string', 'max:255']]);
        try {
            $senders->approve($data['kind'], $data['match'], $data['value'], auth()->user()?->email);
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
        AdminAction::log('settings.update', 'заявка по отправителю утверждена', $data['kind'] . ' ' . $data['value']);

        return back()->with('success', ($data['kind'] === 'ham' ? 'Добавлено в общий белый список: ' : 'Правило стало общим: ') . $data['value']);
    }

    public function dismissSender(Request $request): RedirectResponse
    {
        $data = $request->validate(['kind' => ['required', 'in:ham,spam,lists'], 'value' => ['required', 'string', 'max:255']]);
        \App\Services\Mail\SenderRules::dismiss($data['kind'], $data['value']);
        AdminAction::log('settings.update', 'заявка по отправителю отклонена', $data['kind'] . ' ' . $data['value']);

        return back()->with('success', 'Заявка отклонена: ' . $data['value']);
    }

    public function demoteSender(\App\Models\SenderRule $rule, \App\Services\Mail\SenderRules $senders): RedirectResponse
    {
        try {
            $senders->demote($rule);
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
        AdminAction::log('settings.update', 'общее правило снято', $rule->value);

        return back()->with('success', 'Общее правило снято: ' . $rule->value);
    }

    public function saveSpam(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'tag2' => ['required', 'numeric', 'min:1', 'max:20'], 'kill' => ['required', 'numeric', 'min:1', 'max:30'], 'cutoff' => ['required', 'numeric', 'min:1', 'max:50'],
            'virus' => ['boolean'], 'greylist' => ['boolean'],
        ]);
        if ($data['kill'] < $data['tag2']) {
            return back()->with('error', 'Порог «блокировать» не может быть ниже порога «помечать»');
        }
        try {
            $cur = $this->amavis->current();
            $this->amavis->setLevels(['tag2' => $data['tag2'], 'kill' => $data['kill'], 'cutoff' => $data['cutoff']]);
            if (($data['virus'] ?? false) !== $cur['virus']) {
                $this->amavis->setVirus((bool) ($data['virus'] ?? false));
            }
            if (($data['greylist'] ?? false) !== $cur['greylist']) {
                $this->amavis->setGreylist((bool) ($data['greylist'] ?? false));
            }
            $this->amavis->apply();
        } catch (\RuntimeException $e) {
            return back()->with('error', 'Не применилось: ' . $e->getMessage());
        }
        AdminAction::log('settings.update', 'антиспам', 'помечать от ' . $data['tag2'] . ', блокировать от ' . $data['kill']);

        return back()->with('success', 'Настройки антиспама применены');
    }

    public function addWblist(Request $request): RedirectResponse
    {
        $data = $request->validate(['pattern' => ['required', 'string', 'max:120'], 'wb' => ['required', Rule::in(['W', 'B'])], 'note' => ['nullable', 'string', 'max:120']]);
        try {
            $this->wblist->add($data['pattern'], $data['wb'], $data['note'] ?? '');
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }
        AdminAction::log('wblist.add', $data['pattern'], $data['wb'] === 'W' ? 'белый' : 'чёрный');

        return back()->with('success', ($data['wb'] === 'W' ? 'В белый список: ' : 'В чёрный список: ') . $data['pattern']);
    }

    public function removeWblist(int $id): RedirectResponse
    {
        $this->wblist->remove($id);
        AdminAction::log('wblist.delete', '#' . $id);

        return back()->with('success', 'Убрано из списка');
    }

    public function saveQuarantine(Request $request): RedirectResponse
    {
        $data = $request->validate(['digest' => ['boolean'], 'digest_time' => ['required', 'date_format:H:i'], 'keep_days' => ['required', 'integer', 'min:1', 'max:365']]);
        AppSetting::put('quarantine', $data + ['digest' => false]);
        AdminAction::log('settings.update', 'карантин');

        return back()->with('success', 'Правила карантина сохранены');
    }

    public function releaseQuarantine(Request $request, string $id): RedirectResponse
    {
        $secret = $request->validate(['secret' => ['required', 'string', 'max:32']])['secret'];
        try {
            $this->quarantine->release($id, $secret);
        } catch (\RuntimeException $e) {
            return back()->with('error', 'Не выпущено: ' . $e->getMessage());
        }
        AdminAction::log('quarantine.release', $id);

        return back()->with('success', 'Письмо доставлено получателю');
    }

    public function deleteQuarantine(string $id): RedirectResponse
    {
        $this->quarantine->delete($id);
        AdminAction::log('quarantine.delete', $id);

        return back()->with('success', 'Удалено из карантина');
    }
}
