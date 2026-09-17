@php
    use Carbon\Carbon;
    $domain = substr(strrchr($user, '@') ?: '', 1) ?: config('app.mail_domain', '');
    $when = null;
    if (! empty($m['date'])) {
        try { $when = Carbon::parse($m['date'])->timezone(config('app.timezone'))->locale('ru'); } catch (\Throwable) { $when = null; }
    }
    $addr = function (array $a): string {
        $name = trim((string) ($a['name'] ?? ''));
        $mail = trim((string) ($a['mail'] ?? ''));
        if ($name !== '' && $name !== $mail) {
            return '<b>' . e($name) . '</b> &lt;' . e($mail) . '&gt;';
        }
        return e($mail !== '' ? $mail : $name);
    };
    $list = fn (array $xs) => implode(', ', array_map($addr, $xs));
    // Размер и склонение — общие для всего приложения (App\Support\Format), чтобы один
    // и тот же файл не показывался «1.0 МБ» в очереди и «1 МБ» в письме.
    $size = fn (int $n): string => \App\Support\Format::size($n);
    $shown = $thread;
    $rest = $threadRest ?? 0;
@endphp
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $m['subject'] }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Golos+Text:wght@400;500;600;700&display=swap">
    <style>
        /* Печатная форма письма (деловой вариант). Поля страницы и подвал — через @page: Chrome/Edge с 131-й версии
           рисуют margin-боксы (дата печати слева, номер страницы справа); Firefox поля даст, а подвал пропустит. */
        @page {
            size: A4; margin: 14mm 16mm 18mm;
            @bottom-left { content: "Распечатано {{ str_replace('"', '', $printedAt) }}"; font: 11px "Golos Text", "Segoe UI", Arial, sans-serif; color: #98A3B3; vertical-align: top; padding-top: 4mm; }
            @bottom-right { content: "Почта {{ str_replace('"', '', $domain) }} · " counter(page) " / " counter(pages); font: 11px "Golos Text", "Segoe UI", Arial, sans-serif; color: #98A3B3; vertical-align: top; padding-top: 4mm; }
        }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body { background: #E9ECF1; font-family: "Golos Text", "Segoe UI", Arial, sans-serif; color: #1B2430; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        a { color: #1B4FC4; text-decoration: none; }
        .sheet { width: 210mm; min-height: 297mm; margin: 24px auto; background: #fff; box-shadow: 0 2px 12px rgba(27, 36, 48, .12); }
        .page { padding: 14mm 16mm 0; }
        .head { display: flex; flex-direction: column; gap: 6px; padding-bottom: 16px; }
        .head .box { font-size: 12px; color: #98A3B3; }
        .head h1 { margin: 0; font-size: 20px; line-height: 1.3; font-weight: 700; overflow-wrap: anywhere; }
        .meta { background: #F3F5F8; border: 1px solid #E3E8EF; padding: 14px 16px; display: grid; grid-template-columns: 80px minmax(0, 1fr); gap: 6px 12px; font-size: 13px; line-height: 1.4; break-inside: avoid; }
        .meta .k { color: #6B7787; }
        .meta .v { overflow-wrap: anywhere; }
        .meta b { font-weight: 600; }
        .body { padding: 22px 0 0; font-size: 14px; line-height: 1.55; overflow-wrap: break-word; }
        .body img { max-width: 100%; height: auto; }
        .body table { max-width: 100%; }
        .body blockquote { margin: 8px 0; padding-left: 12px; border-left: 3px solid #E3E8EF; color: #6B7787; }
        .body pre { white-space: pre-wrap; word-wrap: break-word; font: inherit; line-height: 1.6; margin: 0; }
        .body p { margin: 0 0 12px; }
        .thread { margin-top: 26px; padding-top: 12px; border-top: 1px solid #E3E8EF; font-size: 12.5px; color: #6B7787; display: flex; flex-direction: column; gap: 4px; break-inside: avoid; }
        .thread .t { font-weight: 600; color: #1B2430; }
        .thread .s { color: #1B2430; }
        /* 319: раньше от предыдущих писем печаталась одна строка заголовка, а текст пропадал.
           Печатаем их целиком, отделяя от основного письма и друг от друга. */
        .prev { break-inside: avoid; padding: 10px 0 2px; border-top: 1px dashed #E3E8EF; }
        .prev:first-of-type { border-top: 0; }
        .prev__head { font-weight: 600; color: #1B2430; }
        .prev__att { margin-top: 2px; }
        .prev__body { margin-top: 6px; color: #1B2430; font-size: 12.5px; }
        .prev__body pre { white-space: pre-wrap; word-wrap: break-word; font: inherit; margin: 0; }
        .prev__body img { max-width: 100%; height: auto; }
        .foot-screen { padding: 14mm 16mm 10mm; display: flex; justify-content: space-between; font-size: 11px; color: #98A3B3; }
        .bar { position: sticky; top: 0; z-index: 2; display: flex; gap: 8px; align-items: center; justify-content: center; padding: 10px 16px; background: rgba(233, 236, 241, .92); backdrop-filter: blur(6px); font-size: 13px; color: #6B7787; }
        .bar button { font: inherit; font-weight: 500; padding: 7px 14px; border-radius: 8px; border: 1px solid #C9D1DC; background: #fff; color: #1B2430; cursor: pointer; }
        .bar button.pri { background: #1B4FC4; border-color: #1B4FC4; color: #fff; }
        .bar button:hover { filter: brightness(.97); }
        @media print {
            body { background: #fff; }
            .bar { display: none; }
            /* 323: Firefox не рисует @bottom-left/@bottom-right, и дата печати пропадала
               с бумаги совсем. Там, где margin-боксы поддерживаются, прячем экранный
               подвал; где нет — он и остаётся подвалом. */
            .foot-screen { display: flex; padding: 6mm 0 0; border-top: 1px solid #E3E8EF; }
            @supports (page: a4) and (content: counter(page)) { .foot-screen { display: none; } }
            .sheet { width: auto; min-height: 0; margin: 0; box-shadow: none; }
            .page { padding: 0; }
        }
        @media (max-width: 820px) {
            .sheet { width: auto; margin: 0; min-height: 0; }
            .page { padding: 16px 16px 0; }
            .meta { grid-template-columns: minmax(0, 1fr); gap: 2px; }
            .meta .k { margin-top: 6px; }
            .foot-screen { padding: 0 16px 16px; }
        }
    </style>
</head>
<body>
<div class="bar">
    <button type="button" class="pri" onclick="window.print()">Печать</button>
    <button type="button" onclick="window.close()">Закрыть</button>
    <span>Так письмо будет выглядеть на бумаге.</span>
</div>
<div class="sheet">
            <div class="page">
                <div class="head">
                    <span class="box">Почта {{ $domain }} · ящик {{ $user }}</span>
                    <h1>{{ $m['subject'] }}</h1>
                </div>
                <div class="meta">
                    <span class="k">От</span><span class="v">{!! $addr($m['from'] ?? []) !!}</span>
                    <span class="k">Кому</span><span class="v">{!! $list($m['to'] ?? []) ?: '—' !!}</span>
                    @if (! empty($m['cc']))
                        <span class="k">Копия</span><span class="v">{!! $list($m['cc']) !!}</span>
                    @endif
                    <span class="k">Отправлено</span><span class="v">{{ $when ? $when->isoFormat('dddd, D MMMM YYYY, HH:mm') : '—' }}</span>
                    @if ($attachments)
                        <span class="k">Вложения</span>
                        <span class="v">{{ implode('; ', array_map(fn ($a) => $a['name'] . ' (' . $size((int) ($a['size'] ?? 0)) . ')', $attachments)) }}<br><i>Сами файлы не печатаются — откройте письмо в почте.</i></span>
                    @endif
                </div>
                <div class="body">
                    @if (! empty($m['html']))
                        {{-- 318: на экране внешние адреса спрятаны (data-blocked-*), а печать
                             выводила письмо как есть — отправитель рассылки узнавал об открытии
                             письма ровно в момент печати. Оставляем их спрятанными. --}}
                        {!! $m['html'] !!}
                    @else
                        <pre>{{ $m['text'] ?? '' }}</pre>
                    @endif
                </div>
                @if ($shown)
                    <div class="thread">
                        <span class="t">Ранее в переписке</span>
                        @foreach ($shown as $t)
                            @php
                                $td = null;
                                try { $td = ! empty($t['date']) ? Carbon::parse($t['date'])->timezone(config('app.timezone')) : null; } catch (\Throwable) { $td = null; }
                                $tf = trim((string) ($t['from']['name'] ?? '')) ?: (string) ($t['from']['mail'] ?? '');
                                $tatt = array_values(array_filter($t['attachments'] ?? [], fn ($a) => empty($a['inline'])));
                            @endphp
                            <div class="prev">
                                <div class="prev__head">{{ $td ? $td->format('d.m.Y, H:i') : '—' }} — {{ $tf }}: <span class="s">«{{ $t['subject'] ?? '(без темы)' }}»</span></div>
                                @if ($tatt)
                                    <div class="prev__att">Вложения: {{ implode('; ', array_map(fn ($a) => $a['name'] . ' (' . $size((int) ($a['size'] ?? 0)) . ')', $tatt)) }}</div>
                                @endif
                                @if (! empty($t['html']))
                                    <div class="prev__body">{!! $t['html'] !!}</div>
                                @elseif (! empty($t['text']))
                                    <div class="prev__body"><pre>{{ $t['text'] }}</pre></div>
                                @endif
                            </div>
                        @endforeach
                        @if ($rest > 0)
                            <span>и ещё {{ \App\Support\Format::count($rest, 'письмо', 'письма', 'писем') }} — не напечатаны: откройте их в почте</span>
                        @endif
                    </div>
                @endif
            </div>
    <div class="foot-screen"><span>Распечатано {{ $printedAt }}</span><span>Почта {{ $domain }}</span></div>
</div>
<script>
    // Ждём шрифт и картинки, затем сразу открываем диалог печати; страница остаётся — можно напечатать ещё раз.
    (function () {
        if (new URLSearchParams(location.search).get('auto') === '0') return;
        var ready = document.fonts && document.fonts.ready ? document.fonts.ready : Promise.resolve();
        window.addEventListener('load', function () {
            ready.then(function () { setTimeout(function () { window.print(); }, 250); });
        });
    })();
</script>
</body>
</html>
