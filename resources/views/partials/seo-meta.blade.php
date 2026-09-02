@php
    /** @var array<string, mixed>|null $seo */
    $seo = $seo ?? [];
    $title = $seo['title'] ?? config('seo.title', config('app.name'));
    $description = $seo['description'] ?? config('seo.description', '');
    $image = $seo['image'] ?? url(config('seo.image', '/storage/ai_icons/music_home.jpg'));
    $canonical = $seo['url'] ?? url()->current();
    $type = $seo['type'] ?? 'website';
    $robots = $seo['robots'] ?? 'index, follow';
    $siteName = $seo['site_name'] ?? config('seo.site_name', config('app.name'));
    $twitterCard = $seo['twitter_card'] ?? 'summary_large_image';
    $twitterHandle = $seo['twitter_handle'] ?? config('seo.twitter_handle');
    $locale = $seo['locale'] ?? config('seo.locale', 'en_US');
@endphp

<title>{{ $title }}</title>
<meta name="description" content="{{ $description }}">
<meta name="robots" content="{{ $robots }}">
<link rel="canonical" href="{{ $canonical }}">

<meta property="og:locale" content="{{ $locale }}">
<meta property="og:type" content="{{ $type }}">
<meta property="og:site_name" content="{{ $siteName }}">
<meta property="og:title" content="{{ $title }}">
<meta property="og:description" content="{{ $description }}">
<meta property="og:url" content="{{ $canonical }}">
<meta property="og:image" content="{{ $image }}">
<meta property="og:image:alt" content="{{ $siteName }}">

<meta name="twitter:card" content="{{ $twitterCard }}">
<meta name="twitter:title" content="{{ $title }}">
<meta name="twitter:description" content="{{ $description }}">
<meta name="twitter:image" content="{{ $image }}">
@if ($twitterHandle)
<meta name="twitter:site" content="{{ str_starts_with($twitterHandle, '@') ? $twitterHandle : '@'.$twitterHandle }}">
@endif

<script type="application/ld+json">
{!! json_encode([
    '@' . 'context' => 'https://schema.org',
    '@type' => 'WebSite',
    'name' => $siteName,
    'url' => url('/'),
    'description' => $description,
    'potentialAction' => [
        '@type' => 'SearchAction',
        'target' => url('/lab').'?type=text-to-video',
        'query-input' => 'required name=search_term_string',
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}
</script>
