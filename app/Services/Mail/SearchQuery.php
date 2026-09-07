<?php

namespace App\Services\Mail;

use Carbon\Carbon;
use Webklex\PHPIMAP\Query\WhereQuery;

/**
 * Строка поиска с операторами → IMAP SEARCH.
 *
 *   от:иванов   кому:buh@   тема:счёт   есть:флажок   есть:непрочитанное   есть:вложение
 *   до:2026-09-01   после:2026-08-01   свободный текст — по всему письму
 *
 * Английские синонимы: from: to: subject: is:flagged is:unread has:attachment before: after:
 */
class SearchQuery
{
    private array $terms = [];

    private array $text = [];

    public function __construct(string $query)
    {
        $this->parse($query);
    }

    private function parse(string $query): void
    {
        preg_match_all('/(?:(\S+?):("[^"]*"|\S+))|("[^"]+")|(\S+)/u', $query, $m, PREG_SET_ORDER);
        foreach ($m as $t) {
            if (($t[1] ?? '') !== '') {
                $this->terms[] = [mb_strtolower($t[1]), trim($t[2], '"')];
            } elseif (($t[3] ?? '') !== '') {
                $this->text[] = trim($t[3], '"');
            } elseif (($t[4] ?? '') !== '') {
                $this->text[] = $t[4];
            }
        }
    }

    public function apply(WhereQuery $q): WhereQuery
    {
        foreach ($this->terms as [$key, $value]) {
            switch ($key) {
                case 'от': case 'from':
                    $q->whereFrom($value);
                    break;
                case 'кому': case 'to':
                    $q->whereTo($value);
                    break;
                case 'тема': case 'subject':
                    $q->whereSubject($value);
                    break;
                case 'копия': case 'cc':
                    $q->whereCc($value);
                    break;
                case 'до': case 'before':
                    if ($d = $this->date($value)) {
                        $q->whereBefore($d);
                    }
                    break;
                case 'после': case 'after': case 'с': case 'since':
                    if ($d = $this->date($value)) {
                        $q->whereSince($d);
                    }
                    break;
                case 'есть': case 'is': case 'has':
                    $v = mb_strtolower($value);
                    if (in_array($v, ['флажок', 'flagged', 'starred'])) {
                        $q->whereFlagged('FLAGGED');
                    } elseif (in_array($v, ['непрочитанное', 'непрочитанные', 'unread', 'unseen'])) {
                        $q->whereUnseen();
                    } elseif (in_array($v, ['вложение', 'вложения', 'attachment', 'attachments'])) {
                        $q->whereHeader('Content-Type', 'multipart/mixed');
                    } elseif (in_array($v, ['ответ', 'answered'])) {
                        $q->whereAnswered();
                    }
                    break;
                default:
                    $this->text[] = $key . ':' . $value;
            }
        }

        foreach ($this->text as $word) {
            // TEXT ищет по заголовкам и телу: без полнотекстового индекса это перебор, но ящики небольшие.
            $q->whereText($word);
        }

        return $q;
    }

    private function date(string $value): ?Carbon
    {
        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
