<?php

namespace App\Http\Controllers;

use App\Models\AdminAction;
use App\Models\AppSetting;
use App\Models\EmployeeProfile;
use App\Models\Unit;
use App\Models\Vmail\Domain;
use App\Models\Vmail\Mailbox;
use App\Services\Mail\ImapSession;
use App\Services\Units\UnitService;
use App\Services\Vmail\MailboxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Импорт сотрудников из CSV (выгрузка из 1С, Excel, AD): разбор файла с угадыванием
 * кодировки и колонок → предпросмотр → создание ящиков, профилей и подразделений.
 */
class ImportController extends Controller
{
    /** Заголовки, по которым узнаём колонки. */
    private const GUESS = [
        'name' => ['фио', 'имя', 'сотрудник', 'полное имя', 'name', 'full name', 'displayname'],
        'last_name' => ['фамилия', 'last name', 'surname', 'sn'],
        'first_name' => ['имя', 'first name', 'givenname'],
        'middle_name' => ['отчество', 'middle name', 'patronymic'],
        'login' => ['логин', 'login', 'адрес', 'email', 'e-mail', 'почта', 'mail', 'samaccountname', 'username'],
        'unit' => ['подразделение', 'отдел', 'department', 'unit', 'служба'],
        'title' => ['должность', 'title', 'position', 'rank'],
        'phone' => ['телефон', 'phone', 'тел', 'telephone'],
        'mobile' => ['мобильный', 'mobile', 'сотовый'],
        'personal_email' => ['личная почта', 'личный email', 'personal', 'домашняя почта'],
        'password' => ['пароль', 'password'],
    ];

    public function __construct(private readonly MailboxService $mailboxes, private readonly UnitService $units)
    {
    }

    /** Разобрать файл: кодировка, разделитель, заголовки, догадки о колонках, первые строки. */
    public function preview(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:5120']]);
        $raw = (string) file_get_contents($request->file('file')->getRealPath());
        $encoding = 'UTF-8';
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        } elseif (! mb_check_encoding($raw, 'UTF-8')) {
            $encoding = 'Windows-1251';
            $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1251');
        }
        $lines = preg_split('/\r\n|\r|\n/', trim($raw));
        $first = $lines[0] ?? '';
        $delimiter = substr_count($first, ';') >= substr_count($first, ',') ? (substr_count($first, "\t") > substr_count($first, ';') ? "\t" : ';') : ',';
        $rows = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $rows[] = array_map(fn ($c) => trim((string) $c, " \t\"'"), str_getcsv($line, $delimiter, '"', '\\'));
        }
        if (count($rows) < 2) {
            return response()->json(['message' => 'В файле нет данных — нужна строка заголовков и хотя бы одна строка сотрудника'], 422);
        }
        $headers = array_map(fn ($h) => mb_strtolower(trim($h)), $rows[0]);
        $mapping = [];
        foreach (self::GUESS as $field => $names) {
            foreach ($headers as $i => $h) {
                if ($h !== '' && in_array($h, $names, true) && ! in_array($i, $mapping, true)) {
                    $mapping[$field] = $i;
                    break;
                }
            }
        }
        // «Имя» одновременно подходит под name и first_name: если нашлась фамилия — это first_name.
        if (isset($mapping['name'], $mapping['last_name']) && ! isset($mapping['first_name']) && $headers[$mapping['name']] === 'имя') {
            $mapping['first_name'] = $mapping['name'];
            unset($mapping['name']);
        }
        $body = array_slice($rows, 1);

        return response()->json([
            'encoding' => $encoding, 'delimiter' => $delimiter === "\t" ? 'tab' : $delimiter,
            'headers' => $rows[0], 'mapping' => $mapping, 'rows' => array_slice($body, 0, 500), 'total' => count($body),
            'fields' => ['name' => 'ФИО', 'last_name' => 'Фамилия', 'first_name' => 'Имя', 'middle_name' => 'Отчество', 'login' => 'Логин / адрес', 'unit' => 'Подразделение', 'title' => 'Должность', 'phone' => 'Телефон', 'mobile' => 'Мобильный', 'personal_email' => 'Личная почта', 'password' => 'Пароль'],
        ]);
    }

    /** Проверить строки с выбранным сопоставлением: что будет создано, что обновлено, что пропущено. */
    public function check(Request $request): JsonResponse
    {
        $data = $request->validate(['rows' => ['required', 'array', 'max:2000'], 'mapping' => ['required', 'array'], 'domain' => ['required', 'string']]);
        $out = [];
        $seen = [];
        foreach ($data['rows'] as $i => $row) {
            $rec = $this->record($row, $data['mapping'], $data['domain']);
            $rec['n'] = $i + 1;
            if ($rec['name'] === '') {
                $rec += ['status' => 'skip', 'why' => 'нет имени — строка пропущена'];
            } elseif ($rec['login'] === '') {
                $rec += ['status' => 'skip', 'why' => 'не удалось составить логин'];
            } elseif (isset($seen[$rec['username']])) {
                $rec += ['status' => 'skip', 'why' => 'дубликат строки ' . $seen[$rec['username']]];
            } elseif (Mailbox::query()->where('username', $rec['username'])->exists()) {
                $rec += ['status' => 'update', 'why' => 'уже есть — обновить отдел и должность'];
            } else {
                $rec += ['status' => 'create', 'why' => 'будет создан'];
            }
            $seen[$rec['username']] = $rec['n'];
            $out[] = $rec;
        }

        return response()->json(['rows' => $out]);
    }

    /** Создать/обновить. */
    public function run(Request $request): JsonResponse
    {
        $data = $request->validate([
            'rows' => ['required', 'array', 'max:2000'], 'mapping' => ['required', 'array'], 'domain' => ['required', 'string'],
            'send_passwords' => ['boolean'], 'create_units' => ['boolean'], 'quota' => ['nullable', 'integer', 'min:0'],
        ]);
        abort_unless(Domain::query()->where('domain', $data['domain'])->exists(), 422, 'Домен не найден');
        $quota = $data['quota'] ?? (int) (AppSetting::group('limits')['default_quota_mb'] ?? 2048);
        $created = $updated = $skipped = $mailed = 0;
        $errors = [];
        $passwords = [];
        $seen = [];
        foreach ($data['rows'] as $i => $row) {
            $rec = $this->record($row, $data['mapping'], $data['domain']);
            if ($rec['name'] === '' || $rec['login'] === '' || isset($seen[$rec['username']])) {
                $skipped++;
                continue;
            }
            $seen[$rec['username']] = true;
            try {
                $unitId = null;
                if ($rec['unit'] !== '' && ($data['create_units'] ?? true)) {
                    $unitId = $this->unitByName($rec['unit'])->id;
                } elseif ($rec['unit'] !== '') {
                    $unitId = Unit::query()->where('name', $rec['unit'])->value('id');
                }
                $existing = Mailbox::query()->find($rec['username']);
                if ($existing) {
                    if ($rec['title'] !== '') {
                        $existing->rank = $rec['title'];
                    }
                    if ($rec['unit'] !== '') {
                        $existing->department = $rec['unit'];
                    }
                    $existing->save();
                    $updated++;
                } else {
                    $password = $rec['password'] !== '' ? $rec['password'] : Str::password(12, true, true, false);
                    $this->mailboxes->create([
                        'local_part' => $rec['login'], 'domain' => $data['domain'], 'password' => $password, 'name' => $rec['name'], 'quota' => $quota, 'active' => true,
                        'first_name' => $rec['first_name'], 'last_name' => $rec['last_name'], 'telephone' => $rec['phone'], 'mobile' => $rec['mobile'],
                        'department' => $rec['unit'], 'rank' => $rec['title'], 'recovery_email' => $rec['personal_email'],
                        'services' => $this->services(),
                    ]);
                    $created++;
                    $passwords[$rec['username']] = $password;
                    if (($data['send_passwords'] ?? false) && $rec['personal_email'] !== '' && $this->sendPassword($rec, $password)) {
                        $mailed++;
                    }
                }
                $profile = EmployeeProfile::for($rec['username']);
                $profile->fill(['middle_name' => $rec['middle_name'] ?: $profile->middle_name, 'title' => $rec['title'] ?: $profile->title, 'phone' => $rec['phone'] ?: $profile->phone, 'mobile' => $rec['mobile'] ?: $profile->mobile, 'personal_email' => $rec['personal_email'] ?: $profile->personal_email]);
                $profile->save();
                if ($unitId && $profile->unit_id !== $unitId) {
                    $this->units->move($rec['username'], $unitId);
                }
            } catch (\Throwable $e) {
                $errors[] = 'Строка ' . ($i + 1) . ' (' . $rec['username'] . '): ' . mb_substr($e->getMessage(), 0, 160);
            }
        }
        AdminAction::log('employee.import', $data['domain'], "создано {$created}, обновлено {$updated}, пропущено {$skipped}");

        return response()->json(['created' => $created, 'updated' => $updated, 'skipped' => $skipped, 'mailed' => $mailed, 'errors' => $errors, 'passwords' => ($data['send_passwords'] ?? false) ? [] : $passwords]);
    }

    /** @return array<string,string> */
    private function record(array $row, array $mapping, string $domain): array
    {
        $get = fn (string $f) => isset($mapping[$f]) && $mapping[$f] !== '' && $mapping[$f] !== null ? trim((string) ($row[(int) $mapping[$f]] ?? '')) : '';
        $first = $get('first_name');
        $last = $get('last_name');
        $middle = $get('middle_name');
        $name = $get('name') ?: trim(implode(' ', array_filter([$last, $first, $middle])));
        if ($first === '' && $last === '' && $name !== '') {
            $parts = preg_split('/\s+/', $name);
            $last = $parts[0] ?? '';
            $first = $parts[1] ?? '';
            $middle = $middle !== '' ? $middle : implode(' ', array_slice($parts, 2));
        }
        $login = mb_strtolower($get('login'));
        if (str_contains($login, '@')) {
            $login = explode('@', $login)[0];
        }
        if ($login === '' && $name !== '') {
            // фамилия + инициалы: Иванов Пётр Сергеевич → ivanovps
            $parts = preg_split('/\s+/', $name);
            $login = self::translit($parts[0] ?? '') . implode('', array_map(fn ($p) => mb_substr(self::translit($p), 0, 1), array_slice($parts, 1, 2)));
        }
        $login = preg_replace('/[^a-z0-9._-]/', '', self::translit($login));
        $login = trim($login, '._-');
        $personal = mb_strtolower($get('personal_email'));

        return [
            'name' => $name, 'first_name' => $first, 'last_name' => $last, 'middle_name' => $middle, 'login' => $login, 'username' => $login !== '' ? $login . '@' . $domain : '',
            'unit' => $get('unit'), 'title' => $get('title'), 'phone' => $get('phone'), 'mobile' => $get('mobile'),
            'personal_email' => filter_var($personal, FILTER_VALIDATE_EMAIL) ? $personal : '', 'password' => $get('password'),
        ];
    }

    private function unitByName(string $name): Unit
    {
        $unit = Unit::query()->where('name', $name)->first();
        if ($unit) {
            return $unit;
        }

        return $this->units->create(['name' => $name]);
    }

    /** @return array<string,bool> */
    private function services(): array
    {
        $flags = [];
        foreach (MailboxService::serviceFlags() as $f) {
            $flags[$f] = true;
        }

        return $flags;
    }

    private function sendPassword(array $rec, string $password): bool
    {
        $domain = config('areas.default_domain');
        try {
            (new Mailer(ImapSession::smtpLocal()))->send((new Email())
                ->from(new Address('noreply@' . $domain, 'Почта ' . $domain))
                ->to(new Address($rec['personal_email'], $rec['name']))
                ->subject('Ваша рабочая почта ' . $rec['username'])
                ->text("Здравствуйте, {$rec['name']}!\n\nДля вас создан рабочий почтовый ящик.\n\nАдрес: {$rec['username']}\nПароль: {$password}\n\nВеб-почта: https://mail.{$domain}\nНастройки для телефона и почтовой программы: сервер mail.{$domain}, IMAP 993 (SSL), SMTP 587 (STARTTLS), логин — полный адрес.\n\nПароль выдаёт и меняет администратор — при необходимости обращайтесь к нему."));

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function translit(string $s): string
    {
        $map = ['а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'e', 'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sch', 'ъ' => '', 'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya'];

        return strtr(mb_strtolower($s), $map);
    }
}
