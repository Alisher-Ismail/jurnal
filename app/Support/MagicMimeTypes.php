<?php

namespace App\Support;

use League\MimeTypeDetection\ExtensionMimeTypeDetector;
use League\MimeTypeDetection\MimeTypeDetector;
use Symfony\Component\Mime\MimeTypeGuesserInterface;

/**
 * File type detection without PHP's fileinfo extension (some hosting PHP builds, e.g. cPanel ea-php84,
 * ship without it). The type is read from the file's first bytes; the file name's extension is the
 * fallback. Used both by Symfony (upload validation, downloads) and by Flysystem (Storage disks).
 */
class MagicMimeTypes implements MimeTypeGuesserInterface, MimeTypeDetector
{
    private ExtensionMimeTypeDetector $byExtension;

    public function __construct()
    {
        $this->byExtension = new ExtensionMimeTypeDetector();
    }

    /** MIME type from the first bytes of a file, or null when they are not recognised. */
    public static function sniff(string $head): ?string
    {
        return match (true) {
            $head === '' => null,
            str_starts_with($head, '%PDF') => 'application/pdf',
            str_starts_with($head, "PK\x03\x04") => match (true) {
                str_contains($head, 'word/') => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                str_contains($head, 'xl/') => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                str_contains($head, 'ppt/') => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                default => 'application/zip',
            },
            str_starts_with($head, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") => 'application/msword',
            str_starts_with($head, "\x89PNG\r\n\x1A\n") => 'image/png',
            str_starts_with($head, "\xFF\xD8\xFF") => 'image/jpeg',
            str_starts_with($head, 'GIF87a'), str_starts_with($head, 'GIF89a') => 'image/gif',
            str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP' => 'image/webp',
            str_starts_with($head, '{\rtf') => 'application/rtf',
            (bool) preg_match('/^\s*(<\?xml[^>]*>\s*)?<svg[\s>]/i', substr($head, 0, 512)) => 'image/svg+xml',
            (bool) preg_match('/^\s*<(!doctype html|html)[\s>]/i', substr($head, 0, 256)) => 'text/html',
            mb_check_encoding(substr($head, 0, 2048), 'UTF-8') && ! preg_match('/[\x00-\x08\x0E-\x1F]/', substr($head, 0, 2048)) => 'text/plain',
            default => null,
        };
    }

    /** Enough of the file to recognise it: Word/Excel zip archives name their parts within the first kilobytes. */
    private static function head(string $path): string
    {
        return (string) @file_get_contents($path, false, null, 0, 65536);
    }

    // Symfony\Component\Mime\MimeTypeGuesserInterface

    public function isGuesserSupported(): bool
    {
        return true;
    }

    public function guessMimeType(string $path): ?string
    {
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        return self::sniff(self::head($path)) ?? $this->byExtension->detectMimeTypeFromPath($path);
    }

    // League\MimeTypeDetection\MimeTypeDetector

    public function detectMimeType(string $path, $contents): ?string
    {
        $sniffed = is_string($contents) ? self::sniff(substr($contents, 0, 65536)) : null;

        return $sniffed ?? $this->byExtension->detectMimeTypeFromPath($path);
    }

    public function detectMimeTypeFromBuffer(string $contents): ?string
    {
        return self::sniff(substr($contents, 0, 65536));
    }

    public function detectMimeTypeFromPath(string $path): ?string
    {
        return $this->byExtension->detectMimeTypeFromPath($path);
    }

    public function detectMimeTypeFromFile(string $path): ?string
    {
        return $this->guessMimeType($path);
    }
}
