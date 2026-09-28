<?php

namespace App\Services\Mail;

use App\Models\Vmail\Mailbox;
use Illuminate\Support\Facades\Cache;

/**
 * Имена коллег по адресам.
 *
 * Часть сотрудников настроила почтовую программу без отображаемого имени, и в списке
 * писем такие строки выглядели как «popovav@innotec.su» — в разборе это каждое третье
 * письмо. Имена всех ящиков и так лежат в общей книге: подставляем их там, где
 * отправитель не подписался сам. Сам адрес остаётся в подсказке при наведении.
 */
final class Directory
{
    /**
     * Адрес → имя по всем ящикам сервера. Список меняется редко (завели сотрудника,
     * поправили ФИО), поэтому держим его в кэше десять минут.
     *
     * @return array<string,string>
     */
    public static function names(): array
    {
        try {
            return Cache::remember('mail.directory', 600, function () {
                return Mailbox::query()
                    ->pluck('name', 'username')
                    ->map(fn ($n) => trim((string) $n))
                    ->filter()
                    ->mapWithKeys(fn ($n, $mail) => [strtolower((string) $mail) => $n])
                    ->all();
            });
        } catch (\Throwable) {
            return [];   // база недоступна — покажем адрес, как и раньше
        }
    }

    /**
     * Адреса своего домена, которых в подсказках и истории быть не должно: ящики выключены, без IMAP
     * или с закрытым входом (уволенные). Раньше они подсказывались, как и действующие сотрудники.
     *
     * @return array<string,true>  адрес в нижнем регистре → true
     */
    public static function hidden(): array
    {
        try {
            return Cache::remember('mail.directory.hidden', 60, function () {
                $list = Mailbox::query()->where(fn ($q) => $q->where('active', 0)->orWhere('enableimap', 0))->pluck('username')->all();
                $list = array_merge($list, \App\Models\EmployeeProfile::blockedUsernames());

                return array_fill_keys(array_map('strtolower', $list), true);
            });
        } catch (\Throwable) {
            return [];
        }
    }

    /** Имя коллеги по адресу или null, если это не наш ящик. */
    public static function nameFor(?string $mail): ?string
    {
        $mail = strtolower(trim((string) $mail));

        return $mail === '' ? null : (self::names()[$mail] ?? null);
    }

    /**
     * Дополнить пару «имя + адрес»: если имени нет (Mime::address подставляет туда адрес),
     * взять имя из общей книги.
     *
     * @param  array{name:string,mail:string}  $a
     * @return array{name:string,mail:string}
     */
    public static function fill(array $a): array
    {
        if (($a['name'] ?? '') !== ($a['mail'] ?? '')) {
            return $a;   // отправитель подписался сам — его имя и показываем
        }
        $name = self::nameFor($a['mail'] ?? '');

        return $name === null ? $a : ['name' => $name, 'mail' => $a['mail']];
    }

    /** Забыть список — после правки ФИО или заведения ящика. */
    public static function forget(): void
    {
        Cache::forget('mail.directory');
    }
}
