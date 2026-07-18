<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title inertia>{{ config('app.name', 'AI Studio') }}</title>

    <link rel="icon" href="/storage/ai_icons/logo_icon_only.png" type="image/png">
    <link rel="shortcut icon" href="/storage/ai_icons/logo_icon_only.png" type="image/png">
    <link rel="apple-touch-icon" href="/storage/ai_icons/logo_icon_only.png">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700&family=Inter:wght@400;500;600;700&family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">

    <script>
        (function () {
            var theme = localStorage.getItem('theme');
            if (theme === 'light') {
                document.documentElement.classList.remove('dark');
            } else {
                document.documentElement.classList.add('dark');
            }

            var lang = localStorage.getItem('app_lang');
            if (lang !== 'en' && lang !== 'fr' && lang !== 'ar') {
                lang = 'en';
            }
            document.documentElement.lang = lang;
            document.documentElement.dir = lang === 'ar' ? 'rtl' : 'ltr';
            document.cookie = 'app_lang=' + lang + ';path=/;max-age=31536000;SameSite=Lax';
        })();
    </script>

    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.tsx'])
    @inertiaHead
</head>
<body class="min-h-screen bg-surface dark:bg-[var(--dark-surface)] text-text-primary dark:text-[var(--dark-text-primary)] antialiased">
    @inertia
</body>
</html>
