<?php echo '<?xml version="1.0" encoding="UTF-8"?>'; ?>

<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
@foreach ($urls as $entry)
    <url>
        <loc>{{ $entry['loc'] }}</loc>
        <changefreq>{{ $entry['changefreq'] ?? 'weekly' }}</changefreq>
        <priority>{{ $entry['priority'] ?? '0.5' }}</priority>
    </url>
@endforeach
</urlset>
