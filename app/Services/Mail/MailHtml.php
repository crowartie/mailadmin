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
     * Письмо — чужой HTML. Режем скрипты, формы, внешние ресурсы и стили,
     * которые могут вылезти за пределы окна чтения. Картинки data: (встроенные) оставляем.
     */
    public static function sanitize(string $html): string
    {
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
        $config->set('CSS.AllowedProperties', [
            'color', 'background-color', 'background', 'font-weight', 'font-style', 'font-variant',
            'text-decoration', 'text-align', 'text-transform', 'font-size', 'font-family',
            'letter-spacing', 'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
            'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
            'border', 'border-top', 'border-right', 'border-bottom', 'border-left',
            'border-color', 'border-style', 'border-width', 'border-radius',
            'border-collapse', 'border-spacing',
            'width', 'max-width', 'min-width', 'height', 'max-height',
            'line-height', 'vertical-align', 'white-space', 'list-style-type',
        ]);
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

        return self::blockRemote((new \HTMLPurifier($config))->purify($html));
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
