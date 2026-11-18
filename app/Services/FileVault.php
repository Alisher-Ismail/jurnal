<?php

namespace App\Services;

use App\Services\Telegram\TelegramApi;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Stores every journal file in a private Telegram channel and serves it back by file_id.
 * When the bot is not configured (e.g. local development) files fall back to a local disk.
 */
class FileVault
{
    public function __construct(private TelegramApi $telegram)
    {
    }

    /** Bots can upload 50 MB but only download 20 MB, so bigger files stay on the server. */
    public const TELEGRAM_MAX_BYTES = 20 * 1024 * 1024;

    public function usesTelegram(): bool
    {
        return $this->telegram->enabled() && filled(config('services.telegram.storage_chat_id'));
    }

    public function fitsTelegram(int $bytes): bool
    {
        return $this->usesTelegram() && $bytes > 0 && $bytes <= self::TELEGRAM_MAX_BYTES;
    }

    /**
     * @return array{file_id: ?string, message_id: ?int, path: ?string}
     */
    public function storeUpload(UploadedFile $file, string $caption, string $localDisk = 'local', string $localDir = 'vault'): array
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: 'pdf');
        $name = $this->safeName($file->getClientOriginalName(), $extension);

        if ($this->fitsTelegram((int) $file->getSize())) {
            try {
                $message = $this->telegram->sendDocumentContents(
                    config('services.telegram.storage_chat_id'), $file->get(), $name, $caption
                );

                return ['file_id' => $message['document']['file_id'], 'message_id' => $message['message_id'], 'path' => null];
            } catch (Throwable $e) {
                Log::warning('Telegram upload failed, storing locally', ['error' => $e->getMessage()]);
            }
        }

        return ['file_id' => null, 'message_id' => null, 'path' => $file->storeAs($localDir, Str::random(24).'.'.$extension, $localDisk)];
    }

    /**
     * Re-post a file the bot already received so the storage channel keeps its own copy.
     *
     * @return array{file_id: string, message_id: ?int}
     */
    public function storeTelegramFile(string $fileId, string $caption): array
    {
        if (! $this->usesTelegram()) {
            return ['file_id' => $fileId, 'message_id' => null];
        }

        try {
            $message = $this->telegram->sendDocumentById(config('services.telegram.storage_chat_id'), $fileId, $caption);

            return ['file_id' => $message['document']['file_id'], 'message_id' => $message['message_id']];
        } catch (Throwable $e) {
            Log::warning('Could not copy file to storage chat', ['error' => $e->getMessage()]);

            return ['file_id' => $fileId, 'message_id' => null];
        }
    }

    /** Local copy of a Telegram-stored file (cached in storage/app/stamped/src), e.g. for stamping or text extraction. */
    public function telegramCopy(string $fileId): ?string
    {
        $path = storage_path('app/stamped/src/'.md5($fileId).'.pdf');
        if (is_file($path)) {
            return $path;
        }
        try {
            $contents = $this->contents($fileId, null);
        } catch (Throwable $e) {
            return null;
        }
        if (! $contents) {
            return null;
        }
        if (! is_dir(dirname($path))) {
            @mkdir(dirname($path), 0775, true);
        }
        if (@file_put_contents($path, $contents) === false) {
            @unlink($path); // e.g. disk quota reached: no half-written copy

            return null;
        }
        SpaceSaver::limitCache();

        return $path;
    }

    /** Upload an existing local file to the storage chat (used to migrate legacy PDFs). */
    public function storeLocalFile(string $absolutePath, string $filename, string $caption): ?string
    {
        if (! is_file($absolutePath) || ! $this->fitsTelegram((int) filesize($absolutePath))) {
            return null;
        }
        $message = $this->telegram->sendDocumentContents(
            config('services.telegram.storage_chat_id'), file_get_contents($absolutePath), $filename, $caption
        );

        return $message['document']['file_id'];
    }

    /**
     * Find a legacy local file. Older versions wrote uploads to different places depending on
     * the disk they used (storage/app, storage/app/public, public/storage), so check them all,
     * and finally look for the bare file name anywhere under those folders.
     */
    public function localPath(?string $path, string $disk = 'local'): ?string
    {
        if (! $path || str_contains($path, '..')) {
            return null;
        }
        $path = ltrim(preg_replace('#^(public/|storage/)+#', '', str_replace('\\', '/', $path)), '/');

        foreach ($this->legacyRoots($disk) as $root) {
            if (is_file($root.'/'.$path)) {
                return $root.'/'.$path;
            }
        }

        // Cached as '' when missing, so a missing file does not trigger a scan on every request.
        $found = Cache::remember('legacy-file:'.md5($path), now()->addHour(), function () use ($path) {
            $name = basename($path);
            $loose = self::looseName($name);
            $similar = '';
            foreach ([public_path('storage'), storage_path('app')] as $root) {
                if (! is_dir($root)) {
                    continue;
                }
                $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
                foreach ($files as $file) {
                    if (! $file->isFile()) {
                        continue;
                    }
                    if ($file->getFilename() === $name) {
                        return $file->getPathname();
                    }
                    if ($similar === '' && $loose !== '' && self::looseName($file->getFilename()) === $loose) {
                        $similar = $file->getPathname();
                    }
                }
            }

            // Same name apart from apostrophe style (' ʻ ’ `), HTML escaping, spacing or letter case.
            return $similar;
        });

        return $found !== '' && is_file($found) ? $found : null;
    }

    /**
     * Where every issue/article PDF is served from.
     *
     * @return array<int, array{record: string, url: string, title: string, path: ?string, where: string, missing: bool}>
     */
    public function report(): array
    {
        $rows = [];
        $base = rtrim(str_replace(DIRECTORY_SEPARATOR, '/', base_path()), '/').'/';
        $records = \App\Models\Maqola::orderBy('id')->get()->concat(\App\Models\Article::orderBy('id')->get());

        foreach ($records as $record) {
            if (! $record->pdf && ! $record->pdf_file_id) {
                continue;
            }
            $isIssue = $record instanceof \App\Models\Maqola;
            Cache::forget('legacy-file:'.md5(ltrim(preg_replace('#^(public/|storage/)+#', '', (string) $record->pdf), '/')));
            $local = $record->pdf_file_id ? null : $this->localPath($record->pdf, 'public');
            if ($local && self::detectExtension($local) !== 'pdf') {
                $rows[] = [
                    'record' => ($isIssue ? 'issue ' : 'article ').$record->id,
                    'url' => route($isIssue ? 'admin.issues.edit' : 'admin.articles.edit', $record->id),
                    'title' => (string) $record->title,
                    'path' => $record->pdf,
                    'where' => 'NOT A PDF ('.strtoupper((string) self::detectExtension($local) ?: '?').')',
                    'missing' => true,
                ];
                continue;
            }

            $rows[] = [
                'record' => ($isIssue ? 'issue ' : 'article ').$record->id,
                'url' => route($isIssue ? 'admin.issues.edit' : 'admin.articles.edit', $record->id),
                'title' => (string) $record->title,
                'path' => $record->pdf,
                'where' => $record->pdf_file_id ? 'Telegram' : ($local ? str_replace($base, '', str_replace(DIRECTORY_SEPARATOR, '/', $local)) : 'MISSING'),
                'missing' => ! $record->pdf_file_id && ! $local,
            ];
        }

        return $rows;
    }

    /** @return string[] */
    public function legacyRoots(string $disk = 'local'): array
    {
        // public/storage first: files there keep their old, Scholar-indexed public URL.
        return array_values(array_unique(array_map(fn ($p) => rtrim(str_replace('\\', '/', $p), '/'), [
            public_path('storage'),
            Storage::disk($disk)->path(''),
            Storage::disk('public')->path(''),
            Storage::disk('local')->path(''),
            public_path(),
        ])));
    }

    public function exists(?string $fileId, ?string $path, string $disk = 'local'): bool
    {
        return ($fileId && $this->telegram->enabled()) || $this->localPath($path, $disk) !== null;
    }

    public function contents(?string $fileId, ?string $path, string $disk = 'local'): ?string
    {
        if ($fileId && $this->telegram->enabled()) {
            return Http::timeout(120)->get($this->telegram->fileUrl($fileId))->body();
        }
        $local = $this->localPath($path, $disk);

        return $local ? file_get_contents($local) : null;
    }

    public function download(?string $fileId, ?string $path, string $filename, string $disk = 'local', bool $inline = false): StreamedResponse
    {
        $headers = [
            'Content-Type' => $this->mimeFor($filename),
            'Content-Disposition' => ($inline ? 'inline' : 'attachment').'; filename="'.str_replace('"', '', $filename).'"',
        ];

        $url = null;
        if ($fileId && $this->telegram->enabled()) {
            try {
                $url = $this->telegram->fileUrl($fileId);
            } catch (Throwable $e) {
                // Telegram unreachable or file too big to fetch: serve the server copy if there is one.
                Log::warning('Telegram download failed', ['file_id' => $fileId, 'error' => $e->getMessage()]);
            }
        }

        if ($url) {
            return response()->stream(function () use ($url) {
                $body = Http::withOptions(['stream' => true])->timeout(120)->get($url)->toPsrResponse()->getBody();
                while (! $body->eof()) {
                    echo $body->read(65536);
                    flush();
                }
            }, 200, $headers + ['Cache-Control' => 'private, max-age=3600']);
        }

        $local = $this->localPath($path, $disk);
        abort_unless($local, 404);

        // A Word file must not be sent as "something.pdf": name and type follow the real content.
        $realName = self::nameFor($local, $filename);
        if ($realName !== $filename) {
            $headers['Content-Type'] = $this->mimeFor($realName);
            $headers['Content-Disposition'] = 'attachment; filename="'.str_replace('"', '', $realName).'"';
        }

        return response()->stream(fn () => readfile($local), 200, $headers + ['Content-Length' => filesize($local)]);
    }

    /** File name reduced to letters and digits, for matching names that differ only in punctuation. */
    public static function looseName(string $name): string
    {
        $name = html_entity_decode(rawurldecode($name), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // \p{Lm} is excluded on purpose: the Uzbek ʻ is a "modifier letter", but here it is just an apostrophe.
        return mb_strtolower((string) preg_replace('/[^\p{Lu}\p{Ll}\p{Lt}\p{Lo}\p{N}]+/u', '', $name));
    }

    /** Real type of a file from its first bytes (uploads are sometimes misnamed). */
    public static function detectExtension(string $absolutePath): ?string
    {
        $head = (string) @file_get_contents($absolutePath, false, null, 0, 8);

        return match (true) {
            str_starts_with($head, '%PDF') => 'pdf',
            str_starts_with($head, "PK\x03\x04") => 'docx',
            str_starts_with($head, "\xD0\xCF\x11\xE0") => 'doc',
            default => null,
        };
    }

    /** Fix the extension of a download name to match the file's real content. */
    public static function nameFor(string $absolutePath, string $filename): string
    {
        $real = self::detectExtension($absolutePath);
        $current = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return $real && $real !== $current ? pathinfo($filename, PATHINFO_FILENAME).'.'.$real : $filename;
    }

    public function safeName(string $original, string $extension): string
    {
        $base = Str::slug(pathinfo($original, PATHINFO_FILENAME)) ?: 'file';

        return Str::limit($base, 80, '').'.'.strtolower($extension ?: 'pdf');
    }

    private function mimeFor(string $filename): string
    {
        return match (strtolower(pathinfo($filename, PATHINFO_EXTENSION))) {
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            default => 'application/octet-stream',
        };
    }
}
