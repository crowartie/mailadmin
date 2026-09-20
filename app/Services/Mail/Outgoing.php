<?php

namespace App\Services\Mail;

use App\Models\Webmail\Recent;
use App\Models\Webmail\SentRetry;
use App\Exceptions\MailException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Отправка письма и всё, что должно случиться после неё.
 *
 * Главное правило этой части: письмо уже у получателя, поэтому сбой любого следующего
 * шага — копии в «Отправленные», отметки исходного, удаления черновика — не должен
 * выглядеть как «письмо не отправлено». Копия при сбое не теряется, а встаёт в очередь
 * (keepSentCopy): именно так когда-то пропадали копии у отправлявших с телефона.
 */
class Outgoing
{
    private readonly MailBuilder $builder;

    public function __construct(
        private readonly ImapSession $session,
        private readonly MailStore $store,
    ) {
        $this->builder = new MailBuilder($session, $store);
    }

    /** @see MailBuilder::build() */
    /** @param bool $forSend письмо собирается на отправку (не в черновик): вложения исходного письма крупнее порога уходят ссылкой */
    public function build(array $form, array $files = [], array $cloud = [], bool $forSend = false): Email
    {
        return $this->builder->build($form, $files, $cloud, $forSend);
    }

    /** @see MailAddressList::parseAddresses() */
    public function parseAddresses(string $raw): array
    {
        return MailAddressList::parseAddresses($raw);
    }

    /** @see MailAddressList::splitAddresses() */
    public static function splitAddresses(string $raw): array
    {
        return MailAddressList::splitAddresses($raw);
    }

    /** @see MailBuilder::identities() — зовёт страница почты при открытии. */
    public function identities(): array
    {
        return $this->builder->identities();
    }

    /** @see MailBuilder::sharedSenders() */
    public static function sharedSenders(string $user): array
    {
        return MailBuilder::sharedSenders($user);
    }


    public static function messageId(Email $email): string
    {
        return trim((string) $email->getHeaders()->get('Message-ID')?->getBodyAsString(), '<>');
    }


    /** То же для письма, которое у нас уже в виде текста (очередь отложенной отправки). */
    public static function rawMessageId(string $raw): ?string
    {
        $head = explode("\r\n\r\n", str_replace("\n", "\r\n", str_replace("\r\n", "\n", $raw)), 2)[0];

        return preg_match('/^Message-ID:\s*<([^>]+)>/mi', $head, $m) ? $m[1] : null;
    }


    /**
     * Выполнить уборку после успешной отправки. Письмо уже у получателя, поэтому любая ошибка здесь
     * попадает в журнал, но не возвращается пользователю: иначе он видит «не отправлено» и шлёт повторно.
     */
    private function tidyUp(callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('после отправки письма: ' . $e->getMessage());
        }
    }


    /**
     * Положить копию в «Отправленные». Если не получилось — не теряем её: письмо ложится
     * в очередь, и mail:sent-retry доносит копию потом.
     *
     * Раньше сбой этого шага уходил только в журнал. Человек видел «письмо отправлено»,
     * а в папке ничего не было — и это читалось как «почта удаляет письма сама».
     */
    public static function keepSentCopy(MailStore $store, string $user, string $raw, ?string $messageId, ?string $subject = null): void
    {
        $folder = $store->rolePath('sent');
        try {
            $store->append($folder, $raw, ['\\Seen'], $messageId);
        } catch (\Throwable $e) {
            $path = 'sent-retry/' . $user . '/' . uniqid('', true) . '.eml';
            \Illuminate\Support\Facades\Storage::disk('local')->put($path, $raw);
            SentRetry::create([
                'user' => $user, 'folder' => $folder, 'message_id' => $messageId,
                'subject' => mb_substr((string) $subject, 0, 400), 'path' => $path,
                'error' => mb_substr($e->getMessage(), 0, 2000),
            ]);
            \Illuminate\Support\Facades\Log::warning('копия в «Отправленные» отложена (' . $user . '): ' . $e->getMessage());
        }
    }


    /** Отправить сейчас, положить копию в «Отправленные», отметить исходное отвеченным, убрать черновик. */
    public function send(Email $email, array $form): string
    {
        $from = strtolower($email->getFrom()[0]->getAddress());
        $shared = collect(MailBuilder::sharedSenders($this->session->user()))->firstWhere('mail', $from);
        if ($shared) {
            // Письмо от имени общего ящика: SMTP-авторизация под своим логином не пропустит чужой адрес,
            // поэтому шлём через локальный relay (как сам сервер), а копию кладём в «Отправленные» общего ящика.
            $this->deliver(new Mailer(ImapSession::smtpLocal()), $email);
            // Дальше — только уборка: копия в «Отправленные», отметка исходного, удаление черновика.
            // Её сбой раньше приходил в интерфейс как «письмо не отправлено», и сотрудник слал второй раз.
            $this->tidyUp(function () use ($from, $email, $form) {
                $raw = $email->toString();
                $id = self::messageId($email);
                try {
                    $ownerStore = new MailStore(ImapSession::master($from));
                    self::keepSentCopy($ownerStore, $from, $raw, $id, $email->getSubject());
                } catch (\Throwable) {
                    // До общего ящика не достучались — кладём копию себе, чтобы она вообще была.
                    self::keepSentCopy($this->store, $this->session->user(), $raw, $id, $email->getSubject());
                }
                $this->afterSend($this->store, $email, $form, $this->session->user(), false);
            });
        } else {
            $this->deliver(new Mailer($this->session->smtp()), $email);
            $this->tidyUp(fn () => $this->afterSend($this->store, $email, $form, $this->session->user()));
        }

        return self::messageId($email);
    }


    /**
     * Отдать письмо SMTP-серверу. Его отказ — не «ошибка сервера», а ответ по существу
     * (нет такого адреса, письмо слишком большое, сейчас не принимает): показываем словами.
     */
    private function deliver(Mailer $mailer, Email $email): void
    {
        try {
            $mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            // Ссылки на файлы никуда не ушли — файлам в хранилище делать нечего.
            \App\Services\Cloud\LocalFiles::discardForMessage(self::messageId($email));
            throw self::smtpFailure($e);
        } catch (\Throwable $e) {
            \App\Services\Cloud\LocalFiles::discardForMessage(self::messageId($email));
            throw $e;
        }
    }

    /**
     * Ответ SMTP → сообщение для человека. Код 5xx — окончательный отказ, 4xx — временный.
     * Пример: «550 5.1.1 <adress@innotec.su>: Recipient address rejected: User unknown».
     */
    public static function smtpFailure(\Throwable $e): MailException
    {
        $text = $e->getMessage();
        // Symfony оборачивает ответ: Expected response code "250/251/252" but got code "550", with message "…".
        if (preg_match('/got code "(\d{3})", with message "(.*)"\.?$/s', $text, $m)) {
            $code = (int) $m[1];
            $reply = $m[2];
        } else {
            $code = (int) $e->getCode();
            $reply = $text;
        }
        $reply = trim(stripslashes($reply));
        $addr = preg_match('/<([^<>@\s]+@[^<>\s]+)>/', $reply, $a) ? $a[1] : null;
        $lower = mb_strtolower($reply);

        if ($addr && (str_contains($lower, 'user unknown') || str_contains($lower, 'recipient address rejected') || str_contains($lower, 'no such user') || str_contains($lower, 'does not exist'))) {
            return MailException::invalid('Адреса «' . $addr . '» не существует — почтовый сервер отверг его. Проверьте написание и отправьте снова.');
        }
        if (str_contains($lower, 'size') && (str_contains($lower, 'exceed') || str_contains($lower, 'too big') || str_contains($lower, 'limit'))) {
            return MailException::tooLarge('Почтовый сервер не принял письмо: оно слишком большое. Уберите часть вложений или отправьте их ссылкой.');
        }
        if (str_contains($lower, 'relay access denied') || str_contains($lower, 'not permitted') || str_contains($lower, 'authentication')) {
            return MailException::denied('Почтовый сервер не разрешил отправку' . ($addr ? ' на «' . $addr . '»' : '') . ': ' . mb_substr($reply, 0, 160));
        }
        if (str_contains($lower, 'connection') || str_contains($lower, 'timed out') || str_contains($lower, 'timeout')) {
            return MailException::upstream('Не удалось связаться с почтовым сервером: ' . mb_substr($reply, 0, 160) . '. Письмо не потеряно, оно в окне — попробуйте ещё раз.');
        }
        if ($code >= 400 && $code < 500) {
            return MailException::busy('Почтовый сервер сейчас не принимает письмо (' . mb_substr($reply, 0, 160) . '). Попробуйте через несколько минут — письмо не потеряно, оно в окне.');
        }

        return MailException::upstream('Почтовый сервер отверг письмо: ' . mb_substr($reply ?: 'нет ответа', 0, 200));
    }

    /** То же самое из планировщика: транспорт без авторизации, IMAP через master-пользователя. */
    public static function sendRaw(string $raw, string $from, array $recipients, TransportInterface $transport, MailStore $store): void
    {
        $envelope = new \Symfony\Component\Mailer\Envelope(new Address($from), array_map(fn ($r) => new Address($r), $recipients));
        $transport->send(new \Symfony\Component\Mime\RawMessage($raw), $envelope);
        // Письмо уже у получателя. Копия — отдельный шаг, и его сбой не должен выглядеть
        // как «письмо не отправлено»: keepSentCopy положит копию в очередь и донесёт позже.
        self::keepSentCopy($store, $from, $raw, self::rawMessageId($raw));
    }


    public function afterSend(MailStore $store, Email $email, array $form, string $user, bool $copyToSent = true): void
    {
        $raw = $email->toString();
        if ($copyToSent) {
            self::keepSentCopy($store, $user, $raw, self::messageId($email), $email->getSubject());
        }

        if (! empty($form['answeredFolder']) && ! empty($form['answeredUid'])) {
            $store->flag($form['answeredFolder'], [(int) $form['answeredUid']], '\\Answered', true);
        }
        if (! empty($form['draftUid'])) {
            $drafts = $store->rolePath('drafts');
            $store->flag($drafts, [(int) $form['draftUid']], '\\Deleted', true);
            $store->client()->openFolder($drafts, true);
            $store->client()->getConnection()->expunge();
        }

        foreach (array_merge($email->getTo(), $email->getCc(), $email->getBcc()) as $addr) {
            Recent::remember($user, $addr->getAddress(), $addr->getName());
        }
    }


    /** Сохранить черновик; вернуть UID нового черновика. */
    public function saveDraft(Email $email, ?int $previousUid): ?int
    {
        $drafts = $this->store->rolePath('drafts');
        $uid = $this->store->append($drafts, $email->toString(), ['\\Seen', '\\Draft'], self::messageId($email));
        if ($previousUid) {
            $this->store->flag($drafts, [$previousUid], '\\Deleted', true);
            $this->store->client()->openFolder($drafts, true);
            $this->store->client()->getConnection()->expunge();
        }

        return $uid;
    }
}
