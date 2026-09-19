<?php

namespace App\Services\Mail;

/**
 * Тело письма в HTML: чистка чужой разметки и сокрытие внешних ссылок.
 *
 * Вынесено из MailStore: тоже чистые функции, и именно их удобнее всего покрыть тестами —
 * от них зависит и безопасность (скрипты, следящие пиксели), и вид письма.
 */
final class MailHtml
{
    /**
     * Что письму разрешено про оформление — один список и для встроенных стилей,
     * и для собственных блоков <style> письма (см. MailCss).
     *
     * Список нарочно перечислительный: сюда не попадают position, z-index, координаты
     * и преобразования — то, чем письмо могло бы вылезти за пределы своего места
     * и накрыть собой интерфейс.
     *
     * border-radius сюда не входит: HTMLPurifier знает его только в отдельном режиме
     * CSS.Proprietary, а таблицам он не нужен. Из-за него письма однажды перестали
     * открываться вовсе.
     */
    public const CSS_PROPERTIES = [
        'color', 'background-color', 'background', 'font-weight', 'font-style', 'font-variant',
        'text-decoration', 'text-align', 'text-transform', 'font-size', 'font-family',
        'letter-spacing', 'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
        'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
        'border', 'border-top', 'border-right', 'border-bottom', 'border-left',
        'border-color', 'border-style', 'border-width', 'border-collapse', 'border-spacing',
        'width', 'max-width', 'min-width', 'height', 'max-height',
        'line-height', 'vertical-align', 'white-space', 'list-style-type', 'table-layout',
        // На display держатся колонки: MJML, на котором свёрстано большинство рассылок,
        // ставит блоки рядом через inline-block. Требует режима CSS.AllowTricky (см. ниже);
        // выйти за пределы письма он не даёт — за это отвечают position, z-index
        // и координаты, и их в списке нет.
        'display',
    ];

    /** Область, внутри которой действуют собственные стили письма. */
    public const SCOPE = '.msg__body-inner';

    /**
     * Письмо — чужой HTML. Режем скрипты, формы, внешние ресурсы и стили,
     * которые могут вылезти за пределы окна чтения. Картинки data: (встроенные) оставляем.
     */
    public static function sanitize(string $html): string
    {
        // Собственные стили письма вынимаем до очистки: HTMLPurifier вырезал бы их целиком,
        // а вместе с ними — задуманную вёрстку. Вернём их в конце, но только внутрь письма.
        [$html, $ownCss] = MailCss::extract($html);

        $config = \HTMLPurifier_Config::createDefault();
        $config->set('Cache.SerializerPath', storage_path('app/purifier'));
        $config->set('HTML.ForbiddenElements', ['script', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'style', 'link', 'meta']);
        $config->set('HTML.ForbiddenAttributes', ['*@onclick', '*@onload', '*@onerror']);
        // Что письму разрешено про оформление. Список нарочно перечислительный: сюда не
        // попадают position, z-index, координаты и преобразования — то, чем письмо могло бы
        // вылезти за пределы своего места и накрыть собой интерфейс.
        //
        // Рамки, фон и отступы таблиц добавлены после сравнения с Mail.ru: без них письма,
        // свёрстанные таблицами (а это почти все рассылки), теряли разделительные линии
        // и превращались в сплошную простыню.
        // Что письму разрешено про оформление. Список нарочно перечислительный: сюда не
        // попадают position, z-index, координаты и преобразования — то, чем письмо могло бы
        // вылезти за пределы своего места и накрыть собой интерфейс.
        // Режим «хитрых» свойств нужен, чтобы HTMLPurifier вообще знал про display.
        // Он регистрирует описания display, visibility, position, float и overflow,
        // но что из них пропускать, решает список ниже: position там нет и не будет.
        $config->set('CSS.AllowTricky', true);
        $config->set('CSS.AllowedProperties', self::CSS_PROPERTIES);
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true, 'data' => true, 'tel' => true]);
        // Внешние ссылки на картинки оставляем в разметке, но прячем в data-blocked-* (blockRemote).
        // Раньше здесь стояло true: Purifier вырезал их совсем, поэтому обещанная кнопка
        // «показать картинки» ничего показать не могла, а рассылки и подписи выглядели пустыми.
        $config->set('URI.DisableExternalResources', false);
        $config->set('HTML.TargetBlank', true);
        // Пустые ячейки в письмах — это распорки: на них держатся отступы между блоками.
        // Пока их выбрасывали, у письма от OpenAI из 63 ячеек оставалось 36, и вёрстка
        // складывалась в сплошной текст. Пустой элемент занимает место, но ничего не делает,
        // поэтому опасности в нём нет.
        $config->set('AutoFormat.RemoveEmpty', false);

        if (! is_dir(storage_path('app/purifier'))) {
            @mkdir(storage_path('app/purifier'), 0775, true);
        }

        $clean = self::blockRemote((new \HTMLPurifier($config))->purify($html));
        $scoped = MailCss::scope($ownCss, self::SCOPE, self::CSS_PROPERTIES);

        // Блок собран нами из проверенных правил, поэтому добавляется после очистки.
        return $scoped === '' ? $clean : $clean . "\n<style>\n" . $scoped . '</style>';
    }

    /**
     * Спрятать всё, что тянется из интернета при открытии письма: это следящие пиксели.
     * Имя атрибута меняем, значение оставляем — «Показать картинки» возвращает его одной заменой.
     * Клиентская проверка ловила только src у img, поэтому srcset и background грузились молча.
     */
    public static function blockRemote(string $html): string
    {
        $html = (string) preg_replace(
            '/\s(src|background)\s*=\s*(["\'])\s*((?:https?:)?\/\/)/i',
            ' data-blocked-$1=$2$3',
            $html
        );
        // srcset — список адресов через запятую, первый может быть и относительным.
        return (string) preg_replace_callback(
            '/\ssrcset\s*=\s*(["\'])(.*?)\1/is',
            fn ($m) => preg_match('/(^|[\s,])(https?:)?\/\//i', $m[2])
                ? ' data-blocked-srcset=' . $m[1] . $m[2] . $m[1]
                : $m[0],
            $html
        );
    }
}
