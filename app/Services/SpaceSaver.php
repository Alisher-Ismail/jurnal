<?php

namespace App\Services;

use App\Models\Article;
use App\Models\Maqola;
use App\Models\Paper;
use App\Services\Telegram\TelegramApi;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Keeps the hosting disk small: journal files live in the Telegram storage channel and the
 * server copy is deleted once Telegram's copy is confirmed (same size). Files over the bots'
 * 20 MB download limit must stay on the server. Files no record points to are never touched.
 * Rebuildable caches (stamped PDFs, downloaded copies, conversions) are pruned by age and size.
 */
class SpaceSaver
{
    /** Cached copies older than this are deleted; recent ones keep downloads fast. */
    public const CACHE_DAYS = 7;

    public const CACHE_DIRS = ['app/stamped', 'app/convert'];

    /** Folder of the old admin theme (overridable so tests never touch the real one). */
    public ?string $themeRoot = null;

    public function __construct(private FileVault $vault, private TelegramApi $telegram)
    {
    }

    /**
     * @return array{moved: int, freed_bytes: int, kept_big: int, kept_big_bytes: int, failed: string[], left: int, cache_freed_bytes: int}
     */
    public function run(int $seconds = 40): array
    {
        $deadline = microtime(true) + $seconds;
        $report = ['moved' => 0, 'freed_bytes' => 0, 'kept_big' => 0, 'kept_big_bytes' => 0, 'failed' => [], 'left' => 0, 'cache_freed_bytes' => 0];

        if ($this->vault->usesTelegram()) {
            foreach ($this->candidates() as [$record, $label, $column, $disk, $caption, $name]) {
                $local = $this->exactLocalPath($record->{$column}, $disk);
                if (! $local) {
                    continue;
                }
                $size = (int) filesize($local);
                if ($size > FileVault::TELEGRAM_MAX_BYTES) {
                    $report['kept_big']++;
                    $report['kept_big_bytes'] += $size;
                    continue;
                }
                $isPaper = $record instanceof Paper;
                if (! $isPaper && FileVault::detectExtension($local) !== 'pdf') {
                    continue; // e.g. a Word file published as the PDF: an editor has to fix it first
                }
                if (microtime(true) > $deadline) {
                    $report['left']++;
                    continue;
                }

                try {
                    $idColumn = $isPaper ? 'file_id' : 'pdf_file_id';
                    if (! $record->{$idColumn}) {
                        $record->forceFill([$idColumn => $this->vault->storeLocalFile($local, $name, $caption)])->save();
                    }

                    // Delete only when Telegram really has the same file.
                    $remote = $this->telegram->call('getFile', ['file_id' => $record->{$idColumn}]);
                    if ((int) ($remote['file_size'] ?? -1) !== $size) {
                        $report['failed'][] = $label.': '.__('Telegram copy differs in size, server copy kept');
                        continue;
                    }
                    if (! $isPaper) {
                        // Other records pointing at the same file must not lose it.
                        foreach ([Article::class, Maqola::class] as $model) {
                            $model::where('pdf', $record->pdf)->whereNull('pdf_file_id')->update(['pdf_file_id' => $record->pdf_file_id]);
                        }
                    }
                    if (@unlink($local)) {
                        $report['moved']++;
                        $report['freed_bytes'] += $size;
                    }
                } catch (Throwable $e) {
                    $report['failed'][] = $label.': '.$e->getMessage();
                    Log::warning('Moving file to Telegram failed', ['record' => $label, 'error' => $e->getMessage()]);
                }
            }
        }

        $report['cache_freed_bytes'] = $this->pruneCaches();

        return $report;
    }

    public static function summary(array $report): string
    {
        $mb = fn (int $bytes) => number_format($bytes / 1048576, 1);
        $parts = [__(':count files moved to Telegram, :mb MB freed', ['count' => $report['moved'], 'mb' => $mb($report['freed_bytes'] + $report['cache_freed_bytes'])])];
        if ($report['left']) {
            $parts[] = __(':count files left: they continue automatically (or press the button again)', ['count' => $report['left']]);
        }
        if ($report['kept_big']) {
            $parts[] = __(':count files over 20 MB stay on the server (:mb MB)', ['count' => $report['kept_big'], 'mb' => $mb($report['kept_big_bytes'])]);
        }
        if ($report['failed']) {
            $parts[] = __(':count failed', ['count' => count($report['failed'])]);
        }

        return implode('. ', $parts).'.';
    }

    /**
     * Update packages (jurnal-*.zip) left in the site folder after extracting them, and old or oversized logs.
     *
     * @return array{removed: array<string, int>, bytes: int}
     */
    public function removeLeftovers(): array
    {
        $removed = [];
        // Only this journal's own update packages: any other archive may be someone's backup.
        foreach (glob(base_path('jurnal-*.zip')) ?: [] as $zip) {
            $size = (int) filesize($zip);
            if (@unlink($zip)) {
                $removed[basename($zip)] = $size;
            }
        }
        foreach (glob(storage_path('logs/*.log')) ?: [] as $log) {
            $size = (int) filesize($log);
            if (filemtime($log) < now()->subDays(14)->getTimestamp()) {
                if (@unlink($log)) {
                    $removed['logs/'.basename($log)] = $size;
                }
            } elseif ($size > 5 * 1048576) {
                // Keep the newest part of a large log: recent errors are the useful ones.
                $tail = (string) @file_get_contents($log, false, null, $size - 1048576);
                if (@file_put_contents($log, $tail) !== false) {
                    $removed['logs/'.basename($log).' (trimmed)'] = $size - strlen($tail);
                }
            }
        }

        // The old AdminLTE theme: the panel no longer loads it. dist/img stays (site icon and logo).
        foreach (['plugins', 'docs', 'pages', 'style', 'dist/css', 'dist/js'] as $dir) {
            $path = ($this->themeRoot ?? public_path('admin')).'/'.$dir;
            if (is_dir($path) && ! is_link($path)) {
                $size = $this->dirSize($path);
                if (\Illuminate\Support\Facades\File::deleteDirectory($path)) {
                    $removed['public/admin/'.$dir.' ('.__('old theme').')'] = $size;
                }
            }
        }

        foreach ($this->unusedPackages() as $package => $path) {
            $size = $this->dirSize($path);
            if (\Illuminate\Support\Facades\File::deleteDirectory($path)) {
                $removed['vendor/'.$package] = $size;
                $parent = dirname($path);
                if (is_dir($parent) && count(scandir($parent)) === 2) {
                    @rmdir($parent);
                }
            }
        }

        if (array_filter(array_keys($removed), fn ($k) => str_starts_with($k, 'vendor/'))) {
            // The cached provider list must not name a removed package; Laravel rebuilds it.
            @unlink(base_path('bootstrap/cache/packages.php'));
            @unlink(base_path('bootstrap/cache/services.php'));
        }

        return ['removed' => $removed, 'bytes' => array_sum($removed)];
    }

    /**
     * Packages left in vendor/ by older uploads (e.g. developer tools) that the installed set no
     * longer contains. Uploading the update replaces files but never removes old ones.
     *
     * @return array<string, string> package name => folder
     */
    public function unusedPackages(): array
    {
        $installed = json_decode((string) @file_get_contents(base_path('vendor/composer/installed.json')), true);
        $packages = $installed['packages'] ?? $installed ?? [];
        // Only trust a production package list that looks complete.
        if (($installed['dev'] ?? true) !== false || count($packages) < 20 || ! isset(array_column($packages, null, 'name')['laravel/framework'])) {
            return [];
        }
        $keep = array_flip(array_column($packages, 'name'));

        $unused = [];
        foreach (glob(base_path('vendor/*/*'), GLOB_ONLYDIR) ?: [] as $dir) {
            $name = basename(dirname($dir)).'/'.basename($dir);
            if (in_array(basename(dirname($dir)), ['composer', 'bin'], true) || isset($keep[$name]) || is_link($dir)) {
                continue;
            }
            $unused[$name] = $dir;
        }

        return $unused;
    }

    public function dirSize(string $path): int
    {
        $size = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $size += $file->isFile() ? $file->getSize() : 0;
        }

        return $size;
    }

    /**
     * Largest folders of the site, for the System page (cached: walking the tree takes a moment).
     *
     * @return array<string, int> folder => bytes, biggest first
     */
    public function folderSizes(): array
    {
        return \Illuminate\Support\Facades\Cache::remember('disk-usage', now()->addMinutes(10), function () {
            $sizes = [];
            $base = rtrim(str_replace('\\', '/', base_path()), '/');
            $groups = ['public/storage', 'public/admin', 'storage/app', 'storage/logs', 'vendor'];
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if (! $file->isFile()) {
                    continue;
                }
                $rel = substr(str_replace('\\', '/', $file->getPathname()), strlen($base) + 1);
                $group = null;
                foreach ($groups as $g) {
                    if (str_starts_with($rel, $g.'/')) {
                        $group = $g;
                        break;
                    }
                }
                if ($group === 'storage/app') {
                    // storage/app holds PDFs, caches and backups: show which.
                    $group = implode('/', array_slice(explode('/', $rel), 0, 3));
                    $group = str_contains(substr($group, 12), '.') ? 'storage/app (files)' : $group;
                }
                $group ??= str_contains($rel, '/') ? strtok($rel, '/') : $rel;
                $sizes[$group] = ($sizes[$group] ?? 0) + $file->getSize();
            }
            arsort($sizes);

            return array_slice($sizes, 0, 12, true);
        });
    }

    /** Bytes used on the server by journal files and caches. */
    public function usage(): array
    {
        $files = 0;
        $big = 0;
        foreach ($this->candidates() as [$record, , $column, $disk]) {
            if ($local = $this->exactLocalPath($record->{$column}, $disk)) {
                $size = (int) filesize($local);
                $files += $size;
                $big += $size > FileVault::TELEGRAM_MAX_BYTES ? $size : 0;
            }
        }
        $cache = 0;
        foreach ($this->cacheFiles() as $file) {
            $cache += $file->getSize();
        }

        return ['files' => $files, 'movable' => $files - $big, 'big' => $big, 'cache' => $cache];
    }

    /** Upper limit for the rebuildable caches together (stamped PDFs, Telegram copies, conversions). */
    public const CACHE_MAX_MB = 60;

    /**
     * Keep the caches under CACHE_MAX_MB, removing the oldest files first. Called after every cache
     * write but runs at most every 30 seconds. Without it, bulk jobs (reading all PDFs for abstracts
     * or the plagiarism index) filled the hosting quota and every page failed ("Disk quota exceeded").
     */
    public static function limitCache(): void
    {
        $marker = storage_path('app/.cache-limit-checked');
        if (is_file($marker) && filemtime($marker) > time() - 30) {
            return;
        }
        @touch($marker);

        $files = [];
        $total = 0;
        foreach (self::CACHE_DIRS as $dir) {
            $root = storage_path($dir);
            if (! is_dir($root)) {
                continue;
            }
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile() && $file->getFilename() !== '.gitignore') {
                    $files[] = [$file->getMTime(), $file->getSize(), $file->getPathname()];
                    $total += $file->getSize();
                }
            }
        }
        $max = self::CACHE_MAX_MB * 1048576;
        if ($total <= $max) {
            return;
        }
        usort($files, fn ($a, $b) => $a[0] <=> $b[0]);
        foreach ($files as [, $size, $path]) {
            if ($total <= $max * 0.75) {
                break;
            }
            if (@unlink($path)) {
                $total -= $size;
            }
        }
    }

    /** Delete cached copies older than CACHE_DAYS; they are rebuilt from Telegram when needed. */
    public function pruneCaches(?int $days = null): int
    {
        $limit = now()->subDays($days ?? self::CACHE_DAYS)->getTimestamp();
        $freed = 0;
        foreach ($this->cacheFiles() as $file) {
            if ($file->getMTime() < $limit) {
                $size = $file->getSize();
                if (@unlink($file->getPathname())) {
                    $freed += $size;
                }
            }
        }

        return $freed;
    }

    /** @return iterable<\SplFileInfo> */
    private function cacheFiles(): iterable
    {
        foreach (self::CACHE_DIRS as $dir) {
            $root = storage_path($dir);
            if (! is_dir($root)) {
                continue;
            }
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile() && $file->getFilename() !== '.gitignore') {
                    yield $file;
                }
            }
        }
    }

    /**
     * Records whose file may be on the server: [record, label, path column, disk, caption, upload name].
     *
     * @return iterable<array>
     */
    private function candidates(): iterable
    {
        foreach (Maqola::whereNotNull('pdf')->where('pdf', '!=', '')->orderBy('id')->cursor() as $issue) {
            yield [$issue, 'issue '.$issue->id, 'pdf', 'public', '📚 '.e($issue->title), $this->name($issue->title)];
        }
        foreach (Article::whereNotNull('pdf')->where('pdf', '!=', '')->orderBy('id')->cursor() as $article) {
            yield [$article, 'article '.$article->id, 'pdf', 'public', '📄 '.e($article->title), $this->name($article->title)];
        }
        foreach (Paper::whereNotNull('file_path')->orderBy('id')->cursor() as $paper) {
            $ext = strtolower(pathinfo((string) ($paper->file_name ?: $paper->file_path), PATHINFO_EXTENSION)) ?: 'pdf';
            yield [$paper, 'paper '.$paper->code, 'file_path', 'local', '📝 '.e($paper->code.' · '.$paper->title), $this->vault->safeName((string) $paper->file_name, $ext)];
        }
    }

    /**
     * The file a record points to, by its exact path only. The loose name matching used for
     * serving old links is too forgiving to decide what may be deleted.
     */
    private function exactLocalPath(?string $path, string $disk): ?string
    {
        if (! $path || str_contains($path, '..')) {
            return null;
        }
        $path = ltrim(preg_replace('#^(public/|storage/)+#', '', str_replace('\\', '/', $path)), '/');
        foreach ($this->vault->legacyRoots($disk) as $root) {
            if (is_file($root.'/'.$path)) {
                return $root.'/'.$path;
            }
        }

        return null;
    }

    private function name(?string $title): string
    {
        return (Str::slug(Str::limit((string) $title, 60, '')) ?: 'document').'.pdf';
    }
}
