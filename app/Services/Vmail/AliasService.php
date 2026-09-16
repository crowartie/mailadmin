<?php

namespace App\Services\Vmail;

use App\Models\Vmail\Alias;
use App\Models\Vmail\Forwarding;
use Illuminate\Support\Facades\DB;

/**
 * Псевдонимы в схеме iRedMail: строка в `alias` — это имя и статус,
 * а адреса доставки лежат в `forwardings` с флагом is_alias.
 */
class AliasService
{
    /**
     * @param  array{address:string,name?:string,targets:array<int,string>,active?:bool}  $data
     */
    public function create(array $data): Alias
    {
        $address = strtolower(trim($data['address']));

        return DB::connection('vmail')->transaction(function () use ($data, $address) {
            $alias = new Alias();
            $alias->address = $address;
            $alias->domain = substr(strrchr($address, '@'), 1);
            $alias->name = $data['name'] ?? '';
            $alias->accesspolicy = 'public';
            $alias->active = (bool) ($data['active'] ?? true);
            $alias->created = now();
            $alias->modified = now();
            $alias->save();

            $this->syncTargets($alias, $data['targets']);

            return $alias;
        });
    }

    /**
     * @param  array{name?:string,targets:array<int,string>,active?:bool}  $data
     */
    public function update(Alias $alias, array $data): Alias
    {
        return DB::connection('vmail')->transaction(function () use ($alias, $data) {
            $alias->name = $data['name'] ?? '';
            $alias->active = (bool) ($data['active'] ?? true);
            $alias->modified = now();
            $alias->save();

            $this->syncTargets($alias, $data['targets']);

            return $alias;
        });
    }

    public function delete(Alias $alias): void
    {
        DB::connection('vmail')->transaction(function () use ($alias) {
            Forwarding::where('address', $alias->address)->where('is_alias', 1)->delete();
            $alias->delete();
        });
    }

    /**
     * @param  array<int,string>  $targets
     */
    private function syncTargets(Alias $alias, array $targets): void
    {
        Forwarding::where('address', $alias->address)->where('is_alias', 1)->delete();

        foreach (array_unique(array_filter(array_map(fn ($t) => strtolower(trim($t)), $targets))) as $target) {
            if ($target === $alias->address) {
                continue; // псевдоним сам на себя — петля
            }

            Forwarding::create([
                'address' => $alias->address,
                'forwarding' => $target,
                'domain' => $alias->domain,
                'dest_domain' => substr(strrchr($target, '@') ?: '@', 1),
                'is_forwarding' => false,
                'is_alias' => true,
                'is_list' => false,
                'is_maillist' => false,
                'active' => $alias->active,
            ]);
        }
    }
}
