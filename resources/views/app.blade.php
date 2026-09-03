<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <?php
        $viteManifestPath = public_path('build/manifest.json');
        $viteManifest = is_file($viteManifestPath)
            ? json_decode(file_get_contents($viteManifestPath), true)
            : [];
        $legacyPolyfill = $viteManifest['vite/legacy-polyfills-legacy']['file'] ?? null;
        $legacyEntry = $viteManifest['resources/js/app-legacy.tsx']['file'] ?? null;
        $hasLegacyBundle = is_string($legacyPolyfill) && is_string($legacyEntry);

        $metaManifestPath = public_path('build-meta/manifest.json');
        $metaManifest = is_file($metaManifestPath)
            ? json_decode(file_get_contents($metaManifestPath), true)
            : [];
        $metaEntry = $metaManifest['resources/js/app-meta.tsx']['file'] ?? null;
        $metaStyle = $metaManifest['style.css']['file'] ?? null;
        $hasMetaBundle = is_string($metaEntry) && is_string($metaStyle);

        $inAppUserAgent = (string) request()->userAgent();
        $isMetaWebView = preg_match(
            '/MetaIAB|FBAN|FBAV|FBIOS|FB4A|FB_IAB|Instagram|Messenger/i',
            $inAppUserAgent,
        ) === 1;
        $useMetaBundle = $hasMetaBundle
            && $isMetaWebView
            && ! is_file(public_path('hot'));
        $bootRequestId = (string) \Illuminate\Support\Str::uuid();
    ?>

    @php($gtmId = config('services.gtm.id'))
    <script>
        (function () {
            var requestId = @json($bootRequestId);
            var reported = {};

            function clean(value) {
                return String(value || '').replace(/[\r\n]+/g, ' ').slice(0, 500);
            }

            window.__reportBootFailure = function (stage, error) {
                if (window.__appBootState && window.__appBootState.stage === 'mounted') return;
                var state = window.__appBootState || {};
                var key = clean(stage) + ':' + clean(error || state.error);
                if (reported[key]) return;
                reported[key] = true;

                var query = [
                    'id=' + encodeURIComponent(requestId),
                    'stage=' + encodeURIComponent(clean(stage || state.stage || 'unknown')),
                    'entry=' + encodeURIComponent(clean(state.entry || 'blade')),
                    'error=' + encodeURIComponent(clean(error || state.error || '')),
                ].join('&');
                (new Image()).src = '/client/boot-report.gif?' + query;
            };

            window.addEventListener('error', function (event) {
                var target = event.target;
                var source = target && (target.src || target.href);
                if (source && !/\.js(?:[?#]|$)/i.test(source)) return;
                window.__reportBootFailure(
                    source ? 'resource-error' : 'window-error',
                    source || event.message || 'Unknown startup error'
                );
            }, true);

            window.addEventListener('unhandledrejection', function (event) {
                var reason = event.reason;
                window.__reportBootFailure(
                    'unhandled-rejection',
                    reason && reason.message ? reason.message : reason
                );
            });

            window.addEventListener('app:boot-stage', function (event) {
                if (!event.detail || event.detail.stage !== 'failed') return;
                window.__reportBootFailure('bootstrap-failed', event.detail.error || '');
            });
        })();
    </script>

    @if ($gtmId && ! $useMetaBundle)
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
    @if ($useMetaBundle)
        <link rel="stylesheet" href="{{ asset('build-meta/'.$metaStyle) }}">
    @else
        @vite(['resources/css/app.css', 'resources/js/app.tsx'])
    @endif
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
        <div data-boot-error hidden style="width:min(28rem,calc(100% - 2rem));text-align:center">
            <h1 style="margin:0;font-size:1.15rem;color:#fff">
                {{ config('seo.site_name', config('app.name')) }}
            </h1>
            <p style="margin:.6rem 0 0;font-size:.875rem;line-height:1.5;color:#d4d4d8">
                The interactive app could not start, but you can still browse the site.
            </p>
            <nav aria-label="Fallback navigation" style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.55rem;margin-top:1rem">
                <a href="{{ url('/') }}" style="border:1px solid #29292f;border-radius:.75rem;padding:.7rem;color:#fff;text-decoration:none">Home</a>
                <a href="{{ url('/trends') }}" style="border:1px solid #29292f;border-radius:.75rem;padding:.7rem;color:#fff;text-decoration:none">Trends</a>
                <a href="{{ url('/tools') }}" style="border:1px solid #29292f;border-radius:.75rem;padding:.7rem;color:#fff;text-decoration:none">Tools</a>
                <a href="{{ url('/lab') }}" style="border:1px solid #29292f;border-radius:.75rem;padding:.7rem;color:#fff;text-decoration:none">AI Lab</a>
                <a href="{{ url('/pricing') }}" style="border:1px solid #29292f;border-radius:.75rem;padding:.7rem;color:#fff;text-decoration:none">Pricing</a>
                <a href="{{ url('/?login=1') }}" style="border:1px solid #29292f;border-radius:.75rem;padding:.7rem;color:#fff;text-decoration:none">Sign in</a>
            </nav>
            <p style="margin:.8rem 0 0;font-size:.75rem;line-height:1.5;color:#71717a">
                You can also tap ⋯ and choose &ldquo;Open in browser&rdquo;.
            </p>
            <button
                type="button"
                onclick="location.reload()"
                style="margin-top:1rem;border:0;border-radius:.75rem;background:#FF5733;padding:.65rem 1.25rem;color:white;font:600 .875rem system-ui,-apple-system,sans-serif"
            >Refresh</button>
        </div>
    </div>
    @inertia
    @if ($useMetaBundle)
        {{-- A single classic IIFE: no ESM, dynamic import, nomodule, or SystemJS. --}}
        <script>window.__metaBootStarted=true;</script>
        <script src="{{ asset('build-meta/'.$metaEntry) }}" onerror="window.__reportBootFailure('meta-script-error',this.src)"></script>
    @elseif ($hasLegacyBundle)
        <script nomodule>!function(){var e=document,t=e.createElement("script");if(!("noModule"in t)&&"onbeforeload"in t){var n=!1;e.addEventListener("beforeload",(function(e){if(e.target===t)n=!0;else if(!e.target.hasAttribute("nomodule")||!n)return;e.preventDefault()}),!0),t.type="module",t.src=".",e.head.appendChild(t),t.remove()}}();</script>
        <script nomodule crossorigin id="vite-legacy-polyfill" src="{{ asset('build/'.$legacyPolyfill) }}"></script>
        <script nomodule crossorigin id="vite-legacy-entry" data-src="{{ asset('build/'.$legacyEntry) }}">System.import(document.getElementById('vite-legacy-entry').getAttribute('data-src'))</script>
    @endif
    <script>
        (function () {
            var recoveryStarted = Boolean(window.__metaBootStarted);
            var metaScript = @json($hasMetaBundle ? asset('build-meta/'.$metaEntry) : null);
            var metaStyle = @json($hasMetaBundle ? asset('build-meta/'.$metaStyle) : null);

            function bootIsPending() {
                return document.getElementById('app-boot') !== null;
            }

            window.__showAppBootError = function () {
                var boot = document.getElementById('app-boot');
                if (!boot) return;
                var loading = boot.querySelector('[data-boot-loading]');
                var error = boot.querySelector('[data-boot-error]');
                if (loading) loading.hidden = true;
                if (error) error.hidden = false;
            };

            function recoverBoot() {
                if (!bootIsPending() || recoveryStarted || !metaScript || !metaStyle) return;
                recoveryStarted = true;

                if (!document.getElementById('meta-app-style')) {
                    var style = document.createElement('link');
                    style.id = 'meta-app-style';
                    style.rel = 'stylesheet';
                    style.href = metaStyle;
                    document.head.appendChild(style);
                }

                var script = document.createElement('script');
                script.src = metaScript;
                script.onload = function () {
                    if (bootIsPending()) {
                        window.__reportBootFailure('meta-loaded-not-mounted', '');
                    }
                };
                script.onerror = function () {
                    window.__reportBootFailure('meta-script-error', metaScript);
                    window.__showAppBootError();
                };
                document.body.appendChild(script);
            }

            // Unmarked Facebook feed WebViews first receive the normal module entry.
            // If it stalls, retry with the independent classic bundle.
            window.setTimeout(recoverBoot, 6000);
            window.setTimeout(function () {
                if (!bootIsPending()) return;
                window.__reportBootFailure('boot-timeout', '');
                window.__showAppBootError();
            }, 20000);
        })();
    </script>
    @if ($gtmId && $useMetaBundle)
        <script>
            (function () {
                var loaded = false;
                function loadGtm() {
                    if (loaded) return;
                    loaded = true;
                    var w=window,d=document,s='script',l='dataLayer',i=@json($gtmId);
                    w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});
                    var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';
                    j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;
                    f.parentNode.insertBefore(j,f);
                }

                if (window.__appBootState && window.__appBootState.stage === 'mounted') {
                    loadGtm();
                    return;
                }

                window.addEventListener('app:boot-stage', function (event) {
                    if (!event.detail || event.detail.stage !== 'mounted') return;
                    loadGtm();
                }, { once: true });
            })();
        </script>
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
