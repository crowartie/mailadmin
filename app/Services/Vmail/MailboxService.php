<?php

namespace App\Services\Vmail;

use App\Models\Vmail\Forwarding;
use App\Models\Vmail\Mailbox;
use App\Models\Vmail\UsedQuota;
use App\Services\Dav\EmployeeBook;
use Illuminate\Support\Facades\DB;

/**
 * Операции над ящиками в схеме iRedMail.
 *
 * Здесь собраны все правила, нарушение которых даёт «ящик есть, а почта не ходит»:
 *  - обязательная строка self-delivery в forwardings;
 *  - maildir по раскладке iRedMail;
 *  - пароль в формате, который понимает Dovecot.
 */
class MailboxService
{
    public function __construct(private readonly EmployeeBook $addressBook)
    {
    }

    /**
     * Хеш пароля в формате Dovecot SSHA512: base64(sha512(пароль + соль) + соль).
     * Проверено против `doveadm pw -t` — вызывать внешний бинарник не нужно.
     */
    public function hashPassword(string $plain): string
    {
        $salt = random_bytes(8);

        return '{SSHA512}' . base64_encode(hash('sha512', $plain . $salt, true) . $salt);
    }

    /**
     * Путь Maildir по правилам iRedMail: домен, три первых символа логина
     * и каталог с меткой времени, чтобы новый ящик не сел на каталог удалённого.
     */
    public function buildMaildir(string $localPart, string $domain): string
    {
        $name = strtolower($localPart);
        $chars = str_split($name);

        $parts = [];
        for ($i = 0; $i < 3; $i++) {
            $parts[] = $chars[$i] ?? end($chars);
        }

        return sprintf(
            '%s/%s/%s-%s/',
            strtolower($domain),
            implode('/', $parts),
            $name,
            now()->format('Y.m.d.H.i.s')
        );
    }

    /**
     * @param  array<string,mixed>  $data
     */
    public function create(array $data): Mailbox
    {
        $username = strtolower($data['local_part'] . '@' . $data['domain']);

        $mailbox = DB::connection('vmail')->transaction(function () use ($data, $username) {
            $mailbox = new Mailbox();
            $mailbox->username = $username;
            $mailbox->password = $this->hashPassword($data['password']);
            $mailbox->domain = strtolower($data['domain']);
            $mailbox->maildir = $this->buildMaildir($data['local_part'], $data['domain']);
            $mailbox->storagebasedirectory = $data['storagebasedirectory'] ?? '/var/vmail';
            $mailbox->storagenode = $data['storagenode'] ?? 'vmail1';
            $mailbox->mailboxformat = 'maildir';
            $mailbox->mailboxfolder = 'Maildir';
            $mailbox->language = $data['language'] ?? 'ru_RU';
            $mailbox->created = now();
            $mailbox->modified = now();
            $mailbox->passwordlastchange = now();

            $this->fillEditable($mailbox, $data);
            $mailbox->save();

            // Без этой строки Postfix не считает адрес локальным получателем.
            $this->syncForwardings($mailbox, $data['forwardings'] ?? [], $data['keep_copy'] ?? true);
            $this->syncAliases($mailbox, $data['aliases'] ?? []);
            $this->syncAdminRights($mailbox, $data);

            return $mailbox;
        });
        // Карточка в общей книге «Сотрудники» — вместе с ящиком, но вне транзакции:
        // сбой книги не должен откатывать создание сотрудника.
        $this->addressBook->put($mailbox);

        return $mailbox;
    }

    /**
     * @param  array<string,mixed>  $data
     */
    public function update(Mailbox $mailbox, array $data): Mailbox
    {
        $mailbox = DB::connection('vmail')->transaction(function () use ($mailbox, $data) {
            if (filled($data['password'] ?? null)) {
                $mailbox->password = $this->hashPassword($data['password']);
                $mailbox->passwordlastchange = now();
            }

            $this->fillEditable($mailbox, $data);
            $mailbox->modified = now();
            $mailbox->save();

            $this->syncForwardings($mailbox, $data['forwardings'] ?? [], $data['keep_copy'] ?? true);
            $this->syncAliases($mailbox, $data['aliases'] ?? []);
            $this->syncAdminRights($mailbox, $data);

            return $mailbox;
        });
        $this->addressBook->put($mailbox);

        return $mailbox;
    }

    /**
     * Удаление по логике iRedMail: запись отправляется в deleted_mailboxes,
     * откуда её потом подбирает уборщик и удаляет файлы с диска.
     * Сами письма мы не трогаем — это делает почтовый сервер.
     */
    public function delete(Mailbox $mailbox, string $admin): void
    {
        DB::connection('vmail')->transaction(function () use ($mailbox, $admin) {
            $used = UsedQuota::find($mailbox->username);

            DB::connection('vmail')->table('deleted_mailboxes')->insert([
                'timestamp' => now(),
                'username' => $mailbox->username,
                'domain' => $mailbox->domain,
                'maildir' => $mailbox->maildir_path,
                'bytes' => $used?->bytes ?? 0,
                'messages' => $used?->messages ?? 0,
                'admin' => $admin,
                'delete_date' => now()->toDateString(),
            ]);

            Forwarding::where('address', $mailbox->username)
                ->orWhere('forwarding', $mailbox->username)
                ->delete();

            UsedQuota::where('username', $mailbox->username)->delete();

            DB::connection('vmail')->table('domain_admins')
                ->where('username', $mailbox->username)
                ->delete();

            $mailbox->delete();
        });

        $this->addressBook->remove($mailbox);
        try {
            app(\App\Services\Dav\DavStore::class)->removeUser($mailbox->username);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('DAV-данные ящика не удалены', ['user' => $mailbox->username, 'error' => $e->getMessage()]);
        }
        // Наши таблицы: профиль, пароли приложений, веб-сеансы, привязка к подразделению.
        \App\Models\EmployeeProfile::query()->where('username', $mailbox->username)->delete();
        \App\Models\AppPassword::query()->where('username', $mailbox->username)->delete();
        \App\Models\MailSession::query()->where('user', $mailbox->username)->delete();
        \Illuminate\Support\Facades\Cache::forget('nav.counts');
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function fillEditable(Mailbox $mailbox, array $data): void
    {
        $mailbox->name = $data['name'] ?? '';
        $mailbox->quota = (int) ($data['quota'] ?? 0);
        $mailbox->active = (bool) ($data['active'] ?? true);

        foreach (['first_name', 'last_name', 'telephone', 'mobile', 'department', 'rank', 'employeeid', 'recovery_email'] as $field) {
            $mailbox->{$field} = $data[$field] ?? '';
        }

        $mailbox->isadmin = (bool) ($data['isadmin'] ?? false);
        $mailbox->isglobaladmin = (bool) ($data['isglobaladmin'] ?? false);

        // Флаги служб: Dovecot читает их напрямую при аутентификации. Если форма их не прислала —
        // новому ящику включаем всё (иначе он не примет ни письмо, ни вход), у существующего не трогаем.
        if (array_key_exists('services', $data)) {
            foreach (self::serviceFlags() as $flag) {
                $mailbox->{$flag} = (bool) ($data['services'][$flag] ?? false);
            }
            // Эти три в схеме — char(1) со значениями y/n, а не tinyint.
            foreach (self::sogoFlags() as $flag) {
                $mailbox->{$flag} = ($data['services'][$flag] ?? false) ? 'y' : 'n';
            }
        } elseif (! $mailbox->exists) {
            foreach (self::serviceFlags() as $flag) {
                $mailbox->{$flag} = true;
            }
            foreach (self::sogoFlags() as $flag) {
                $mailbox->{$flag} = 'y';
            }
        }
    }

    /**
     * Пересылки: полностью пересобираем набор строк для адреса.
     *
     * @param  array<int,string>  $targets
     */
    private function syncForwardings(Mailbox $mailbox, array $targets, bool $keepCopy): void
    {
        Forwarding::where('address', $mailbox->username)
            ->where('is_alias', 0)
            ->where('is_list', 0)
            ->where('is_maillist', 0)
            ->delete();

        // Копия в собственном ящике — та самая строка «сам себе».
        if ($keepCopy || empty($targets)) {
            Forwarding::create([
                'address' => $mailbox->username,
                'forwarding' => $mailbox->username,
                'domain' => $mailbox->domain,
                'dest_domain' => $mailbox->domain,
                'is_forwarding' => false,
                'is_alias' => false,
                'is_list' => false,
                'is_maillist' => false,
                'active' => $mailbox->active,
            ]);
        }

        foreach (array_filter(array_unique($targets)) as $target) {
            $target = strtolower(trim($target));

            if ($target === $mailbox->username) {
                continue;
            }

            Forwarding::create([
                'address' => $mailbox->username,
                'forwarding' => $target,
                'domain' => $mailbox->domain,
                'dest_domain' => substr(strrchr($target, '@') ?: '@', 1),
                'is_forwarding' => true,
                'is_alias' => false,
                'is_list' => false,
                'is_maillist' => false,
                'active' => true,
            ]);
        }
    }

    /**
     * Дополнительные адреса ящика (в Kerio — вкладка «Адрес эл. почты»).
     * В схеме это строки forwardings, где получатель — сам ящик, а адрес — псевдоним.
     *
     * @param  array<int,string>  $aliases
     */
    private function syncAliases(Mailbox $mailbox, array $aliases): void
    {
        Forwarding::where('forwarding', $mailbox->username)
            ->where('is_alias', 1)
            ->delete();

        foreach (array_filter(array_unique($aliases)) as $alias) {
            $alias = strtolower(trim($alias));

            if ($alias === $mailbox->username) {
                continue;
            }

            Forwarding::create([
                'address' => $alias,
                'forwarding' => $mailbox->username,
                'domain' => substr(strrchr($alias, '@') ?: '@', 1),
                'dest_domain' => $mailbox->domain,
                'is_forwarding' => false,
                'is_alias' => true,
                'is_list' => false,
                'is_maillist' => false,
                'active' => true,
            ]);
        }
    }

    /**
     * Права администратора: флаг в mailbox плюс строка в domain_admins,
     * по которой iRedAdmin определяет, какими доменами человек управляет.
     *
     * @param  array<string,mixed>  $data
     */
    private function syncAdminRights(Mailbox $mailbox, array $data): void
    {
        $table = DB::connection('vmail')->table('domain_admins');
        $table->where('username', $mailbox->username)->delete();

        if (! $mailbox->isadmin && ! $mailbox->isglobaladmin) {
            return;
        }

        $table->insert([
            'username' => $mailbox->username,
            // ALL — признак глобального администратора в схеме iRedMail.
            'domain' => $mailbox->isglobaladmin ? 'ALL' : $mailbox->domain,
            'created' => now(),
            'modified' => now(),
            'active' => 1,
        ]);
    }

    /** @return array<int,string> */
    public static function serviceFlags(): array
    {
        return [
            'enablesmtp', 'enablesmtpsecured',
            'enablepop3', 'enablepop3secured', 'enablepop3tls',
            'enableimap', 'enableimapsecured', 'enableimaptls',
            'enablemanagesieve', 'enablemanagesievesecured',
            'enablesieve', 'enablesievesecured', 'enablesievetls',
            'enabledeliver', 'enablelda', 'enablelmtp', 'enableinternal',
            'enabledoveadm', 'enablelib-storage', 'enablequota-status',
            'enableindexer-worker', 'enabledsync', 'enablesogo',
        ];
    }

    /** @return array<int,string> */
    public static function sogoFlags(): array
    {
        return ['enablesogowebmail', 'enablesogocalendar', 'enablesogoactivesync'];
    }
}
