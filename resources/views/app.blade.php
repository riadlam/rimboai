<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    @php($gtmId = config('services.gtm.id'))
    @if ($gtmId)
    <!-- Google Tag Manager -->
    <script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
    new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
    j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
    'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
    })(window,document,'script','dataLayer','{{ $gtmId }}');</script>
    <!-- End Google Tag Manager -->
    @endif

    @include('partials.seo-meta')

    <link rel="icon" href="/storage/ai_icons/logo_icon_only.png" type="image/png">
    <link rel="shortcut icon" href="/storage/ai_icons/logo_icon_only.png" type="image/png">
    <link rel="apple-touch-icon" href="/storage/ai_icons/logo_icon_only.png">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700&family=Inter:wght@400;500;600;700&family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">

    <script>
        (function () {
            try {
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
            } catch (e) {
                document.documentElement.classList.add('dark');
                document.documentElement.lang = 'en';
                document.documentElement.dir = 'ltr';
            }
        })();
    </script>

    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.tsx'])
    @inertiaHead
</head>
<body class="min-h-screen bg-surface dark:bg-[var(--dark-surface)] text-text-primary dark:text-[var(--dark-text-primary)] antialiased">
    @if ($gtmId)
    <!-- Google Tag Manager (noscript) -->
    <noscript><iframe src="https://www.googletagmanager.com/ns.html?id={{ $gtmId }}"
    height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
    <!-- End Google Tag Manager (noscript) -->
    @endif
    <div
        id="app-boot"
        role="status"
        aria-live="polite"
        style="position:fixed;inset:0;z-index:9999;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:1rem;background:#0d0d12;color:#e4e4e7;font-family:system-ui,-apple-system,sans-serif"
    >
        <img src="/storage/ai_icons/logo_icon_only.png" alt="" width="56" height="56" style="width:3.5rem;height:3.5rem;border-radius:1rem" />
        <p style="margin:0;font-size:0.875rem;color:#a1a1aa">Loading…</p>
        <p data-boot-hint hidden style="margin:0;max-width:16rem;padding:0 1rem;font-size:0.75rem;line-height:1.5;text-align:center;color:#71717a">
            Taking too long? Tap ⋯ in the menu and choose &ldquo;Open in browser&rdquo;.
        </p>
    </div>
    @inertia
    <noscript>
        <div style="max-width:48rem;margin:2rem auto;padding:0 1rem;font-family:system-ui,sans-serif">
            <h1>{{ config('seo.site_name', config('app.name')) }}</h1>
            <p>{{ config('seo.description') }}</p>
            <p><a href="{{ url('/lab') }}">Open AI Lab</a> · <a href="{{ url('/tools') }}">Video Tools</a> · <a href="{{ url('/pricing') }}">Pricing</a></p>
        </div>
    </noscript>
</body>
</html>
