<?php

namespace App\Providers;

use Illuminate\Pagination\Paginator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register()
    {
        //
    }

    public function boot()
    {
        // Hosting PHP without the fileinfo extension (e.g. cPanel ea-php84): detect file types ourselves.
        if (! class_exists(\finfo::class)) {
            $this->withoutFileinfo();
        }

        Paginator::defaultView('site.partials.pagination');

        // After an upload: run the database update and clear caches on the first request.
        \App\Support\AutoUpdate::run();

        // Telegram settings edited in Panel → System override .env.
        \App\Support\RuntimeSettings::apply();
    }

    /**
     * Without fileinfo, Laravel's local disks fail on creation ("Class finfo not found") and upload
     * validation (mimes:pdf,docx) cannot tell file types. Both get MagicMimeTypes instead.
     */
    private function withoutFileinfo(): void
    {
        $detector = new \App\Support\MagicMimeTypes();
        \Symfony\Component\Mime\MimeTypes::getDefault()->registerGuesser($detector);

        \Illuminate\Support\Facades\Storage::extend('local', function ($app, array $config) use ($detector) {
            $visibility = \League\Flysystem\UnixVisibility\PortableVisibilityConverter::fromArray(
                $config['permissions'] ?? [],
                $config['directory_visibility'] ?? $config['visibility'] ?? \League\Flysystem\Visibility::PRIVATE
            );
            $links = ($config['links'] ?? null) === 'skip'
                ? \League\Flysystem\Local\LocalFilesystemAdapter::SKIP_LINKS
                : \League\Flysystem\Local\LocalFilesystemAdapter::DISALLOW_LINKS;
            $adapter = new \League\Flysystem\Local\LocalFilesystemAdapter($config['root'], $visibility, $config['lock'] ?? LOCK_EX, $links, $detector);

            return new \Illuminate\Filesystem\FilesystemAdapter(
                new \League\Flysystem\Filesystem($adapter, \Illuminate\Support\Arr::only($config, ['directory_visibility', 'disable_asserts', 'temporary_url', 'url', 'visibility'])),
                $adapter,
                $config
            );
        });
    }
}
