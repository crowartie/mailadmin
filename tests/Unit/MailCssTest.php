<?php

namespace Tests\Unit;

use App\Services\Mail\MailCss;
use App\Services\Mail\MailHtml;
use Tests\TestCase;

/**
 * Собственные стили письма: что из них проходит, а что нет.
 *
 * Эта проверка стоит на границе безопасности. Стили письма раньше выбрасывались целиком
 * именно потому, что через них письмо дотягивается до интерфейса: прячет страницу,
 * накрывает её собой, сообщает отправителю об открытии. Теперь они пропускаются, но
 * переписанными — и каждое «нельзя» ниже когда-то было причиной, по которой их вырезали.
 */
class MailCssTest extends TestCase
{
    private const SCOPE = '.msg__body-inner';

    private function scope(string $css): string
    {
        return MailCss::scope($css, self::SCOPE, MailHtml::CSS_PROPERTIES);
    }

    public function test_selector_gets_the_scope(): void
    {
        $out = $this->scope('.title { color: #333 }');
        $this->assertStringContainsString('.msg__body-inner .title', $out);
        $this->assertStringContainsString('color: #333', $out);
    }

    public function test_every_selector_in_a_list_gets_the_scope(): void
    {
        $out = $this->scope('.a, .b { color: red }');
        $this->assertStringContainsString('.msg__body-inner .a', $out);
        $this->assertStringContainsString('.msg__body-inner .b', $out);
    }

    /** Письмо не должно прятать страницу целиком: body превращается в само письмо. */
    public function test_body_selector_is_confined_to_the_message(): void
    {
        $out = $this->scope('body { background-color: #eee }');
        $this->assertStringContainsString('.msg__body-inner {', $out);
        $this->assertStringNotContainsString('body {', $out);
    }

    public function test_html_and_root_are_confined_too(): void
    {
        foreach (['html', ':root'] as $sel) {
            $out = $this->scope($sel . ' { color: red }');
            $this->assertStringStartsWith('.msg__body-inner', trim($out), "селектор {$sel} не ограничен");
        }
    }

    /** Свойства не из списка не проходят: position накрыл бы интерфейс собой. */
    public function test_dangerous_properties_do_not_pass(): void
    {
        foreach (['position: fixed', 'z-index: 99999', 'top: 0', 'transform: scale(9)', 'opacity: 0'] as $decl) {
            $out = $this->scope('.x { ' . $decl . ' }');
            $this->assertSame('', trim($out), "прошло недопустимое: {$decl}");
        }
    }

    /** Обращение к сети из стилей — следящий пиксель. */
    public function test_network_urls_are_dropped(): void
    {
        $out = $this->scope('.x { background: url(https://tracker.example/p.gif) }');
        $this->assertStringNotContainsString('tracker.example', $out);
    }

    public function test_embedded_images_are_kept(): void
    {
        $out = $this->scope('.x { background: url(data:image/gif;base64,R0lGOD) }');
        $this->assertStringContainsString('data:image/gif', $out);
    }

    public function test_page_level_rules_do_not_pass(): void
    {
        foreach (['@import url(https://evil.example/x.css);', '@font-face { font-family: a; }', '@page { margin: 0 }'] as $rule) {
            $out = $this->scope($rule . ' .x { color: red }');
            $this->assertStringNotContainsString('@import', $out);
            $this->assertStringNotContainsString('@font-face', $out);
            $this->assertStringNotContainsString('@page', $out);
        }
    }

    /** На @media держится вёрстка писем под телефон — он остаётся, но с областью внутри. */
    public function test_media_survives_and_inner_selectors_are_scoped(): void
    {
        $out = $this->scope('@media (max-width: 600px) { .col { width: 100% } }');
        $this->assertStringContainsString('@media (max-width: 600px)', $out);
        $this->assertStringContainsString('.msg__body-inner .col', $out);
    }

    /**
     * !important остаётся: на нём держится ширина колонок в рассылках. MJML пишет
     * «width: 21.93% !important», чтобы перебить встроенное width:100%; без этого
     * двухколоночная вёрстка складывается в стопку.
     */
    public function test_important_is_kept(): void
    {
        $out = $this->scope('.mj-column-per-21-93 { width: 21.93% !important }');
        $this->assertStringContainsString('width: 21.93% !important', $out);
    }

    /** display разрешён — на нём стоят колонки; но письму целиком спрятать себя нельзя. */
    public function test_display_is_allowed_inside_but_not_for_the_message_itself(): void
    {
        $inside = $this->scope('.col { display: inline-block }');
        $this->assertStringContainsString('display: inline-block', $inside);

        $whole = $this->scope('body { display: none }');
        $this->assertStringNotContainsString('display', $whole);
    }

    public function test_comments_cannot_hide_a_selector(): void
    {
        $out = $this->scope('/* } body { display: none } /* */ .x { color: red }');
        $this->assertStringNotContainsString('display: none', $out);
    }

    public function test_javascript_in_value_does_not_pass(): void
    {
        $out = $this->scope('.x { background: url("javascript:alert(1)") }');
        $this->assertStringNotContainsString('javascript', $out);
    }

    public function test_style_blocks_are_taken_out_of_the_message(): void
    {
        [$html, $css] = MailCss::extract('<p>текст</p><style>.a{color:red}</style><p>ещё</p>');
        $this->assertStringNotContainsString('<style', $html);
        $this->assertStringContainsString('текст', $html);
        $this->assertStringContainsString('ещё', $html);
        $this->assertStringContainsString('color:red', $css);
    }

    public function test_empty_input_gives_empty_output(): void
    {
        $this->assertSame('', $this->scope(''));
        $this->assertSame('', $this->scope('   '));
        $this->assertSame('', $this->scope('это не css вовсе'));
    }

    /**
     * Подключение шрифта не должно уносить с собой следующие правила.
     *
     * Написано по настоящей рассылке: в адресе шрифта есть точка с запятой
     * («wght@0,200..900;1,200..900»), и поиск конца правила по первой же «;»
     * обрывался на середине адреса. Хвост приклеивался к следующему правилу,
     * и блок @media со всей сеткой колонок отбрасывался целиком.
     */
    public function test_font_import_does_not_swallow_the_next_rules(): void
    {
        $css = '@import url(https://fonts.googleapis.com/css2?family=Source+Code+Pro:ital,wght@0,200..900;1,200..900&display=swap);'
            . '@import url(https://example.com/fonts.css);'
            . '@media only screen and (min-width:480px) { .mj-column-per-50 { width:50% !important; max-width: 50%; } }';

        $out = $this->scope($css);

        $this->assertStringContainsString('@media only screen and (min-width:480px)', $out);
        $this->assertStringContainsString('.msg__body-inner .mj-column-per-50', $out);
        $this->assertStringContainsString('width: 50% !important', $out);
        $this->assertStringNotContainsString('fonts.googleapis.com', $out);
        $this->assertStringNotContainsString('@import', $out);
    }

    /** Скобки и кавычки в значениях не должны сбивать счёт. */
    public function test_braces_inside_strings_do_not_break_parsing(): void
    {
        $out = $this->scope('.a:before { content: "}"; color: #111111 } .b { color: #222222 }');

        $this->assertStringContainsString('.msg__body-inner .b', $out);
        $this->assertStringContainsString('#222222', $out);
    }

    /** Незакрытая кавычка ломает правило — но наружу не должно уйти ничего сломанного. */
    public function test_unclosed_quote_produces_nothing_broken(): void
    {
        $out = $this->scope(".a { font-family: 'Broken }\n.b { color: #333333 }");

        $this->assertStringNotContainsString('Broken', $out);
        $this->assertSame(substr_count($out, '{'), substr_count($out, '}'), 'скобки в выводе не сходятся');
    }

    /** Письмо целиком: стили доезжают, но только своей областью. */
    public function test_sanitize_keeps_message_styles_scoped(): void
    {
        $html = MailHtml::sanitize('<style>body{display:none}.t{border-bottom:1px solid #ddd}</style><p class="t">текст</p>');
        $this->assertStringContainsString('текст', $html);
        $this->assertStringContainsString('.msg__body-inner .t', $html);
        $this->assertStringContainsString('border-bottom', $html);
        // Правило на письмо целиком не может менять display: спрятать себя не выйдет
        $this->assertStringNotContainsString('display: none', $html);
    }
}
