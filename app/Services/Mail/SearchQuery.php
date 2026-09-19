<?php

namespace App\Services\Mail;

use Carbon\Carbon;
use Webklex\PHPIMAP\Query\WhereQuery;

/**
 * Строка поиска с операторами → IMAP SEARCH.
 *
 *   от:иванов   кому:buh@   тема:счёт   текст:договор   есть:флажок   есть:непрочитанное   есть:вложение
 *   до:2026-09-01   после:2026-08-01   свободный текст — по всему письму
 *
 * Английские синонимы: from: to: subject: is:flagged is:unread has:attachment before: after:
 */
class SearchQuery
{
    private array $terms = [];

    private array $text = [];

    /** Имя файла из оператора «файл:» — отбор по нему делается после поиска (см. MailStore). */
    private ?string $file = null;

    /** Просили «есть:вложение» — отбор тоже по структуре письма. */
    private bool $hasFile = false;

    public function __construct(string $query)
    {
        $this->parse($query);
    }

    private function parse(string $query): void
    {
        // Значение можно писать и вплотную к двоеточию, и через пробел: в справке примеры
        // напечатаны с пробелом («от: иванов»), и раньше такой запрос искал буквально «от:».
        preg_match_all('/(?:([^\s:]+):\s*("[^"]*"|[^\s"]+))|("[^"]+")|(\S+)/u', $query, $m, PREG_SET_ORDER);
        foreach ($m as $t) {
            if (($t[1] ?? '') !== '') {
                $this->terms[] = [mb_strtolower($t[1]), self::clean($t[2])];
            } elseif (($t[3] ?? '') !== '') {
                $this->text[] = self::clean($t[3]);
            } elseif (($t[4] ?? '') !== '') {
                $this->text[] = self::clean($t[4]);
            }
        }
    }

    /**
     * Кавычки и обратные слэши убираем: библиотека обрамляет значение кавычками сама,
     * и запрос вроде «труба 15"» уходил на сервер незакрытой строкой — тот отвечал ошибкой.
     */
    private static function clean(string $v): string
    {
        return trim(str_replace(['"', '\\'], '', trim($v, '"')));
    }

    /** Что искали в именах вложений, если просили: «файл:счёт». */
    public function fileName(): ?string
    {
        return $this->file;
    }

    /** Нужны ли только письма с вложениями («есть:вложение»). */
    public function needsAttachment(): bool
    {
        return $this->hasFile || $this->file !== null;
    }

    public function apply(WhereQuery $q): WhereQuery
    {
        $added = 0;
        foreach ($this->terms as [$key, $value]) {
            if ($value === '') {
                continue;
            }
            $added++;
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
                case 'текст': case 'body':
                    // Только тело письма, без заголовков: «текст:договор» не найдёт письмо,
                    // где «договор» стоит лишь в теме. Тело в полнотекстовом указателе есть.
                    $q->whereBody($value);
                    break;
                case 'до': case 'before': case 'по':
                    if ($d = $this->date($value)) {
                        // По-русски «до 1 сентября» читается как «по 1 сентября включительно»,
                        // а BEFORE в IMAP — строго раньше даты: запрос «после:01.09 до:01.09»
                        // давал пусто. Сдвигаем на день.
                        $q->whereBefore($d->copy()->addDay());
                    }
                    break;
                case 'после': case 'after': case 'с': case 'since':
                    if ($d = $this->date($value)) {
                        $q->whereSince($d);
                    }
                    break;
                case 'файл': case 'вложение': case 'file': case 'attachment':
                    // Имена файлов лежат в письме закодированными, поэтому обычный поиск
                    // по тексту их не находит никогда. Отбор идёт после поиска — по
                    // структуре письма (см. MailStore::keepWithFile).
                    $this->file = $value;
                    $added--;   // сам по себе этот оператор условий серверу не добавляет
                    break;
                case 'есть': case 'is': case 'has':
                    $v = mb_strtolower($value);
                    if (in_array($v, ['флажок', 'flagged', 'starred'])) {
                        // Именно where('FLAGGED') без значения: whereFlagged() подставляет значение
                        // вторым словом и получается недопустимый критерий FLAGGED "FLAGGED".
                        $q->where('FLAGGED');
                    } elseif (in_array($v, ['непрочитанное', 'непрочитанные', 'unread', 'unseen'])) {
                        $q->whereUnseen();
                    } elseif (in_array($v, ['вложение', 'вложения', 'attachment', 'attachments'])) {
                        // Поиск по заголовкам при включённом полнотекстовом индексе не находит
                        // ничего — проверено на боевом сервере: ноль писем в папке, где вложения
                        // есть у сотен. Отбираем по структуре письма, как и «файл:».
                        $this->hasFile = true;
                        $added--;
                    } elseif (in_array($v, ['ответ', 'answered'])) {
                        $q->whereAnswered();
                    }
                    break;
                default:
                    $added--; // не оператор, а обычные слова с двоеточием
                    $this->text[] = $key . ':' . $value;
            }
        }

        // Каждое слово — отдельное условие в IMAP SEARCH. На запросе из полусотни слов
        // сервер отвечал ошибкой, а человек видел «сервер достраивает индекс».
        $words = array_slice(array_values(array_filter($this->text, fn ($w) => $w !== '')), 0, 12);
        foreach ($words as $word) {
            if ($word === '') {
                continue;
            }
            // TEXT ищет по заголовкам и телу. На сервере включён полнотекстовый индекс
            // (Dovecot fts_xapian, fts_enforced=body), поэтому это не перебор по всей папке.
            $q->whereText($word);
            $added++;
        }

        // Ни одного условия (например, запрос из одних кавычек) — показываем всю папку,
        // иначе на сервер уходит SEARCH без критериев и он отвечает ошибкой.
        if ($added < 1) {
            $q->all();
        }

        return $q;
    }

    /**
     * Дата запроса: «вчера», «сегодня», «позавчера», «14.09.2026», «2026-09-14».
     * Непонятное значение — понятная ошибка, а не молча выброшенное условие:
     * раньше «до:вчера» показывало всю папку, будто фильтр сработал.
     */
    private function date(string $value): ?Carbon
    {
        $v = mb_strtolower(trim($value));
        $today = Carbon::today();
        $named = [
            'сегодня' => 0, 'today' => 0,
            'вчера' => 1, 'yesterday' => 1,
            'позавчера' => 2,
            'неделя' => 7, 'неделю' => 7, 'week' => 7,
            'месяц' => 30, 'month' => 30,
            'год' => 365, 'year' => 365,
        ];
        if (isset($named[$v])) {
            return $today->copy()->subDays($named[$v]);
        }
        if (preg_match('/^(\d{1,2})[.\-\/](\d{1,2})[.\-\/](\d{2,4})$/', $v, $m)) {
            $year = (int) $m[3] < 100 ? 2000 + (int) $m[3] : (int) $m[3];
            try {
                return Carbon::createFromDate($year, (int) $m[2], (int) $m[1])->startOfDay();
            } catch (\Throwable) {
                abort(422, "Не понимаю дату «{$value}». Напишите так: 14.09.2026, или словом: сегодня, вчера.");
            }
        }
        if (preg_match('/^\d{4}-\d{1,2}-\d{1,2}$/', $v)) {
            try {
                return Carbon::parse($v)->startOfDay();
            } catch (\Throwable) {
                // ниже — общая подсказка
            }
        }
        abort(422, "Не понимаю дату «{$value}». Напишите так: 14.09.2026, или словом: сегодня, вчера.");
    }
}
