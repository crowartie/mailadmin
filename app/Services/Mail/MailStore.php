<?php

namespace App\Services\Mail;

use Webklex\PHPIMAP\Attachment;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\Message;

/**
 * Всё, что веб-почта делает с ящиком, — одной точкой входа.
 *
 * Сама работа разложена по частям, каждая отвечает за своё:
 *   FolderTree      — дерево папок, роли, общие папки коллег, место в ящике;
 *   MessageSummary  — строка списка писем;
 *   MessageListing  — страницы, порядок, отбор и поиск;
 *   MessageReader   — чтение письма, переписка, вложения;
 *   MailActions     — флаги, перенос, удаление, поиск для правил.
 *
 * Здесь остаются вызовы той же формы, что и раньше: разделение не потребовало
 * трогать контроллеры, правила, планировщик и индекс цепочек.
 */
class MailStore
{
    /** @see MessageListing::PAGE */
    public const PAGE = MessageListing::PAGE;

    /** @see FolderTree::SHARED_PREFIX */
    public const SHARED_PREFIX = FolderTree::SHARED_PREFIX;

    private readonly FolderTree $tree;

    private readonly MessageSummary $summaries;

    private readonly MailActions $actions;

    private readonly MessageListing $listing;

    private readonly MessageReader $reader;

    /** Сколько писем переписки осталось за пределами показанного (см. threadOf). */
    public int $threadHidden = 0;

    public function __construct(private readonly Client $client)
    {
        $this->tree = new FolderTree($client);
        $this->summaries = new MessageSummary($client);
        $this->actions = new MailActions($client, $this->tree, $this);
        $this->listing = new MessageListing($client, $this->tree, $this->summaries);
        $this->reader = new MessageReader($client, $this->tree, $this->summaries, $this->actions);
    }

    public function client(): Client
    {
        return $this->client;
    }

    // ── Разбор писем: чистые функции живут в Mime ────────────────────────
    // Эти три метода зовут контроллеры и службы, поэтому оставляем их здесь
    // тонкими обёртками: так разделение не потребовало трогать вызывающий код.

    /**
     * @see Mime::attachmentName()
     *
     * Запасное имя не обязательно: контроллер скачивания зовёт эту функцию с одним
     * доводом, и без значения по умолчанию скачивание вложения падало.
     */
    public static function attachmentName(Attachment $a, string $fallback = 'attachment'): string
    {
        return Mime::attachmentName($a, $fallback);
    }

    /** @see Mime::messageIds() */
    public static function messageIds(mixed $raw): array
    {
        return Mime::messageIds($raw);
    }

    /** @see Mime::address() */
    public static function address(?string $name, ?string $mail): array
    {
        return Mime::address($name, $mail);
    }

    // ── Папки ────────────────────────────────────────────────────────────

    /** @see FolderTree::folders() */
    public function folders(): array
    {
        return $this->tree->folders();
    }

    /** @see FolderTree::folder() */
    public function folder(string $path): Folder
    {
        return $this->tree->folder($path);
    }

    /** @see FolderTree::folderRole() */
    public function folderRole(string $path): string
    {
        return $this->tree->folderRole($path);
    }

    /** @see FolderTree::folderTitle() */
    public function folderTitle(string $path): string
    {
        return $this->tree->folderTitle($path);
    }

    /** @see FolderTree::folderStatus() */
    public function folderStatus(string $path): array
    {
        return $this->tree->folderStatus($path);
    }

    /** @see FolderTree::status() */
    public function status(string $path): array
    {
        return $this->tree->status($path);
    }

    /** @see FolderTree::quota() */
    public function quota(): ?array
    {
        return $this->tree->quota();
    }

    /** @see FolderTree::rolePath() */
    public function rolePath(string $role): string
    {
        return $this->tree->rolePath($role);
    }

    /** @see FolderTree::rolePathFor() */
    public function rolePathFor(string $context, string $role): string
    {
        return $this->tree->rolePathFor($context, $role);
    }

    /** @see FolderTree::moveTarget() */
    public function moveTarget(string $from, string $target): string
    {
        return $this->tree->moveTarget($from, $target);
    }

    /** @see FolderTree::createFolder() */
    public function createFolder(string $name, ?string $parent = null): string
    {
        return $this->tree->createFolder($name, $parent);
    }

    /** @see FolderTree::renameFolder() */
    public function renameFolder(string $path, string $newName): string
    {
        return $this->tree->renameFolder($path, $newName);
    }

    /**
     * Удалить папку, сохранив письма.
     *
     * Раньше папка удалялась вместе с содержимым, и письма исчезали бесследно: диалог
     * предупреждал, но папка может выглядеть пустой из-за фильтра, а внутри лежать
     * сотня писем. Теперь письма сначала переезжают в «Корзину» — из неё их можно
     * вернуть, — и только потом папка удаляется. Так делают Thunderbird и Outlook.
     *
     * Папку внутри самой корзины переносить некуда: её письма и так в корзине,
     * и они удаляются вместе с ней, как и раньше.
     *
     * @return int сколько писем переехало в корзину
     */
    public function deleteFolder(string $path): int
    {
        // Папку с вложенными папками сервер не удаляет, но и не отказывает внятно:
        // отвечает «ок», а папка остаётся. Говорим прямо.
        $children = 0;
        foreach ($this->tree->folders() as $f) {
            if (str_starts_with((string) $f['path'], $path . '/') || str_starts_with((string) $f['path'], $path . '.')) {
                $children++;
            }
        }
        if ($children > 0) {
            throw \App\Exceptions\MailException::invalid('Сначала удалите вложенные папки (' . $children . ') — сервер не удаляет папку, пока внутри есть другие.');
        }

        $trash = $this->tree->rolePathFor($path, 'trash');
        $moved = 0;
        $insideTrash = $trash !== '' && ($path === $trash || str_starts_with($path, $trash . '/') || str_starts_with($path, $trash . '.'));
        if ($trash !== '' && ! $insideTrash) {
            $uids = $this->listing->searchFrom($path, 1);
            if ($uids !== []) {
                $this->actions->move($path, $uids, $trash);
                $moved = count($uids);
            }
        }
        // Удалять открытую папку нельзя: соединение сидит в ней, и сервер на DELETE
        // отвечает молчанием, роняя всё, что идёт следом в том же запросе.
        $this->client->openFolder('INBOX', true);
        $this->tree->deleteFolder($path);

        return $moved;
    }

    /** @see MailAttachments::attachedMessage() */
    public function attachedMessage(string $path, int $uid, int $index): array
    {
        return $this->reader->attachedMessage($path, $uid, $index);
    }

    /** @see MailAttachments::attachedPart() */
    public function attachedPart(string $path, int $uid, int $index, int $sub): MailPart
    {
        return $this->reader->attachedPart($path, $uid, $index, $sub);
    }

    /** @see FolderTree::ensureFolder() */
    public function ensureFolder(string $path): string
    {
        return $this->tree->ensureFolder($path);
    }

    /** @see FolderTree::user() */
    public function user(): string
    {
        return $this->tree->user();
    }

    /** @see FolderTree::roleOfPath() */
    public static function roleOfPath(string $path): string
    {
        return FolderTree::roleOfPath($path);
    }

    /** @see FolderTree::sharedOwner() */
    public static function sharedOwner(string $path): ?string
    {
        return FolderTree::sharedOwner($path);
    }

    /** @see FolderTree::forgetSharesCache() */
    public static function forgetSharesCache(string $user): void
    {
        FolderTree::forgetSharesCache($user);
    }

    // ── Списки и поиск ───────────────────────────────────────────────────

    /** @see MessageListing::list() */
    public function list(string $path, int $page = 1, string $filter = 'all', ?string $query = null, string $sort = 'date'): array
    {
        return $this->listing->list($path, $page, $filter, $query, $sort);
    }

    /** @see MessageListing::searchEverywhere() */
    public function searchEverywhere(string $query, int $page = 1, string $sort = 'date', ?string $from = null): array
    {
        return $this->listing->searchEverywhere($query, $page, $sort, $from);
    }

    /** @see MessageListing::searchFrom() */
    public function searchFrom(string $path, int $fromUid): array
    {
        return $this->listing->searchFrom($path, $fromUid);
    }

    /** @see MessageSummary::summary() */
    public function summary(Message $message, ?string $preview = null): array
    {
        return $this->summaries->summary($message, $preview);
    }

    // ── Чтение ───────────────────────────────────────────────────────────

    /** @see MessageReader::message() */
    public function message(string $path, int $uid, bool $markSeen = true): array
    {
        return $this->reader->message($path, $uid, $markSeen);
    }

    /**
     * @see MessageReader::threadOf()
     *
     * Сколько писем не поместилось, контроллер читает следом как $store->threadHidden,
     * поэтому переносим признак сюда сразу после ответа.
     */
    public function threadOf(string $path, int $uid): array
    {
        $out = $this->reader->threadOf($path, $uid);
        $this->threadHidden = $this->reader->threadHidden;

        return $out;
    }

    /** @see MessageReader::full() */
    public function full(Message $message, string $path): array
    {
        return $this->reader->full($message, $path);
    }

    /** @see MessageReader::attachment() */
    public function attachment(string $path, int $uid, int $index): MailPart
    {
        return $this->reader->attachment($path, $uid, $index);
    }

    /** @see MessageReader::attachmentsZip() */
    public function attachmentsZip(string $path, int $uid): array
    {
        return $this->reader->attachmentsZip($path, $uid);
    }

    /** @see MessageReader::attachmentPreviewPdf() */
    public function attachmentPreviewPdf(string $path, int $uid, int $index): string
    {
        return $this->reader->attachmentPreviewPdf($path, $uid, $index);
    }

    /** @see MessageReader::headersText() */
    public function headersText(string $path, int $uid): string
    {
        return $this->reader->headersText($path, $uid);
    }

    /** @see MessageReader::raw() */
    public function raw(string $path, int $uid): string
    {
        return $this->reader->raw($path, $uid);
    }

    /** @see MessageReader::rawHeaders() */
    public function rawHeaders(string $path, array $uids): array
    {
        return $this->reader->rawHeaders($path, $uids);
    }

    // ── Действия ─────────────────────────────────────────────────────────

    /** @see MailActions::flag() */
    public function flag(string $path, array $uids, string $flag, bool $on): void
    {
        $this->actions->flag($path, $uids, $flag, $on);
    }

    /** @see MailActions::move() */
    public function move(string $path, array $uids, string $target): void
    {
        $this->actions->move($path, $uids, $target);
    }

    /** @see MailActions::copy() */
    public function copy(string $path, array $uids, string $target): void
    {
        $this->actions->copy($path, $uids, $target);
    }

    /** @see MailActions::delete() */
    public function delete(string $path, array $uids): void
    {
        $this->actions->delete($path, $uids);
    }

    /** @see MailActions::emptyFolder() */
    public function emptyFolder(string $path): void
    {
        $this->actions->emptyFolder($path);
    }

    /** @see MailActions::append() */
    public function append(string $path, string $raw, array $flags = ['\\Seen'], ?string $messageId = null): ?int
    {
        return $this->actions->append($path, $raw, $flags, $messageId);
    }

    /** @see MailActions::findByMessageId() */
    public function findByMessageId(string $path, string $messageId): ?int
    {
        return $this->actions->findByMessageId($path, $messageId);
    }

    /** @see MailActions::hasReplyTo() */
    public function hasReplyTo(string $path, string $messageId): bool
    {
        return $this->actions->hasReplyTo($path, $messageId);
    }

    /** @see MailActions::searchAll() */
    public function searchAll(string $path): array
    {
        return $this->actions->searchAll($path);
    }

    /** @see MailActions::searchSender() */
    public function searchSender(string $path, string $match, string $value): array
    {
        return $this->actions->searchSender($path, $match, $value);
    }

    /** @see MailActions::searchCondition() */
    public function searchCondition(string $path, array $c): array
    {
        return $this->actions->searchCondition($path, $c);
    }
}
