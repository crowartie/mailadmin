@php
    $domain = config('areas.default_domain');
    $size = $r ? number_format($r['size'] / 1048576, 1, ',', ' ') . ' МБ' : '';
    $date = $r ? \Illuminate\Support\Carbon::parse($r['date'])->translatedFormat('j F Y') : '';
    // «Что нового» пишется в CHANGELOG строками «- …»; продолжения строк склеиваем с предыдущим пунктом.
    $notes = [];
    // /u обязателен: без него \R принимает байт 0x85 внутри буквы «х» за перевод строки и режет текст.
    foreach (preg_split('/\R/u', (string) ($r['notes'] ?? '')) as $line) {
        if (preg_match('/^\s*[-•*]\s+(.*)$/u', $line, $m)) {
            $notes[] = $m[1];
        } elseif (trim($line) !== '' && $notes) {
            $notes[count($notes) - 1] .= ' ' . trim($line);
        }
    }
    $clean = fn ($s) => preg_replace('/\*\*(.+?)\*\*/u', '$1', $s);
@endphp
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Почта для Android — {{ $domain }}</title>
    <style>
        :root { --bg: #E9ECF1; --card: #fff; --text: #1B2430; --muted: #5A6472; --faint: #98A3B3; --accent: #2F6FEB; --line: #E3E7ED; }
        @media (prefers-color-scheme: dark) { :root { --bg: #0F1318; --card: #1A2029; --text: #E6EAF0; --muted: #A4ADBA; --faint: #6E7888; --accent: #6B9BFF; --line: #2A323D; } }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); font-family: "Segoe UI", Roboto, Arial, sans-serif; color: var(--text); }
        main { max-width: 720px; margin: 0 auto; padding: 24px 16px 40px; }
        .card { background: var(--card); border-radius: 16px; box-shadow: 0 2px 12px rgba(27, 36, 48, .10); padding: 24px; margin-bottom: 16px; }
        .head { display: flex; gap: 16px; align-items: center; }
        .icon { width: 64px; height: 64px; border-radius: 16px; background: var(--accent); display: grid; place-items: center; flex: none; }
        h1 { margin: 0; font-size: 24px; }
        h2 { margin: 0 0 12px; font-size: 17px; }
        .sub { color: var(--muted); font-size: 14px; margin-top: 2px; }
        .dl { display: flex; gap: 20px; align-items: center; flex-wrap: wrap; margin-top: 20px; }
        .btn { display: inline-flex; align-items: center; gap: 10px; background: var(--accent); color: #fff; text-decoration: none; font-weight: 600; font-size: 17px; padding: 14px 24px; border-radius: 12px; }
        .meta { color: var(--faint); font-size: 13px; margin-top: 8px; }
        .qr { margin-left: auto; text-align: center; color: var(--faint); font-size: 12px; }
        .qr svg { background: #fff; padding: 8px; border-radius: 10px; display: block; margin-bottom: 6px; }
        ol, ul { margin: 0; padding-left: 20px; line-height: 1.55; font-size: 15px; }
        li { margin-bottom: 6px; }
        .note { color: var(--muted); font-size: 14px; line-height: 1.5; margin: 12px 0 0; }
        a.link { color: var(--accent); }
        @media (max-width: 600px) { .qr { display: none; } .btn { width: 100%; justify-content: center; } }
    </style>
</head>
<body>
<main>
    <div class="card">
        <div class="head">
            <div class="icon">
                <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="2.5" y="4.5" width="19" height="15" rx="2.5"/><path d="M3.5 6.5l8.5 6 8.5-6"/></svg>
            </div>
            <div>
                <h1>Почта для Android</h1>
                <div class="sub">Почта, календарь, контакты и облако {{ $domain }} — в одном приложении</div>
            </div>
        </div>
        @if ($r)
            <div class="dl">
                <div>
                    <a class="btn" href="{{ url('/app/pochta.apk') }}">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 4v12M6 10l6 6 6-6M4 20h16"/></svg>
                        Скачать приложение
                    </a>
                    <div class="meta">Версия {{ $r['version'] }} · {{ $date }} · {{ $size }}</div>
                </div>
                <div class="qr">{!! $qr !!}Откройте на телефоне</div>
            </div>
        @else
            <p class="note">Приложение ещё не выложено. Загляните позже или спросите администратора.</p>
        @endif
    </div>

    @if ($r)
        <div class="card">
            <h2>Как установить</h2>
            <ol>
                <li>Нажмите «Скачать приложение» на телефоне и откройте скачанный файл.</li>
                <li>Если телефон спросит — разрешите установку приложений из браузера. Это нужно один раз.</li>
                <li>Если появится «Приложение не проверено» или «Неизвестное приложение» — нажмите «Всё равно установить»: приложение ставится не из магазина, а с сервера почты.</li>
                <li>Откройте «Почту», введите адрес и пароль от почты.</li>
            </ol>
            <p class="note">Новые версии приложение предлагает само: «Ещё → О приложении» или плашка при запуске.</p>
        </div>

        @if ($notes)
            <div class="card">
                <h2>Что нового в версии {{ $r['version'] }}</h2>
                <ul>
                    @foreach ($notes as $n)
                        <li>{{ $clean($n) }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
    @endif

    <div class="card">
        <h2>iPhone и компьютер</h2>
        <p class="note" style="margin: 0">Для iPhone приложения пока нет — пользуйтесь веб-почтой в браузере: <a class="link" href="{{ url('/mail') }}">{{ parse_url(url('/'), PHP_URL_HOST) }}/mail</a>. На компьютере — тоже веб-почта.</p>
    </div>
</main>
</body>
</html>
