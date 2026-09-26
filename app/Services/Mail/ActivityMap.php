<?php

namespace App\Services\Mail;

/**
 * Что считать действием сотрудника и как его назвать.
 *
 * Чистые функции без запроса и базы — их проверяют тесты. Опросы (status), подсказки при
 * наборе адреса и прочий фон не записываются: журнал про то, что человек сделал сам.
 * Из запроса берутся только числа и признаки: сколько писем, какая папка по роли,
 * по какому полю искал — но не что.
 */
final class ActivityMap
{
    /** Подписи действий для страницы «Активность». */
    public const LABELS = [
        'page.mail' => 'Открыл почту', 'page.folder' => 'Открыл папку', 'page.settings' => 'Открыл настройки',
        'page.feedback' => 'Открыл обращения', 'page.quarantine' => 'Открыл карантин', 'print' => 'Печать письма',
        'page.cloud' => 'Открыл облако', 'cloud.open' => 'Облако: открыл папку', 'cloud.mkdir' => 'Облако: создал папку',
        'cloud.rename' => 'Облако: переименовал', 'cloud.move' => 'Облако: перенёс', 'cloud.delete' => 'Облако: удалил',
        'cloud.restore' => 'Облако: вернул из корзины', 'cloud.purge' => 'Облако: удалил навсегда', 'cloud.upload' => 'Облако: загрузил файл',
        'cloud.upload-abort' => 'Облако: отменил загрузку', 'cloud.link' => 'Облако: дал ссылку', 'cloud.unlink' => 'Облако: отозвал ссылку',
        'cloud.attach' => 'Облако: приложил к письму', 'compose.stage' => 'Большой файл для письма', 'cloud.download' => 'Облако: скачал или посмотрел',
        'list' => 'Листал список', 'list.date' => 'Перешёл к дате', 'search' => 'Искал', 'open' => 'Открыл письмо', 'thread' => 'Открыл переписку',
        'raw' => 'Исходник письма', 'attachment' => 'Скачал вложение', 'attachment.preview' => 'Просмотр вложения',
        'attachment.zip' => 'Скачал все вложения', 'attachment.mail' => 'Открыл вложенное письмо', 'image' => 'Картинка в тексте письма',
        'send' => 'Отправил письмо', 'send.cancel' => 'Отменил отправку', 'draft.save' => 'Сохранил черновик',
        'draft.open' => 'Открыл черновик', 'msg.move' => 'Перенёс письма', 'msg.delete' => 'Удалил письма',
        'msg.seen' => 'Отметил прочитанным', 'msg.unseen' => 'Отметил непрочитанным', 'msg.flag' => 'Поставил флажок',
        'msg.unflag' => 'Снял флажок', 'msg.spam' => 'В спам', 'msg.notspam' => 'Не спам', 'msg.archive' => 'В архив',
        'msg.label' => 'Метка', 'msg.unlabel' => 'Снял метку', 'msg.copy' => 'Скопировал письма',
        'msg.snooze' => 'Отложил письмо', 'msg.remind' => 'Напоминание',
        'folder.create' => 'Создал папку', 'folder.rename' => 'Переименовал папку', 'folder.delete' => 'Удалил папку',
        'folder.empty' => 'Очистил папку', 'folder.share' => 'Общий доступ к папке',
        'rules.save' => 'Сохранил правила', 'rules.apply' => 'Применил правила', 'settings.save' => 'Сохранил настройки',
        'label.create' => 'Создал метку', 'label.rename' => 'Изменил метку', 'label.delete' => 'Удалил метку',
        'security.2fa' => 'Двухфакторный вход', 'security.app-password' => 'Пароль приложения', 'security.kick' => 'Завершил сеансы',
        'sender.mark' => 'Отметил отправителя', 'files.list' => 'Открыл «Мои файлы»', 'file.open' => 'Скачал файл из облака',
        'file.preview' => 'Просмотр файла из облака', 'file.renew' => 'Продлил ссылку', 'file.zip' => 'Скачал все файлы из облака', 'file.delete' => 'Удалил файл из облака',
        'contacts.import' => 'Загрузил контакты', 'contacts.export' => 'Выгрузил контакты', 'contacts.forget' => 'Забыл адрес',
        'task.create' => 'Создал задачу', 'task.update' => 'Изменил задачу', 'task.delete' => 'Удалил задачу',
        'quarantine.release' => 'Выпустил из карантина', 'quarantine.delete' => 'Удалил из карантина',
        'feedback.send' => 'Написал обращение',
        'compose.open' => 'Начал письмо', 'compose.close' => 'Закрыл письмо', 'compose.discard' => 'Бросил письмо',
        'menu.open' => 'Открыл меню письма', 'error' => 'Ошибка сервера',
    ];

    /**
     * @param  array<string,mixed>  $query  параметры адреса
     * @param  array<string,mixed>  $input  тело запроса (только считаем, ничего не сохраняем)
     * @return array{0:string,1:?string,2:?string}|null  [действие, роль папки, подробность]; null — не записывать
     */
    public static function describe(string $method, string $path, array $query = [], array $input = [], int $files = 0): ?array
    {
        $method = strtoupper($method);
        $path = trim($path, '/');
        if ($path === 'cloud') {
            return ['page.cloud', null, null];
        }
        if (! str_starts_with($path, 'mail')) {
            return null;
        }
        if (! str_starts_with($path, 'mail/api/')) {
            return self::page($path);
        }
        $p = substr($path, strlen('mail/api/'));
        $seg = explode('/', $p);
        $is = fn (string $re) => (bool) preg_match('#^' . $re . '$#u', $p);

        return match (true) {
            $is('status'), $is('suggest'), $is('check-domain'), $is('activity'), $is('outbox'),
            $is('feedback(/unread|/\d+)?') && $method === 'GET', $is('folders') && $method === 'GET', $is('folders/.+/shares') && $method === 'GET',
            $is('files/[^/]+') && $method === 'GET', $is('settings') && $method === 'GET', $is('labels') && $method === 'GET',
            $is('security'), $is('rules') && $method === 'GET', $is('contacts/(books|groups|history)') && $method === 'GET',
            $is('tasks') && $method === 'GET', $is('quarantine') && $method === 'GET' => null,

            $is('list-at/.+') => ['list.date', self::role(urldecode(substr($p, 8))), null],
            $is('list/.+') => self::listing(urldecode(substr($p, 5)), $query),
            $is('message/.+/attachment/\d+/preview\.pdf') => ['attachment.preview', self::role(self::folderOf($p, 'message')), null],
            $is('message/.+/attachment/\d+/message(/\d+)?') => ['attachment.mail', self::role(self::folderOf($p, 'message')), null],
            $is('message/.+/attachments\.zip') => ['attachment.zip', self::role(self::folderOf($p, 'message')), null],
            $is('message/.+/cloud\.zip') => ['file.zip', self::role(self::folderOf($p, 'message')), null],
            // Картинки, встроенные в текст письма (cid), браузер тянет сам при открытии — это не «скачал вложение».
            $is('message/.+/attachment/\d+') => [! empty($query['inline']) ? 'image' : 'attachment', self::role(self::folderOf($p, 'message')), null],
            $is('message/.+/raw') => ['raw', self::role(self::folderOf($p, 'message')), null],
            $is('message/.+/thread') => ['thread', self::role(self::folderOf($p, 'message')), null],
            $is('message/.+/\d+') => ['open', self::role(self::folderOf($p, 'message')), null],

            $is('action') && $method === 'POST' => self::action($input),
            $is('send') && $method === 'POST' => ['send', null, self::sendDetail($input, $files)],
            $is('draft') && $method === 'POST' => ['draft.save', 'drafts', $files > 0 ? 'файлов ' . $files : null],
            $is('compose/stage') && $method === 'POST' => ['compose.stage', null, null],
            $is('compose/stage/.+') => null,
            $is('draft/\d+') => ['draft.open', 'drafts', null],
            $is('outbox/\d+') && $method === 'DELETE' => ['send.cancel', null, null],

            $is('folders') && $method === 'POST' => ['folder.create', self::role((string) ($input['parent'] ?? '')), isset($input['parent']) && $input['parent'] !== '' && $input['parent'] !== null ? 'вложенная' : null],
            $is('folders/.+/empty') => ['folder.empty', self::role(self::between($p, 'folders/', '/empty')), null],
            $is('folders/.+/shares') => ['folder.share', self::role(self::between($p, 'folders/', '/shares')), $method === 'DELETE' ? 'снял' : 'дал'],
            $is('folders/.+') && $method === 'PATCH' => ['folder.rename', self::role(self::between($p, 'folders/', '')), null],
            $is('folders/.+') && $method === 'DELETE' => ['folder.delete', self::role(self::between($p, 'folders/', '')), null],

            $is('settings') => ['settings.save', null, self::keys($input)],
            $is('labels') => ['label.create', null, null],
            $is('labels/\d+') => [$method === 'DELETE' ? 'label.delete' : 'label.rename', null, null],
            $is('security/2fa/.+') => ['security.2fa', null, $seg[2] ?? null],
            $is('security/app-passwords(/\d+)?') => ['security.app-password', null, $method === 'DELETE' ? 'отозвал' : 'создал'],
            $is('security/sessions/.+') => ['security.kick', null, $seg[2] === 'kick-others' ? 'остальные' : 'один'],
            $is('rules') => ['rules.save', null, 'правил ' . count((array) ($input['rules'] ?? []))],
            $is('rules/apply') => ['rules.apply', null, null],
            $is('sender/mark') => ['sender.mark', null, isset($input['mark']) ? (string) $input['mark'] : null],
            $is('files') => ['files.list', null, null],
            $is('files/[^/]+/content') => ['file.open', null, null],
            $is('files/[^/]+/preview\.pdf') => ['file.preview', null, null],
            $is('files/[^/]+/renew') => ['file.renew', null, null],
            $is('files/[^/]+') && $method === 'DELETE' => ['file.delete', null, null],
            $is('contacts/import') => ['contacts.import', null, null],
            $is('contacts/export') => ['contacts.export', null, null],
            $is('contacts/history/.+') => ['contacts.forget', null, null],
            $is('tasks') => ['task.create', null, null],
            $is('tasks/.+') => [$method === 'DELETE' ? 'task.delete' : 'task.update', null, null],
            $is('quarantine/[^/]+/release') => ['quarantine.release', null, null],
            $is('quarantine/[^/]+') && $method === 'DELETE' => ['quarantine.delete', null, null],
            $is('feedback') && $method === 'POST' => ['feedback.send', null, null],
            // Облако: части загрузки и служебные запросы — фон, в журнал идут действия человека.
            $is('cloud/folders'), $is('cloud/uploads/[^/]+') && $method === 'GET', $is('cloud/uploads/[^/]+/\d+'), $is('cloud/uploads') => null,
            $is('cloud/list') => ['cloud.open', null, ($query['path'] ?? '') === '' ? 'корень' : 'папка'],
            $is('cloud/recent') => ['cloud.open', null, 'недавние'],
            $is('cloud/links') => ['cloud.open', null, 'со ссылками'],
            $is('cloud/trash') && $method === 'GET' => ['cloud.open', null, 'корзина'],
            $is('cloud/folder') => ['cloud.mkdir', null, null],
            $is('cloud/rename') => ['cloud.rename', null, null],
            $is('cloud/move') => ['cloud.move', null, 'штук ' . count((array) ($input['paths'] ?? []))],
            $is('cloud/delete') => ['cloud.delete', null, 'штук ' . count((array) ($input['paths'] ?? []))],
            $is('cloud/trash/\d+/restore') => ['cloud.restore', null, null],
            $is('cloud/trash(/\d+)?') => ['cloud.purge', null, null],
            $is('cloud/uploads/[^/]+/finish') => ['cloud.upload', null, null],
            $is('cloud/uploads/[^/]+') => ['cloud.upload-abort', null, null],
            // PUT — правка срока или пароля уже выданной ссылки (приложение); POST — выдача.
            $is('cloud/link') => ['cloud.link', null, trim(($method === 'PUT' ? 'изменил' : '') . (! empty($input['password']) ? ' с паролем' : '')) ?: null],
            $is('cloud/unlink') => ['cloud.unlink', null, null],
            $is('cloud/attach') => ['cloud.attach', null, 'файлов ' . count((array) ($input['paths'] ?? []))],
            $is('cloud/file') => ['cloud.download', null, ! empty($query['inline']) ? 'просмотр' : 'скачивание'],
            default => null,
        };
    }

    /** Роль папки по её пути: содержимое имён своих папок в журнал не попадает. */
    public static function role(string $folder): ?string
    {
        $folder = trim($folder);
        if ($folder === '') {
            return null;
        }
        $root = preg_split('#[/.]#', $folder, 2)[0];
        $nested = strlen($root) < strlen($folder);
        $role = match (mb_strtolower($root)) {
            'inbox' => 'inbox',
            'sent', 'sent items', 'sent messages' => 'sent',
            'drafts' => 'drafts',
            'trash', 'deleted items', 'deleted messages' => 'trash',
            'junk', 'junk e-mail', 'spam' => 'junk',
            'archive', 'archives' => 'archive',
            'shared', 'общие', 'public' => 'shared',
            default => 'own',
        };

        return $nested && $role !== 'own' && $role !== 'shared' ? $role . '-sub' : $role;
    }

    /** @return array{0:string,1:?string,2:?string}|null */
    private static function page(string $path): ?array
    {
        return match (true) {
            $path === 'mail' => ['page.mail', null, null],
            str_starts_with($path, 'mail/folder/') => ['page.folder', self::role(urldecode(substr($path, 12))), null],
            str_starts_with($path, 'mail/settings') => ['page.settings', null, trim(substr($path, 13), '/') ?: 'general'],
            $path === 'mail/feedback' => ['page.feedback', null, null],
            $path === 'mail/quarantine' => ['page.quarantine', null, null],
            str_starts_with($path, 'mail/print/') => ['print', null, null],
            default => null,
        };
    }

    /** @return array{0:string,1:?string,2:?string} */
    private static function listing(string $folder, array $query): array
    {
        $q = trim((string) ($query['q'] ?? ''));
        if ($q !== '') {
            // Только по какому полю искали — не что.
            $scope = preg_match('/^(\p{L}+):/u', $q, $m) ? mb_strtolower($m[1]) : 'текст';
            $where = ! empty($query['everywhere']) ? ', везде' : '';

            return ['search', self::role($folder), $scope . $where];
        }
        $page = (int) ($query['page'] ?? 1);
        $extra = [];
        if ($page > 1) {
            $extra[] = 'стр. ' . $page;
        }
        if ((int) ($query['offset'] ?? 0) > 0) {
            $extra[] = 'прокрутка';
        }
        if (! empty($query['filter']) && $query['filter'] !== 'all') {
            $extra[] = 'отбор';
        }

        return ['list', self::role($folder), $extra ? implode(', ', $extra) : null];
    }

    /** @return array{0:string,1:?string,2:?string} */
    private static function action(array $input): array
    {
        $op = preg_replace('/[^a-z_-]/', '', strtolower((string) ($input['op'] ?? '')));
        $uids = is_array($input['uids'] ?? null) ? count($input['uids']) : (isset($input['uid']) ? 1 : 0);
        $detail = $uids > 0 ? 'писем ' . $uids : null;
        if ($op === 'move' || $op === 'copy') {
            $detail = trim(($detail ?? '') . ' → ' . (self::role((string) ($input['target'] ?? '')) ?? '?'));
        }

        return ['msg.' . ($op !== '' ? $op : 'other'), self::role((string) ($input['folder'] ?? '')), $detail];
    }

    private static function sendDetail(array $input, int $files): string
    {
        $count = fn (string $k) => count(array_filter(array_map('trim', explode(',', (string) ($input[$k] ?? '')))));
        $parts = ['адресатов ' . ($count('to') + $count('cc') + $count('bcc'))];
        $cloud = (is_array($input['cloud'] ?? null) ? count($input['cloud']) : 0) + (is_array($input['staged'] ?? null) ? count($input['staged']) : 0);
        if ($files > 0) {
            $parts[] = 'файлов ' . $files;
        }
        if ($cloud > 0) {
            $parts[] = 'в облаке ' . $cloud;
        }
        if (! empty($input['scheduleAt']) || ! empty($input['schedule_at'])) {
            $parts[] = 'отложено';
        }
        if (! empty($input['draftUid'])) {
            $parts[] = 'из черновика';
        }

        return implode(', ', $parts);
    }

    private static function folderOf(string $p, string $prefix): string
    {
        $rest = substr($p, strlen($prefix) + 1);
        // …/{folder}/{uid}/… — папка может содержать «/», uid — первое число после неё.
        return preg_match('#^(.*?)/\d+(/|$)#', $rest, $m) ? urldecode($m[1]) : '';
    }

    /** Кусок пути между префиксом и суффиксом, раскодированный (папка может содержать «/»). */
    private static function between(string $p, string $prefix, string $suffix): string
    {
        $s = substr($p, strlen($prefix));
        if ($suffix !== '' && str_ends_with($s, $suffix)) {
            $s = substr($s, 0, -strlen($suffix));
        }

        return urldecode($s);
    }

    private static function keys(array $input): ?string
    {
        $keys = array_slice(array_keys($input), 0, 8);

        return $keys ? mb_substr(implode(', ', $keys), 0, 120) : null;
    }
}
