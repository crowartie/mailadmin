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
            return '<b>' . e($name) . '</b> <span class="mail">&lt;' . e($mail) . '&gt;</span>';
        }
        return e($mail !== '' ? $mail : $name);
    };
    $list = fn (array $xs) => implode(', ', array_map($addr, $xs));
    // Размер и склонение — общие для всего приложения (App\Support\Format), чтобы один
    // и тот же файл не показывался «1.0 МБ» в очереди и «1 МБ» в письме.
    $size = fn (int $n): string => \App\Support\Format::size($n);
    $shown = $thread;
    $rest = $threadRest ?? 0;
    // Внутри предпросмотра почты страница показывается в рамке: без своей панели кнопок
    // и без серого поля вокруг листа — их даёт сама почта.
    $embed = request()->query('embed') === '1';
    $total = 1 + count($shown) + $rest;
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
        /* Печатная форма письма — по образцу Gmail и Outlook: строка «кто печатает» мелко сверху,
           тема заголовком, поля простыми строками, текст без рамок, вложения списком в конце.
           Поля страницы и подвал — через @page: Chrome/Edge с 131-й версии рисуют margin-боксы
           (дата печати слева, номер страницы справа); Firefox поля даст, а подвал пропустит. */
        @page {
            size: A4; margin: 14mm 16mm 18mm;
            @bottom-left { content: "Распечатано {{ str_replace('"', '', $printedAt) }}"; font: 10.5px "Golos Text", "Segoe UI", Arial, sans-serif; color: #98A3B3; vertical-align: top; padding-top: 4mm; }
            @bottom-right { content: "Почта {{ str_replace('"', '', $domain) }} · " counter(page) " / " counter(pages); font: 10.5px "Golos Text", "Segoe UI", Arial, sans-serif; color: #98A3B3; vertical-align: top; padding-top: 4mm; }
        }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body { background: {{ $embed ? 'transparent' : '#E9ECF1' }}; font-family: "Golos Text", "Segoe UI", Arial, sans-serif; color: #1B2430; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        a { color: #1B4FC4; text-decoration: none; }
        .sheet { width: 210mm; min-height: 297mm; margin: {{ $embed ? '16px' : '24px' }} auto; background: #fff; box-shadow: 0 2px 12px rgba(27, 36, 48, .12); padding: 14mm 16mm 16mm; }

        /* Шапка: почта слева, ящик справа — как строка «Gmail · адрес» у Gmail. */
        .top { display: flex; justify-content: space-between; gap: 12px; font-size: 11.5px; color: #98A3B3; padding-bottom: 10px; border-bottom: 1px solid #E3E8EF; }
        h1 { margin: 18px 0 4px; font-size: 21px; line-height: 1.3; font-weight: 700; overflow-wrap: anywhere; }
        .count { font-size: 12.5px; color: #6B7787; margin-bottom: 14px; }

        /* Одно письмо: шапка «кто → кому, когда», затем текст. */
        .msg { break-inside: auto; }
        .msg + .msg { margin-top: 22px; padding-top: 18px; border-top: 1px solid #E3E8EF; }
        .hd { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 2px 16px; font-size: 13px; line-height: 1.45; padding: 12px 0; border-top: 1px solid #E3E8EF; border-bottom: 1px solid #E3E8EF; break-inside: avoid; }
        .hd .from { font-size: 14px; }
        .hd .when { color: #6B7787; white-space: nowrap; text-align: right; }
        .hd .to { grid-column: 1 / -1; color: #6B7787; overflow-wrap: anywhere; }
        .hd .to b { color: #1B2430; font-weight: 600; }
        .hd .mail { color: #6B7787; font-weight: 400; }
        .msg--prev .hd { border-top: 0; padding-top: 0; }
        .body { padding: 18px 0 0; font-size: 14px; line-height: 1.55; overflow-wrap: break-word; }
        .body img { max-width: 100%; height: auto; }
        .body table { max-width: 100%; }
        .body blockquote { margin: 8px 0; padding-left: 12px; border-left: 3px solid #E3E8EF; color: #6B7787; }
        .body pre { white-space: pre-wrap; word-wrap: break-word; font: inherit; line-height: 1.6; margin: 0; }
        .body p { margin: 0 0 12px; }
        .msg--prev .body { font-size: 13px; }

        /* Вложения — списком после текста, с размерами; сами файлы на бумагу не попадают. */
        .atts { margin-top: 16px; padding-top: 10px; border-top: 1px solid #E3E8EF; font-size: 12.5px; break-inside: avoid; }
        .atts .t { color: #6B7787; margin-bottom: 4px; }
        .atts ul { margin: 0; padding: 0; list-style: none; }
        .atts li { padding: 2px 0; }
        .atts li span { color: #98A3B3; }
        .atts .note { color: #98A3B3; margin-top: 4px; font-size: 11.5px; }

        .rest { margin-top: 16px; font-size: 12.5px; color: #6B7787; }
        .foot-screen { display: flex; justify-content: space-between; font-size: 11px; color: #98A3B3; margin-top: 24px; padding-top: 8px; border-top: 1px solid #E3E8EF; }

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
            @supports (page: a4) and (content: counter(page)) { .foot-screen { display: none; } }
            .sheet { width: auto; min-height: 0; margin: 0; box-shadow: none; padding: 0; }
        }
        @media (max-width: 820px) {
            .sheet { width: auto; margin: 0; min-height: 0; padding: 16px; }
            .hd { grid-template-columns: minmax(0, 1fr); }
            .hd .when { text-align: left; }
        }
    </style>
</head>
<body>
@unless ($embed)
<div class="bar">
    <button type="button" class="pri" onclick="window.print()">Печать</button>
    <button type="button" onclick="window.close()">Закрыть</button>
    <span>Так письмо будет выглядеть на бумаге.</span>
</div>
@endunless
<div class="sheet">
    <div class="top"><span>Почта {{ $domain }}</span><span>{{ $user }}</span></div>
    <h1>{{ $m['subject'] }}</h1>
    @if ($total > 1)
        <div class="count">{{ \App\Support\Format::count($total, 'письмо', 'письма', 'писем') }} в переписке</div>
    @else
        <div class="count"></div>
    @endif

    <div class="msg">
        <div class="hd">
            <span class="from">{!! $addr($m['from'] ?? []) !!}</span>
            <span class="when">{{ $when ? $when->isoFormat('D MMMM YYYY, HH:mm') : '—' }}</span>
            <span class="to">Кому: {!! $list($m['to'] ?? []) ?: '—' !!}@if (! empty($m['cc'])) &nbsp;·&nbsp; Копия: {!! $list($m['cc']) !!}@endif</span>
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
        @if ($attachments)
            <div class="atts">
                <div class="t">{{ \App\Support\Format::count(count($attachments), 'вложение', 'вложения', 'вложений') }}</div>
                <ul>
                    @foreach ($attachments as $a)
                        <li>{{ $a['name'] }} <span>{{ $size((int) ($a['size'] ?? 0)) }}</span></li>
                    @endforeach
                </ul>
                <div class="note">Сами файлы не печатаются — откройте письмо в почте.</div>
            </div>
        @endif
    </div>

    {{-- 319: раньше от предыдущих писем печаталась одна строка заголовка, а текст пропадал.
         Печатаем их целиком, каждое со своей шапкой — как Gmail печатает переписку. --}}
    @foreach ($shown as $t)
        @php
            $td = null;
            try { $td = ! empty($t['date']) ? Carbon::parse($t['date'])->timezone(config('app.timezone'))->locale('ru') : null; } catch (\Throwable) { $td = null; }
            $tatt = array_values(array_filter($t['attachments'] ?? [], fn ($a) => empty($a['inline'])));
        @endphp
        <div class="msg msg--prev">
            <div class="hd">
                <span class="from">{!! $addr($t['from'] ?? []) !!}</span>
                <span class="when">{{ $td ? $td->isoFormat('D MMMM YYYY, HH:mm') : '—' }}</span>
                <span class="to">Кому: {!! $list($t['to'] ?? []) ?: '—' !!}</span>
            </div>
            @if (! empty($t['html']))
                <div class="body">{!! $t['html'] !!}</div>
            @elseif (! empty($t['text']))
                <div class="body"><pre>{{ $t['text'] }}</pre></div>
            @endif
            @if ($tatt)
                <div class="atts"><div class="t">Вложения</div><ul>@foreach ($tatt as $a)<li>{{ $a['name'] }} <span>{{ $size((int) ($a['size'] ?? 0)) }}</span></li>@endforeach</ul></div>
            @endif
        </div>
    @endforeach
    @if ($rest > 0)
        <div class="rest">И ещё {{ \App\Support\Format::count($rest, 'письмо', 'письма', 'писем') }} переписки не напечатано — откройте их в почте.</div>
    @endif

    <div class="foot-screen"><span>Распечатано {{ $printedAt }}</span><span>Почта {{ $domain }}</span></div>
</div>
<script>
    // Ждём шрифт и картинки, затем открываем диалог печати — если не просили не делать этого
    // (auto=0: предпросмотр в почте и «открыть в отдельной вкладке» печатают по кнопке).
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
