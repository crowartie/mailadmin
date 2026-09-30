@php
    $domain = config('areas.default_domain');
    $size = $r ? number_format($r['size'] / 1048576, 1, ',', ' ') . ' МБ' : '';
    $date = '';
    if ($r) {
        try { $date = \Illuminate\Support\Carbon::parse($r['date'])->translatedFormat('j F Y'); } catch (\Throwable) { $date = (string) $r['date']; }
    }
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
    // Приложение для Windows (desktop/, DesktopRelease): отдельная карточка ниже.
    $w = $w ?? null;
    $wSize = $w ? number_format($w['size'] / 1048576, 1, ',', ' ') . ' МБ' : '';
    $wDate = '';
    if ($w) {
        try { $wDate = \Illuminate\Support\Carbon::parse($w['date'])->translatedFormat('j F Y'); } catch (\Throwable) { $wDate = (string) $w['date']; }
    }
    $wNotes = [];
    foreach (preg_split('/\R/u', (string) ($w['notes'] ?? '')) as $line) {
        if (preg_match('/^\s*[-•*]\s+(.*)$/u', $line, $m)) {
            $wNotes[] = $m[1];
        } elseif (trim($line) !== '' && $wNotes) {
            $wNotes[count($wNotes) - 1] .= ' ' . trim($line);
        }
    }
@endphp
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Приложения «Почта» — {{ $domain }}</title>
    <style>
        /* Те же токены, что у веб-почты (resources/scss/_variables.scss, гамма «А»): страница
           открывается до входа, поэтому цвета продублированы, а не подключены сборкой. */
        :root { --bg: #F5F3EF; --card: #fff; --text: #2B3036; --muted: #646B76; --faint: #646B76; --accent: #C94E00; --accent-on: #fff; --link: #1D5FD1; --line: #E6E4E0; }
        @media (prefers-color-scheme: dark) { :root { --bg: #1E2226; --card: #2A2F35; --text: #EDEBE7; --muted: #A3A9B2; --faint: #A3A9B2; --accent: #FF8A3D; --accent-on: #1E2226; --link: #7FA8FF; --line: #3A4048; } }
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
        .btn { display: inline-flex; align-items: center; gap: 10px; background: var(--accent); color: var(--accent-on); text-decoration: none; font-weight: 600; font-size: 17px; padding: 14px 24px; border-radius: 12px; }
        .meta { color: var(--faint); font-size: 13px; margin-top: 8px; }
        .qr { margin-left: auto; text-align: center; color: var(--faint); font-size: 12px; }
        .qr svg { background: #fff; padding: 8px; border-radius: 10px; display: block; margin-bottom: 6px; }
        ol, ul { margin: 0; padding-left: 20px; line-height: 1.55; font-size: 15px; }
        li { margin-bottom: 6px; }
        .note { color: var(--muted); font-size: 14px; line-height: 1.5; margin: 12px 0 0; }
        a.link { color: var(--link); }
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

    <div class="card" id="windows">
        <div class="head">
            <div class="icon">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8M12 16v4"/></svg>
            </div>
            <div>
                <h1 style="font-size: 20px">Почта для Windows</h1>
                <div class="sub">Отдельное окно с почтой, значок в трее со счётчиком, уведомления о письмах, запуск вместе с Windows</div>
            </div>
        </div>
        @if ($w)
            <div class="dl">
                <div>
                    <a class="btn" href="{{ url('/app/pochta-setup.exe') }}">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 4v12M6 11l6 6 6-6M5 20h14"/></svg>
                        Скачать для Windows
                    </a>
                    <div class="meta">Версия {{ $w['version'] }} · {{ $wDate }} · {{ $wSize }} · Windows 10 и 11</div>
                </div>
            </div>
            <ol style="margin-top: 16px">
                <li>Скачайте и откройте файл. Права администратора не нужны — приложение ставится только вам.</li>
                <li>Если Windows покажет «Система Windows защитила ваш компьютер» — нажмите «Подробнее», затем «Выполнить в любом случае». Так Windows реагирует на программы без платной цифровой подписи.</li>
                <li>Укажите свой адрес почты — приложение само найдёт сервер — и войдите, как в браузере. Закрытое окно уходит в трей — уведомления продолжат приходить.</li>
                <li>Ящики на других серверах (например, второй компании) добавляются кнопкой «+» слева; переключение — значками или Ctrl+1, Ctrl+2.</li>
            </ol>
            <p class="note">Обновления приложение ставит само. Меню значка в трее: написать письмо, уведомления, запуск вместе с Windows, выход.</p>
            @if ($wNotes)
                <h2 style="margin-top: 16px">Что нового в версии {{ $w['version'] }}</h2>
                <ul>
                    @foreach ($wNotes as $n)
                        <li>{{ $clean($n) }}</li>
                    @endforeach
                </ul>
            @endif
        @else
            <p class="note">Приложение для Windows ещё не выложено. На компьютере пользуйтесь веб-почтой: <a class="link" href="{{ url('/mail') }}">{{ parse_url(url('/'), PHP_URL_HOST) }}/mail</a>.</p>
        @endif
    </div>

    <div class="card">
        <h2>iPhone</h2>
        <p class="note" style="margin: 0">Откройте <a class="link" href="{{ url('/mail') }}">{{ parse_url(url('/'), PHP_URL_HOST) }}/mail</a> в Safari, нажмите «Поделиться» и «На экран “Домой”». Почта появится иконкой и откроется как приложение; в её настройках включите «Уведомления браузера о новых письмах».</p>
    </div>
</main>
</body>
</html>
