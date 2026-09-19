<?php

namespace Tests\Unit;

use App\Services\Mail\MailHtml;
use Tests\TestCase;

/**
 * Список разрешённого оформления должен быть таким, какой HTMLPurifier понимает.
 *
 * Написано по двум сбоям одного дня. Если в списке окажется свойство, которого он не знает
 * (border-radius и display требуют отдельных режимов), он не пропускает его молча,
 * а бросает ошибку — и письма с оформлением перестают открываться вовсе. Причём
 * не сразу: ошибка возникает при первой пересборке его собственного кэша, поэтому
 * сразу после правки всё выглядит работающим.
 *
 * Проверяем каждое свойство по отдельности через полную очистку письма — ровно так,
 * как это происходит в работе.
 */
class MailHtmlPropertiesTest extends TestCase
{
    /** Осмысленное значение для каждого свойства: пустое или бессмысленное отбрасывается само. */
    private const SAMPLES = [
        'color' => '#333333',
        'background-color' => '#eeeeee',
        'background' => '#ffffff',
        'font-weight' => 'bold',
        'font-style' => 'italic',
        'font-variant' => 'normal',
        'text-decoration' => 'underline',
        'text-align' => 'center',
        'text-transform' => 'uppercase',
        'font-size' => '14px',
        'font-family' => 'Arial, sans-serif',
        'letter-spacing' => '1px',
        'line-height' => '20px',
        'vertical-align' => 'top',
        'white-space' => 'nowrap',
        'list-style-type' => 'disc',
        'table-layout' => 'fixed',
        'border-collapse' => 'collapse',
        'border-spacing' => '0',
        'display' => 'inline-block',
    ];

    public function test_every_allowed_property_is_understood(): void
    {
        $unsupported = [];
        foreach (MailHtml::CSS_PROPERTIES as $prop) {
            $value = self::SAMPLES[$prop] ?? $this->guess($prop);
            try {
                MailHtml::sanitize('<p style="' . $prop . ': ' . $value . '">текст</p>');
            } catch (\Throwable $e) {
                $unsupported[] = $prop . ' → ' . $e->getMessage();
            }
        }

        $this->assertSame([], $unsupported, "свойства, которых очистка не понимает:\n" . implode("\n", $unsupported));
    }

    /** Опасное не должно проходить, сколько бы мы ни расширяли список оформления. */
    public function test_dangerous_properties_never_pass(): void
    {
        foreach (['position: fixed', 'position: absolute', 'z-index: 9999', 'top: 0', 'left: 0', 'transform: scale(4)', 'opacity: 0'] as $decl) {
            $html = MailHtml::sanitize('<p style="' . $decl . '">текст</p>');
            $name = explode(':', $decl)[0];
            $this->assertStringNotContainsString($name, $html, "прошло недопустимое: {$decl}");
        }
    }

    /** Письмо цело: очистка не должна съедать текст. */
    public function test_text_survives_sanitizing(): void
    {
        $html = MailHtml::sanitize('<p>Здравствуйте, «Ёлки» — 1234</p>');
        $this->assertStringContainsString('Здравствуйте', $html);
        $this->assertStringContainsString('«Ёлки»', $html);
    }

    private function guess(string $prop): string
    {
        if (str_contains($prop, 'width') || str_contains($prop, 'height')) {
            return '100px';
        }
        if (str_starts_with($prop, 'border')) {
            return '1px solid #cccccc';
        }

        return '4px';
    }
}
