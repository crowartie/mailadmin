<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title inertia>Почта</title>
    <script>
        // Тема до загрузки приложения, чтобы страница не мигала светлым.
        try { var t = localStorage.getItem('mail.theme'); if (t === 'dark') document.documentElement.dataset.theme = 'dark'; var s = localStorage.getItem('mail.scheme'); if (s === 'classic') document.documentElement.dataset.scheme = 'classic';
            // Схема «Стекло»: переменные палитры сохранены при прошлом включении — ставим до загрузки, чтобы не мигало.
            if (s === 'glass') { var g = localStorage.getItem('mail.glassStyle'); if (g) { document.documentElement.dataset.scheme = 'glass'; document.documentElement.setAttribute('style', g); document.documentElement.dataset.theme = localStorage.getItem('mail.glassDark') === '1' ? 'dark' : 'light'; document.documentElement.dataset.motion = localStorage.getItem('mail.glassMotion') || 'expressive'; if (localStorage.getItem('mail.glassSplit') === '1') document.documentElement.dataset.glassSplit = '1'; } } } catch (e) {}
    </script>
    <!-- Иконка вкладки: конверт с логотипом; ?v= — чтобы браузеры не держали старую -->
    <link rel="icon" href="/favicon.ico?v=2" sizes="any">
    <link rel="icon" type="image/png" sizes="512x512" href="/icon-512.png?v=2">
    <link rel="apple-touch-icon" href="/icon-512.png?v=3">
    <!-- Веб-приложение (PWA): манифест, цвет шапки, полный экран на iPhone с экрана «Домой» -->
    <link rel="manifest" href="/manifest.webmanifest">
    <meta name="theme-color" content="#C94E00">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="Почта">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Golos+Text:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap">
    @vite(['resources/scss/app.scss', 'resources/js/app.js'])
    @inertiaHead
</head>
<body>
@inertia
</body>
</html>
