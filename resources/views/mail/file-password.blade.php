@php
    $domain = config('areas.default_domain');
@endphp
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Файл защищён паролем</title>
    <style>
        body { margin: 0; background: #E9ECF1; font-family: "Segoe UI", Arial, sans-serif; color: #1B2430; display: grid; place-items: center; min-height: 100vh; }
        .box { background: #fff; border-radius: 14px; box-shadow: 0 2px 12px rgba(27, 36, 48, .12); padding: 28px 32px; max-width: 460px; width: calc(100% - 32px); box-sizing: border-box; margin: 16px; }
        h1 { margin: 0 0 10px; font-size: 20px; }
        p { margin: 0 0 8px; font-size: 14px; line-height: 1.5; color: #4B5563; }
        .name { font-weight: 600; color: #1B2430; overflow-wrap: anywhere; }
        form { display: flex; gap: 8px; margin-top: 14px; }
        input { flex: 1; min-width: 0; font: inherit; font-size: 15px; padding: 9px 12px; border: 1px solid #C9D1DC; border-radius: 8px; }
        input:focus { outline: 2px solid #3B6FD8; outline-offset: -1px; border-color: transparent; }
        button { font: inherit; font-size: 15px; padding: 9px 16px; border: 0; border-radius: 8px; background: #3B6FD8; color: #fff; cursor: pointer; }
        .err { color: #B42318; font-size: 14px; margin-top: 10px; }
        .foot { margin-top: 16px; font-size: 12px; color: #98A3B3; }
    </style>
</head>
<body>
<div class="box">
    <h1>Файл защищён паролем</h1>
    <p><span class="name">{{ $file->name }}</span></p>
    <p>Пароль отправитель сообщает отдельно — в письме его нет.</p>
    <form method="post" action="{{ $file->url() }}">
        <input type="password" name="password" autocomplete="off" autofocus required aria-label="Пароль">
        <button type="submit">Скачать</button>
    </form>
    @if ($error !== '')
        <div class="err">{{ $error }}</div>
    @endif
    <div class="foot">Почта {{ $domain }}</div>
</div>
</body>
</html>
