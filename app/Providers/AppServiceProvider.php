<?php

namespace App\Providers;

use Illuminate\Filesystem\LocalFilesystemAdapter;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use League\Flysystem\Filesystem as Flysystem;
use League\Flysystem\Local\LocalFilesystemAdapter as FlysystemLocalAdapter;
use League\Flysystem\PathPrefixing\PathPrefixedAdapter;
use League\Flysystem\ReadOnly\ReadOnlyFilesystemAdapter;
use League\Flysystem\UnixVisibility\PortableVisibilityConverter;
use League\Flysystem\Visibility;
use League\MimeTypeDetection\ExtensionMimeTypeDetector;
use League\MimeTypeDetection\FinfoMimeTypeDetector;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerLocalFilesystemWithoutFinfoFallback();

        $appUrl = (string) config('app.url');
        $forceHttps = (bool) config('app.force_https')
            || (config('app.env') === 'production' && str_starts_with($appUrl, 'https://'));

        if ($forceHttps) {
            URL::forceScheme('https');
        }
    }

    /**
     * Shared hosts often disable ext-fileinfo. Flysystem's default FinfoMimeTypeDetector
     * then fatals on Storage::disk('public') / UploadedFile::storeAs(). Fall back to
     * extension-based MIME detection when finfo is missing.
     */
    private function registerLocalFilesystemWithoutFinfoFallback(): void
    {
        Storage::extend('local', function ($app, array $config) {
            $visibility = PortableVisibilityConverter::fromArray(
                $config['permissions'] ?? [],
                $config['directory_visibility'] ?? $config['visibility'] ?? Visibility::PRIVATE
            );

            $links = ($config['links'] ?? null) === 'skip'
                ? FlysystemLocalAdapter::SKIP_LINKS
                : FlysystemLocalAdapter::DISALLOW_LINKS;

            $mimeTypeDetector = class_exists(\finfo::class, false)
                ? new FinfoMimeTypeDetector
                : new ExtensionMimeTypeDetector;

            $adapter = new FlysystemLocalAdapter(
                $config['root'],
                $visibility,
                $config['lock'] ?? LOCK_EX,
                $links,
                $mimeTypeDetector,
            );

            if ($config['read-only'] ?? false) {
                $adapter = new ReadOnlyFilesystemAdapter($adapter);
            }

            if (! empty($config['prefix'])) {
                $adapter = new PathPrefixedAdapter($adapter, $config['prefix']);
            }

            return (new LocalFilesystemAdapter(
                new Flysystem($adapter, Arr::only($config, [
                    'directory_visibility',
                    'disable_asserts',
                    'retain_visibility',
                    'temporary_url',
                    'url',
                    'visibility',
                ])),
                $adapter,
                $config,
            ))->shouldServeSignedUrls(
                $config['serve'] ?? false,
                fn () => $app['url'],
            );
        });
    }
}
