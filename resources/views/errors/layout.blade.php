{{--
    Страница ошибки. Без неё Laravel показывает свою английскую заглушку «404 | Not Found»
    без единой ссылки: сотрудник, открывший старую закладку на переименованную папку,
    оказывался в тупике и правил адрес руками.
--}}
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') — Почта</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Golos+Text:wght@400;500;600;700&display=swap">
    <style>
        :root { color-scheme: light dark; }
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
            padding: 24px; background: #F2F4F7; color: #161D26;
            font-family: "Golos Text", "Segoe UI", system-ui, sans-serif; font-size: 15px; line-height: 1.5;
        }
        .card { width: 100%; max-width: 440px; background: #fff; border: 1px solid #DFE5EC; border-radius: 14px; padding: 28px; }
        .logo { width: 36px; height: 36px; border-radius: 10px; background: #FFCE3B; color: #161D26; display: flex; align-items: center; justify-content: center; font-weight: 700; }
        h1 { margin: 18px 0 8px; font-size: 21px; line-height: 1.25; font-weight: 700; }
        p { margin: 0 0 18px; color: #4A5563; }
        .btns { display: flex; flex-wrap: wrap; gap: 10px; }
        a.btn { text-decoration: none; font-weight: 500; font-size: 14px; padding: 9px 15px; border-radius: 9px; border: 1px solid #DFE5EC; color: #161D26; background: #fff; }
        a.btn--primary { background: #1B4FC4; border-color: #1B4FC4; color: #fff; }
        a.btn:focus-visible { outline: 2px solid #1B4FC4; outline-offset: 2px; }
        .code { margin-top: 20px; font-size: 12.5px; color: #6E7A89; }
        @media (prefers-color-scheme: dark) {
            body { background: #10151B; color: #E8EDF3; }
            .card { background: #171E26; border-color: #2A343F; }
            p { color: #B4BECB; }
            a.btn { background: #171E26; border-color: #2A343F; color: #E8EDF3; }
            a.btn--primary { background: #2F6FEB; border-color: #2F6FEB; color: #fff; }
            .code { color: #8593A3; }
        }
    </style>
</head>
<body>
    <main class="card">
        <div class="logo">П</div>
        <h1>@yield('title')</h1>
        <p>@yield('message')</p>
        <div class="btns">
            <a class="btn btn--primary" href="/mail">К письмам</a>
            <a class="btn" href="/mail/help">Справка</a>
            <a class="btn" href="/mail/feedback">Сообщить о проблеме</a>
        </div>
        <div class="code">Код ошибки: @yield('code')</div>
    </main>
</body>
</html>
