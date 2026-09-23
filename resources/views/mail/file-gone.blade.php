@php
    $domain = config('areas.default_domain');
    $expired = ($reason ?? '') === 'expired';
    $unavailable = ($reason ?? '') === 'unavailable';
@endphp
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $expired ? 'Срок хранения файла истёк' : ($unavailable ? 'Файл временно недоступен' : 'Файла нет') }}</title>
    <style>
        body { margin: 0; background: #E9ECF1; font-family: "Segoe UI", Arial, sans-serif; color: #1B2430; display: grid; place-items: center; min-height: 100vh; }
        .box { background: #fff; border-radius: 14px; box-shadow: 0 2px 12px rgba(27, 36, 48, .12); padding: 28px 32px; max-width: 460px; margin: 16px; }
        h1 { margin: 0 0 10px; font-size: 20px; }
        p { margin: 0 0 8px; font-size: 14px; line-height: 1.5; color: #4B5563; }
        .name { font-weight: 600; color: #1B2430; overflow-wrap: anywhere; }
        .foot { margin-top: 16px; font-size: 12px; color: #98A3B3; }
    </style>
</head>
<body>
<div class="box">
    @if ($expired)
        <h1>Срок хранения файла истёк</h1>
        <p>Файл <span class="name">{{ $file->name }}</span> хранился до {{ $file->expires_at?->format('d.m.Y') }}.</p>
        <p>Попросите отправителя продлить ссылку — в его почте это одна кнопка, файл никуда не делся.</p>
    @elseif ($unavailable)
        <h1>Файл временно недоступен</h1>
        <p>Хранилище сейчас не отвечает. Попробуйте открыть ссылку через несколько минут.</p>
    @else
        <h1>Такого файла нет</h1>
        <p>Ссылка неверна или файл удалён отправителем.</p>
    @endif
    <div class="foot">Почта {{ $domain }}</div>
</div>
</body>
</html>
