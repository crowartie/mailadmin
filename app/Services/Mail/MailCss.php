<?php

namespace App\Services\Mail;

/**
 * Собственные стили письма — с ограничением области действия.
 *
 * Раньше блоки <style> вырезались целиком, и письмо теряло задуманную вёрстку: у рассылки
 * от OpenAI таких блоков девять, и на них держалась двухколоночная сетка, отступы и
 * разделители. Читалось всё это сплошной колонкой.
 *
 * Просто вернуть стили нельзя: через них письмо дотягивается до интерфейса — `body {
 * display: none }` прячет страницу, `position: fixed` накрывает её собой, `url(...)`
 * сообщает отправителю, что письмо открыли. Поэтому стили не возвращаются как есть,
 * а переписываются:
 *
 *   • каждому правилу приписывается область — селектор письма, и наружу оно не действует;
 *   • свойства пропускаются по тому же списку, что и у встроенных стилей (см. MailHtml);
 *   • обращения к сети (url со схемой http) убираются — это следящие пиксели;
 *   • правила уровня страницы (@import, @font-face, @page) не проходят вовсе;
 *   • @media сохраняется: на нём держится вёрстка писем под телефон.
 *
 * Разбор нарочно простой и построчный: полноценный разборщик CSS здесь не нужен, а лишнее,
 * чего он не понял, просто не проходит. Отбрасывать больше, чем надо, безопасно;
 * пропускать лишнее — нет.
 */
final class MailCss
{
    /** Сколько стилей письма разбираем. Дальше — уже не письмо, а способ занять процессор. */
    private const MAX_INPUT = 200000;

    /** Сколько правил оставляем. */
    private const MAX_RULES = 800;

    /** Правила уровня страницы: письму они не нужны, а навредить могут. */
    private const FORBIDDEN_AT = ['import', 'charset', 'font-face', 'page', 'namespace', 'document'];

    /**
     * Вырезать из письма блоки <style> и вернуть их содержимое.
     *
     * @return array{0:string,1:string} письмо без блоков <style> и сам собранный CSS
     */
    public static function extract(string $html): array
    {
        $css = '';
        $out = preg_replace_callback(
            '#<style\b[^>]*>(.*?)</style\s*>#is',
            function (array $m) use (&$css) {
                $css .= "\n" . $m[1];

                return '';
            },
            $html
        );

        return [$out ?? $html, $css];
    }

    /**
     * Переписать стили письма так, чтобы они действовали только внутри $scope.
     *
     * @param  string  $scope  селектор области, например «.msg__body-inner»
     * @param  string[]  $allowedProperties  какие свойства пропускать
     */
    public static function scope(string $css, string $scope, array $allowedProperties): string
    {
        if (trim($css) === '') {
            return '';
        }
        $css = mb_substr($css, 0, self::MAX_INPUT);
        $css = self::stripComments($css);
        $allowed = array_flip(array_map('strtolower', $allowedProperties));

        $out = [];
        $rules = 0;
        foreach (self::blocks($css) as [$prelude, $body, $isAt]) {
            if ($rules >= self::MAX_RULES) {
                break;
            }
            if ($isAt) {
                $name = strtolower(ltrim(explode(' ', trim($prelude))[0], '@'));
                if (in_array($name, self::FORBIDDEN_AT, true)) {
                    continue;
                }
                if ($name !== 'media' && $name !== 'supports') {
                    continue;   // незнакомое правило уровня страницы не пропускаем
                }
                $inner = self::scope($body, $scope, $allowedProperties);
                if (trim($inner) !== '') {
                    $out[] = trim($prelude) . " {\n" . $inner . "}\n";
                    $rules++;
                }

                continue;
            }
            $selector = self::scopeSelector($prelude, $scope);
            if ($selector === null) {
                continue;
            }
            $decls = self::filterDeclarations($body, $allowed);
            if ($decls === '') {
                continue;
            }
            $out[] = $selector . ' { ' . $decls . " }\n";
            $rules++;
        }

        return implode('', $out);
    }

    /** Убрать комментарии: внутри них прячут и селекторы, и закрывающие скобки. */
    private static function stripComments(string $css): string
    {
        return (string) preg_replace('#/\*.*?\*/#s', ' ', $css);
    }

    /**
     * Разложить CSS на блоки «преамбула { тело }» с учётом вложенности @media.
     *
     * @return array<int,array{0:string,1:string,2:bool}>
     */
    private static function blocks(string $css): array
    {
        $out = [];
        $len = strlen($css);
        $prelude = '';
        $i = 0;
        while ($i < $len) {
            $ch = $css[$i];
            if ($ch === '{') {
                $depth = 1;
                $body = '';
                $i++;
                while ($i < $len && $depth > 0) {
                    $c = $css[$i];
                    if ($c === '{') {
                        $depth++;
                    } elseif ($c === '}') {
                        $depth--;
                        if ($depth === 0) {
                            break;
                        }
                    }
                    $body .= $c;
                    $i++;
                }
                $i++;
                $p = trim($prelude);
                if ($p !== '') {
                    $out[] = [$p, $body, str_starts_with($p, '@')];
                }
                $prelude = '';

                continue;
            }
            if ($ch === '}') {   // лишняя закрывающая — пропускаем
                $prelude = '';
                $i++;

                continue;
            }
            if ($ch === '@' && $prelude === '') {
                // Правило без тела: @import "...";
                $end = strpos($css, ';', $i);
                $brace = strpos($css, '{', $i);
                if ($end !== false && ($brace === false || $end < $brace)) {
                    $i = $end + 1;

                    continue;
                }
            }
            $prelude .= $ch;
            $i++;
        }

        return $out;
    }

    /**
     * Приписать области каждый селектор списка.
     *
     * Селекторы уровня страницы (html, body, :root) превращаются в саму область: письмо
     * вправе оформить свой «фон страницы», но только внутри себя.
     */
    private static function scopeSelector(string $prelude, string $scope): ?string
    {
        $parts = [];
        foreach (explode(',', $prelude) as $sel) {
            $sel = trim(preg_replace('/\s+/', ' ', $sel) ?? '');
            if ($sel === '' || mb_strlen($sel) > 200) {
                continue;
            }
            // Ничего исполняемого и никаких выходов за область.
            if (preg_match('/[{}<>;()\\\\]|javascript:|expression|@/i', $sel)) {
                continue;
            }
            if (preg_match('/^(html|body|:root)\b/i', $sel)) {
                $rest = trim((string) preg_replace('/^(html|body|:root)\b/i', '', $sel));

                $parts[] = $rest === '' ? $scope : $scope . ' ' . $rest;

                continue;
            }
            $parts[] = $scope . ' ' . $sel;
        }

        return $parts ? implode(', ', $parts) : null;
    }

    /** Оставить только разрешённые свойства и только те значения, что не ходят в сеть. */
    private static function filterDeclarations(string $body, array $allowed): string
    {
        $out = [];
        foreach (explode(';', $body) as $decl) {
            $pos = strpos($decl, ':');
            if ($pos === false) {
                continue;
            }
            $prop = strtolower(trim(substr($decl, 0, $pos)));
            $value = trim(substr($decl, $pos + 1));
            if ($prop === '' || $value === '' || ! isset($allowed[$prop])) {
                continue;
            }
            if (mb_strlen($value) > 500) {
                continue;
            }
            // Обращение к сети из стилей — это следящий пиксель. Встроенные data: оставляем.
            if (preg_match('/url\s*\(/i', $value) && ! preg_match('/url\s*\(\s*["\']?data:/i', $value)) {
                continue;
            }
            if (preg_match('/expression|javascript:|behavior|@import|\\\\/i', $value)) {
                continue;
            }
            // !important письма перебивал бы наши собственные стили чтения.
            $value = trim((string) preg_replace('/!\s*important/i', '', $value));
            if ($value === '') {
                continue;
            }
            $out[] = $prop . ': ' . $value;
        }

        return implode('; ', $out);
    }
}
