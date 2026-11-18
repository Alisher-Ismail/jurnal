<?php

namespace App\Support;

use Throwable;

/**
 * Journal details printed on certificates, edited in Panel → System (no .env editing).
 * The indexing list is whatever the editors enter: only databases that really index the journal.
 */
class JournalProfile
{
    public const KEY = 'journal_profile';

    /** @return array{issn: ?string, website: string, editor_name: ?string, editor_title: ?string, indexing: string[]} */
    public static function get(): array
    {
        try {
            $saved = AppSetting::get(self::KEY, []) ?: [];
        } catch (Throwable $e) {
            $saved = []; // before the database update
        }

        return [
            'issn' => ($saved['issn'] ?? null) ?: config('journal.issn'),
            'website' => preg_replace(['#^https?://#', '#/public$#'], '', rtrim((string) (($saved['website'] ?? null) ?: config('app.url')), '/')),
            'editor_name' => ($saved['editor_name'] ?? null) ?: config('journal.editor_name'),
            'editor_title' => ($saved['editor_title'] ?? null) ?: null,
            'indexing' => array_values(array_filter(array_map('trim', $saved['indexing'] ?? []))),
            // Sent to the author on acceptance; the certificate follows the confirmed payment.
            'payment_fee' => trim((string) ($saved['payment_fee'] ?? '')) ?: '50 000 so‘m',
            'payment_info' => trim((string) ($saved['payment_info'] ?? '')) ?: 'To‘lov ma’lumotlari uchun tahririyatga yozing: @Urobotuz. To‘lov chekini shu manzilga yuboring.',
        ];
    }

    /** The ISSN as entered in Panel → System (falls back to JOURNAL_ISSN in .env), or null. */
    public static function issn(): ?string
    {
        return trim((string) self::get()['issn']) ?: null;
    }

    /** Printable form: "ISSN 0000-0000", or the value as entered when it already says ISSN/e-ISSN. */
    public static function issnLabel(): ?string
    {
        $issn = self::issn();
        if (! $issn) {
            return null;
        }

        return stripos($issn, 'issn') !== false ? $issn : 'ISSN '.$issn;
    }

    /** Just the number(s) for citation_issn meta tags, e.g. "3030-1234". */
    public static function issnNumber(): ?string
    {
        $issn = self::issn();
        if (! $issn) {
            return null;
        }

        return preg_match('/\d{4}-\d{3}[\dX]/i', $issn, $m) ? strtoupper($m[0]) : $issn;
    }

    public static function save(array $values): void
    {
        AppSetting::put(self::KEY, array_merge(AppSetting::get(self::KEY, []) ?: [], $values));
    }
}
