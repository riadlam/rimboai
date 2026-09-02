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

    <?php
        $viteManifestPath = public_path('build/manifest.json');
        $viteManifest = is_file($viteManifestPath)
            ? json_decode(file_get_contents($viteManifestPath), true)
            : [];
        $legacyPolyfill = $viteManifest['vite/legacy-polyfills-legacy']['file'] ?? null;
        $legacyEntry = $viteManifest['resources/js/app-legacy.tsx']['file'] ?? null;
        $hasLegacyBundle = is_string($legacyPolyfill) && is_string($legacyEntry);
    ?>

    @if ($hasLegacyBundle)
        {{-- Detect module-capable WebViews that cannot parse the modern production bundle. --}}
        <script type="module">import.meta.url;import("_").catch(()=>1);(async function*(){})().next();window.__vite_is_modern_browser=true</script>
        <script type="module">!function(){if(window.__vite_is_modern_browser)return;console.warn("Loading compatibility bundle");var e=document.getElementById("vite-legacy-polyfill"),n=document.createElement("script");if(!e)return;n.src=e.src;n.onload=function(){System.import(document.getElementById("vite-legacy-entry").getAttribute("data-src"))};document.body.appendChild(n)}();</script>
    @endif

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
        <p data-boot-loading style="margin:0;font-size:0.875rem;color:#a1a1aa">Loading…</p>
        <div data-boot-error hidden style="max-width:20rem;padding:0 1rem;text-align:center">
            <p style="margin:0;font-size:0.875rem;line-height:1.5;color:#d4d4d8">
                This browser could not start the app.
            </p>
            <p style="margin:.5rem 0 0;font-size:.75rem;line-height:1.5;color:#71717a">
                Refresh this page, or tap ⋯ and choose &ldquo;Open in browser&rdquo;.
            </p>
            <button
                type="button"
                onclick="location.reload()"
                style="margin-top:1rem;border:0;border-radius:.75rem;background:#FF5733;padding:.65rem 1.25rem;color:white;font:600 .875rem system-ui,-apple-system,sans-serif"
            >Refresh</button>
        </div>
    </div>
    @inertia
    @if ($hasLegacyBundle)
        <script nomodule>!function(){var e=document,t=e.createElement("script");if(!("noModule"in t)&&"onbeforeload"in t){var n=!1;e.addEventListener("beforeload",(function(e){if(e.target===t)n=!0;else if(!e.target.hasAttribute("nomodule")||!n)return;e.preventDefault()}),!0),t.type="module",t.src=".",e.head.appendChild(t),t.remove()}}();</script>
        <script nomodule crossorigin id="vite-legacy-polyfill" src="{{ asset('build/'.$legacyPolyfill) }}"></script>
        <script nomodule crossorigin id="vite-legacy-entry" data-src="{{ asset('build/'.$legacyEntry) }}">System.import(document.getElementById('vite-legacy-entry').getAttribute('data-src'))</script>
    @endif
    <noscript>
        <div style="max-width:48rem;margin:2rem auto;padding:0 1rem;font-family:system-ui,sans-serif">
            <h1>{{ config('seo.site_name', config('app.name')) }}</h1>
            <p>{{ config('seo.description') }}</p>
            <p><a href="{{ url('/lab') }}">Open AI Lab</a> · <a href="{{ url('/tools') }}">Video Tools</a> · <a href="{{ url('/pricing') }}">Pricing</a></p>
        </div>
    </noscript>
</body>
</html>
