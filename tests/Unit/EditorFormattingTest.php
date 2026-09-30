<?php

namespace Tests\Unit;

use App\Services\Mail\MailHtml;
use Tests\TestCase;

/**
 * Панель оформления письма (обращение №53) вставляет цвет, выделение, размер, шрифт, выравнивание,
 * зачёркивание, таблицу с рамками, линию, моноширинный блок и заголовки. Всё это должно пережить
 * очистку при показе письма у получателя — иначе оформление молча пропадёт.
 */
class EditorFormattingTest extends TestCase
{
    public function test_оформление_редактора_переживает_очистку_при_показе(): void
    {
        $html = '<p style="text-align: center"><span style="color: rgb(198, 40, 40)">важно</span> '
            . '<span style="background-color: rgb(255, 243, 176)">срок</span> '
            . '<span style="font-size: large">крупно</span> '
            . '<span style="font-family: Georgia, &quot;Times New Roman&quot;, serif">засечки</span> '
            . '<strike>старое</strike> <s>тоже</s></p>'
            . '<table style="border-collapse: collapse"><tbody><tr><td style="border: 1px solid #cfcbc4; padding: 4px 8px">1</td><td style="border: 1px solid #cfcbc4; padding: 4px 8px">2</td></tr></tbody></table>'
            . '<hr><pre>код 123</pre><h2>Заголовок</h2>'
            . '<blockquote style="margin: 0 0 0 40px; border: none; padding: 0px">отступ</blockquote>';
        $out = MailHtml::sanitize($html);

        foreach (['text-align', 'color:', 'background-color', 'font-size', 'font-family', 'border-collapse', 'border:', 'padding'] as $keep) {
            $this->assertStringContainsString($keep, $out, "пропало свойство $keep");
        }
        foreach (['<strike>', '<s>', '<table', '<td', '<hr', '<pre>', '<h2>', '<blockquote'] as $tag) {
            $this->assertStringContainsString($tag, $out, "пропал тег $tag");
        }
    }

    /** Межстрочный интервал (обращение №65) ставится на абзацы и списки — получатель должен его увидеть. */
    public function test_межстрочный_интервал_переживает_очистку(): void
    {
        $out = MailHtml::sanitize('<div style="line-height: 1.5">первая строка<br>вторая</div><ul><li style="line-height: 2">пункт</li></ul>'
            . '<table><tbody><tr><td style="line-height: 1.15">ячейка</td></tr></tbody></table>');
        foreach (['line-height:1.5', 'line-height:2', 'line-height:1.15'] as $keep) {
            $this->assertStringContainsString($keep, str_replace(' ', '', $out), "пропал $keep");
        }
    }

    public function test_текстовая_версия_держит_таблицу_и_заголовок_на_своих_строках(): void
    {
        $m = new \ReflectionMethod(\App\Services\Mail\MailBuilder::class, 'htmlToText');
        $text = $m->invoke(null, '<h2>Смета</h2><table><tr><td>Насос</td><td>2</td></tr><tr><td>Кабель</td><td>10</td></tr></table><hr><p>Итого</p>');
        $this->assertSame("Смета\nНасос\t2\nКабель\t10\n\nИтого", $text);
    }
}
