<?php

namespace App\Services\Vmail;

use App\Models\Vmail\Alias;
use App\Models\Vmail\Domain;
use App\Models\Vmail\Forwarding;
use App\Models\Vmail\Mailbox;
use Illuminate\Support\Facades\DB;

/**
 * «Проверить адрес»: куда в итоге доставится письмо.
 *
 * Идём по тем же таблицам, что и Postfix: алиас домена → псевдоним →
 * пересылки → рассылка → ящик. Петли и слишком длинные цепочки останавливаем,
 * а не зависаем на них — это как раз то, что администратору и нужно увидеть.
 */
class AddressResolver
{
    private const MAX_DEPTH = 6;

    /**
     * @return array{address:string,kind:string,label:string,note:?string,next:array<int,mixed>}
     */
    public function trace(string $address, array &$seen = [], int $depth = 0): array
    {
        $address = strtolower(trim($address));

        if ($depth > self::MAX_DEPTH) {
            return $this->node($address, 'loop', 'слишком длинная цепочка', 'обработка остановлена');
        }
        if (isset($seen[$address])) {
            return $this->node($address, 'loop', 'петля', 'адрес уже встречался выше');
        }
        $seen[$address] = true;

        [$local, $domain] = array_pad(explode('@', $address, 2), 2, '');

        // Алиас домена: ired.innotec.su → innotec.su.
        $canonical = DB::connection('vmail')->table('alias_domain')
            ->where('alias_domain', $domain)->value('target_domain');
        if ($canonical && $canonical !== $domain) {
            $node = $this->node($address, 'domain-alias', 'алиас домена', "→ {$canonical}");
            $node['next'][] = $this->trace("{$local}@{$canonical}", $seen, $depth + 1);

            return $node;
        }

        $isLocalDomain = Domain::where('domain', $domain)->exists();
        if (! $isLocalDomain) {
            return $this->node($address, 'external', 'внешний адрес', 'уйдёт через SMTP наружу');
        }

        // Ящик — конечная точка, но у него могут быть пересылки.
        if ($mailbox = Mailbox::with('usedQuota')->find($address)) {
            $used = $mailbox->usedQuota?->bytes ?? 0;
            $node = $this->node(
                $address,
                $mailbox->active ? 'mailbox' : 'mailbox-off',
                $mailbox->active ? 'ящик' : 'ящик заблокирован',
                trim(($mailbox->name ? $mailbox->name . ' · ' : '') . $this->size($used))
            );

            $forwards = Forwarding::where('address', $address)->where('is_forwarding', 1)->pluck('forwarding');
            $keepsCopy = Forwarding::where('address', $address)->whereColumn('address', 'forwarding')->exists();

            if ($forwards->isNotEmpty() && ! $keepsCopy) {
                $node['note'] .= ' · копия в ящике НЕ остаётся';
            }
            foreach ($forwards as $target) {
                $node['next'][] = $this->trace($target, $seen, $depth + 1);
            }

            return $node;
        }

        // Рассылка.
        $list = DB::connection('vmail')->table('maillists')->where('address', $address)->first();
        if ($list) {
            $members = Forwarding::where('address', $address)
                ->where(fn ($q) => $q->where('is_list', 1)->orWhere('is_maillist', 1))
                ->pluck('forwarding');
            $node = $this->node($address, 'list', 'рассылка', ($list->name ?: '') . ' · ' . $members->count() . ' подписчиков');
            foreach ($members->take(20) as $member) {
                $node['next'][] = $this->trace($member, $seen, $depth + 1);
            }

            return $node;
        }

        // Псевдоним (именованный или дополнительный адрес сотрудника).
        $targets = Forwarding::where('address', $address)->where('is_alias', 1)->pluck('forwarding');
        if ($targets->isNotEmpty()) {
            $alias = Alias::find($address);
            $node = $this->node(
                $address,
                'alias',
                $alias ? 'псевдоним' : 'дополнительный адрес',
                $alias?->name ?: null
            );
            foreach ($targets as $target) {
                $node['next'][] = $this->trace($target, $seen, $depth + 1);
            }

            return $node;
        }

        return $this->node($address, 'missing', 'адреса нет', 'отправитель получит отказ 550');
    }

    /** @return array{address:string,kind:string,label:string,note:?string,next:array} */
    private function node(string $address, string $kind, string $label, ?string $note): array
    {
        return ['address' => $address, 'kind' => $kind, 'label' => $label, 'note' => $note, 'next' => []];
    }

    private function size(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return sprintf('%.1f ГБ', $bytes / 1073741824);
        }

        return sprintf('%d МБ', round($bytes / 1048576));
    }
}
