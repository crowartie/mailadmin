<?php

namespace Tests\Unit;

use App\Services\Mail\MailCss;
use App\Services\Mail\MailHtml;
use PHPUnit\Framework\TestCase;

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

    /** !important письма перебивал бы наши собственные стили чтения. */
    public function test_important_is_stripped(): void
    {
        $out = $this->scope('.x { color: red !important }');
        $this->assertStringContainsString('color: red', $out);
        $this->assertStringNotContainsString('important', $out);
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

    /** Письмо целиком: стили доезжают, но только своей областью. */
    public function test_sanitize_keeps_message_styles_scoped(): void
    {
        $html = MailHtml::sanitize('<style>body{display:none}.t{border-bottom:1px solid #ddd}</style><p class="t">текст</p>');
        $this->assertStringContainsString('текст', $html);
        $this->assertStringContainsString('.msg__body-inner .t', $html);
        $this->assertStringContainsString('border-bottom', $html);
        // display не в списке разрешённого, поэтому «спрятать страницу» не выйдет
        $this->assertStringNotContainsString('display: none', $html);
    }
}
