<?php

namespace App\Http\Controllers;

use App\Models\Innovation;
use App\Services\ToolsService;
use App\Services\TrendsFeedService;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Schema;

class SitemapController extends Controller
{
    public function __invoke(TrendsFeedService $trends): Response
    {
        $urls = [];

        foreach (config('seo.sitemap', []) as $path) {
            $urls[] = [
                'loc' => url($path),
                'changefreq' => 'weekly',
                'priority' => $path === '/' ? '1.0' : '0.8',
            ];
        }

        foreach (ToolsService::all() as $tool) {
            $slug = str_replace('tools.', '', (string) ($tool['route'] ?? ''));
            if ($slug !== '') {
                $urls[] = [
                    'loc' => url("/tools/{$slug}"),
                    'changefreq' => 'monthly',
                    'priority' => '0.7',
                ];
            }
        }

        foreach ($trends->feed(500) as $template) {
            $key = $template['key'] ?? null;
            if (is_string($key) && $key !== '') {
                $urls[] = [
                    'loc' => url("/trends/{$key}"),
                    'changefreq' => 'weekly',
                    'priority' => '0.6',
                ];
            }
        }

        if (Schema::hasTable('innovations')) {
            Innovation::query()
                ->active()
                ->orderByDesc('id')
                ->limit(500)
                ->pluck('slug')
                ->each(function (string $slug) use (&$urls): void {
                    $urls[] = [
                        'loc' => url("/post/{$slug}"),
                        'changefreq' => 'monthly',
                        'priority' => '0.5',
                    ];
                });
        }

        $xml = view('sitemap', ['urls' => $urls])->render();

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
        ]);
    }
}
