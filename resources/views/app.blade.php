<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title inertia>Почта</title>
    <script>
        // Тема до загрузки приложения, чтобы страница не мигала светлым.
        try { var t = localStorage.getItem('mail.theme'); if (t === 'dark') document.documentElement.dataset.theme = 'dark'; } catch (e) {}
    </script>
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
